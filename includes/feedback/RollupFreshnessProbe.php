<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Freshness signals for the redirects-hits rollup, for the feedback payload's
 * `environment_extras.view_build_state` field.
 *
 * The rollup (ABJ_404_Solution_LogsHitsRollupService, reached through the
 * `logs_repository` service) is the subsystem most implicated in "the admin
 * redirects page never loads" reports: the redirects tab reads hit counts from
 * the rollup table, and a rollup that does not exist, is mid-rebuild, or has
 * fallen far behind logsv2 is the difference between a page that renders and
 * one that times out. This probe answers, in one payload field, whether that
 * has happened on the reporting site.
 *
 * WHY THIS READS A SERVICE AND NOT OPTIONS. This is the live successor to the
 * staged view-build pipeline that commit 73a55a70 (2026-06-13) deleted. The
 * previous implementation hand-assembled the same field from five
 * `get_option('abj404_view_build_*')` literals; that commit removed every
 * writer of those names without touching the reads, and because get_option()
 * returns null for a missing key and is_scalar(null) is false, every key was
 * silently dropped and this field shipped `[]` on every payload for seven
 * weeks -- through four consecutive support reports from a user whose reported
 * symptom was exactly the one this field exists to diagnose. Reading the
 * rollup service's own public accessors instead means there is no storage-key
 * literal here to be orphaned: the service owns its keys, so a rename moves
 * the read with the write. tests/FeedbackProbeOrphanedOptionReadsTest.php
 * fails the build if any feedback probe reintroduces that shape.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition.
 */
class ABJ_404_Solution_RollupFreshnessProbe {

    /**
     * Live rollup state: does the rollup table exist, does it need a rebuild
     * right now, when did it last actually refresh or get scheduled, and how
     * far behind is its stored watermark from logsv2's true max id?
     *
     * `rollup_stored_max_log_id` vs `logsv2_max_log_id` is the staleness
     * measure the field exists for: equal means current, a large gap means the
     * rollup has fallen behind and the redirects tab is showing stale hit
     * counts (or timing out trying not to).
     *
     * Throws when the rollup service is unavailable, so the caller's
     * recordProbe() wrapper writes a `view_build_state_error` marker. An empty
     * array must never again be this probe's way of saying "I could not look."
     *
     * COST. Every accessor here is a single-row read (information_schema
     * lookups, an indexed MAX(id), a runtime flag); none of them starts a
     * rebuild, which matters because a probe that provoked the stall it exists
     * to diagnose would be worse than no probe. hitsTableNeedsRebuild()
     * re-reads three of the values below internally, so a support send issues
     * those reads twice. That is deliberate: re-deriving the staleness rule
     * here from the raw ids would copy LogsHitsRollupService's policy into the
     * payload builder, and a duplicated rule that drifts is a far worse trade
     * than three extra single-row queries on a user-initiated send.
     *
     * @return array<string, int|bool>
     */
    public function collectRollupFreshness(): array {
        $logsRepo = function_exists('abj_service_optional') ? abj_service_optional('logs_repository') : null;
        if (!$logsRepo instanceof ABJ_404_Solution_LogHitsLifecycleInterface
            || !$logsRepo instanceof ABJ_404_Solution_LogHitsRebuildInterface) {
            throw new \RuntimeException('logs_repository service unavailable for view_build_state probe');
        }
        $lastUpdated = $logsRepo->getLogsHitsTableLastUpdated();
        $lastScheduled = $logsRepo->getLogsHitsTableLastScheduledAt();
        return array(
            'rollup_table_exists'      => (bool)$logsRepo->logsHitsTableExists(),
            'rollup_needs_rebuild'     => (bool)$logsRepo->hitsTableNeedsRebuild(),
            'rollup_last_updated_at'   => is_numeric($lastUpdated) ? (int)$lastUpdated : 0,
            'rollup_last_scheduled_at' => is_numeric($lastScheduled) ? (int)$lastScheduled : 0,
            'rollup_stored_max_log_id' => (int)$logsRepo->getStoredMaxLogId(),
            'logsv2_max_log_id'        => (int)$logsRepo->getMaxLogId(),
        );
    }
}
