<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../../ngram/NGramNetworkOptionStore.php';
require_once __DIR__ . '/../../ngram/NGramCacheRebuildScheduler.php';
require_once __DIR__ . '/../../ngram/NGramCacheSyncRebuilder.php';
require_once __DIR__ . '/../../ngram/NGramCacheReconciler.php';
require_once __DIR__ . '/../../ngram/NGramTaxonomyBuilder.php';

/**
 * DatabaseUpgradesEtc delegate that owns the n-gram cache lifecycle.
 *
 * Acts as a thin orchestrator around five single-responsibility
 * collaborators:
 *
 *   - NGramNetworkOptionStore: network-aware option storage + multisite
 *     detection (also reached from DatabaseUpgradeBootstrap via the
 *     cross-component dispatcher).
 *   - NGramCacheRebuildScheduler: WP-Cron driven async rebuild loop.
 *   - NGramCacheSyncRebuilder: synchronous TRUNCATE+rebuild used by
 *     manual rebuild tools and the all-content composer.
 *   - NGramCacheReconciler: incremental sync-missing + cleanup-orphaned.
 *   - NGramTaxonomyBuilder: build n-grams for category/tag terms.
 *
 * Lock ownership lives here on the public entry points: the three
 * write paths (rebuildNGramCache, rebuildNGramCacheAsync,
 * syncMissingNGrams) acquire the 'ngram_rebuild' SyncUtils lock and
 * scheduleNGramCacheRebuild acquires 'ngram_schedule', then delegate
 * the actual work. This keeps the lock contract on the public
 * surface while the collaborators stay pure.
 */
class ABJ_404_Solution_DatabaseUpgradeNGram extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Documented cron hook for the n-gram rebuild loop. Defined here
     * so the literal appears in this file for the Pattern 8
     * coordination-key audit (AsyncWorkerCoordinationAuditTest).
     * The scheduler collaborator owns the runtime use.
     */
    const REBUILD_CRON_HOOK = 'abj404_rebuild_ngram_cache_hook';

    /**
     * Convert legacy n-gram cache `last_updated` datetime storage to bigint
     * Unix epoch seconds before the generic schema diff runs.
     *
     * A direct ALTER from datetime to bigint lets MySQL coerce
     * "2026-06-13 03:04:05" into a YYYYMMDDHHMMSS-style number, not an epoch.
     * The temporary-column copy makes the conversion explicit and keeps
     * verifyColumns() from applying that unsafe implicit conversion.
     *
     * @param string $tableName Physical n-gram cache table name.
     * @return bool True when the table is already safe for generic schema
     *              verification, or after conversion succeeds.
     */
    function ensureLastUpdatedEpochColumn($tableName): bool {
        $tableName = is_scalar($tableName) ? (string)$tableName : '';
        if ($tableName === '') {
            $this->logger->warn('Skipping n-gram last_updated epoch migration because the table name was empty.');
            return false;
        }

        $lastUpdatedColumn = $this->getColumnMetadata($tableName, 'last_updated');
        if ($lastUpdatedColumn === null) {
            $tempColumn = $this->getColumnMetadata($tableName, 'last_updated_epoch');
            if ($tempColumn !== null) {
                return $this->renameNGramEpochTempColumn($tableName);
            }
            return true;
        }

        $type = strtolower($this->columnValue($lastUpdatedColumn, 'Type'));
        if (strpos($type, 'datetime') === false && strpos($type, 'timestamp') === false) {
            return true;
        }

        $tempColumn = $this->getColumnMetadata($tableName, 'last_updated_epoch');
        if ($tempColumn === null && !$this->runNGramTimestampMigrationQuery(
            "ALTER TABLE {$tableName}
             ADD COLUMN `last_updated_epoch` bigint(20) NOT NULL DEFAULT 0
             COMMENT 'Last time N-grams were computed as Unix epoch seconds'
             AFTER `ngram_count`",
            'add temporary n-gram epoch timestamp column'
        )) {
            return false;
        }

        if (!$this->runNGramTimestampMigrationQuery(
            "UPDATE {$tableName}
             SET `last_updated_epoch` = COALESCE(
                 TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', `last_updated`),
                 0
             )",
            'copy legacy n-gram datetimes into epoch timestamp column'
        )) {
            return false;
        }

        if (!$this->runNGramTimestampMigrationQuery(
            "ALTER TABLE {$tableName}
             DROP COLUMN `last_updated`",
            'drop legacy n-gram datetime timestamp column'
        )) {
            return false;
        }

        if (!$this->renameNGramEpochTempColumn($tableName)) {
            return false;
        }

        $convertedColumn = $this->getColumnMetadata($tableName, 'last_updated');
        $convertedType = $convertedColumn !== null ? strtolower($this->columnValue($convertedColumn, 'Type')) : '';
        if (strpos($convertedType, 'bigint') === false) {
            $this->logger->warn("N-gram last_updated epoch migration did not leave a bigint column on {$tableName}.");
            return false;
        }

        $this->logger->infoMessage("Migrated {$tableName}.last_updated from datetime to bigint Unix epoch seconds.");
        return true;
    }

    /**
     * @param string $tableName
     * @return bool
     */
    private function renameNGramEpochTempColumn(string $tableName): bool {
        return $this->runNGramTimestampMigrationQuery(
            "ALTER TABLE {$tableName}
             CHANGE COLUMN `last_updated_epoch` `last_updated` bigint(20) NOT NULL DEFAULT 0
             COMMENT 'Last time N-grams were computed as Unix epoch seconds'",
            'rename temporary n-gram epoch timestamp column'
        );
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return array<string, mixed>|null
     */
    private function getColumnMetadata(string $tableName, string $columnName): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM {$tableName}",
            array('log_errors' => false)
        );
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $columnRow = array();
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $columnRow[$key] = $value;
                }
            }
            if (strtolower($this->columnValue($columnRow, 'Field')) === strtolower($columnName)) {
                return $columnRow;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $wantedKey
     * @return string
     */
    private function columnValue(array $row, string $wantedKey): string {
        foreach ($row as $key => $value) {
            if (strtolower((string)$key) === strtolower($wantedKey) && is_scalar($value)) {
                return (string)$value;
            }
        }
        return '';
    }

    /**
     * @param string $query
     * @param string $action
     * @return bool
     */
    private function runNGramTimestampMigrationQuery(string $query, string $action): bool {
        global $wpdb;
        // Schema-bootstrap migration for a system-generated plugin table name.
        // Runs before generic schema diff to avoid unsafe datetime-to-bigint coercion.
        // DAO-bypass-approved: one-shot schema/data migration over a system-generated plugin table name.
        $result = $wpdb->query($query);
        $lastError = isset($wpdb->last_error) && is_string($wpdb->last_error) ? $wpdb->last_error : '';
        if ($result !== false && $lastError === '') {
            return true;
        }
        $this->logger->warn("Failed to {$action}: " . ($lastError !== '' ? $lastError : 'wpdb query returned false'));
        return false;
    }

    /**
     * Acquire the 'ngram_schedule' SyncUtils lock and delegate to the
     * scheduler. Multiple admin clicks during a click storm collapse
     * into one scheduled cron event.
     *
     * @return bool
     */
    function scheduleNGramCacheRebuild() {
        $lockKey = 'ngram_schedule';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);

        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram rebuild scheduling: Another process holds the lock. Skipping.");
            return true;
        }

        try {
            return $this->newScheduler()->scheduleRebuild();
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * WP-Cron callback. Acquires the shared 'ngram_rebuild' lock so
     * its INSERTs cannot race with a concurrent TRUNCATE from the
     * sync rebuilder, then delegates to the scheduler's batch driver.
     *
     * @param int $offset legacy parameter retained for cron payload
     *                    compatibility; the scheduler reads the
     *                    authoritative offset from the network option
     *                    store.
     * @return void
     */
    function rebuildNGramCacheAsync($offset = 0) {
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram async rebuild batch already processing (another process holds lock). Skipping.");
            return;
        }

        try {
            $this->newScheduler()->runAsyncBatch();
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * Synchronous rebuild entry point. Same lock as the async path so
     * its TRUNCATE cannot race batch INSERTs.
     *
     * @param int $batchSize
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    function rebuildNGramCache($batchSize = 100, $forceRebuild = false) {
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->infoMessage("N-gram rebuild already in progress (locked). Skipping.");
            return [
                'total_pages' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'locked' => true,
            ];
        }

        try {
            return $this->newSyncRebuilder()->rebuild($batchSize, $forceRebuild);
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * Sync entries that exist in the source but are missing from the
     * cache. Same lock as rebuild to keep mutations serialized.
     *
     * @param int $batchSize
     * @return array<string, mixed>
     */
    function syncMissingNGrams($batchSize = 50) {
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->debugMessage("Ngram sync skipped - rebuild/sync already in progress.");
            return ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0, 'locked' => true];
        }

        try {
            return $this->newReconciler()->syncMissing($batchSize);
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * Delete cache rows whose source no longer exists. Runs without
     * the rebuild lock — it only deletes by primary key.
     *
     * @return array<string, mixed>
     */
    function cleanupOrphanedNGrams() {
        return $this->newReconciler()->cleanupOrphaned();
    }

    /**
     * @param int $batchSize
     * @return array{processed:int, success:int, failed:int}
     */
    function buildNGramsForCategories($batchSize = 50) {
        return $this->newTaxonomyBuilder()->buildForCategories($batchSize);
    }

    /**
     * @param int $batchSize
     * @return array{processed:int, success:int, failed:int}
     */
    function buildNGramsForTags($batchSize = 50) {
        return $this->newTaxonomyBuilder()->buildForTags($batchSize);
    }

    /**
     * Build n-grams for posts, categories, and tags. Used by the
     * Tools page comprehensive rebuild.
     *
     * @param int $batchSize
     * @return array<string, mixed>
     */
    function buildNGramsForAllContent($batchSize = 100) {
        $this->logger->infoMessage("Starting comprehensive N-gram cache build for all content types...");

        $postsStats = $this->rebuildNGramCache($batchSize, true);
        $categoriesStats = $this->buildNGramsForCategories($batchSize);
        $tagsStats = $this->buildNGramsForTags($batchSize);

        $totalStats = [
            'posts' => $postsStats,
            'categories' => $categoriesStats,
            'tags' => $tagsStats,
            'total_processed' => $this->numericStat($postsStats, 'processed') + $this->numericStat($categoriesStats, 'processed') + $this->numericStat($tagsStats, 'processed'),
            'total_success' => $this->numericStat($postsStats, 'success') + $this->numericStat($categoriesStats, 'success') + $this->numericStat($tagsStats, 'success'),
            'total_failed' => $this->numericStat($postsStats, 'failed') + $this->numericStat($categoriesStats, 'failed') + $this->numericStat($tagsStats, 'failed'),
        ];

        $this->logger->infoMessage("Comprehensive N-gram build complete: {$totalStats['total_processed']} total processed, {$totalStats['total_success']} success, {$totalStats['total_failed']} failed.");

        return $totalStats;
    }

    /**
     * Cross-component contract: DatabaseUpgradeBootstrap and others
     * reach this via the upgrade dispatcher to learn whether the
     * plugin is network-activated.
     *
     * @return bool
     */
    function isNetworkActivated() {
        return $this->newOptionStore()->isNetworkActivated();
    }

    /**
     * Cross-component contract: network-aware option getter.
     *
     * @param string $option_name
     * @param mixed $default
     * @return mixed
     */
    function getNetworkAwareOption($option_name, $default = false) {
        return $this->newOptionStore()->getOption($option_name, $default);
    }

    /**
     * Cross-component contract: network-aware option setter.
     *
     * @param string $option_name
     * @param mixed $value
     * @return bool
     */
    function updateNetworkAwareOption($option_name, $value) {
        return $this->newOptionStore()->updateOption($option_name, $value);
    }

    /**
     * Exposed for the multisite race-condition test (calls through
     * the upgrade dispatcher) and as part of the schedule
     * pre-condition. Sums permalink_cache rows across every site when
     * network-activated, otherwise returns the current site count.
     *
     * @return int
     */
    function countTotalPagesForNGramRebuild() {
        return $this->newScheduler()->countTotalPagesForRebuild();
    }

    /**
     * @param array<string, mixed> $stats
     * @return int
     */
    private function numericStat(array $stats, string $key): int {
        $value = $stats[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    private function newOptionStore(): ABJ_404_Solution_NGramNetworkOptionStore {
        return new ABJ_404_Solution_NGramNetworkOptionStore();
    }

    private function newScheduler(): ABJ_404_Solution_NGramCacheRebuildScheduler {
        return new ABJ_404_Solution_NGramCacheRebuildScheduler(
            $this->dbCore,
            $this->resolveNGramRebuilder(),
            $this->logger,
            $this->newOptionStore()
        );
    }

    private function newSyncRebuilder(): ABJ_404_Solution_NGramCacheSyncRebuilder {
        return new ABJ_404_Solution_NGramCacheSyncRebuilder(
            $this->dbCore,
            $this->resolveNGramRebuilder(),
            $this->resolveNGramCoveragePolicy(),
            $this->logger
        );
    }

    private function newReconciler(): ABJ_404_Solution_NGramCacheReconciler {
        return new ABJ_404_Solution_NGramCacheReconciler(
            $this->dbCore,
            $this->resolveNGramRebuilder(),
            $this->resolveNGramExtractor(),
            $this->resolveNGramCacheRepository(),
            $this->resolveNGramCoveragePolicy(),
            $this->contentRepo,
            $this->f,
            $this->logger
        );
    }

    private function newTaxonomyBuilder(): ABJ_404_Solution_NGramTaxonomyBuilder {
        return new ABJ_404_Solution_NGramTaxonomyBuilder(
            $this->resolveNGramExtractor(),
            $this->resolveNGramCacheRepository(),
            $this->contentRepo,
            $this->f,
            $this->logger
        );
    }

    /** @return object */
    private function resolveNGramExtractor() {
        if ($this->ngramExtractor instanceof ABJ_404_Solution_NGramExtractor) {
            return $this->ngramExtractor;
        }
        if (is_object($this->ngramExtractor) && method_exists($this->ngramExtractor, 'extractNGrams')) {
            return $this->ngramExtractor;
        }
        $legacy = $this->legacyNGramFacade('extractNGrams');
        if ($legacy !== null) {
            return $legacy;
        }
        return new ABJ_404_Solution_NGramExtractor($this->f, $this->logger);
    }

    /** @return object */
    private function resolveNGramCacheRepository() {
        if ($this->ngramCacheRepository instanceof ABJ_404_Solution_NGramCacheRepository) {
            return $this->ngramCacheRepository;
        }
        if (is_object($this->ngramCacheRepository) && method_exists($this->ngramCacheRepository, 'storeNGrams')) {
            return $this->ngramCacheRepository;
        }
        $legacy = $this->legacyNGramFacade('storeNGrams');
        if ($legacy !== null) {
            return $legacy;
        }
        return new ABJ_404_Solution_NGramCacheRepository(
            $this->typedDbCoreOrNull(),
            $this->logger,
            new ABJ_404_Solution_NGramSimilarity(),
            function() {
                return $this->resolveConcreteNGramCoveragePolicy();
            }
        );
    }

    /** @return object */
    private function resolveNGramCoveragePolicy() {
        if ($this->ngramCoveragePolicy instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            return $this->ngramCoveragePolicy;
        }
        if (is_object($this->ngramCoveragePolicy) && method_exists($this->ngramCoveragePolicy, 'invalidateCoverageCaches')) {
            return $this->ngramCoveragePolicy;
        }
        $legacy = $this->legacyNGramFacade('invalidateCoverageCaches');
        if ($legacy !== null) {
            return $legacy;
        }
        return new ABJ_404_Solution_NGramCoveragePolicy($this->typedDbCoreOrNull());
    }

    /** @return object */
    private function resolveNGramRebuilder() {
        if ($this->ngramRebuilder instanceof ABJ_404_Solution_NGramRebuilder) {
            return $this->ngramRebuilder;
        }
        if (is_object($this->ngramRebuilder) && method_exists($this->ngramRebuilder, 'rebuildCache')) {
            return $this->ngramRebuilder;
        }
        $legacy = $this->legacyNGramFacade('rebuildCache');
        if ($legacy !== null) {
            return $legacy;
        }
        return new ABJ_404_Solution_NGramRebuilder(
            $this->typedDbCoreOrNull(),
            $this->logger,
            $this->f,
            $this->resolveConcreteNGramExtractor(),
            $this->resolveConcreteNGramCacheRepository(),
            $this->resolveConcreteNGramCoveragePolicy()
        );
    }

    /**
     * @param string $requiredMethod
     * @return object|null
     */
    private function legacyNGramFacade(string $requiredMethod) {
        return is_object($this->ngramFilter) && method_exists($this->ngramFilter, $requiredMethod)
            ? $this->ngramFilter
            : null;
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    private function typedDbCoreOrNull() {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_NGramExtractor */
    private function resolveConcreteNGramExtractor() {
        if ($this->ngramExtractor instanceof ABJ_404_Solution_NGramExtractor) {
            return $this->ngramExtractor;
        }
        return new ABJ_404_Solution_NGramExtractor($this->f, $this->logger);
    }

    /** @return ABJ_404_Solution_NGramCacheRepository */
    private function resolveConcreteNGramCacheRepository() {
        if ($this->ngramCacheRepository instanceof ABJ_404_Solution_NGramCacheRepository) {
            return $this->ngramCacheRepository;
        }
        return new ABJ_404_Solution_NGramCacheRepository(
            $this->typedDbCoreOrNull(),
            $this->logger,
            new ABJ_404_Solution_NGramSimilarity(),
            function() {
                return $this->resolveConcreteNGramCoveragePolicy();
            }
        );
    }

    /** @return ABJ_404_Solution_NGramCoveragePolicy */
    private function resolveConcreteNGramCoveragePolicy() {
        if ($this->ngramCoveragePolicy instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            return $this->ngramCoveragePolicy;
        }
        return new ABJ_404_Solution_NGramCoveragePolicy($this->typedDbCoreOrNull());
    }
}
