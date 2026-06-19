<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped runtime state and static configuration for database upgrades.
 */
final class ABJ_404_Solution_DatabaseUpgradeRuntimeState {

    /**
     * Number of rows updated per chunk by backfillRedirectsCanonicalUrl().
     * @var int
     */
    public const CANONICAL_URL_BACKFILL_CHUNK_SIZE = 5000;

    /**
     * Per-invocation wall-clock budget for redirect canonical URL backfill.
     * @var int
     */
    public const CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 25;

    /**
     * Per-invocation wall-clock budget for logsv2 canonical URL backfill.
     * @var int
     */
    public const LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 15;

    /**
     * wp_options key flipped when every logsv2 canonical_url value is filled.
     * @var string
     */
    public const LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION = 'abj404_logsv2_canonical_url_backfill_complete';

    /**
     * wp_options key flipped when every redirects canonical_url value is filled.
     * Lets the hits-rebuild phase2 JOIN drop the COALESCE wrap on the redirects
     * side and probe idx_canonical_url directly. See i359.
     * @var string
     */
    public const REDIRECTS_CANONICAL_URL_BACKFILL_COMPLETE_OPTION = 'abj404_redirects_canonical_url_backfill_complete';

    /**
     * Rows resolved per chunk by backfillRedirectsDenormColumns(). Smaller than
     * the canonical-url chunk (5000) because each chunk also runs a logsv2
     * GROUP BY rollup join, so a tighter chunk keeps each statement bounded.
     * @var int
     */
    public const REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE = 1000;

    /**
     * Per-invocation wall-clock budget for the redirects denorm backfill.
     * @var int
     */
    public const REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC = 20;

    /**
     * Short post-response shutdown budget for admin-ajax backstops when a cron
     * event was accepted but host loopback cron may never run.
     * @var int
     */
    public const REDIRECTS_DENORM_SHUTDOWN_BACKSTOP_TIME_BUDGET_SEC = 3;

    /**
     * Rows recomputed per chunk by reconcileRedirectsDenormColumns() (Denorm
     * Step 3d). Same 1000-row chunk as the backfill: each chunk also runs a
     * logsv2 GROUP BY rollup join, so a tighter chunk keeps each statement
     * bounded on a large table.
     * @var int
     */
    public const REDIRECTS_DENORM_RECONCILE_CHUNK_SIZE = 1000;

    /**
     * Per-invocation wall-clock budget for the nightly redirects denorm
     * reconcile. A pass that exhausts it persists its id cursor and resumes on
     * the next nightly tick, so a huge table converges across successive nights.
     * @var int
     */
    public const REDIRECTS_DENORM_RECONCILE_TIME_BUDGET_SEC = 20;

    /**
     * wp_options key holding the resumable ascending-id cursor for the nightly
     * full reconcile (Denorm Step 3d): the highest redirect id recomputed in the
     * current pass, or 0 when no pass is mid-flight (the next tick starts fresh).
     * @var string
     */
    public const REDIRECTS_DENORM_RECONCILE_CURSOR_OPTION = 'abj404_redirects_denorm_reconcile_cursor';

    /**
     * wp_options key holding the ascending-id cursor for the one-time redirects
     * denorm backfill (Denorm Step 3a). It prevents each daily/on-demand chunk
     * from repeatedly scanning the already-drained low-id prefix on large hosts.
     * @var string
     */
    public const REDIRECTS_DENORM_BACKFILL_CURSOR_OPTION = 'abj404_redirects_denorm_backfill_cursor';

    /**
     * wp_options key holding a short "do not re-arm before" timestamp for the
     * on-demand redirects denorm backfill trigger.
     * @var string
     */
    public const REDIRECTS_DENORM_BACKFILL_ARMED_UNTIL_OPTION = 'abj404_redirects_denorm_backfill_armed_until';

    /**
     * Known plugin table suffixes for adoption.
     * @var array<int, string>
     */
    private const PLUGIN_TABLE_SUFFIXES = [
        'abj404_redirects',
        'abj404_logsv2',
        'abj404_spelling_cache',
        'abj404_permalink_cache',
        'abj404_lookup',
        'abj404_ngram_cache',
        'abj404_logs_hits',
        'abj404_redirect_conditions',
        'abj404_engine_profiles',
        'abj404_view_cache',
    ];

    /** @var string|null */
    private static $runtimeId = null;

    /** @var bool */
    private static $logsv2CanonicalBackfillScheduled = false;

    /** @var bool */
    private static $redirectsSortKeyBackfillScheduled = false;

    /** @var bool */
    private static $redirectsDenormBackfillScheduled = false;

    /** @return void */
    public static function initializeRuntimeId(): void {
        if (self::$runtimeId === null) {
            self::$runtimeId = uniqid('', true);
        }
    }

    /** @return string|null */
    public static function getRuntimeId() {
        return self::$runtimeId;
    }

    /** @return void */
    public static function resetRuntimeIdForTests(): void {
        self::$runtimeId = null;
    }

    public static function isLogsv2CanonicalBackfillScheduled(): bool {
        return self::$logsv2CanonicalBackfillScheduled;
    }

    public static function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void {
        self::$logsv2CanonicalBackfillScheduled = $scheduled;
    }

    /** @return void */
    public static function resetLogsv2CanonicalBackfillScheduledFlagForTests(): void {
        self::$logsv2CanonicalBackfillScheduled = false;
    }

    /**
     * Request-scoped dedup flag for the on-demand redirects sort-key drain
     * scheduler (scheduleRedirectsSortKeyBackfill). Mirrors the logsv2 canonical
     * flag: set once per request after the drain is scheduled so repeated admin
     * redirect-table renders in the same request do not re-probe or re-schedule.
     *
     * @return bool
     */
    public static function isRedirectsSortKeyBackfillScheduled(): bool {
        return self::$redirectsSortKeyBackfillScheduled;
    }

    /**
     * @param bool $scheduled
     * @return void
     */
    public static function setRedirectsSortKeyBackfillScheduled(bool $scheduled): void {
        self::$redirectsSortKeyBackfillScheduled = $scheduled;
    }

    /** @return void */
    public static function resetRedirectsSortKeyBackfillScheduledFlagForTests(): void {
        self::$redirectsSortKeyBackfillScheduled = false;
    }

    /** @return bool */
    public static function isRedirectsDenormBackfillScheduled(): bool {
        return self::$redirectsDenormBackfillScheduled;
    }

    /**
     * @param bool $scheduled
     * @return void
     */
    public static function setRedirectsDenormBackfillScheduled(bool $scheduled): void {
        self::$redirectsDenormBackfillScheduled = $scheduled;
    }

    /** @return void */
    public static function resetRedirectsDenormBackfillScheduledFlagForTests(): void {
        self::$redirectsDenormBackfillScheduled = false;
    }

    /** @return array<int, string> */
    public static function getPluginTableSuffixes(): array {
        return self::PLUGIN_TABLE_SUFFIXES;
    }
}
