<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moves a plugin cron hook onto a fixed `daily` recurrence, retiring whatever
 * event an older build left behind at a different one.
 *
 * A cadence policy rather than a cron primitive, which is why it lives beside
 * ABJ_404_Solution_CronScheduler instead of inside it: it decides WHICH event
 * should exist and in what order the replacement and the removal must happen,
 * then asks the scheduler to perform each step.
 *
 * Takes NO recurrence parameter by design: hardcoding the target here makes it
 * structurally impossible for a future caller to reintroduce a variable-driven
 * recurrence, which is the root shape of the original bug (a WP-Cron event's
 * own recurrence tied to a user-configurable interval instead of a fixed,
 * frequent trigger -- see EmailDigest::scheduleNextDigest() and WP.org support
 * topic weekly-digest-3). Without this migration, a site upgrading from a build
 * that scheduled `abj404_send_digest` at `weekly` recurrence would keep that
 * stale recurrence forever: scheduleRecurringIfMissing()'s next-scheduled guard
 * only checks whether ANY event exists for the hook, not whether it matches the
 * intended cadence.
 */
class ABJ_404_Solution_CronRecurrenceMigration {

    /** The one cadence this policy migrates hooks onto. */
    const TARGET_RECURRENCE = 'daily';

    /** @var ABJ_404_Solution_CronScheduler */
    private $scheduler;

    /** @var ABJ_404_Solution_ScheduledEventInspector */
    private $inspector;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /**
     * @param ABJ_404_Solution_CronScheduler $scheduler
     * @param ABJ_404_Solution_ScheduledEventInspector $inspector
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct(
        ABJ_404_Solution_CronScheduler $scheduler,
        ABJ_404_Solution_ScheduledEventInspector $inspector,
        $logger = null
    ) {
        $this->scheduler = $scheduler;
        $this->inspector = $inspector;
        $this->logger = $logger;
    }

    /**
     * Ensure the hook has an event whose recurrence is exactly `daily`.
     *
     * The replacement is scheduled BEFORE the stale event is removed, so a
     * request that dies between the two steps leaves the hook over-scheduled
     * rather than unscheduled. If the removal then fails, the replacement is
     * rolled back so the site is left exactly as it was found.
     *
     * @param array<int, mixed> $args
     * @return bool True when the hook is left running at the target recurrence.
     */
    public function ensureDailyRecurrence(string $hook, int $delaySeconds = 0, array $args = array()): bool {
        $lockKey = 'cron-recurrence-' . hash('sha256', serialize(array($hook, array_values($args))));
        $synchronizer = abj_service('sync_utils');
        $owner = $synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            if ($this->logger !== null && method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage('Cron recurrence migration already in progress for ' . $hook . '.');
            }
            return false;
        }

        try {
            return $this->ensureDailyRecurrenceWhileLocked($hook, $delaySeconds, $args);
        } finally {
            $synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }

    /**
     * Converge every exact hook/args event while the migration lock is held.
     *
     * @param array<int, mixed> $args
     */
    private function ensureDailyRecurrenceWhileLocked(string $hook, int $delaySeconds, array $args): bool {
        $events = $this->inspector->eventsForHook($hook, $args);
        if ($events === array()) {
            return $this->scheduler->scheduleRecurringAt(array(
                'hook' => $hook,
                'recurrence' => self::TARGET_RECURRENCE,
                'timestamp' => $this->scheduler->timestampAfter($delaySeconds),
                'args' => $args,
            ));
        }

        $dailyEvents = array_values(array_filter($events, static function(array $event): bool {
            return $event['recurrence'] === self::TARGET_RECURRENCE;
        }));
        if ($dailyEvents !== array()) {
            return $this->removeEventsExcept(array(
                'events' => $events,
                'keeper' => $dailyEvents[0],
                'hook' => $hook,
                'args' => $args,
            ));
        }

        $current = $events[0];
        if ($current['recurrence'] === null) {
            $this->logWarning('[CRON_RECURRENCE_UNAVAILABLE] Cannot migrate cron hook ' . $hook
                . ': existing recurrence is unavailable. Recovery: inspect and recreate the event in WP-Cron.');
            return false;
        }
        if (!function_exists('wp_unschedule_event')) {
            $this->logWarning('[CRON_UNSCHEDULE_UNAVAILABLE] Cannot migrate cron hook ' . $hook
                . ': wp_unschedule_event is unavailable. Recovery: restore the WordPress cron API and retry.');
            return false;
        }

        $replacementTimestamp = $this->replacementTimestamp(array(
            'delaySeconds' => $delaySeconds,
            'staleTimestamps' => array_values(array_map(static function(array $event): int {
                return $event['timestamp'];
            }, $events)),
        ));

        if (!$this->scheduler->scheduleRecurringAt(array(
            'hook' => $hook,
            'recurrence' => self::TARGET_RECURRENCE,
            'timestamp' => $replacementTimestamp,
            'args' => $args,
        ))) {
            return false;
        }
        $removedCount = 0;
        foreach ($events as $event) {
            if (!$this->scheduler->unscheduleAt(array(
                'timestamp' => $event['timestamp'],
                'hook' => $hook,
                'args' => $args,
                'expectedNextTimestamp' => $replacementTimestamp,
            ))) {
                break;
            }
            $removedCount++;
        }
        if ($removedCount === count($events)) {
            return true;
        }

        if ($removedCount > 0) {
            $this->logWarning('[CRON_PARTIAL_MIGRATION] Removed ' . $removedCount . ' stale event(s) for ' . $hook
                . ' before a later removal failed. The daily replacement was retained so the hook remains '
                . 'scheduled. Recovery: the next migration run will remove the remaining stale event(s).');
            return false;
        }

        if (!$this->scheduler->unscheduleAt(array(
            'timestamp' => $replacementTimestamp,
            'hook' => $hook,
            'args' => $args,
            'expectedNextTimestamp' => $current['timestamp'],
        ))) {
            $this->logWarning('[CRON_ROLLBACK_FAILED] Failed to roll back replacement cron hook ' . $hook
                . ' after stale-event removal failed. Recovery: inspect duplicate events in WP-Cron and keep the daily event.');
        }
        return false;
    }

    /**
     * Remove stale and duplicate events while retaining exactly one daily event.
     *
     * @param array{events: list<array{timestamp: int, recurrence: string|null}>, keeper: array{timestamp: int, recurrence: string|null}, hook: string, args: array<int, mixed>} $request
     */
    private function removeEventsExcept(array $request): bool {
        $keeperSkipped = false;
        foreach ($request['events'] as $event) {
            if (!$keeperSkipped && $event === $request['keeper']) {
                $keeperSkipped = true;
                continue;
            }
            if (!$this->scheduler->unscheduleAt(array(
                'timestamp' => $event['timestamp'],
                'hook' => $request['hook'],
                'args' => $request['args'],
                'expectedNextTimestamp' => $request['keeper']['timestamp'],
            ))) {
                $this->logWarning('[CRON_DUPLICATE_REMOVAL_FAILED] Could not remove a duplicate event for ' .
                    $request['hook'] . '. Recovery: inspect the hook in WP-Cron and keep one daily event.');
                return false;
            }
        }
        return true;
    }

    /**
     * The instant the replacement event goes at, kept distinct from the stale
     * event's own timestamp so the two can be told apart afterwards.
     */
    /** @param array{delaySeconds: int, staleTimestamps: list<int>} $request */
    private function replacementTimestamp(array $request): int {
        $timestamp = $this->scheduler->timestampAfter($request['delaySeconds']);
        if (!function_exists('wp_get_scheduled_event')) {
            if ($request['staleTimestamps'] === array()) {
                throw new InvalidArgumentException(
                    'Cron recurrence migration requires at least one stale event timestamp.'
                );
            }
            // WordPress 5.0's unschedule primitive returns void on success, so
            // the replacement has to sit AFTER every stale event for the
            // next-scheduled read to prove the old one was really removed.
            return max($timestamp, max($request['staleTimestamps']) + 1);
        }
        while (in_array($timestamp, $request['staleTimestamps'], true)) {
            $timestamp++;
        }
        return $timestamp;
    }

    private function logWarning(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
