<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns WordPress cron primitive access for plugin scheduling code.
 *
 * Domain services decide whether work is needed; this adapter owns hook names,
 * schedule checks, wall-clock offsets, clearing/unscheduling, and failure
 * diagnostics around the WordPress cron API.
 */
class ABJ_404_Solution_CronScheduler {

    const HOOK_CLEANUP = 'abj404_cleanupCronAction';
    const HOOK_GSC_FETCH = 'abj404_gsc_fetch_cron';
    const HOOK_GSC_BACKGROUND_REFRESH = 'abj404_gsc_background_refresh';
    const HOOK_UPDATE_PERMALINK_CACHE = 'abj404_updatePermalinkCacheAction';
    const HOOK_UPDATE_LOGS_HITS_TABLE = 'abj404_updateLogsHitsTableAction';
    const HOOK_SEND_DIGEST = 'abj404_send_digest';
    const HOOK_REBUILD_NGRAM_CACHE = 'abj404_rebuild_ngram_cache_hook';
    const HOOK_LOGSV2_CANONICAL_BACKFILL = 'abj404_logsv2_canonical_backfill';
    const HOOK_REDIRECTS_DENORM_BACKFILL = 'abj404_redirects_denorm_backfill';
    const HOOK_REDIRECTS_SORT_KEY_BACKFILL = 'abj404_redirects_sort_key_backfill';
    const HOOK_SEND_QUEUED_REPORT = 'abj404_send_queued_report';
    const HOOK_REFRESH_STATUS_COUNTS = 'abj404_refresh_status_counts';
    const HOOK_REPAIR_COLLATIONS = 'abj404_repair_collations';
    const HOOK_NETWORK_ACTIVATION = 'abj404_network_activation_hook';
    const HOOK_NETWORK_ACTIVATION_BACKGROUND = 'abj404_network_activation_background';
    const HOOK_NETWORK_UPGRADE_BACKGROUND = 'abj404_network_upgrade_background';
    const HOOK_DUPLICATE_LEGACY = 'abj404_duplicateCronAction';
    const HOOK_REMOVE_DUPLICATES_LEGACY = 'removeDuplicatesCron';
    const HOOK_DELETE_OLD_REDIRECTS_LEGACY = 'deleteOldRedirectsCron';
    // The staged view_done rebuild cron was removed in the denorm chain, but
    // sites upgrading from a build that scheduled it may still carry the event;
    // deactivation must defensively clear it (matches deleteBlogData + Uninstaller).
    const HOOK_REBUILD_VIEW_DONE_LEGACY = 'abj404_rebuildViewDone';

    /** @var callable(string,array<string,mixed>,callable):mixed|null */
    private static $statusCountOperationTracer = null;

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /** @var ABJ_404_Solution_ScheduledEventInspector Read side of the cron store. */
    private $inspector;

    /** @var string */
    private $lastFailureDetail = '';

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @param ABJ_404_Solution_Logging|null $logger
     * @param ABJ_404_Solution_ScheduledEventInspector|null $inspector Defaults to a plain one; it owns no state.
     */
    public function __construct(
        ABJ_404_Solution_Clock $clock,
        $logger = null,
        ?ABJ_404_Solution_ScheduledEventInspector $inspector = null
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
        $this->inspector = $inspector !== null ? $inspector : new ABJ_404_Solution_ScheduledEventInspector();
    }

    /** @param callable(string,array<string,mixed>,callable):mixed|null $tracer */
    public static function setStatusCountOperationTracer($tracer): void {
        self::$statusCountOperationTracer = $tracer;
    }

    /** @return int */
    public function now(): int {
        return $this->clock->now();
    }

    /** @return string */
    public function lastFailureDetail(): string {
        return $this->lastFailureDetail;
    }

    /**
     * @param string $hook
     * @param array<int, mixed> $args
     * @return int|false
     */
    public function nextScheduled(string $hook, array $args = array()) {
        return self::traceStatusCountOperation(
            $hook,
            'next_scheduled_check',
            function () use ($hook, $args) {
                if (!function_exists('wp_next_scheduled')) {
                    return false;
                }
                return empty($args)
                    ? wp_next_scheduled($hook)
                    : wp_next_scheduled($hook, $this->listArgs($args));
            }
        );
    }

    /**
     * @param string $hook
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleSingleIfMissing(string $hook, int $delaySeconds = 0, array $args = array()): bool {
        if ($this->nextScheduled($hook, $args) !== false) {
            return true;
        }
        return $this->scheduleSingle($hook, $delaySeconds, $args);
    }

    /**
     * @param string $hook
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleSingle(string $hook, int $delaySeconds = 0, array $args = array()): bool {
        return $this->scheduleSingleAt($hook, $this->timestampAfter($delaySeconds), $args);
    }

    /**
     * @param string $hook
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleSingleAt(string $hook, int $timestamp, array $args = array()): bool {
        if (!function_exists('wp_schedule_single_event')) {
            $this->logScheduleFailure('single', $hook, null, $timestamp, $args, 'wp_schedule_single_event unavailable');
            return false;
        }
        $scheduled = self::traceStatusCountOperation(
            $hook,
            'scheduling_write',
            fn() => wp_schedule_single_event(
                $timestamp,
                $hook,
                $this->listArgs($args),
                true
            )
        );
        if ($scheduled === false || $this->isWpError($scheduled)) {
            if ($this->inspector->requestedEventIsStored($hook, $args, $timestamp, null)) {
                return $this->reportAlreadySatisfied('single', $hook, $timestamp);
            }
            $this->logScheduleFailure('single', $hook, null, $timestamp, $args, $this->wpErrorMessage($scheduled));
            return false;
        }
        return true;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private static function traceStatusCountOperation(
        string $hook,
        string $operation,
        callable $work
    ) {
        if ($hook !== self::HOOK_REFRESH_STATUS_COUNTS
                || self::$statusCountOperationTracer === null) {
            return $work();
        }
        return (self::$statusCountOperationTracer)(
            $operation,
            array('family' => 'status_refresh_cron'),
            $work
        );
    }

    /**
     * @param string $hook
     * @param string $recurrence
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleRecurringIfMissing(string $hook, string $recurrence, int $delaySeconds = 0, array $args = array()): bool {
        if ($this->nextScheduled($hook, $args) !== false) {
            return true;
        }
        return $this->scheduleRecurringAt($hook, $recurrence, $this->timestampAfter($delaySeconds), $args);
    }

    /**
     * Removes one identified occurrence without affecting sibling events.
     *
     * @param array<int, mixed> $args
     * @param int $expectedNextTimestamp What nextScheduled() must report afterwards on
     *   WordPress builds whose unschedule primitive returns no status of its own.
     */
    public function unscheduleAt(int $timestamp, string $hook, array $args, int $expectedNextTimestamp): bool {
        if (!function_exists('wp_unschedule_event')) {
            $this->lastFailureDetail = 'wp_unschedule_event unavailable';
            $this->logWarning('Cannot unschedule cron hook ' . $hook . ': wp_unschedule_event unavailable.');
            return false;
        }
        $result = empty($args)
            ? wp_unschedule_event($timestamp, $hook, array(), true)
            : wp_unschedule_event($timestamp, $hook, $this->listArgs($args), true);
        if ($result === false || $this->isWpError($result)) {
            if ($this->inspector->requestedEventIsAbsent($hook, $args, $timestamp)) {
                return $this->reportAlreadySatisfied('removal of', $hook, $timestamp);
            }
            $errorMessage = $this->wpErrorMessage($result);
            $this->lastFailureDetail = $errorMessage !== '' ? $errorMessage : 'wp_unschedule_event returned false';
            $this->logWarning('Failed to unschedule cron hook ' . $hook . ' at timestamp ' . $timestamp
                . '. Detail: ' . $this->lastFailureDetail);
            return false;
        }
        if ($result === null && $this->nextScheduled($hook, $args) !== $expectedNextTimestamp) {
            $this->lastFailureDetail = 'event remained scheduled after wp_unschedule_event returned no status';
            $this->logWarning('Failed to verify cron hook removal for ' . $hook . ' at timestamp ' . $timestamp . '.');
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    public function scheduleDailyInWindowIfMissing(string $hook, int $startHour, int $endHour): bool {
        $startHour = max(0, min(23, $startHour));
        $endHour = max(0, min(23, $endHour));
        if ($endHour < $startHour) {
            $endHour = $startHour;
        }
        $hourRange = max(1, $endHour - $startHour + 1);
        $hour = $startHour + (random_int(0, 23) % $hourRange);
        $timeForEvent = sprintf(
            '%02d:%02d:%02d',
            $hour,
            random_int(10, 59),
            random_int(10, 59)
        );
        // The requested [$startHour, $endHour] window is a WP-site-local
        // off-peak window (e.g. "0-5am, when this site has the least
        // traffic"). wp_schedule_event() below compares the resulting
        // timestamp against WP-Cron's true-UTC clock, so the wall-clock
        // hour must be anchored to the site's configured timezone
        // (SiteTimezone) rather than PHP's implicit default timezone --
        // otherwise the "local off-peak" window silently lands at the
        // wrong local hour whenever the two timezones differ (e.g. a
        // managed host running PHP in UTC for a site configured to
        // America/Los_Angeles).
        try {
            $timestamp = (new DateTimeImmutable('today ' . $timeForEvent, ABJ_404_Solution_SiteTimezone::resolve()))->getTimestamp();
        } catch (Exception $e) {
            $this->logScheduleFailure('recurring', $hook, 'daily', 0, array(), 'failed to calculate daily schedule timestamp: ' . $e->getMessage());
            return false;
        }
        if ($this->nextScheduled($hook) !== false) {
            return true;
        }
        return $this->scheduleRecurringAt($hook, 'daily', $timestamp);
    }

    /**
     * @param array<int, mixed> $args
     * @return void
     */
    public function clearHook(string $hook, array $args = array()): void {
        if (!function_exists('wp_clear_scheduled_hook')) {
            $this->logWarning('Cannot clear cron hook ' . $hook . ': wp_clear_scheduled_hook unavailable.');
            return;
        }
        empty($args) ? wp_clear_scheduled_hook($hook) : wp_clear_scheduled_hook($hook, $this->listArgs($args));
    }

    /**
     * @param array<int, mixed> $args
     * @return void
     */
    public function unscheduleAllOccurrences(string $hook, array $args = array()): void {
        if (!function_exists('wp_unschedule_event')) {
            $this->logWarning('Cannot unschedule cron hook ' . $hook . ': wp_unschedule_event unavailable.');
            return;
        }
        $timestamp = $this->nextScheduled($hook, $args);
        while ($timestamp !== false) {
            empty($args) ? wp_unschedule_event($timestamp, $hook) : wp_unschedule_event($timestamp, $hook, $this->listArgs($args));
            $timestamp = $this->nextScheduled($hook, $args);
        }
    }

    /**
     * @param array<int, string>|null $hooks
     * @return void
     */
    public function clearRegisteredHooks(?array $hooks = null): void {
        foreach ($hooks ?? self::registeredHooks() as $hook) {
            $this->unscheduleAllOccurrences($hook);
            $this->unscheduleAllOccurrences($hook, array(''));
            $this->clearHook($hook);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function registeredHooks(): array {
        return array(
            self::HOOK_CLEANUP,
            self::HOOK_GSC_FETCH,
            self::HOOK_GSC_BACKGROUND_REFRESH,
            self::HOOK_UPDATE_PERMALINK_CACHE,
            self::HOOK_UPDATE_LOGS_HITS_TABLE,
            self::HOOK_SEND_DIGEST,
            self::HOOK_REBUILD_NGRAM_CACHE,
            self::HOOK_LOGSV2_CANONICAL_BACKFILL,
            self::HOOK_REDIRECTS_DENORM_BACKFILL,
            self::HOOK_REDIRECTS_SORT_KEY_BACKFILL,
            self::HOOK_SEND_QUEUED_REPORT,
            self::HOOK_REFRESH_STATUS_COUNTS,
            self::HOOK_REPAIR_COLLATIONS,
            self::HOOK_NETWORK_ACTIVATION,
            self::HOOK_NETWORK_ACTIVATION_BACKGROUND,
            self::HOOK_NETWORK_UPGRADE_BACKGROUND,
            self::HOOK_DUPLICATE_LEGACY,
            self::HOOK_REMOVE_DUPLICATES_LEGACY,
            self::HOOK_DELETE_OLD_REDIRECTS_LEGACY,
            self::HOOK_REBUILD_VIEW_DONE_LEGACY,
        );
    }

    /**
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleRecurringAt(string $hook, string $recurrence, int $timestamp, array $args = array()): bool {
        if (!function_exists('wp_schedule_event')) {
            $this->logScheduleFailure('recurring', $hook, $recurrence, $timestamp, $args, 'wp_schedule_event unavailable');
            return false;
        }
        $scheduled = wp_schedule_event($timestamp, $recurrence, $hook, $this->listArgs($args), true);
        if ($scheduled === false || $this->isWpError($scheduled)) {
            if ($this->inspector->requestedEventIsStored($hook, $args, $timestamp, $recurrence)) {
                return $this->reportAlreadySatisfied('recurring', $hook, $timestamp);
            }
            $this->logScheduleFailure('recurring', $hook, $recurrence, $timestamp, $args, $this->wpErrorMessage($scheduled));
            return false;
        }
        return true;
    }

    /**
     * Record that a write WordPress reported as failed had in fact already
     * been satisfied (see ABJ_404_Solution_ScheduledEventInspector) and report
     * success. Debug level on purpose: nothing is wrong, nothing needs doing,
     * and the line exists only so the race stays traceable in a debug log.
     *
     * @param string $type 'single', 'recurring' or 'removal of'.
     */
    private function reportAlreadySatisfied(string $type, string $hook, int $timestamp): bool {
        $this->lastFailureDetail = '';
        $this->logDebug(sprintf(
            'WordPress reported the %s cron write for %s at timestamp %d as failed, but the cron store '
            . 'already holds the requested state (a concurrent request wrote it first). Treating as scheduled.',
            $type,
            $hook,
            $timestamp
        ));
        return true;
    }

    private function timestampAfter(int $delaySeconds): int {
        return $this->clock->now() + max(0, $delaySeconds);
    }

    /**
     * @param array<int, mixed> $args
     * @return list<mixed>
     */
    private function listArgs(array $args): array {
        return array_values($args);
    }

    /** @param mixed $value */
    private function isWpError($value): bool {
        return function_exists('is_wp_error') && is_wp_error($value);
    }

    /** @param mixed $value */
    private function wpErrorMessage($value): string {
        if ($this->isWpError($value) && is_object($value) && method_exists($value, 'get_error_message')) {
            $message = $value->get_error_message();
            return is_string($message) ? $message : '';
        }
        return '';
    }

    /**
     * @param array<int, mixed> $args
     * @return void
     */
    private function logScheduleFailure(string $type, string $hook, ?string $recurrence, int $timestamp, array $args, string $detail): void {
        $this->lastFailureDetail = $detail;
        global $wpdb;
        $dbError = isset($wpdb) && isset($wpdb->last_error) && is_string($wpdb->last_error) && $wpdb->last_error !== ''
            ? $wpdb->last_error
            : 'none';
        $argsJson = json_encode($args);
        $argsText = is_string($argsJson) ? $argsJson : 'unencodable';
        $this->logError(sprintf(
            'Failed to schedule %s cron hook %s. Recurrence: %s, timestamp: %d, current: %d, args: %s, WP-Cron disabled: %s, DB error: %s, detail: %s',
            $type,
            $hook,
            $recurrence ?? 'single',
            $timestamp,
            $this->clock->now(),
            $argsText,
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no',
            $dbError,
            $detail
        ));
    }

    private function logError(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'errorMessage')) {
            $this->logger->errorMessage($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

    private function logDebug(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'debugMessage')) {
            $this->logger->debugMessage($message);
        }
    }

    private function logWarning(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
