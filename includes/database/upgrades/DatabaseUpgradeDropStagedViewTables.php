<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by DropStagedViewTablesUpgradeTest

/**
 * Denorm Step 3e-D (the one-way door): physically drop the transient staged
 * view-build tables (view_build, view_done, view_deleteme) on upgrade.
 *
 * Background. Through 4.2.x the admin redirect list was served from a staged
 * shared-table pipeline: stageCreateBuildTable() materialized
 * {prefix}abj404_view_build, the build walked it through stages S1-S10
 * (including the idx_pub_* per-sort composite indexes added by the old
 * 10_index_sort.sql), stageRenameSwap() renamed it to view_done, and
 * view_deleteme was the ephemeral previous-generation served table dropped
 * right after each swap. The denorm chain (Steps 3a-3e-C) replaced that whole
 * subsystem with four derived columns persisted on abj404_redirects
 * (logshits, last_used, dest_for_view, published_status) that the live read
 * resolves directly. After 3e-A/B/C no production code reads, writes, or
 * creates any of the three transient tables: they are pure residue on sites
 * that upgraded from a staged-build version.
 *
 * This component removes that residue. The idx_pub_* / idx_status_disabled
 * composite indexes lived ONLY on view_build (never on the permanent
 * abj404_redirects table), so dropping the tables drops those dead indexes
 * with them. The live read against abj404_redirects is a single-table
 * filesort over the narrow PK row set (status IN (1,2,6) is multi-value, so
 * the plan is never index-ordered anyway), so nothing on the read path
 * depends on the dropped indexes.
 *
 * Safety / defensive philosophy:
 *   - Idempotent: DROP TABLE IF EXISTS, so a fresh install (tables never
 *     existed) and a re-upgrade (already dropped) are both clean no-ops.
 *   - Cron-guarded: refuses to run from a cron tick (operator-driven upgrade
 *     path only), so the destructive statement can never fire from daily
 *     maintenance. This is the structural backstop that
 *     CronReachableDestructiveSqlLintTest (Rule G) proves.
 *   - Degrades gracefully on a write-blocked DB (read-only replica / disk
 *     full): skip with a debug breadcrumb, never error, never email. The
 *     residue is harmless and the next healthy upgrade tick cleans it up.
 *   - Only touches the three transient staged tables. It never references a
 *     permanent plugin table, so a future maintenance edit cannot widen its
 *     blast radius onto user data.
 */
class ABJ_404_Solution_DatabaseUpgradeDropStagedViewTables extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Bare suffixes of the transient staged-build tables to drop. Resolved to
     * the site's lowercase-prefixed names at drop time. Kept as a single
     * source of truth so the drop list and any future probe agree.
     *
     * @var array<int, string>
     */
    private const STAGED_VIEW_TABLE_SUFFIXES = array(
        'abj404_view_build',
        'abj404_view_done',
        'abj404_view_deleteme',
    );

    /**
     * Drop the transient staged view-build tables (view_build, view_done,
     * view_deleteme) if present. Runs from the upgrade-only post-create hook
     * (DatabaseUpgradeTableRepair::correctIssuesAfter); never from cron.
     *
     * Returns the count of tables actually dropped (0 on a fresh install, a
     * re-upgrade, a missing $wpdb, or a write-blocked DB) so callers/tests can
     * assert idempotency: the first post-3e-D upgrade returns >=1 when residue
     * exists, every subsequent run returns 0.
     *
     * @return int Number of transient tables that existed and were dropped.
     */
    public function dropStagedViewTables(): int {
        // CRON GUARD (Rule G): operator-driven upgrade path only. A daily cron
        // tick must never reach a DROP TABLE on these (or any) tables. Must be
        // the first executable statement so the lint can see it precedes the
        // destructive SQL below.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 0;
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        // Read-only replica / disk full: leave the residue in place (it is
        // inert) and let the next healthy upgrade tick clean it up. Logging a
        // failed DROP as an error here would email the admin about a hosting
        // condition the plugin degrades past, so skip quietly instead.
        if ($this->dbCore->noticeState()->isWriteBlockActive()) {
            $this->logger->debugMessage(
                'dropStagedViewTables skipped: DB write block active (read-only / disk full). '
                . 'Transient staged-view residue is inert; the next healthy upgrade tick will drop it.'
            );
            return 0;
        }

        $dropped = 0;
        foreach (self::STAGED_VIEW_TABLE_SUFFIXES as $suffix) {
            $tableName = $this->dbCore->doTableNameReplacements('{wp_' . $suffix . '}');
            if (!$this->stagedTableExists($tableName)) {
                continue;
            }
            // @utf8-audit: opt-out - $tableName is doTableNameReplacements() of a fixed internal placeholder (lowercase prefix + literal suffix); system-controlled, cannot contain invalid UTF-8.
            // DAO-bypass-approved: idempotent DROP TABLE IF EXISTS on a deprecated transient table guarded by a SHOW TABLES existence probe; routing through queryAndGetResults would surface a benign infrastructure warning on a write-blocked host even though the table is inert residue.
            $result = $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($tableName) . '`');
            if ($result === false) {
                // A write failed despite the write-block probe passing (e.g. a
                // lock timeout). Inert residue, hosting-side cause: warn (not
                // error, no email) and move on; the next upgrade retries.
                $this->logger->warn(
                    'dropStagedViewTables: DROP of transient table ' . $tableName
                    . ' failed (' . (is_string($wpdb->last_error) ? $wpdb->last_error : 'unknown') . '). '
                    . 'Residue is inert; the next upgrade tick will retry.'
                );
                continue;
            }
            $dropped++;
            $this->logger->infoMessage(
                'dropStagedViewTables: dropped vestigial staged view-build table ' . $tableName
                . ' (denorm Step 3e-D). The live read now serves off the abj404_redirects denorm columns.'
            );
        }

        return $dropped;
    }

    /**
     * Case-sensitive SHOW TABLES existence probe for a transient staged table.
     *
     * Bypasses the DAO on purpose: routing a "does this table exist" probe
     * through queryAndGetResults would log a benign "table doesn't exist" line
     * on every fresh-install upgrade (the common case, where the residue was
     * never present). Same probe shape the denorm backfill/reconcile use.
     *
     * @param string $tableName Fully-qualified, lowercase-prefixed table name.
     * @return bool
     */
    private function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }
        // @utf8-audit: opt-out - $tableName is a fully-qualified, lowercase-prefixed plugin table name from doTableNameReplacements(); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: schema existence probe (SHOW TABLES); see method docblock.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
        return $found === $tableName;
    }
}
