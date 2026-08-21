<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns WordPress cron primitive access for plugin scheduling code.
 *
 * Domain services decide whether work is needed; this adapter owns hook names,
 * schedule checks, wall-clock offsets, and clearing/unscheduling around the
 * WordPress cron API.
 *
 * It performs the writes but does not judge them: what a refused write means,
 * and what gets said about it, belongs to
 * {@see ABJ_404_Solution_CronWriteOutcome} (whose reads go through
 * {@see ABJ_404_Solution_ScheduledEventInspector}), because WordPress reports
 * several already-satisfied outcomes as failures and believing them mails the
 * plugin author an ERROR about work that was already done.
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

    /** @var ABJ_404_Solution_CronWriteOutcome Decides what a refused write meant, and reports it. */
    private $outcome;

    /** @var ABJ_404_Solution_ScheduledEventInspector Read side of the cron store. */
    private $inspector;

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
        $this->inspector = $inspector !== null ? $inspector : new ABJ_404_Solution_ScheduledEventInspector();
        $this->outcome = new ABJ_404_Solution_CronWriteOutcome($logger, $this->inspector);
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
        return $this->outcome->lastFailureDetail();
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
     * Whether anything at all is queued for a hook, whatever arguments it
     * carries.
     *
     * The question {@see nextScheduled()} cannot answer: WordPress identifies
     * an event by hook AND arguments, so a no-args probe is blind to every
     * chain that carries a cursor or a counter in its args. Callers that arm a
     * self-rescheduling chain ask this first, so they recognize their own
     * in-flight link instead of queueing a second one beside it.
     *
     * @param string $hook
     * @return bool
     */
    public function hasAnyScheduledEvent(string $hook): bool {
        return $this->inspector->anyEventIsStored($hook);
    }

    /** Make the next cron-store observation read durable cross-request state. */
    public function refreshStoredEventReads(): void {
        $this->inspector->refreshCronStoreReads();
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
            $this->outcome->reportScheduleFailure(array(
                'type' => 'single',
                'hook' => $hook,
                'recurrence' => null,
                'timestamp' => $timestamp,
                'args' => $args,
                'errorCode' => 'cron_primitive_unavailable',
                'detail' => 'wp_schedule_single_event unavailable',
                'now' => $this->clock->now(),
            ));
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
        if ($this->outcome->reportsFailure($scheduled)) {
            return $this->outcome->resolveSingleWrite(array(
                'writeResult' => $scheduled,
                'hook' => $hook,
                'args' => $args,
                'timestamp' => $timestamp,
                'now' => $this->clock->now(),
            ));
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
        return $this->scheduleRecurringAt(array(
            'hook' => $hook,
            'recurrence' => $recurrence,
            'timestamp' => $this->timestampAfter($delaySeconds),
            'args' => $args,
        ));
    }

    /**
     * Removes one identified occurrence without affecting sibling events.
     *
     * @param array{timestamp: int, hook: string, args: array<int, mixed>, expectedNextTimestamp: int} $request
     *        expectedNextTimestamp is what nextScheduled() must report afterwards on
     *   WordPress builds whose unschedule primitive returns no status of its own.
     */
    public function unscheduleAt(array $request): bool {
        $timestamp = $request['timestamp'];
        $hook = $request['hook'];
        $args = $request['args'];
        $expectedNextTimestamp = $request['expectedNextTimestamp'];
        if (!function_exists('wp_unschedule_event')) {
            $this->outcome->reportUnavailablePrimitive(array(
                'verb' => 'unschedule',
                'hook' => $hook,
                'primitive' => 'wp_unschedule_event',
            ));
            return false;
        }
        $result = empty($args)
            ? wp_unschedule_event($timestamp, $hook, array(), true)
            : wp_unschedule_event($timestamp, $hook, $this->listArgs($args), true);
        if ($this->outcome->reportsFailure($result)) {
            return $this->outcome->resolveRemoval(array(
                'writeResult' => $result,
                'hook' => $hook,
                'args' => $args,
                'timestamp' => $timestamp,
            ));
        }
        if ($result === null && $this->nextScheduled($hook, $args) !== $expectedNextTimestamp) {
            return $this->outcome->reportRemovalNotVerified($hook, $timestamp);
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
            $this->outcome->reportScheduleFailure(array(
                'type' => 'recurring',
                'hook' => $hook,
                'recurrence' => 'daily',
                'timestamp' => 0,
                'args' => array(),
                'errorCode' => 'schedule_timestamp_calculation_failed',
                'detail' => 'failed to calculate daily schedule timestamp: ' . $e->getMessage(),
                'now' => $this->clock->now(),
            ));
            return false;
        }
        if ($this->nextScheduled($hook) !== false) {
            return true;
        }
        return $this->scheduleRecurringAt(array(
            'hook' => $hook,
            'recurrence' => 'daily',
            'timestamp' => $timestamp,
            'args' => array(),
        ));
    }

    /**
     * @param array<int, mixed> $args
     * @return void
     */
    public function clearHook(string $hook, array $args = array()): void {
        if (!function_exists('wp_clear_scheduled_hook')) {
            $this->outcome->reportUnavailablePrimitive(array(
                'verb' => 'clear',
                'hook' => $hook,
                'primitive' => 'wp_clear_scheduled_hook',
            ));
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
            $this->outcome->reportUnavailablePrimitive(array(
                'verb' => 'unschedule',
                'hook' => $hook,
                'primitive' => 'wp_unschedule_event',
            ));
            return;
        }
        $timestamp = $this->nextScheduled($hook, $args);
        while ($timestamp !== false) {
            $result = empty($args)
                ? wp_unschedule_event($timestamp, $hook, array(), true)
                : wp_unschedule_event($timestamp, $hook, $this->listArgs($args), true);

            // A refused removal leaves the occurrence exactly where it was, so
            // the next read returns the same timestamp and asking again can
            // only produce the same refusal. wp_unschedule_event() refuses on a
            // failed cron-store write, and since WordPress 5.7 any plugin on
            // the `pre_unschedule_event` filter can short-circuit it without
            // removing anything.
            if ($this->outcome->reportsFailure($result)) {
                $this->outcome->resolveRemoval(array(
                    'writeResult' => $result,
                    'hook' => $hook,
                    'args' => $args,
                    'timestamp' => $timestamp,
                ));
                return;
            }

            $next = $this->nextScheduled($hook, $args);

            // Terminate on lack of progress rather than on the primitive's
            // answer alone. A short-circuiting filter can return a truthy value
            // while removing nothing, and builds before 5.7 report no status at
            // all, so "it said it worked" is not evidence the occurrence is
            // gone. Advancing is.
            if ($next === $timestamp) {
                $this->outcome->reportRemovalNotVerified($hook, $timestamp);
                return;
            }

            $timestamp = $next;
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
     * @param array{hook: string, recurrence: string, timestamp: int, args?: array<int, mixed>} $request
     * @return bool
     */
    public function scheduleRecurringAt(array $request): bool {
        $hook = $request['hook'];
        $recurrence = $request['recurrence'];
        $timestamp = $request['timestamp'];
        $args = isset($request['args']) ? $request['args'] : array();
        if (!function_exists('wp_schedule_event')) {
            $this->outcome->reportScheduleFailure(array(
                'type' => 'recurring',
                'hook' => $hook,
                'recurrence' => $recurrence,
                'timestamp' => $timestamp,
                'args' => $args,
                'errorCode' => 'cron_primitive_unavailable',
                'detail' => 'wp_schedule_event unavailable',
                'now' => $this->clock->now(),
            ));
            return false;
        }
        $scheduled = wp_schedule_event($timestamp, $recurrence, $hook, $this->listArgs($args), true);
        if ($this->outcome->reportsFailure($scheduled)) {
            return $this->outcome->resolveRecurringWrite(array(
                'writeResult' => $scheduled,
                'hook' => $hook,
                'recurrence' => $recurrence,
                'args' => $args,
                'timestamp' => $timestamp,
                'now' => $this->clock->now(),
            ));
        }
        return true;
    }

    /**
     * The wall-clock second a delay of $delaySeconds lands on, measured against
     * the same clock every write here uses.
     *
     * Public so a caller that has to SAY which timestamp it asked for can hold
     * the one value and hand it to both {@see scheduleSingleAt()} and its own
     * diagnostics, rather than re-deriving it. A diagnostic that recomputes the
     * request it is describing is free to describe a request nobody made, which
     * is how a stalled n-gram rebuild reported a schedule time ten seconds out
     * while the chain had actually backed off (production report 294).
     *
     * @param int $delaySeconds Negative delays are clamped to now.
     */
    public function timestampAfter(int $delaySeconds): int {
        return $this->clock->now() + max(0, $delaySeconds);
    }

    /**
     * @param array<int, mixed> $args
     * @return list<mixed>
     */
    private function listArgs(array $args): array {
        return array_values($args);
    }
}
