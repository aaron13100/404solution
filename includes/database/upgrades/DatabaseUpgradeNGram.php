<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../../ngram/NGramNetworkOptionStore.php';
require_once __DIR__ . '/../../ngram/NGramCacheRebuildScheduler.php';
require_once __DIR__ . '/../../ngram/NGramCacheRebuildBatchRunner.php';
require_once __DIR__ . '/../../ngram/NGramCacheSyncRebuilder.php';
require_once __DIR__ . '/../../ngram/NGramCacheReconciler.php';
require_once __DIR__ . '/../../ngram/NGramLastUpdatedEpochMigration.php';

/**
 * DatabaseUpgradesEtc delegate that owns the n-gram cache lifecycle.
 *
 * Acts as a thin orchestrator around five single-responsibility
 * collaborators:
 *
 *   - NGramNetworkOptionStore: network-aware option storage + multisite
 *     detection (also reached from DatabaseUpgradeBootstrap via the
 *     cross-component dispatcher).
 *   - NGramCacheRebuildScheduler: decides whether the WP-Cron rebuild
 *     chain needs arming (fresh start or resume of a stalled walk).
 *   - NGramCacheRebuildBatchRunner: the cron callback's batch loop that
 *     drains the content set and reschedules the chain.
 *   - NGramCacheSyncRebuilder: synchronous TRUNCATE+rebuild used by
 *     manual rebuild tools and the all-content composer.
 *   - NGramCacheReconciler: incremental sync-missing + cleanup-orphaned
 *     (posts, categories, and tags).
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
     * Cross-component contract: DatabaseUpgradeBootstrap reaches this via the
     * upgrade dispatcher. The conversion itself lives in
     * {@see ABJ_404_Solution_NGramLastUpdatedEpochMigration}.
     *
     * @param string $tableName Physical n-gram cache table name.
     * @return bool True when the table is already safe for generic schema
     *              verification, or after conversion succeeds.
     */
    function ensureLastUpdatedEpochColumn($tableName): bool {
        return $this->newLastUpdatedEpochMigration()->ensureEpochColumn($tableName);
    }

    private function newLastUpdatedEpochMigration(): ABJ_404_Solution_NGramLastUpdatedEpochMigration {
        return new ABJ_404_Solution_NGramLastUpdatedEpochMigration($this->dbCore, $this->logger);
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
     * Whether the rebuild cron chain currently has an event queued.
     *
     * Exposed so callers outside the n-gram package (the Tools-tab rebuild
     * button) can ask the question instead of probing WP-Cron themselves: the
     * chain is identified by hook AND args, and only
     * {@see ABJ_404_Solution_NGramCacheRebuildScheduler::armedRebuildTimestamp()}
     * knows which args a given moment's chain carries.
     *
     * @return bool
     */
    function nGramRebuildIsArmed(): bool {
        return $this->newScheduler()->armedRebuildTimestamp() !== false;
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
            $this->newBatchRunner()->runAsyncBatch();
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
     * Transient key for the backlog-escalation cooldown. Mirrors the
     * self-healing cooldown pattern used by the logsv2 auto-trim.
     */
    const BACKLOG_REBUILD_COOLDOWN_KEY = 'abj404_ngram_backlog_rebuild_cooldown';

    /**
     * Sync entries that exist in the source but are missing from the
     * cache. Same lock as rebuild to keep mutations serialized.
     *
     * The incremental path owns drift only. When the reconciler reports a
     * backlog it cannot finish, the bulk rebuild is re-armed here so the gap
     * closes in cron runs instead of draining at one batch per daily tick.
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
            $stats = $this->newReconciler()->syncMissing($batchSize);
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }

        // Outside the rebuild lock on purpose: scheduling takes the
        // 'ngram_schedule' lock and the batch driver this arms takes
        // 'ngram_rebuild' itself.
        if (is_array($stats) && !empty($stats['posts_backlogged'])) {
            $this->escalateBacklogToBulkRebuild($stats);
        }

        return $stats;
    }

    /**
     * Hand a backlog the incremental reconciler cannot close to the bulk
     * rebuild path, which processes 1,000 rows per cron run and reschedules
     * itself until the whole content set is covered.
     *
     * Idempotent by construction -- scheduleNGramCacheRebuild() no-ops while a
     * rebuild chain is already armed -- and additionally rate limited by a
     * one-hour transient cooldown so a rebuild that cannot make progress
     * cannot be re-armed in a hot loop by repeated sync calls.
     *
     * @param array<string, mixed> $stats Reconciler stats for this run.
     * @return void
     */
    private function escalateBacklogToBulkRebuild(array $stats): void {
        $cooldownKey = self::BACKLOG_REBUILD_COOLDOWN_KEY;
        if (function_exists('get_transient') && get_transient($cooldownKey)) {
            $this->logger->debugMessage(
                "N-gram backlog rebuild already armed within the cooldown window. Skipping.");
            return;
        }

        $remaining = isset($stats['posts_remaining']) && is_numeric($stats['posts_remaining'])
            ? (string)(int)$stats['posts_remaining']
            : 'an unknown number of';

        $this->logger->infoMessage(
            "N-gram cache backlog of {$remaining} posts is beyond incremental sync capacity. "
            . "Handing off to the bulk rebuild.");

        $scheduled = $this->scheduleNGramCacheRebuild();

        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
            // @cache-write-audit: opt-out - escalation cooldown marker, not query result data.
            // allow-cache-empty: fixed rate-limit marker, not a cached query payload.
            set_transient($cooldownKey, 1, $ttl);
        }

        if (!$scheduled) {
            $this->logger->warn(
                "N-gram cache backlog rebuild could not be scheduled; the incremental sync "
                . "keeps draining it until the next attempt.");
        }
    }

    /**
     * Delete cache rows whose source no longer exists. Runs without
     * the rebuild lock; it only deletes by primary key.
     *
     * @return array<string, mixed>
     */
    function cleanupOrphanedNGrams() {
        return $this->newReconciler()->cleanupOrphaned();
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

    private function newOptionStore(): ABJ_404_Solution_NGramNetworkOptionStore {
        return new ABJ_404_Solution_NGramNetworkOptionStore();
    }

    private function newScheduler(): ABJ_404_Solution_NGramCacheRebuildScheduler {
        return new ABJ_404_Solution_NGramCacheRebuildScheduler(
            $this->dbCore,
            $this->logger,
            $this->newOptionStore(),
            $this->cronScheduler instanceof ABJ_404_Solution_CronScheduler ? $this->cronScheduler : null
        );
    }

    /**
     * The platform services a rebuild tick runs against.
     *
     * The null-vs-instance decision about the cron scheduler is made once, in
     * the runtime's constructor, instead of once here and again in each
     * receiving constructor.
     *
     * @return ABJ_404_Solution_NGramRebuildRuntime
     */
    private function newRebuildRuntime(): ABJ_404_Solution_NGramRebuildRuntime {
        return new ABJ_404_Solution_NGramRebuildRuntime(
            $this->dbCore,
            $this->logger,
            $this->cronScheduler instanceof ABJ_404_Solution_CronScheduler ? $this->cronScheduler : null
        );
    }

    private function newBatchRunner(): ABJ_404_Solution_NGramCacheRebuildBatchRunner {
        return new ABJ_404_Solution_NGramCacheRebuildBatchRunner(
            $this->newRebuildRuntime(),
            $this->resolveNGramRebuilder(),
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
            new ABJ_404_Solution_NGramRebuilderDependencies(
                $this->typedDbCoreOrNull(),
                $this->logger,
                $this->f,
                $this->resolveConcreteNGramExtractor(),
                $this->resolveConcreteNGramCacheRepository(),
                $this->resolveConcreteNGramCoveragePolicy()
            )
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
