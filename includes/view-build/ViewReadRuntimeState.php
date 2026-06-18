<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime-only flags and cache constants for the admin view-read path.
 */
final class ABJ_404_Solution_ViewReadRuntimeState {

    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = 'abj404_high_impact_captured';
    const STATUS_CACHE_TTL = 86400;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = 300;
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = 120;
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = 30;
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = 28;
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = 35;
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = 3;
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = 2097152;
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;

    /**
     * Debounce window for the high-frequency captured-insert path. A busy 404
     * site captures continuously; invalidating the captured status-count cache
     * on every insert keeps the SUM(CASE) aggregate permanently cold (report.md
     * Finding 4). A captured insert clears the captured cache at most once per
     * this window; the count is therefore at most this many seconds stale.
     * Matched to the view-snapshot refresh cooldown / client detect-poll cadence.
     */
    const CACHE_KEY_CAPTURED_COUNT_INVALIDATE_COOLDOWN = 'abj404_captured_count_invalidate_cooldown';
    const CAPTURED_COUNT_INVALIDATE_COOLDOWN_SECONDS = 30;

    /** @var bool Per-request bulk mutation deferral flag. */
    public static $bulkMutationInProgress = false;
}
