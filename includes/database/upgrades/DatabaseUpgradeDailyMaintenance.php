<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily-cron entry point that fans out to every database-maintenance sub-task.
 *
 * Called by abj404_dailyMaintenanceCronJobListener() in 404-solution.php.
 * Coordinates (in order): self-heal prologue, ngram cache sync/cleanup, expired
 * transient cleanup, dead-destination flagging, auto-redirect retention,
 * canonical_url backfill (redirects + logsv2), redirects denorm backfill (Step
 * 3a) + nightly full reconcile (Step 3d), internal-link scan, and an
 * inline view_done snapshot refresh. The orchestrator owns the small in-house
 * tasks that only the daily cron triggers (transient cleanup, view-done
 * refresh); everything else routes through the coordinator delegate map.
 */
class ABJ_404_Solution_DatabaseUpgradeDailyMaintenance extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Run all database maintenance tasks.
     *
     * This is the main orchestrator method called by the daily maintenance cron job.
     * It coordinates all database-related maintenance tasks in the proper order.
     *
     * Called by: abj404_dailyMaintenanceCronJobListener() in 404-solution.php
     *
     * @return void
     */
    public function runDatabaseMaintenanceTasks() {
        // Insurance: Verify tables exist (per-site or network-wide based on activation mode)
        // This catches failed activations, database corruption, and edge cases.
        // Routed through runSelfHealPrologue() so the SelfHealingPrologueReachabilityTest
        // can confirm the daily cron reaches the canonical prologue token.
        $this->upgrades()->selfHealUpgrade()->runSelfHealPrologue();

        // Ngram cache maintenance: sync missing entries and cleanup orphaned ones
        $this->upgrades()->nGramUpgrade()->syncMissingNGrams();
        $this->upgrades()->nGramUpgrade()->cleanupOrphanedNGrams();

        // Clean up expired rate limit transients to prevent wp_options bloat
        $this->cleanupExpiredRateLimitTransients();

        // Flag redirects whose destination URL is generating 404s (drives redirect suspension)
        abj_service('redirects_retention_service')->flagDeadDestinationRedirects();

        // Expire auto-created redirects that exceed the configured age threshold
        abj_service('redirects_retention_service')->expireOldAutoRedirects();

        // Backfill canonical_url on legacy redirect rows so the captured-page
        // JOIN to logs_hits.requested_url stays index-friendly. Chunked + rate-
        // limited so the daily cron continues progress without blocking large
        // sites; converges on its own across successive runs.
        $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();

        // Same idea for logsv2: legacy rows (pre-4.1.x) lack canonical_url, so
        // the hits-rebuild JOIN falls back to CONCAT/TRIM and can't use
        // idx_canonical_url. Chunked + rate-limited so even a multi-hundred-K
        // logsv2 backlog converges across successive cron ticks. Tighter
        // 15-second budget (vs redirects' 25) because this same function is
        // also reachable from the browser-triggered lazy-backfill AJAX endpoint.
        $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillLogsv2CanonicalUrl();

        // Denorm Step 3a (i459): populate the four derived columns (logshits,
        // last_used, dest_for_view, published_status) on legacy redirect rows
        // that pre-date the column add. Chunked + wall-clock-bounded so even a
        // large redirects table converges across successive daily ticks without
        // blocking activation. Runs after the canonical_url backfills so the
        // hits rollup join matches on the freshly populated canonical_url column.
        $this->upgrades()->redirectsDenormBackfillUpgrade()->backfillRedirectsDenormColumns();

        // report5.md Finding 1: an install upgraded ACROSS the dest_sort_key
        // column add already has dest_for_view populated, so the main backfill's
        // dest_for_view IS NULL sentinel skips it and the converged-row live
        // write-back never fires; the indexable Destination sort key would stay
        // NULL until the nightly reconcile below happened to walk that row. This
        // cheap narrow drain (no per-type joins) closes that window directly,
        // keyed on the self-clearing dest_sort_key IS NULL AND dest_for_view IS
        // NOT NULL sentinel. Runs after the main backfill so freshly-resolved rows
        // (which already got their sort key) are skipped, and converges to a no-op.
        $this->upgrades()->redirectsSortKeyBackfillUpgrade()->backfillRedirectsDestSortKey();

        // report6.md: same one-time drain for url_sort_key (the indexable URL sort
        // key, LEFT(url, 191)). New/edited rows get it in real time via the Step 3c
        // recompute; this converges legacy rows that pre-date the column add so the
        // URL sort is index-ordered on the captured tab too, without waiting for the
        // nightly reconcile below.
        $this->upgrades()->redirectsSortKeyBackfillUpgrade()->backfillRedirectsUrlSortKey();

        // Denorm Step 3d (i462): nightly full reconcile of the same four derived
        // columns for ALL redirect rows. The Tier-3 floor / backstop: a
        // brute-force cursor-walked recompute that catches drift from raw-SQL
        // writers that bypassed the Step 3c real-time hooks (no hook fired, no
        // timestamp bumped). Chunked + wall-clock-bounded with a resumable id
        // cursor so a large table converges across successive nightly ticks.
        // Background-only: never on the read path, never blocks the table view;
        // a stale value can only delay a refresh by one pass, never blank rows.
        // Runs after the backfill so a fresh install's one-time population goes
        // first and the reconcile then keeps the populated columns honest.
        $this->upgrades()->redirectsDenormReconcileUpgrade()->reconcileRedirectsDenormColumns();

        // Nightly internal-link scan: find broken internal links in published content.
        if (class_exists('ABJ_404_Solution_InternalLinkScanner')) {
            $scanner = new ABJ_404_Solution_InternalLinkScanner();
            $scanner->runNightlyScan();
        }
    }

    /**
     * Clean up expired rate limit transients from wp_options table.
     *
     * WordPress transients are supposed to auto-delete when they expire, but in practice
     * they can accumulate over time. This maintenance task removes expired rate limit
     * transients to prevent wp_options table bloat.
     *
     * Called during daily maintenance cron job.
     *
     * @return array<string, mixed> Statistics: ['deleted' => int, 'errors' => int]
     */
    public function cleanupExpiredRateLimitTransients() {
        global $wpdb;

        $this->logger->debugMessage("Cleaning up expired rate limit transients...");

        $stats = ['deleted' => 0, 'errors' => 0];

        // Delete expired rate limit transients
        // WordPress stores transients as two rows: _transient_* and _transient_timeout_*
        // The timeout row contains the expiration timestamp
        // We delete both the value and timeout rows for expired transients

        $currentTime = abj_clock()->now();

        // Find all expired rate limit timeout keys
        // DAO-bypass-approved: WP-core wp_options probe; $wpdb->prepare is read-only string formatting, executed via $wpdb->get_col below
        $query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_abj404_rate_limit_') . '%',
            $currentTime
        );

        // DAO-bypass-approved: Outside-plugin-tables wp_options cleanup probe (parallels DataAccessTrait_ViewQueries:478 transient clear)
        $expiredTimeouts = $wpdb->get_col($query);

        $lastError = (string)($wpdb->last_error ?? '');
        if ($lastError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($lastError)) {
                $this->logger->errorMessage("Failed to query for expired rate limit transients: " . $lastError);
            }
            return ['deleted' => 0, 'errors' => 1, 'error' => $lastError];
        }

        if (!empty($expiredTimeouts)) {
            $this->logger->debugMessage("Found " . count($expiredTimeouts) . " expired rate limit transients to delete.");

            foreach ($expiredTimeouts as $timeoutKey) {
                // Get the corresponding value key (remove '_timeout' from the name)
                $valueKey = str_replace('_transient_timeout_', '_transient_', $timeoutKey);

                // Delete both the timeout and value rows
                $timeoutDeleted = delete_option($timeoutKey);
                $valueDeleted = delete_option($valueKey);

                if ($timeoutDeleted || $valueDeleted) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            }

            $this->logger->debugMessage("Deleted {$stats['deleted']} expired rate limit transients, {$stats['errors']} errors.");
        } else {
            $this->logger->debugMessage("No expired rate limit transients found.");
        }

        return $stats;
    }

}
