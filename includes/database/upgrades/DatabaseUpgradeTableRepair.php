<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pre/post-upgrade table-correctness work for ABJ_404_Solution_DatabaseUpgradesEtc.
 *
 * Originally inlined in DatabaseUpgradesEtc.php; extracted in 4.1.8 alongside
 * the new repairStrippedViewCacheTable() hardening and the _logs_hits recovery
 * path so the host class stays under its line budget (FileSizeLimitsTest).
 *
 * The methods are scoped to "make the schema match the file's intent": detect
 * tables that were corrupted by past DDL parsing bugs (3.3.3, 4.1.7) and either
 * drop them for clean recreation, or recreate them empty when the cache-style
 * table can be rebuilt by a later cron tick.
 */
class ABJ_404_Solution_DatabaseUpgradeTableRepair extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Run before runInitialCreateTables() during an upgrade.  Cleans up data
     * issues that would block the create/verify pass, then drops any table
     * whose live schema is positively known to have been stripped.
     *
     * @return void
     */
    function correctIssuesBefore() {
	$this->logsRepo->correctDuplicateLookupValues();

	// 3.3.4+: Repair any plugin table that was stripped of all columns by a
	// DDL parsing bug.  The 3.3.3 bug only affected view_cache, but any future
	// DDL file shipped without parseable column syntax could wipe any table.
	// Dropped tables are pure caches or safely recreatable; runInitialCreateTables()
	// will recreate them immediately after.
	$this->repairStrippedViewCacheTable();

	$this->correctMatchData();
    }

    /**
     * Run after runInitialCreateTables() during an upgrade.  Cleans up data
     * issues that depend on the new schema, then recreates any cache table
     * that prior bugs may have dropped without recreating.
     *
     * @return void
     */
	function correctIssuesAfter() {
	$this->correctMatchData();
	$this->recoverMissingLogsHitsTable();

	// Denorm Step 3e-D (i467): drop the transient staged view-build tables
	// (view_build, view_done, view_deleteme) wholesale. The denorm chain
	// moved the admin redirect-list read onto derived columns on
	// abj404_redirects, so these tables (and the idx_pub_* per-sort indexes
	// that only ever lived on view_build) are pure residue. Dropping the
	// tables supersedes the old per-column cleanup that dropped the
	// translated *_for_view label columns: there is no point altering
	// columns on a table we drop in the same pass. Idempotent + cron-guarded
	// inside the component; CronReachableDestructiveSqlLintTest Rule G proves
	// the DROP is unreachable from cron.
	$this->upgrades()->dropStagedViewTablesUpgrade()->dropStagedViewTables();

	// t_260523_224315_207: drop the deprecated mutation watermark side
	// table. Redirect changes now use direct rebuild invalidation instead
	// of a separate mutation-signal subsystem. Idempotent DROP IF EXISTS so
	// a fresh install (no legacy table) and a re-upgrade (already dropped)
	// are both no-ops. See docs/design-lesson-watermark-overengineering.md.
	$this->dropDeprecatedMutationWatermarkTable();
    }

    /**
     * Drop the deprecated `wp_abj404_mutation_watermark` table. The table
     * held a single-row counter that the pre-removal watermark primitive
     * incremented on every mutation. Safe to call on every upgrade -- the
     * statement is idempotent and the table cannot reappear because no
     * production code creates it any more.
     *
     * @return void
     */
    function dropDeprecatedMutationWatermarkTable() {
	if (function_exists('wp_doing_cron') && wp_doing_cron()) { return; }
	global $wpdb;
	if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
		return;
	}
	$prefix = isset($wpdb->prefix) ? strtolower((string)$wpdb->prefix) : 'wp_';
	$deprecatedWatermarkTableName = $prefix . 'abj404_mutation_watermark';
	// @utf8-audit: opt-out - system-controlled table name composed from $wpdb->prefix plus the fixed-literal "abj404_mutation_watermark", cannot contain invalid UTF-8 bytes.
	// DAO-bypass-approved: idempotent DROP TABLE IF EXISTS on a deprecated table; DAO error logging would surface a benign "table did not exist" line on every upgrade.
	$wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($deprecatedWatermarkTableName) . "`");

	// Also drop the orphaned wp_options keys from the removed watermark /
	// admin-mutation gate system. These options were used by the staged-build
	// at-stage abort gate and the admin-mutation visibility gate, both of
	// which were removed in favor of the 120s cache TTL + explicit
	// invalidation on admin mutation.
	if (function_exists('delete_option')) {
		$orphanedOptions = array(
			$prefix . 'abj404_view_done_mutation_invalidated_at',
			$prefix . 'abj404_view_build_started_watermark',
			$prefix . 'abj404_view_build_active_started_watermark',
			$prefix . 'abj404_view_build_last_started_watermark',
			$prefix . 'abj404_view_build_built_watermark',
		);
		foreach ($orphanedOptions as $optionName) {
			delete_option($optionName);
		}
	}
    }

    /**
     * For every permanent plugin table, check whether the table exists but is
     * missing its primary `id` column — the signature of the 3.3.3 column-drop
     * bug.  When a stripped table is detected, ALTER it to add back the `id`
     * AUTO_INCREMENT PRIMARY KEY in place; runInitialCreateTables() then runs
     * verifyColumns to fill in any other missing columns.
     *
     * Why ALTER, not DROP: dropping the table during a daily cron path was the
     * direct cause of the 4.1.6 → 4.1.7 incident, where ~93% of upgraded sites
     * lost their `_logs_hits` table because a mis-named DDL file caused this
     * method to mis-classify a runtime-rebuilt table as "stripped" and drop
     * it. Even with the 4.1.8 positive-evidence guard, dropping during cron
     * remains the wrong primitive: a future detection regression would again
     * wipe live data. ALTER preserves whatever rows the table already holds,
     * so the worst case of a mis-detection is a no-op rebuild of an index
     * column the table already has — a recoverable warning, not data loss.
     *
     * Generalised in 3.3.5 from a view_cache-only fix to cover all plugin tables:
     * the 3.3.3 bug only affected view_cache.sql, but any future DDL file shipped
     * without parseable backtick column syntax would trigger the same data wipe on
     * that table with no repair path.
     *
     * 4.1.8: Hardened to require POSITIVE evidence before repairing a table. The
     * 4.1.7 release shipped a DDL file whose placeholder mis-classified
     * `_logs_hits` (a runtime-rebuilt table with no `id` column) as permanent.
     * The previous "drop if no `id` in live DDL" check then wiped the table on
     * upgrade. The current check only fires when the *file's* DDL declares an
     * `id` column AND the live table is missing it — absence of `id` in a file
     * that never declared one is not evidence of stripping.
     *
     * 4.1.8: Also called from runInitialCreateTables() so that any caller of
     * createDatabaseTables() — including non-upgrade callers like the daily
     * insurance check — repairs stripped tables before CREATE TABLE IF NOT
     * EXISTS turns the broken state into a permanent table that verifyColumns
     * cannot fully repair.  Idempotent: when the live DDL already declares
     * `id`, every iteration short-circuits.
     *
     * @return void
     */
    function repairStrippedViewCacheTable() {
	foreach ($this->upgrades()->bootstrapUpgrade()->discoverPermanentDDLFiles() as $ddlEntry) {
		$tableName = $this->dbCore->doTableNameReplacements($ddlEntry['placeholder']);

		// Positive evidence required: the file's intended DDL must declare `id`.
		// If the file never had an `id` column, absence in the live table is
		// not "stripping" — it's the table's normal shape.
		$intendedDdl = $ddlEntry['ddlContent'];
		if (!$this->ddlDeclaresIdColumn($intendedDdl)) {
			continue;
		}

		$liveDdl = $this->dbCore->tableNameResolver()->getCreateTableDDL($tableName);

		// Table doesn't exist at all — nothing to repair (recovery handled elsewhere).
		if (empty($liveDdl)) {
			continue;
		}

		// Live table has the column the file declares — table is intact.
		if ($this->ddlDeclaresIdColumn($liveDdl)) {
			continue;
		}

		// File declares `id`, live table is missing it — stripped.
		// ALTER (not DROP): preserve whatever rows the table holds so a
		// false-positive detection cannot lose user data.  Both MySQL 5.7+
		// and MariaDB 10.x accept retro-adding an AUTO_INCREMENT PRIMARY
		// KEY in this single-statement form; the prior comment claiming
		// otherwise (rationale for the original DROP) was incorrect.
		$this->logger->infoMessage("Repairing stripped plugin table " . $tableName .
			" (missing id column — caused by DDL parsing bug). Adding id column via ALTER.");
		$this->dbCore->queryAndGetResults(
			"ALTER TABLE `" . $tableName . "` " .
			"ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST"
		);
	}
    }

    /**
     * Returns true if the DDL declares a column literally named `id` (in
     * backticks, the only style permitted in plugin DDL files since 3.3.5 —
     * see DDLColumnParsingRobustnessTest::testEveryDdlFileUsesBacktickColumnStyle).
     *
     * Matching `\bid\b` against raw DDL is unsafe — it hits the `id` substring
     * in `auto_increment`, `void`, and any column name containing those letters.
     *
     * @param string $ddl
     * @return bool
     */
    private function ddlDeclaresIdColumn(string $ddl): bool {
	return stripos($ddl, '`id`') !== false;
    }

    /**
     * Recover the {prefix}_abj404_logs_hits table if it is missing.
     *
     * The 4.1.6 → 4.1.7 upgrade dropped this table on ~93% of sites because a
     * mis-named DDL file caused repairStrippedViewCacheTable() to treat it as
     * a permanent table that had been "stripped" (see git log for 731fec2e and
     * the 4.1.7 → 4.1.8 changelog). This method creates the table empty so
     * that the scheduled rebuild (createRedirectsForViewHitsTable) can
     * re-populate it. It is safe to run on any site — getCreateTableDDL()
     * detects an existing table and we skip the create.
     *
     * Idempotent. Cheap. Safe to call on every upgrade.
     *
     * @return void
     */
    private function recoverMissingLogsHitsTable(): void {
	$tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
	if ($this->dbCore->tableNameResolver()->getCreateTableDDL($tableName) !== '') {
		return;
	}

	$tempDdl = ABJ_404_Solution_FileSystemService::readFileContents(
		__DIR__ . '/../../sql/createLogsHitsTempTable.sql');
	if (!is_string($tempDdl) || trim($tempDdl) === '') {
		return;
	}

	// The temp DDL targets `{wp_abj404_logs_hits}_temp`. Strip the `_temp`
	// suffix to recreate the final table at its real name.
	$finalDdl = str_replace(
		'{wp_abj404_logs_hits}_temp',
		'{wp_abj404_logs_hits}',
		$tempDdl);
	$finalDdl = $this->upgrades()->bootstrapUpgrade()->applyPluginTableCharsetCollate($finalDdl);
	$finalDdl = $this->dbCore->doTableNameReplacements($finalDdl);

	$this->logger->infoMessage("Recreating missing " . $tableName .
		" (lost during the 4.1.6→4.1.7 upgrade). The scheduled rebuild will repopulate it.");
	$this->dbCore->queryAndGetResults($finalDdl);

	// The missing-table notice (set when ALTER TABLE failed during the 4.1.7
	// activation) is now stale — the table has been recovered.  Clear it so
	// the admin does not see an error notice on the next page load.
	if (function_exists('delete_transient')) {
		delete_transient('abj404_plugin_db_notice');
	}
    }

    /**
     * Drop spelling-cache rows whose match data was never populated.  These
     * are remnants from interrupted background workers; the cache fills in
     * organically on the next 404, so it's safe to delete the empty rows.
     *
     * Called from correctIssuesBefore() *and* correctIssuesAfter() during
     * the upgrade flow.  The "before" call may run when the spelling_cache
     * table doesn't exist (fresh install, or after stripped-table drop), so
     * suppress errors and skip the table-repair retry: there's nothing to
     * delete if the table doesn't exist, and we don't want this maintenance
     * call to set the missing_table admin notice transient.
     *
     * @return void
     */
    function correctMatchData() {
	$this->dbCore->queryAndGetResults(
		"delete from {wp_abj404_spelling_cache} where matchdata is null or matchdata = ''",
		array('log_errors' => false, 'skip_repair' => true)
	);
    }

}
