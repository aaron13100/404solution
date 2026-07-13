<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-site self-heal prologue: verify required plugin tables exist on every heavy
 * boot path, and either trigger full CREATE TABLE recovery or run the lightweight
 * drift-correction sweep when everything is present.
 *
 * The single named token every "heavy boot" entry point routes through before
 * doing useful work. Pattern 1 in docs/PROACTIVE_BUG_DISCOVERY.md: bugs recurred
 * because each recovery primitive (repairStrippedViewCacheTable,
 * updateTableEngineToInnoDB, createIndexes, correctCollations, adoptOrphanedTables)
 * was reachable from one boot path only. Centralising the fan-out here makes the
 * reachability invariant testable (SelfHealingPrologueReachabilityTest) instead of
 * relying on a developer to remember to wire every primitive into every new entry
 * point.
 *
 * Reached by Loader heavy boot, multisite activation/upgrade batches, the daily
 * cron via {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance}, and direct
 * coordinator calls.
 */
class ABJ_404_Solution_DatabaseUpgradeSelfHeal extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @return void */
    public function runDailyInsuranceCheck() {
        // Always verify current site only
        // Per-site cron execution ensures network coverage without O(N²) duplication
        $this->verifyAndRepairCurrentSite();
    }

    /**
     * Self-healing boot prologue. The single named token every "heavy boot"
     * entry point routes through before doing useful work.
     *
     * Documented order, delegated to verifyAndRepairCurrentSite() (the
     * existing per-site insurance check):
     *
     *   1. Discover required tables from create*Table.sql files (same source
     *      of truth as runInitialCreateTables()).
     *   2. If ANY table is missing, call createDatabaseTables(false), which
     *      funnels through reallyCreateDatabaseTables() into runInitialCreateTables(),
     *      reaching:
     *        a. repairStrippedViewCacheTable() (3.3.3 column-drop recovery),
     *        b. verifyTableMaterialized() (d9024114 per-table CREATE verify),
     *        c. verifyColumns() (schema-drift tolerance),
     *      then updateTableEngineToInnoDB(), correctCollations(), createIndexes(),
     *      and renameAbj404TablesToLowerCase() into adoptOrphanedTables().
     *   3. If all tables exist, run the drift-correction sweep:
     *        a. correctCollations() (utf8mb4 drift),
     *        b. createIndexes() (lost-index recovery after hosting migrations),
     *        c. updateTableEngineToInnoDB() (MyISAM reversion recovery).
     *   4. adoptOrphanedTables() covers prefix migrations even when tables
     *      under the current prefix exist.
     *
     * Idempotent: safe to call multiple times in the same request. The
     * SHOW TABLES check makes the tables-exist branch cheap (~1ms per table).
     *
     * Light-path entry points (frontend 404 dispatch, admin AJAX, REST,
     * WP-CLI commands, on-demand caches like permalink-cache rebuild) opt
     * out via the SelfHealingPrologueReachabilityTest allowlist because
     * (a) the prologue runs nightly via the daily cron tick so drift is
     * caught within 24h, and (b) running it on every request would be a
     * perf regression and risks cron-lock contention under high traffic.
     * Those paths rely on
     * `queryAndGetResults::attemptMissingTableRepairAndRetry()` for
     * per-query recovery instead.
     *
     * @return void
     */
    public function runSelfHealPrologue() {
        $this->verifyAndRepairCurrentSite();
    }

    /**
     * Verify and repair tables for the current site only.
     *
     * Derives the list of required tables dynamically from create*Table.sql files
     * (same source of truth as runInitialCreateTables()), so new tables are
     * automatically included without any code changes here.
     *
     * If ANY table is missing, triggers full table creation/repair.
     *
     * @return void
     */
    public function verifyAndRepairCurrentSite() {
        global $wpdb;

        // Derive required tables from SQL DDL files -- same source of truth as runInitialCreateTables().
        $requiredTables = [];
        foreach ($this->upgrades()->bootstrapUpgrade()->discoverPermanentDDLFiles() as $ddlEntry) {
            $requiredTables[] = $ddlEntry['bareTableName'];
        }

        $missingTables = [];
        $normalizedPrefix = $this->dbCore->tableNameResolver()->getLowercasePrefix();

        // Check each required table
        foreach ($requiredTables as $tableName) {
            $fullTableName = $this->dbCore->tableNameResolver()->getPrefixedTableName($tableName);
            // DAO-bypass-approved: Schema-bootstrap inside repairMissingTables() -- runs before CREATE TABLE; routing through DAO would trigger the same missing-table auto-repair we are about to invoke ourselves (recursion)
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$fullTableName}'");

            if (!$tableExists) {
                $missingTables[] = $tableName;
            }
        }

        // If any tables are missing, run repair
        if (!empty($missingTables)) {
            $this->logger->infoMessage(sprintf(
                "Site %d (prefix: %s, normalized: %s) is missing %d table(s): %s. Running repair...",
                get_current_blog_id(),
                $wpdb->prefix,
                $normalizedPrefix,
                count($missingTables),
                implode(', ', $missingTables)
            ));

            // Repair: call the same idempotent routine activation uses
            // This is safe because createDatabaseTables() is idempotent
            $this->upgrades()->bootstrapUpgrade()->createDatabaseTables(false);  // false = not updating to new version

            $this->logger->infoMessage("Table repair complete for site " . get_current_blog_id());
        } else {
            // Tables exist - insurance: verify/correct collations, ensure indexes exist,
            // and enforce InnoDB engine. This catches collation drift (including column-level
            // drift), missed index additions, and MyISAM reversions from hosting migrations
            // or table restores -- without waiting for the next plugin upgrade.
            $this->upgrades()->collationDriftUpgrade()->correctCollations();
            $this->upgrades()->indexesUpgrade()->createIndexes();
            $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
        }

        // Check for orphaned tables under a stale/changed prefix and adopt their data.
        // This catches hosting migrations or wp-config prefix changes that leave plugin
        // tables under the old prefix. The method is idempotent -- no-op when nothing to adopt.
        // (On the missing-tables path above, createDatabaseTables() already triggers adoption
        // via renameAbj404TablesToLowerCase(), but running it again is harmless and covers
        // edge cases where tables exist under the current prefix but orphans remain.)
        $this->upgrades()->orphanAdoptionUpgrade()->adoptOrphanedTables();
    }
}
