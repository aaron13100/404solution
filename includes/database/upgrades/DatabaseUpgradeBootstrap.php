<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table-bootstrap orchestration sub-component of DatabaseUpgradesEtc.
 *
 * Owns the CREATE TABLE / first-activation flow:
 *  - Synchronized createDatabaseTables() entry point.
 *  - reallyCreateDatabaseTables() orchestrator that walks the per-site path
 *    (single site vs network activation vs network upgrade), runs the
 *    permanent-DDL bootstrap, ensures collations / engine / indexes, and
 *    schedules the canonical_url backfill. One-time n-gram cache rebuild
 *    scheduling is delegated to
 *    ABJ_404_Solution_DatabaseUpgradeNGramCacheInitializer.
 *  - Per-DDL-file discovery (discoverPermanentDDLFiles), post-CREATE
 *    materialization verification (verifyTableMaterialized), charset/collation
 *    rewriting (applyPluginTableCharsetCollate), lowercase rename pass
 *    (renameAbj404TablesToLowerCase) and post-column-add hooks
 *    (handleSpecificCases).
 *
 * Reached through the explicit createDatabaseTables() facade method on
 * ABJ_404_Solution_DatabaseUpgradesEtc.
 */
class ABJ_404_Solution_DatabaseUpgradeBootstrap extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** Create the tables when the plugin is first activated.
     * @param bool $updatingToNewVersion
     * @return void
     */
    function createDatabaseTables($updatingToNewVersion = false, bool $force = false) {

        $synchronizedKeyFromUser = "create_db_tables";
        $uniqueID = null;

        if (!$force) {
            $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

            if ($uniqueID == '' || $uniqueID == null) {
                $this->logger->debugMessage("Avoiding multiple calls for creating database tables.");
                return;
            }
        }

        // Fixed: Use finally block to ensure lock is ALWAYS released, even on fatal errors
        try {
            $this->reallyCreateDatabaseTables($updatingToNewVersion);

        } catch (\Exception $e) {
            $this->logger->errorMessage("Error creating database tables. ", $e);
            throw $e;  // Re-throw to propagate the error
        } finally {
            // Release the lock only if one was acquired (non-forced path).
            if ($uniqueID !== null) {
                $this->syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
            }
        }
    }

    /**
     * @param bool $updatingToNewVersion
     * @return void
     */
    private function reallyCreateDatabaseTables($updatingToNewVersion = false) {
        if ($updatingToNewVersion) {
            $this->upgrades()->tableRepairUpgrade()->correctIssuesBefore();
        }

        // MULTISITE: Process current site immediately, schedule background task for remaining sites
        if ($this->upgrades()->nGramUpgrade()->isNetworkActivated() && !$updatingToNewVersion) {
            // Activation path: create tables for current site + schedule background for others.
            $currentBlogId = get_current_blog_id();
            $this->runInitialCreateTables();
            $this->upgrades()->collationDriftUpgrade()->correctCollations();
            $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
            $this->upgrades()->indexesUpgrade()->createIndexes();

            // First chunk of the canonical_url backfill runs in-band so newly
            // upgraded small sites finish in one shot. Larger sites converge
            // over subsequent daily-maintenance cron ticks (same method).
            $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();

            $this->logger->infoMessage(sprintf(
                "Network activation: Created tables for current site (ID %d). Scheduling background task for remaining sites.",
                $currentBlogId
            ));

            $this->upgrades()->multiSiteUpgrade()->scheduleBackgroundMultisiteActivation($currentBlogId);

        } else if ($this->upgrades()->nGramUpgrade()->isNetworkActivated() && $updatingToNewVersion) {
            // Upgrade path on a network install: update tables for current site + schedule
            // background upgrade for other sites (so sub-site tables are also updated).
            $currentBlogId = get_current_blog_id();
            $this->runInitialCreateTables();
            $this->upgrades()->collationDriftUpgrade()->correctCollations();
            $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
            $this->upgrades()->indexesUpgrade()->createIndexes();

            // First chunk of the canonical_url backfill runs in-band so newly
            // upgraded small sites finish in one shot. Larger sites converge
            // over subsequent daily-maintenance cron ticks (same method).
            $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();

            $this->logger->infoMessage(sprintf(
                "Network upgrade: Updated tables for current site (ID %d). Scheduling background upgrade for remaining sites.",
                $currentBlogId
            ));

            $this->upgrades()->multiSiteUpgrade()->scheduleBackgroundMultisiteUpgrade($currentBlogId);

        } else {
            // Single site (or non-network-activated): create/update tables for current site only.
            $this->runInitialCreateTables();
            $this->upgrades()->collationDriftUpgrade()->correctCollations();
            $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
            $this->upgrades()->indexesUpgrade()->createIndexes();

            // First chunk of the canonical_url backfill runs in-band so newly
            // upgraded small sites finish in one shot. Larger sites converge
            // over subsequent daily-maintenance cron ticks (same method).
            $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();
        }

        // Open the narrow-sort-key read gate immediately for installs that are
        // already fully populated (a fresh activation has no legacy rows; an
        // upgrade from a build that already carried the column has its keys set).
        // Activation-safe: this only flips the latch when no NULL key remains, it
        // never runs the time-budgeted drain (that stays on the daily cron), so a
        // large fresh-upgrade table never blocks activation. Until the cron drain
        // converges on such a table the admin read falls back to the wide source
        // column (correct order, filesort bounded to the Page Redirects minority).
        $this->upgrades()->redirectsSortKeyBackfillUpgrade()->refreshSortKeyBackfillLatches();

        // Adopt orphaned tables AFTER target tables exist (rename handles prefix mismatches).
        $this->renameAbj404TablesToLowerCase();

        // we could do this only when a table is created or when the "meta" column is created
        // but it doesn't take long anyway so we do it every night.
        $this->permalinkCache->updatePermalinkCache(1);

        // One-time N-gram cache initialization (async via WP-Cron to prevent
        // blocking). Owned by the dedicated initializer collaborator.
        (new ABJ_404_Solution_DatabaseUpgradeNGramCacheInitializer($this->upgrades(), $this->logger))
            ->scheduleRebuildIfUninitialized($updatingToNewVersion);

        // Run one-time migration to relative paths (Issue #24)
        if (get_option('abj404_migrated_to_relative_paths') !== '1') {
            $migrationResults = $this->upgrades()->pluginUpdateUpgrade()->migrateURLsToRelativePaths();

            // Show admin notice if migration occurred
            if ($updatingToNewVersion && is_array($migrationResults) && !empty($migrationResults['redirects_updated'])) {
                $rawRedirectsUpdated = $migrationResults['redirects_updated'];
                $redirectsUpdated = is_scalar($rawRedirectsUpdated) ? (int)$rawRedirectsUpdated : 0;
                $message = sprintf(
                    _n(
                        '404 Solution: Migrated %d redirect to subdirectory-independent format.',
                        '404 Solution: Migrated %d redirects to subdirectory-independent format.',
                        $redirectsUpdated,
                        '404-solution'
                    ),
                    $redirectsUpdated
                );
                if (function_exists('add_settings_error')) {
                    add_settings_error('abj404_settings', 'migration_success', $message, 'updated');
                }
            }
        }

        if ($updatingToNewVersion) {
            $this->upgrades()->tableRepairUpgrade()->correctIssuesAfter();
        }
    }

    /**
     * Makes all plugin table names lowercase, in case someone thought it was funny to use
     * the lower_case_table_names=0 setting. Also detects and adopts orphaned plugin tables
     * under old prefixes (from site migrations or the rename bug in v2.35.16 through v3.x).
     * @return void
     */
    function renameAbj404TablesToLowerCase() {
        global $wpdb;

        // On case-insensitive MySQL (lower_case_table_names >= 1), table names
        // are already treated as lowercase internally. Renaming is pointless and
        // can cause issues on some hosting setups.
        // DAO-bypass-approved: Schema-bootstrap inside renameAbj404TablesToLowerCase(). Runs before plugin DAO is fully wired during DB upgrades.
        $lctnResult = $wpdb->get_row("SHOW VARIABLES LIKE 'lower_case_table_names'", ARRAY_A);
        if (is_array($lctnResult)) {
            $lctnValue = null;
            foreach ($lctnResult as $key => $value) {
                if (strtolower((string)$key) === 'value') {
                    $lctnValue = $value;
                    break;
                }
            }
            if (is_scalar($lctnValue) && (int)$lctnValue >= 1) {
                // MySQL already handles table names case-insensitively.
                // Still run adoption check in case of prefix mismatch.
                $this->upgrades()->orphanAdoptionUpgrade()->adoptOrphanedTables();
                return;
            }
        }

        // Fetch all tables containing "abj404", case-insensitive
        $dbNameRaw = $wpdb->dbname ?? '';
        if ($dbNameRaw === '') {
            $this->logger->warn("Could not determine database name for lowercase rename.");
            return;
        }
        $dbNameEscaped = esc_sql($dbNameRaw);
        $dbName = is_array($dbNameEscaped) ? '' : $dbNameEscaped;
        $query = "SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = '{$dbName}'
            AND LOWER(table_name) LIKE '%abj404%'";
        $results = $this->dbCore->queryAndGetResults($query);

        if (!is_array($results['rows'])) {
            $this->logger->warn("Could not query information_schema tables for lowercase rename.");
            return;
        }

        foreach ($results['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Case-insensitive key lookup: MySQL drivers return information_schema
            // column names in varying cases (table_name, TABLE_NAME, Table_Name).
            $tableName = null;
            foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'table_name' && is_scalar($value)) {
                        $tableName = (string)$value;
                    break;
                }
            }

            if (!empty($tableName)) {
                $lowercaseName = strtolower($tableName);

                // Check if the table name is already lowercase, skip if it is
                if ($tableName !== $lowercaseName) {
                    // Rename the table to lowercase
                    $renameQuery = "RENAME TABLE `{$tableName}` TO `{$lowercaseName}`";
                    $this->dbCore->queryAndGetResults($renameQuery,
                        ['ignore_errors' => ["already exists"]]);
                    $this->logger->infoMessage("Renamed table {$tableName} to {$lowercaseName}\n");
                }
            } else {
                $this->logger->warn("I didn't find a table name in the results of this row: " .
                    print_r($row, true));
            }
        }

        // After renaming, check for orphaned tables under old prefixes.
        $this->upgrades()->orphanAdoptionUpgrade()->adoptOrphanedTables();
    }

    /** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
     * @return void
     */
    function handleSpecificCases($tableName, $colName) {
        if (empty($tableName) || !is_string($tableName)) {
            return;
        }

        if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
            global $wpdb;
            $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../../sql/logsSetMinLogID.sql");
            $this->dbCore->queryAndGetResults($query);
            // Ensure composite index exists after backfilling min_log_id.
            $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
        }
        if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
            // clear the permalink cache so that the url length column will be populated.
            // this could be more efficient but I'll assume that's not necessary.
            $this->contentRepo->truncatePermalinkCacheTable();
        }
    }

    /**
     * Discover all permanent (non-Temp) DDL files and extract table metadata.
     *
     * @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}>
     */
    function discoverPermanentDDLFiles(): array {
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
                continue;
            }
            if (!preg_match('/\{(wp_(abj404_\w+))\}/', $ddlContent, $m)) {
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
                    && is_string($entry['placeholder']) && is_string($entry['bareTableName'])
                    && is_string($entry['ddlContent'])) {
                $validated[] = array('placeholder' => $entry['placeholder'],
                    'bareTableName' => $entry['bareTableName'], 'ddlContent' => $entry['ddlContent']);
            }
        }
        return $validated;
    }

    /** @return void */
    function runInitialCreateTables() {
        // Re-add a stripped `id` PRIMARY KEY (via ALTER) BEFORE any CREATE TABLE
        // IF NOT EXISTS runs.  Without this step, an existing-but-broken table
        // (missing the file's `id` PRIMARY KEY) would survive the IF NOT EXISTS
        // check and verifyColumns would only ALTER ADD the missing non-PK
        // columns, leaving the table without its primary key.  Lives here (not
        // just in correctIssuesBefore) so cron callers of createDatabaseTables()
        // (which don't pass the $updatingToNewVersion flag) also repair
        // stripped tables instead of propagating the broken state.
        $this->upgrades()->tableRepairUpgrade()->repairStrippedViewCacheTable();

        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $ngramEpochMigrationSafe = $this->upgrades()->nGramUpgrade()->ensureLastUpdatedEpochColumn($ngramTable);

        $ddlEntries = $this->discoverPermanentDDLFiles();
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
            if (!$this->verifyTableMaterialized($tableName, $placeholder)) {
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
                $this->upgrades()->indexesUpgrade()->ensureLogsv2CanonicalUrlColumn($tableName);
            }
            // Same logic for the redirects side. canonical_url is required by
            // setupRedirect() and was added in 4.1.11; on a small fraction of
            // sites dbDelta silently fails to add it, so every captured 404
            // emits "Unknown column 'canonical_url' in 'field list'" until
            // verifyColumns eventually retries. Eagerly running the targeted
            // add closes that window.
            if ($bareTableName === 'abj404_redirects') {
                $this->upgrades()->indexesUpgrade()->ensureRedirectsCanonicalUrlColumn($tableName);
                // Denorm Step 3a (i459): same eager online-DDL add for the four
                // derived columns (logshits, last_used, dest_for_view,
                // published_status) so they exist before verifyColumns() and
                // before the chunked backfill reads them. Idempotent: each
                // column is SHOW COLUMNS-guarded, so this is a no-op once added.
                $this->upgrades()->indexesUpgrade()->ensureRedirectsDenormColumns($tableName);
            }
            if ($bareTableName === 'abj404_ngram_cache' && !$ngramEpochMigrationSafe) {
                continue;
            }

            $this->upgrades()->schemaDiffUpgrade()->verifyColumns($tableName, $query);
        }

        // Table-specific post-creation steps.
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($logsTable);
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
     * @param string $tableName  Fully-qualified table name (with prefix).
     * @param string $placeholder Original placeholder (e.g. "{wp_abj404_redirects}") for diagnostic context.
     * @return bool True if table exists post-CREATE, false otherwise.
     */
    private function verifyTableMaterialized(string $tableName, string $placeholder): bool {
        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }
        // @utf8-audit: opt-out - $tableName is fully-qualified plugin table
        // name from doTableNameReplacements / $wpdb->prefix; never user input.
        // DAO-bypass-approved: Schema-bootstrap inside verifyTableMaterialized(). Verifies CREATE TABLE actually materialized; DAO timeout wrapper is irrelevant for DDL existence probe.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
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
    function applyPluginTableCharsetCollate($createTableSql) {
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
}
