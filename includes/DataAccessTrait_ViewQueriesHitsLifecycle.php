<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hits-table lifecycle helpers extracted from
 * ABJ_404_Solution_DataAccess_ViewQueriesTrait to keep the parent trait under
 * the ModularityTest CLASS_LIMIT. Owns the *scheduling, locking, and
 * existence-probe* concerns for the `{wp_abj404_logs_hits}` rollup table,
 * plus the small log-id watermark helpers (getMaxLogId / getMinLogId /
 * getStoredMaxLogId) the scheduling path consults. The *rebuild* path
 * itself lives in ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait
 * (DataAccessTrait_LogsHitsRebuild.php).
 *
 * Composed into ABJ_404_Solution_DataAccess alongside the other DAO traits.
 * Depends on host-class state and methods provided by the rest of DAO:
 * `queryAndGetResults`, `doTableNameReplacements`, `getRuntimeFlag`,
 * `setRuntimeFlag`, `getLowercasePrefix`, `shouldSkipNonEssentialDbWrites`,
 * `recordLogsHitsRollupStalenessSignal`, `hitsTableNeedsRebuild`,
 * `createRedirectsForViewHitsTable`, `getLogsHitsTableStatusRow`, the
 * `$logger` property, the `$hitsTableRebuildScheduled` static, and the
 * HITS_TABLE_* constants on the host class.
 */
trait ABJ_404_Solution_DataAccess_ViewQueriesHitsLifecycleTrait {

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        // Record that we checked during this request (used for admin tooltip UX).
        $this->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        // Piggyback on the captured-404s tab render: also schedule a
        // 15-second logsv2.canonical_url backfill at shutdown if there's
        // legacy NULL-row backlog. The shutdown handler holds a worker
        // for the budget but the admin response is already flushed by
        // fastcgi_finish_request, so the user doesn't perceive the wait.
        // The function is internally deduped + gated on column existence,
        // probe results, and the backfill-complete option, so calling it
        // unconditionally is cheap.
        if (function_exists('abj_service')) {
            $upgradesEtc = abj_service('database_upgrades');
            if (is_object($upgradesEtc) && method_exists($upgradesEtc, 'scheduleLogsv2CanonicalUrlBackfill')) {
                $upgradesEtc->scheduleLogsv2CanonicalUrlBackfill();
            }
        }

        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        // Check if the table exists
        if (!$this->logsHitsTableExists()) {
            // Defer creation to shutdown hook so the admin page loads immediately.
            // The view query gracefully falls back to null hits columns when the
            // table doesn't exist (getRedirectsForViewQuery checks logsHitsTableExists).
            // On sites with large logsv2 tables the INSERT...SELECT that populates
            // the hits table can take minutes, which exceeds proxy timeouts (e.g.
            // Cloudflare's 100-second limit → HTTP 524).
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->scheduleHitsTableRebuild();
            return;
        }

        // Diagnostic: track the max_log_id age signal so a stalled rollup
        // surfaces a broken-cron admin notice instead of silently showing
        // stale hit-count columns. Self-heals when the gap closes.
        $this->recordLogsHitsRollupStalenessSignal();

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->hitsTableNeedsRebuild()) {
            // No new log entries - skip rebuild to reduce server load
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->scheduleHitsTableRebuild();
    }
}
