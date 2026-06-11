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
 * canonical_url backfill (redirects + logsv2), internal-link scan, and an
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
        $this->runSelfHealPrologue();

        // Ngram cache maintenance: sync missing entries and cleanup orphaned ones
        $this->syncMissingNGrams();
        $this->cleanupOrphanedNGrams();

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
        $this->backfillRedirectsCanonicalUrl();

        // Same idea for logsv2: legacy rows (pre-4.1.x) lack canonical_url, so
        // the hits-rebuild JOIN falls back to CONCAT/TRIM and can't use
        // idx_canonical_url. Chunked + rate-limited so even a multi-hundred-K
        // logsv2 backlog converges across successive cron ticks. Tighter
        // 15-second budget (vs redirects' 25) because this same function is
        // also reachable from the Captured-404s tab shutdown hook --
        // see scheduleLogsv2CanonicalUrlBackfill().
        $this->backfillLogsv2CanonicalUrl();

        // Nightly internal-link scan: find broken internal links in published content.
        if (class_exists('ABJ_404_Solution_InternalLinkScanner')) {
            $scanner = new ABJ_404_Solution_InternalLinkScanner();
            $scanner->runNightlyScan();
        }

        $this->refreshViewDoneSnapshotInline();
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

        $currentTime = time();

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

    /**
     * Invalidate the staged view_done snapshot and drive the staged build to
     * completion inline so admin tables on quiet sites still see at most a
     * 24-hour-old snapshot. Bounded by an iteration cap so a build that yields
     * indefinitely (lease contention, transient lock failures) cannot stall
     * the daily maintenance window.
     *
     * Runs after the other daily tasks so canonical_url backfills, dead-dest
     * flagging, and auto-redirect expiry are already reflected in the freshly
     * rebuilt view_done.
     *
     * @return void
     */
    public function refreshViewDoneSnapshotInline(): void {
        $viewRead = abj_service('view_read_service');
        $viewBuild = abj_service('view_build_orchestrator');
        $rebuildHealth = null;
        if (class_exists('ABJ_404_Solution_ServiceContainer')
                && ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
            $service = ABJ_404_Solution_ServiceContainer::safeGet('rebuild_health');
            $rebuildHealth = $service instanceof ABJ_404_Solution_RebuildHealthState ? $service : null;
        }
        if ($rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                && !$rebuildHealth->beginDailyMaintenanceRebuildAttempt()) {
            return;
        }
        if (!is_object($viewRead)
                || !method_exists($viewRead, 'invalidateViewSnapshotCache')
                || !is_object($viewBuild)
                || !method_exists($viewBuild, 'advanceViewBuildOnce')) {
            return;
        }
        $viewRead->invalidateViewSnapshotCache();
        // 11 staged sub-stages with up to a few yields each on resumable
        // stages (S2/S4/S5); 30 ticks comfortably covers a full rebuild.
        for ($i = 0; $i < 30; $i++) {
            $progress = $viewBuild->advanceViewBuildOnce();
            if (!is_array($progress)) { break; }
            if (($progress['status'] ?? '') === 'ready') { break; }
            if (!empty($progress['locked'])) { break; }
        }
    }
}
