<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table-bootstrap orchestration entry point sub-component of DatabaseUpgradesEtc.
 *
 * Owns the CREATE TABLE / first-activation flow:
 *  - Synchronized createDatabaseTables() entry point.
 *  - reallyCreateDatabaseTables() orchestrator that walks the per-site path
 *    (single site vs network activation vs network upgrade), runs the
 *    permanent-DDL bootstrap, ensures collations / engine / indexes, and
 *    schedules the canonical_url backfill. One-time n-gram cache rebuild
 *    scheduling is delegated to
 *    ABJ_404_Solution_DatabaseUpgradeNGramCacheInitializer.
 *
 * Permanent-DDL file discovery/execution/verification and charset/collation
 * rewriting are delegated to ABJ_404_Solution_DatabaseTableDdlExecutor
 * (discoverPermanentDDLFiles, runInitialCreateTables,
 * applyPluginTableCharsetCollate, applyColumnAddedBackfillsAndCacheInvalidation remain here only as
 * thin delegating facades so the ~50+ existing call sites keep working).
 * The lowercase-table-rename / orphan-adoption-trigger pass is delegated to
 * ABJ_404_Solution_DatabaseTableLowercaseRenamer (renameAbj404TablesToLowerCase
 * is likewise kept here as a delegating facade). Both collaborators are
 * constructed fresh on every call, never cached, so they always observe
 * whichever dbCore/contentRepo/logger are current (these can be swapped at
 * runtime via replaceDatabaseUpgradeDependencies()).
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
     *
     * Delegates to a freshly-constructed ABJ_404_Solution_DatabaseTableLowercaseRenamer
     * (never cached: dbCore/logger can be swapped at runtime via
     * replaceDatabaseUpgradeDependencies(), so a cached collaborator could go stale).
     * @return void
     */
    function renameAbj404TablesToLowerCase() {
        $this->lowercaseRenamer()->rename();
    }

    private function lowercaseRenamer(): ABJ_404_Solution_DatabaseTableLowercaseRenamer {
        return new ABJ_404_Solution_DatabaseTableLowercaseRenamer(
            $this->upgrades(), $this->dbCore, $this->logger, $this->getActiveBlogPrefixesLowercase()
        );
    }

    /** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
     * @return void
     */
    function applyColumnAddedBackfillsAndCacheInvalidation($tableName, $colName) {
        $this->ddlExecutor()->applyColumnAddedBackfillsAndCacheInvalidation(array('tableName' => $tableName, 'colName' => $colName));
    }

    /**
     * Discover all permanent (non-Temp) DDL files and extract table metadata.
     *
     * @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}>
     */
    function discoverPermanentDDLFiles(): array {
        return $this->ddlExecutor()->discoverPermanentDDLFiles();
    }

    /** @return void */
    function runInitialCreateTables() {
        $this->ddlExecutor()->runInitialCreateTables();
    }

    /**
     * @param string $createTableSql
     * @return string
     */
    function applyPluginTableCharsetCollate($createTableSql) {
        return $this->ddlExecutor()->applyPluginTableCharsetCollate($createTableSql);
    }

    /**
     * Delegates permanent-DDL discovery/execution/verification and
     * charset/collation rewriting to a freshly-constructed
     * ABJ_404_Solution_DatabaseTableDdlExecutor (never cached: dbCore/contentRepo/logger
     * can be swapped at runtime via replaceDatabaseUpgradeDependencies(), so a cached
     * collaborator could go stale).
     */
    private function ddlExecutor(): ABJ_404_Solution_DatabaseTableDdlExecutor {
        return new ABJ_404_Solution_DatabaseTableDdlExecutor(
            $this->upgrades(), $this->dbCore, $this->contentRepo, $this->logger
        );
    }
}
