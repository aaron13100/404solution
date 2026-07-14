<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permanent-DDL discovery, execution, and post-creation reaction for plugin
 * tables.
 *
 * Owns discovering the permanent (non-Temp) CREATE-TABLE DDL files on disk
 * (discoverPermanentDDLFiles), running them with charset/collation rewriting
 * applied (runInitialCreateTables / applyPluginTableCharsetCollate),
 * verifying each CREATE actually materialized the table on disk
 * (verifyTableMaterialized), and reacting to specific table/column
 * post-creation cases that require a one-time data backfill
 * (applyColumnAddedBackfillsAndCacheInvalidation).
 *
 * Extracted from the table-bootstrap orchestrator (DatabaseUpgradeBootstrap)
 * for the same reason as the lowercase-rename collaborator: DDL execution is
 * a distinct concern from bootstrap orchestration, with its own file-glob
 * discovery, per-table materialization verification, and column-triggered
 * backfill logic.
 *
 * Collaborator of ABJ_404_Solution_DatabaseUpgradeBootstrap, which constructs
 * it fresh on every call (never cached) with the upgrade coordinator, the
 * current DB core / content repository / logger, so it always observes
 * whichever dependencies are current at call time (dbCore / contentRepo /
 * logger can be swapped at runtime via replaceDatabaseUpgradeDependencies()
 * on the owning component).
 */
class ABJ_404_Solution_DatabaseTableDdlExecutor {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $coordinator;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator
     * @param ABJ_404_Solution_DatabaseCore $dbCore Deliberately untyped at the
     *   PHP level (matching ABJ_404_Solution_DatabaseUpgradeComponent::$dbCore):
     *   this collaborator needs methods from both DatabaseCoreInterface
     *   (tableNameResolver) and DatabaseQueryInterface (queryAndGetResults,
     *   doTableNameReplacements), and PHP 7.4 (the plugin's floor version) has
     *   no intersection types. Test doubles (e.g. Abj404NullDatabaseCore)
     *   implement those interfaces without extending the concrete class, so a
     *   native type hint here would break them.
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator,
            $dbCore, ABJ_404_Solution_ContentRepositoryInterface $contentRepo,
            ABJ_404_Solution_Logging $logger) {
        $this->coordinator = $coordinator;
        $this->dbCore = $dbCore;
        $this->contentRepo = $contentRepo;
        $this->logger = $logger;
    }

    /**
     * Discover all permanent (non-Temp) DDL files and extract table metadata.
     *
     * @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}>
     */
    public function discoverPermanentDDLFiles(): array {
        $sqlDir = __DIR__ . '/../../sql';
        $files = glob($sqlDir . '/create*Table.sql');
        if (!is_array($files)) {
            $files = [];
        }
        sort($files);

        $result = [];
        foreach ($files as $file) {
            if (stripos(basename($file), 'Temp') !== false) {
                continue;
            }
            $ddlContent = ABJ_404_Solution_FileSystemService::readFileContents($file);
            if (!is_string($ddlContent) || trim($ddlContent) === '') {
                // Tie the diagnostic to this specific file: the zero-entries
                // guard in runInitialCreateTables() only fires when EVERY
                // file fails, so a lone empty/unreadable file among otherwise
                // healthy ones would otherwise leave its table missing with
                // no trail explaining why.
                $this->logger->errorMessage(
                    'discoverPermanentDDLFiles() found ' . basename($file) . ' empty or '
                    . 'unreadable. The table this file defines will not be created or '
                    . 'repaired until the file is restored. Likely cause: a corrupted or '
                    . 'incomplete plugin installation.'
                );
                continue;
            }
            if (!preg_match('/\{(wp_(abj404_\w+))\}/', $ddlContent, $m)) {
                $this->logger->errorMessage(
                    'discoverPermanentDDLFiles() found ' . basename($file) . ' malformed: '
                    . 'no {wp_abj404_*} table-name placeholder found. The table this file '
                    . 'defines will not be created or repaired until the file is restored. '
                    . 'Likely cause: a corrupted or incomplete plugin installation.'
                );
                continue;
            }
            // Transient staged-build tables (view_build, view_done, view_deleteme)
            // are owned by the staged view-build collaborators.
            // stageCreateBuildTable() creates view_build on demand, stageRenameSwap()
            // renames it to view_done, and view_deleteme is the ephemeral previous-
            // generation served table that gets dropped right after the swap. None
            // of them should participate in the permanent-DDL bootstrap, repair, or
            // missing-table check loops. Their absence between builds is normal,
            // not a corruption signal.
            if (in_array($m[2], array('abj404_view_build', 'abj404_view_done', 'abj404_view_deleteme'), true)) {
                continue;
            }
            $result[] = [
                'placeholder' => '{' . $m[1] . '}',
                'bareTableName' => $m[2],
                'ddlContent' => $ddlContent,
            ];
        }
        // Extension point: add-ons can register extra permanent abj404_* tables
        // (same entry shape as above) to join the create/verify loops; malformed
        // entries from a misbehaving callback are dropped.
        $filtered = apply_filters('abj404_permanent_ddl_files', $result);
        if (!is_array($filtered)) {
            return $result;
        }
        $validated = array();
        foreach ($filtered as $entry) {
            if (is_array($entry)
                    && isset($entry['placeholder'], $entry['bareTableName'], $entry['ddlContent'])
                    && is_string($entry['placeholder']) && trim($entry['placeholder']) !== ''
                    && is_string($entry['bareTableName']) && trim($entry['bareTableName']) !== ''
                    && is_string($entry['ddlContent']) && trim($entry['ddlContent']) !== '') {
                $validated[] = array('placeholder' => $entry['placeholder'],
                    'bareTableName' => $entry['bareTableName'], 'ddlContent' => $entry['ddlContent']);
            }
        }
        return $validated;
    }

    /** @return void */
    public function runInitialCreateTables() {
        // Re-add a stripped `id` PRIMARY KEY (via ALTER) BEFORE any CREATE TABLE
        // IF NOT EXISTS runs.  Without this step, an existing-but-broken table
        // (missing the file's `id` PRIMARY KEY) would survive the IF NOT EXISTS
        // check and verifyColumns would only ALTER ADD the missing non-PK
        // columns, leaving the table without its primary key.  Lives here (not
        // just in correctIssuesBefore) so cron callers of createDatabaseTables()
        // (which don't pass the $updatingToNewVersion flag) also repair
        // stripped tables instead of propagating the broken state.
        $this->coordinator->tableRepairUpgrade()->repairStrippedViewCacheTable();

        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $ngramEpochMigrationSafe = $this->coordinator->nGramUpgrade()->ensureLastUpdatedEpochColumn($ngramTable);

        $ddlEntries = $this->discoverPermanentDDLFiles();
        if (empty($ddlEntries)) {
            // Zero DDL files discovered means no plugin table can be created
            // or repaired this run -- the plugin cannot function. Per the
            // defensive-coding standard ("Can the plugin still do its job
            // after this failure?"), this is a real error, not a warning:
            // silently doing nothing here would leave a fresh install with
            // no tables and no diagnostic trail explaining why.
            $this->logger->errorMessage(
                'discoverPermanentDDLFiles() found zero permanent CREATE-TABLE '
                . 'files (glob: includes/sql/create*Table.sql). No plugin tables '
                . 'can be created or repaired until this is resolved. Likely '
                . 'causes: a corrupted/incomplete plugin installation, the '
                . 'includes/sql/ directory missing or unreadable, or glob() '
                . 'disabled via php.ini disable_functions.'
            );
        }
        foreach ($ddlEntries as $ddlEntry) {
            if (!is_array($ddlEntry)) {
                continue;
            }
            $placeholder = isset($ddlEntry['placeholder']) && is_string($ddlEntry['placeholder'])
                ? $ddlEntry['placeholder'] : '';
            $bareTableName = isset($ddlEntry['bareTableName']) && is_string($ddlEntry['bareTableName'])
                ? $ddlEntry['bareTableName'] : '';
            $ddlContent = isset($ddlEntry['ddlContent']) && is_string($ddlEntry['ddlContent'])
                ? $ddlEntry['ddlContent'] : '';

            $query = $this->applyPluginTableCharsetCollate($ddlContent);
            $this->dbCore->queryAndGetResults($query);

            $tableName = $this->dbCore->doTableNameReplacements($placeholder);

            // Per-table post-CREATE verification: confirm the table actually
            // exists on disk. queryAndGetResults logs SQL errors generically,
            // but a silently-failing CREATE (concurrent DROP, swallowed parse
            // error, prefix drift, or insufficient privileges) is invisible
            // without an explicit existence check. Log per-table so the debug
            // log identifies which DDL didn't materialize and why downstream
            // auto-repair attempts will keep failing.
            if (!$this->verifyTableMaterialized(array('tableName' => $tableName, 'placeholder' => $placeholder))) {
                // Don't abort the loop. Other tables can still get created.
                continue;
            }

            // Targeted online-DDL column add(s) before the generic verifyColumns()
            // flow runs a bare ALTER. On large logsv2 tables (multi-GB on
            // busy sites) bare ADD COLUMN can block the table for tens of
            // seconds; the targeted helper uses ALGORITHM=INPLACE, LOCK=NONE
            // so InnoDB 5.6 or newer picks the lockless online-DDL path. If the
            // engine doesn't support it the helper falls back silently and
            // verifyColumns() picks up the column add as a safety net.
            if ($bareTableName === 'abj404_logsv2') {
                $this->coordinator->indexesUpgrade()->ensureLogsv2CanonicalUrlColumn($tableName);
            }
            // Same logic for the redirects side. canonical_url is required by
            // setupRedirect() and was added in 4.1.11; on a small fraction of
            // sites dbDelta silently fails to add it, so every captured 404
            // emits "Unknown column 'canonical_url' in 'field list'" until
            // verifyColumns eventually retries. Eagerly running the targeted
            // add closes that window.
            if ($bareTableName === 'abj404_redirects') {
                $this->coordinator->indexesUpgrade()->ensureRedirectsCanonicalUrlColumn($tableName);
                // Denorm Step 3a (i459): same eager online-DDL add for the four
                // derived columns (logshits, last_used, dest_for_view,
                // published_status) so they exist before verifyColumns() and
                // before the chunked backfill reads them. Idempotent: each
                // column is SHOW COLUMNS-guarded, so this is a no-op once added.
                $this->coordinator->indexesUpgrade()->ensureRedirectsDenormColumns($tableName);
            }
            if ($bareTableName === 'abj404_ngram_cache' && !$ngramEpochMigrationSafe) {
                continue;
            }

            $this->coordinator->schemaDiffUpgrade()->verifyColumns($tableName, $query);
        }

        // Table-specific post-creation steps.
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $this->coordinator->indexesUpgrade()->ensureLogsCompositeIndex($logsTable);
    }

    /**
     * Verify that a CREATE TABLE actually materialized the named table on disk.
     * Returns true if the table exists, false (and logs a per-table error) if not.
     *
     * Distinguishes silently-failing CREATEs from generic SQL errors so the
     * debug log identifies which specific DDL didn't materialize. Common causes:
     * concurrent DROP from a parallel cron, SQL parse error swallowed by
     * queryAndGetResults, prefix drift between request and table_prefix in
     * wp-config, or missing CREATE TABLE privileges on the DB user.
     *
     * Takes a single associative array (rather than two positional strings)
     * so the fully-qualified table name and the original placeholder --
     * both plain strings -- cannot be silently transposed at the call site.
     *
     * @param array{tableName: string, placeholder: string} $context
     *   tableName: Fully-qualified table name (with prefix).
     *   placeholder: Original placeholder (e.g. "{wp_abj404_redirects}") for diagnostic context.
     * @return bool True if table exists post-CREATE, false otherwise.
     */
    private function verifyTableMaterialized(array $context): bool {
        $tableName = isset($context['tableName']) && is_string($context['tableName']) ? $context['tableName'] : '';
        $placeholder = isset($context['placeholder']) && is_string($context['placeholder']) ? $context['placeholder'] : '';
        if ($tableName === '') {
            return false;
        }
        // Routed through queryAndGetResults() (same pattern as
        // DatabaseUpgradeCollationDrift::correctCollations()) rather than a
        // raw $wpdb->get_var() so this metadata probe carries the DAO's
        // query-timeout wrapper: a concurrent CREATE/ALTER/DROP racing this
        // freshly-run CREATE TABLE can hold a metadata lock that SHOW TABLES
        // waits on, and an unbounded wait here would block schema bootstrap
        // indefinitely instead of surfacing as a logged, recoverable error.
        // @utf8-audit: opt-out - $tableName is fully-qualified plugin table
        // name from doTableNameReplacements / $wpdb->prefix; never user input.
        $result = $this->dbCore->queryAndGetResults(
            "SHOW TABLES LIKE '" . esc_sql($tableName) . "'"
        );
        $found = null;
        if (isset($result['rows']) && is_array($result['rows']) && isset($result['rows'][0])) {
            $row = $result['rows'][0];
            $found = is_array($row) ? reset($row) : $row;
        }
        if ($found === $tableName) {
            return true;
        }
        $this->logger->errorMessage(
            "CREATE TABLE did not materialize '" . $tableName . "' "
            . "(placeholder " . $placeholder . "). "
            . "Table is still missing on disk after CREATE TABLE IF NOT EXISTS ran. "
            . "Likely causes: concurrent DROP from a parallel request, "
            . "SQL parse error suppressed by queryAndGetResults, "
            . "prefix mismatch between request and wp-config table_prefix, "
            . "or insufficient CREATE TABLE privileges on the DB user."
        );
        return false;
    }

    /**
     * @param string $createTableSql
     * @return string
     */
    public function applyPluginTableCharsetCollate($createTableSql) {
        global $wpdb;
        if (!is_string($createTableSql) || $createTableSql === '') {
            return $createTableSql;
        }

        // Always prefer utf8mb4 for plugin tables, regardless of site defaults.
        $collate = 'utf8mb4_unicode_ci';
        if (!empty($wpdb->collate) && stripos($wpdb->collate, 'utf8mb4') !== false) {
            $collate = $wpdb->collate;
        }

        $createTableSql = str_replace('{COLLATION}', $collate, $createTableSql);
        // If the statement already specifies charset/collation, don't override.
        if (preg_match('/\b(?:default\s+)?(?:character\s+set|charset|collate)\b/i', $createTableSql)) {
            return $createTableSql;
        }

        return rtrim($createTableSql) . " DEFAULT CHARACTER SET utf8mb4 COLLATE {$collate}";
    }

    /**
     * Post-creation, column-triggered one-time data backfills. Dispatches
     * on (tableName, colName) to exactly two cases:
     *  - abj404_logsv2.min_log_id: runs the seed SQL (backfillLogsMinLogId)
     *    and ensures the composite index that depends on it.
     *  - abj404_permalink_cache.url_length: truncates the permalink cache
     *    so the new column gets populated on next rebuild.
     * Any other (tableName, colName) pair is a no-op.
     *
     * Takes a single associative array (rather than two positional strings)
     * so the table name and column name -- both plain strings -- cannot be
     * silently transposed at the call site.
     *
     * @param array{tableName: string, colName: string} $context
     * @return void
     */
    public function applyColumnAddedBackfillsAndCacheInvalidation(array $context) {
        $tableName = isset($context['tableName']) && is_string($context['tableName']) ? $context['tableName'] : '';
        $colName = isset($context['colName']) && is_string($context['colName']) ? $context['colName'] : '';
        if (empty($tableName)) {
            return;
        }

        if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
            $this->backfillLogsMinLogId($tableName);
        }
        if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
            // clear the permalink cache so that the url length column will be populated.
            // this could be more efficient but I'll assume that's not necessary.
            $this->contentRepo->truncatePermalinkCacheTable();
        }
    }

    /**
     * One-time backfill for the abj404_logsv2.min_log_id column: runs the
     * seed SQL, then ensures the composite index that depends on it exists.
     * Extracted out of applyColumnAddedBackfillsAndCacheInvalidation() so that method stays a plain
     * column-name dispatcher; the data-access step (SQL file load + execute)
     * lives in its own method instead of inline in the dispatch logic.
     *
     * @param string $tableName
     * @return void
     */
    private function backfillLogsMinLogId($tableName) {
        try {
            $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../../sql/logsSetMinLogID.sql");
        } catch (Exception $e) {
            $this->logger->errorMessage(
                'Could not read logsSetMinLogID.sql backfill file for ' . $tableName
                . ': ' . $e->getMessage() . '. Skipping min_log_id backfill and composite '
                . 'index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $result = $this->dbCore->queryAndGetResults($query);
        $lastErrorValue = $result['last_error'] ?? null;
        if (!is_string($lastErrorValue)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid last_error type ('
                . gettype($lastErrorValue) . ') for ' . $tableName
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $lastError = $lastErrorValue;
        if ($lastError !== '') {
            // Don't create the composite index on the strength of a backfill
            // that didn't actually run: the index exists to make min_log_id
            // lookups fast, and building it now would just lock in whatever
            // stale/default values the column already has. Skipping is safe
            // (idempotent) -- the next upgrade check retries both steps.
            $this->logger->errorMessage(
                'min_log_id backfill query failed for ' . $tableName . ': ' . $lastError
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $this->coordinator->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
    }
}
