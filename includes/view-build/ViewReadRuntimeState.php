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
    const HITS_TABLE_LAST_CHECKED_FLAG = 'abj404_logs_hits_last_checked_at';
    const HITS_TABLE_LAST_DECISION_FLAG = 'abj404_logs_hits_last_decision';
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;

    /** @var bool Per-request bulk mutation deferral flag. */
    public static $bulkMutationInProgress = false;
}
