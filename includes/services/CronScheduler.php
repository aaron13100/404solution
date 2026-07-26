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

    /** @var string */
    private $lastFailureDetail = '';

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct(ABJ_404_Solution_Clock $clock, $logger = null) {
        $this->clock = $clock;
        $this->logger = $logger;
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
     * Ensures a recurring event exists with recurrence EXACTLY `daily`,
     * migrating any pre-existing event scheduled at a different recurrence
     * (e.g. a stale `weekly` event left over from before a hook was
     * normalized onto a fixed cadence -- see EmailDigest::scheduleNextDigest()
     * and WP.org support topic weekly-digest-3). Without this, a site
     * upgrading from a build that scheduled `abj404_send_digest` at
     * `weekly` recurrence would keep that stale recurrence forever:
     * scheduleRecurringIfMissing()'s `!wp_next_scheduled` guard only checks
     * whether ANY event exists for the hook, not whether it matches the
     * intended cadence.
     *
     * Takes NO recurrence parameter by design: hardcoding the target here
     * makes it structurally impossible for a future caller to reintroduce
     * a variable-driven recurrence, which is the root shape of the
     * original bug (a WP-Cron event's own recurrence tied to a
     * user-configurable interval instead of a fixed, frequent trigger).
     *
     * @param array<int, mixed> $args
     * @return bool
     */
    public function scheduleDailyMigratingStaleRecurrence(string $hook, int $delaySeconds = 0, array $args = array()): bool {
        $current = $this->currentScheduledEvent($hook, $args);
        if ($current === null) {
            return $this->scheduleRecurringAt($hook, 'daily', $this->timestampAfter($delaySeconds), $args);
        }
        if ($current['recurrence'] === 'daily') {
            return true;
        }
        if ($current['recurrence'] === null) {
            $this->logWarning('Cannot migrate cron hook ' . $hook . ': existing recurrence is unavailable.');
            return false;
        }
        if (!function_exists('wp_unschedule_event')) {
            $this->logWarning('Cannot migrate cron hook ' . $hook . ': wp_unschedule_event unavailable.');
            return false;
        }

        $replacementTimestamp = $this->timestampAfter($delaySeconds);
        if (!function_exists('wp_get_scheduled_event')) {
            // WordPress 5.0's unschedule primitive returns void on success.
            // Put the replacement after the stale event so nextScheduled()
            // can verify that the old event was actually removed.
            $replacementTimestamp = max($replacementTimestamp, $current['timestamp'] + 1);
        } elseif ($replacementTimestamp === $current['timestamp']) {
            $replacementTimestamp++;
        }

        if (!$this->scheduleRecurringAt($hook, 'daily', $replacementTimestamp, $args)) {
            return false;
        }
        if ($this->unscheduleExact($current['timestamp'], $hook, $args, $replacementTimestamp)) {
            return true;
        }

        if (!$this->unscheduleExact($replacementTimestamp, $hook, $args, $current['timestamp'])) {
            $this->logWarning('Failed to roll back replacement cron hook ' . $hook . ' after stale-event removal failed.');
        }
        return false;
    }

    /**
     * @param array<int, mixed> $args
     * @return array{timestamp: int, recurrence: string|null}|null
     */
    private function currentScheduledEvent(string $hook, array $args = array()): ?array {
        if (function_exists('wp_get_scheduled_event')) {
            $event = empty($args)
                ? wp_get_scheduled_event($hook)
                : wp_get_scheduled_event($hook, $this->listArgs($args));
            if ($event === false) {
                return null;
            }
            if (!is_object($event) || !isset($event->timestamp) || !is_numeric($event->timestamp)) {
                return array('timestamp' => 0, 'recurrence' => null);
            }
            $recurrence = isset($event->schedule) && is_string($event->schedule) && $event->schedule !== ''
                ? $event->schedule
                : null;
            return array('timestamp' => (int)$event->timestamp, 'recurrence' => $recurrence);
        }

        $timestamp = $this->nextScheduled($hook, $args);
        if ($timestamp === false) {
            return null;
        }
        if (!function_exists('wp_get_schedule')) {
            return array('timestamp' => (int)$timestamp, 'recurrence' => null);
        }
        $schedule = empty($args) ? wp_get_schedule($hook) : wp_get_schedule($hook, $this->listArgs($args));
        return array(
            'timestamp' => (int)$timestamp,
            'recurrence' => is_string($schedule) && $schedule !== '' ? $schedule : null,
        );
    }

    /**
     * Removes one identified occurrence without affecting sibling events.
     *
     * @param array<int, mixed> $args
     */
    private function unscheduleExact(int $timestamp, string $hook, array $args, int $expectedNextTimestamp): bool {
        $result = empty($args)
            ? wp_unschedule_event($timestamp, $hook, array(), true)
            : wp_unschedule_event($timestamp, $hook, $this->listArgs($args), true);
        if ($result === false || $this->isWpError($result)) {
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

    /** @return array<mixed, mixed> */
    public function readyCronJobs(): array {
        if (!function_exists('wp_get_ready_cron_jobs')) {
            return array();
        }
        $ready = wp_get_ready_cron_jobs();
        return is_array($ready) ? $ready : array();
    }

    /**
     * @param array<int, mixed> $args
     * @return bool
     */
    private function scheduleRecurringAt(string $hook, string $recurrence, int $timestamp, array $args = array()): bool {
        if (!function_exists('wp_schedule_event')) {
            $this->logScheduleFailure('recurring', $hook, $recurrence, $timestamp, $args, 'wp_schedule_event unavailable');
            return false;
        }
        $scheduled = wp_schedule_event($timestamp, $recurrence, $hook, $this->listArgs($args), true);
        if ($scheduled === false || $this->isWpError($scheduled)) {
            $this->logScheduleFailure('recurring', $hook, $recurrence, $timestamp, $args, $this->wpErrorMessage($scheduled));
            return false;
        }
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

    private function logWarning(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
