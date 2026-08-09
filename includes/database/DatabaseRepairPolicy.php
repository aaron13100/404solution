<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Missing plugin-table repair/retry/notify policy.
 *
 * Runs after DatabaseErrorClassifier has identified a missing plugin table
 * on a query result. Owns:
 *   - cooldown gating (a previous repair failure suppresses retries for 1h),
 *   - the CREATE TABLE attempt via DatabaseUpgradesEtc,
 *   - the retry of the original query with WP error output suppressed,
 *   - the post-create existence check (so a stale retry-error against a now-
 *     materialized table does not double-report),
 *   - the failure path that engages the cooldown and surfaces a single
 *     deduplicated plugin-admin-page notice (never email, never wp-admin-wide),
 *   - the swap-window race shortcut for transient view_build / view_done /
 *     view_deleteme misses.
 *
 * Extracted from DatabaseErrorClassifier so the classifier stays a
 * predicate-only module. See design-audit-2026-06-02.md M201.
 *
 * @since 4.2.1
 */

class ABJ_404_Solution_DatabaseRepairPolicy {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $classifier;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_DatabaseErrorClassifier $classifier
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        ABJ_404_Solution_DatabaseErrorClassifier $classifier,
        $functions,
        $logger
    ) {
        $this->core = $core;
        $this->classifier = $classifier;
        $this->f = $functions;
        $this->logger = $logger;
    }

    /**
     * Attempt one auto-repair pass for missing plugin tables, then retry query once.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function attemptMissingTableRepairAndRetry(
        $query,
        &$result,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ) {
        if ($this->core->tableRepairer()->isTableRepairInProgress()) {
            return;
        }
        if ($this->handleTransientViewBuildTableMissing($query, $result)) {
            return;
        }
        // Rate-limit repeated failures: after a failed repair, downgrade subsequent
        // occurrences to WARNING for 1 hour so cron-per-run error storms don't
        // generate email reports. The first failure still logs ERROR and attempts repair.
        // 1 hour (not 24h), because a transient race during the repair (e.g. concurrent
        // wp-cron firing) can cause one failure that would clear by the next page load.
        // A 24h lockout permanently disables self-healing for the rest of the admin session.
        $repairCooldownKey = 'abj404_missing_table_repair_cooldown';
        $cooldownTtlSeconds = 3600;
        if ($this->isMissingTableRepairOnCooldown($result, $repairCooldownKey)) {
            return;
        }

        // During upgrades and nightly maintenance, createDatabaseTables() runs
        // proactively before any queries.  If we reach this point, a plugin table
        // went missing during normal usage.  Log as INFO while we attempt repair;
        // only escalate to ERROR if repair fails (avoids flooding admin with
        // error emails for transient issues that auto-repair resolves).
        $originalSqlError = is_string($result['last_error']) ? $result['last_error'] : '';
        $missingTable = $this->classifier->tableInspector()->extractMissingTableNameFromError($originalSqlError);
        $this->logger->infoMessage("Missing plugin table detected during query. "
            . "Attempting auto-repair. SQL error: " . $originalSqlError);

        $this->core->tableRepairer()->setTableRepairInProgress(true);
        try {
            $this->runRepairCreateRetryAndReport(
                $query, $result, $repairCooldownKey, $cooldownTtlSeconds,
                $originalSqlError, $missingTable, $tracer
            );
        } catch (Throwable $e) {
            if ($missingTable !== '' && $this->tableMaterializedAfterRepair($missingTable)) {
                $this->logger->infoMessage(
                    "Missing-table auto-repair materialized " . $missingTable .
                    " despite a post-create exception; clearing stale error. Exception: " . $e->getMessage()
                );
                $result['last_error'] = '';
                $this->core->noticeState()->clearPluginDbNoticeIfType('missing_table');
                return;
            }
            $this->logger->warn("Missing-table auto-repair failed: " . $e->getMessage());
            $this->core->noticeState()->setRuntimeFlag($repairCooldownKey, $this->core->clock()->now() + $cooldownTtlSeconds, $cooldownTtlSeconds);
        } finally {
            $this->core->tableRepairer()->setTableRepairInProgress(false);
        }
    }

    /**
     * If the observed error is against a transient staged-view-build table
     * (view_build, view_done, view_deleteme), handle it inline and return true.
     * Returns false if the error is unrelated to those tables, so the caller
     * proceeds with the normal repair flow.
     *
     * Transient staged-view-build tables are owned by the staged-build pipeline
     * and created/dropped between cycles. discoverPermanentDDLFiles() excludes
     * them from createDatabaseTables(), so the repair path cannot recreate them
     * and would fall straight into the failed-repair branch, setting the
     * missing_table admin notice on every plugin page and engaging a 1h cooldown
     * that blocks legit missing-table repair for the redirects / logsv2 / etc.
     * core tables.
     *
     * The silent-degrade is bounded to its actual use case: a SELECT against
     * `view_done` during the S11 RENAME swap window. Reader (admin redirect-list
     * AJAX) races writer (stageRenameSwap); the error is benign because the very
     * next request will see the new view_done. Any OTHER query / table
     * combination on these three tables represents the pipeline operating on its
     * own internal state. If view_build goes missing during INSERT/UPDATE/ALTER/
     * RENAME, the build is genuinely broken (concurrent invalidateViewDone
     * dropping the buffer mid-pipeline, S1's CREATE TABLE silently
     * approved-but-not-executed by an audit firewall, switch_to_blog race) and
     * the error must propagate so the orchestrator can halt and surface a real
     * admin notice instead of marching through every stage marking it complete.
     *
     * Cataloged as Pattern 13 in docs/PROACTIVE_BUG_DISCOVERY.md ("over-broad
     * error-swallow silences real pipeline failure"), the inverse of Pattern 7
     * ("don't escalate infra errors to email"). Reference: WP.org topic
     * 18908598, wp_siddur_ prefix site whose entire S2-to-S11 pipeline silently
     * failed on every cron tick because the prior unbounded swallow wiped
     * last_error for every write.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @return bool true if the case was handled (caller should return).
     */
    public function handleTransientViewBuildTableMissing($query, array &$result): bool {
        $observedError = is_string($result['last_error']) ? $result['last_error'] : '';
        if (!$this->classifier->taxonomy()->schema()->isTransientViewBuildTableError($observedError)) {
            return false;
        }
        $lowerErr = strtolower($observedError);
        $errorMentionsViewDone = ($this->f->strpos($lowerErr, '_abj404_view_done') !== false)
            && ($this->f->strpos($lowerErr, '_abj404_view_deleteme') === false);
        $isReadQuery = $this->core->queryTimeoutManager()->queryProducesResultRows($query);

        if ($errorMentionsViewDone && $isReadQuery) {
            $this->logger->debugMessage(
                "view_done missing on read (S11 swap-window race, expected): "
                . $observedError
            );
            $result['last_error'] = '';
            return true;
        }

        // Pipeline-write or pipeline-internal read against a transient
        // build table that's missing. createDatabaseTables() cannot
        // recreate these tables (they're excluded from
        // discoverPermanentDDLFiles); the build orchestrator owns S1.
        // Skip the repair attempt and let last_error propagate so the
        // stage's runStagedSqlFile() throws and the classifier halts.
        $this->logger->warn(
            "Transient staged-build table missing during pipeline operation "
            . "(build state diverged from disk; halting stage): "
            . $observedError
        );
        return true;
    }

    /**
     * Returns true if the missing-table auto-repair cooldown is currently active.
     * When the cooldown is active, the caller's last_error is cleared so
     * queryAndGetResults() does not double-report this error as
     * "Ugh. SQL query error" ERROR.
     *
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @return bool
     */
    public function isMissingTableRepairOnCooldown(array &$result, string $repairCooldownKey): bool {
        $cooldownUntil = $this->core->noticeState()->getRuntimeFlag($repairCooldownKey);
        if (!is_scalar($cooldownUntil) || (int)$cooldownUntil <= $this->core->clock()->now()) {
            return false;
        }
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        $this->logger->warn("Missing plugin table (repair previously failed, cooldown active): " . $lastError);
        $result['last_error'] = '';
        return true;
    }

    /**
     * Run the actual repair: materialize only missing permanent tables, flush
     * wpdb, retry the original query, and either clear the cooldown (success)
     * or engage the cooldown + admin notice (failure).
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @param int $cooldownTtlSeconds
     * @param string $originalSqlError
     * @param string $missingTable
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function runRepairCreateRetryAndReport(
        $query,
        array &$result,
        string $repairCooldownKey,
        int $cooldownTtlSeconds,
        string $originalSqlError,
        string $missingTable,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): void {
        $upgrades = abj_service('database_upgrades');
        // repairMissingTables(), NOT createDatabaseTables(). This runs inline in
        // whatever request issued the failing query -- frontend 404 dispatch,
        // admin AJAX, REST -- so the work it does has to be bounded by
        // construction: one SHOW TABLES probe per DDL file, plus one
        // CREATE TABLE IF NOT EXISTS for each table that is genuinely missing.
        //
        // createDatabaseTables() ran the entire bootstrap here instead: the
        // schema-wide collation sweep, the MyISAM-to-InnoDB conversion,
        // createIndexes(), the canonical_url + denorm backfills, the orphan
        // adoption scan, a full-corpus permalink-cache rebuild, and the
        // one-time relative-path URL migration -- none of which the caller's
        // retry needs, and all of which scale with site size. It also passed
        // $force = true to bypass the create_db_tables lock, so N concurrent
        // admin-AJAX requests could each run that whole pipeline at once. On a
        // 13k-page site that is a multi-minute stall on a user-facing request
        // (Bruno, report-146.txt: repair at 07:15:05, then pagination requests
        // from 07:16:39 onward that never completed).
        //
        // Every create*Table.sql carries its full column AND index list, so a
        // table created by the bounded path is complete; the schema-wide drift
        // passes belong to the daily maintenance cron, which repairMissingTables()
        // queues a one-off of when it actually creates something.
        $repairCreate = static function () use ($upgrades): void {
            $upgrades->components()->bootstrapUpgrade()->repairMissingTables();
        };
        if ($tracer === null) {
            $repairCreate();
        } else {
            $tracer->traceOperation('missing_table', 'repair_create', $repairCreate);
        }

        global $wpdb;
        if ($tracer === null) {
            if (!$this->core->connectionManager()->resetForRetry($originalSqlError)) {
                return;
            }
        } else {
            $reset = $tracer->traceOperation(
                'missing_table',
                'connection_retry_reset',
                fn(): bool => $this->core->connectionManager()->resetForRetry($originalSqlError)
            );
            if (!$reset) {
                return;
            }
        }

        // Suppress WP's own error output for the retry. If it also fails, we
        // report it ourselves below.  Without this, WP logs a second
        // "WordPress database error" entry on top of the first, producing
        // duplicate noise in debug.log for every failed cron run.
        $prevSuppressState = $wpdb->suppress_errors(true);
        try {
            $retry = function () use ($wpdb, $query): array {
                $retried = array(
                    'rows' => $wpdb->get_results(
                        $query,
                        $this->core->queryExecutor()->getCurrentResultType()
                    ),
                );
                $this->core->resultHarvester()->harvestWpdbResult($retried);
                return $retried;
            };
            $retried = $tracer === null
                ? $retry()
                : $tracer->traceAttempt('missing_table', 'missing_table', $retry);
            $result = array_merge($result, $retried);
        } finally {
            $wpdb->suppress_errors($prevSuppressState);
        }

        $retryError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error']
            : '';
        $retryMissingTable = $this->classifier->tableInspector()->extractMissingTableNameFromError($retryError);
        $materializedTable = $retryMissingTable !== '' ? $retryMissingTable : $missingTable;
        if ($retryError !== ''
                && $materializedTable !== ''
                && $this->classifier->taxonomy()->schema()->isMissingPluginTableError($retryError)
                && $this->tableMaterializedAfterRepair($materializedTable)) {
            $this->logger->infoMessage(
                "Missing-table auto-repair materialized " . $materializedTable .
                " and cleared a stale retry error: " . $retryError
            );
            $result['last_error'] = '';
        }

        if ($result['last_error'] === '') {
            $this->logger->infoMessage("Missing-table auto-repair succeeded.");
            // Clear any active cooldown now that repair is working.
            if (function_exists('delete_transient')) {
                delete_transient($repairCooldownKey);
            } elseif (function_exists('delete_option')) {
                delete_option($repairCooldownKey);
            }
            // If a stale missing_table notice exists from an earlier failed
            // repair attempt, clear it immediately now that repair succeeded.
            $this->core->noticeState()->clearPluginDbNoticeIfType('missing_table');
            return;
        }

        $this->reportRepairRetryFailure(
            $result, $repairCooldownKey, $cooldownTtlSeconds, $originalSqlError, $missingTable
        );
    }

    private function tableMaterializedAfterRepair(string $tableName): bool {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && strpos(get_class($wpdb), 'Mockery_') === 0) {
            return false;
        }

        if ($this->core->tableNameResolver()->tableExists($tableName)) {
            return true;
        }

        if (!isset($wpdb) || !is_object($wpdb) || !is_callable(array($wpdb, 'get_results'))) {
            return false;
        }

        // DAO-bypass-approved: post-repair metadata verification for a system-generated plugin table name.
        // @utf8-audit: opt-out - tableMaterializedAfterRepair receives system-generated plugin table names from the missing-table classifier.
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($tableName) . "`", ARRAY_A);
        return is_array($rows) && empty($wpdb->last_error);
    }

    /**
     * The retry inside runRepairCreateRetryAndReport() came back with an
     * error. Distinguish multisite-cross-prefix (not actionable, silent
     * degrade) from a real failure (WARN log + 1h cooldown + admin notice).
     *
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @param int $cooldownTtlSeconds
     * @param string $originalSqlError
     * @param string $missingTable
     * @return void
     */
    public function reportRepairRetryFailure(
        array &$result,
        string $repairCooldownKey,
        int $cooldownTtlSeconds,
        string $originalSqlError,
        string $missingTable
    ): void {
        global $wpdb;
        // Check for prefix mismatch: plugin tables may exist under a
        // different $table_prefix than the current $wpdb->prefix (common
        // after site migrations or hosting panel clones).
        $prefixDiag = $this->classifier->prefixDiagnostics()->diagnosePrefixMismatch();

        // Multisite cross-prefix: a query referenced another subsite's table.
        // The plugin correctly created tables for the current site, but cannot
        // fix another subsite's missing tables from this request context.
        // That subsite will get its tables when its own cron fires.
        if ($this->classifier->prefixDiagnostics()->isMultisiteCrossPrefixError($originalSqlError)) {
            $this->logger->warn("Multisite cross-prefix table reference (not actionable from this site). "
                . "Current prefix: " . ($wpdb->prefix ?? '')
                . ", Original error: " . $originalSqlError . $prefixDiag);
            // Clear last_error so queryAndGetResults() does not double-report.
            $result['last_error'] = '';
            return;
        }

        // Repair failed. Log at WARN, not ERROR. Per the self-healing
        // philosophy in CLAUDE.md (item 4): "Notify if recovery fails ...
        // Never send email." The admin notice set below is the user-facing
        // surface, gated to the plugin's own admin page. errorMessage()
        // triggers the daily email digest; warn() does not. Previously
        // this site emailed the developer once per cooldown expiry (every
        // 1h) for any permanently-broken table, which is the email-storm
        // pattern Bruno's and the kstal-site logs both exhibit.
        // Include the specific table that failed plus an explicit post-CREATE
        // existence check so the debug log distinguishes "CREATE didn't materialize
        // the table" (concurrency race, swallowed SQL error in queryAndGetResults,
        // insufficient privileges) from other retry-failure modes.
        $tableStillMissing = ($missingTable !== '' && !$this->core->tableNameResolver()->tableExists($missingTable));
        $tableContext = ($missingTable !== '')
            ? " Table: " . $missingTable . "."
            : '';
        $existenceContext = $tableStillMissing
            ? ' Table is still missing after CREATE TABLE ran. '
            . 'repairMissingTables() did not materialize this table '
            . '(likely a concurrent DROP, swallowed SQL error in queryAndGetResults, '
            . 'or insufficient CREATE TABLE privileges).'
            : '';
        $this->logger->warn("Missing plugin table auto-repair failed."
            . $tableContext
            . $existenceContext
            . " Original error: " . $originalSqlError
            . ", Retry error: " . (isset($result['last_error']) && is_scalar($result['last_error'])
                ? (string)$result['last_error'] : '')
            . $prefixDiag);
        // Engage 1h cooldown and surface a single admin notice on
        // the plugin screen so the admin knows to investigate.
        // Never email; never show on all wp-admin pages.
        $this->core->noticeState()->setRuntimeFlag($repairCooldownKey, $this->core->clock()->now() + $cooldownTtlSeconds, $cooldownTtlSeconds);
        $this->setMissingTablePluginDbNotice($result, $missingTable, $prefixDiag);
    }

    /**
     * Construct and store the missing-table admin notice that surfaces on the
     * plugin's own admin screens (gated; never wp-admin-wide, never email).
     *
     * @param array<string, mixed> $result
     * @param string $missingTable
     * @param string $prefixDiag
     * @return void
     */
    public function setMissingTablePluginDbNotice(array $result, string $missingTable, string $prefixDiag): void {
        $tableLabel = ($missingTable !== '') ? "'" . $missingTable . "'" : 'a plugin database table';
        $rawError = is_string($result['last_error']) ? $result['last_error'] : '';
        $adminMsg = sprintf(
            function_exists('__')
                ? __('404 Solution cannot function correctly: the database table %s is missing, and the plugin tried to recreate it but the CREATE TABLE statement could not be executed. This almost always means the WordPress database user does not have permission to run CREATE TABLE (and likely ALTER TABLE / CREATE INDEX) on this database. Until this is fixed, the plugin cannot record 404s, serve redirects, or generate suggestions. To fix it: ask your hosting provider or database administrator to grant CREATE, ALTER, and INDEX privileges to the WordPress database user for this site, then reload this page. Alternatively, restore the missing table from a recent database backup.', '404-solution')
                : '404 Solution cannot function correctly: the database table %s is missing, and the plugin tried to recreate it but the CREATE TABLE statement could not be executed. This almost always means the WordPress database user does not have permission to run CREATE TABLE (and likely ALTER TABLE / CREATE INDEX) on this database. Until this is fixed, the plugin cannot record 404s, serve redirects, or generate suggestions. To fix it: ask your hosting provider or database administrator to grant CREATE, ALTER, and INDEX privileges to the WordPress database user for this site, then reload this page. Alternatively, restore the missing table from a recent database backup.',
            $tableLabel
        );
        if ($rawError !== '') {
            $adminMsg .= ' ' . sprintf(
                function_exists('__') ? __('Original database error: %s', '404-solution') : 'Original database error: %s',
                $rawError
            );
        }
        if ($prefixDiag !== '') {
            $adminMsg .= ' ' . $prefixDiag;
        }
        $noticePayload = array(
            'type'         => 'missing_table',
            'message'      => $adminMsg,
            'guidance'     => '',
            'timestamp'    => $this->core->clock()->now(),
            'error_string' => $rawError,
        );
        $this->core->noticeState()->setRuntimeFlag('abj404_plugin_db_notice', $noticePayload, 86400);
    }
}
