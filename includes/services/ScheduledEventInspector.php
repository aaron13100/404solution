<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answers questions about what the WordPress cron store currently holds.
 *
 * The read half of plugin cron access, split from
 * {@see ABJ_404_Solution_CronScheduler} (which owns the writes). It exists as
 * its own module because a truthful answer is not simply "call
 * wp_next_scheduled()": within a request that has already written to the `cron`
 * option, or failed to, the cached copy WordPress reads back can disagree with
 * what is actually stored. This class owns that distinction, so callers cannot
 * accidentally decide something important from a stale read.
 *
 * It never writes. It depends on no other plugin service, so the scheduler can
 * depend on it without a cycle.
 */
class ABJ_404_Solution_ScheduledEventInspector {

    /**
     * WordPress's own duplicate-event window (10 * MINUTE_IN_SECONDS, see
     * wp_schedule_single_event() in wp-includes/cron.php). An existing event
     * this close to a requested one is a duplicate as far as WordPress is
     * concerned, so it also satisfies the request.
     */
    const DUPLICATE_EVENT_WINDOW_SECONDS = 600;

    /**
     * Whether WordPress would treat an event already stored at
     * $storedTimestamp as a duplicate of one requested for
     * $requestedTimestamp -- and therefore whether the stored event already
     * satisfies that request.
     *
     * A port of the window wp_schedule_single_event() scans, and the reason
     * this is a method rather than one comparison: the window is NOT symmetric
     * around the requested run time, so `abs($stored - $requested) <= 600` is
     * wrong in both directions.
     *
     *   - Asking for a run within the next ten minutes drops the lower bound to
     *     timestamp 0, so EVERY overdue event for the hook is a duplicate. A
     *     site with DISABLE_WP_CRON and a job that never completes accumulates
     *     exactly those, hours or days old.
     *   - Asking for a run that has already passed raises the upper bound to
     *     now + ten minutes rather than requested + ten minutes.
     *
     * Getting this wrong does not merely mis-schedule: it makes the plugin
     * report WordPress's benign "A duplicate event already exists." refusal as
     * a scheduling failure, which is production report 284
     * (vishalborewell.com, signature b2c064ec15425cf2).
     *
     * @param array{storedTimestamp: int, requestedTimestamp: int, now: int} $window
     */
    public static function duplicateWindowCovers(array $window): bool {
        $storedTimestamp = $window['storedTimestamp'];
        $requestedTimestamp = $window['requestedTimestamp'];
        $now = $window['now'];
        $minTimestamp = ($requestedTimestamp < $now + self::DUPLICATE_EVENT_WINDOW_SECONDS)
            ? 0
            : $requestedTimestamp - self::DUPLICATE_EVENT_WINDOW_SECONDS;
        $maxTimestamp = ($requestedTimestamp < $now)
            ? $now + self::DUPLICATE_EVENT_WINDOW_SECONDS
            : $requestedTimestamp + self::DUPLICATE_EVENT_WINDOW_SECONDS;
        return $storedTimestamp >= $minTimestamp && $storedTimestamp <= $maxTimestamp;
    }

    /**
     * The event currently scheduled for a hook, with its recurrence.
     *
     * A null `recurrence` means "an event exists but this WordPress build
     * cannot report its schedule", which callers must treat differently from
     * "no event exists" (null return).
     *
     * @param array<int, mixed> $args
     * @return array{timestamp: int, recurrence: string|null}|null
     */
    public function currentEvent(string $hook, array $args = array()): ?array {
        if (function_exists('wp_get_scheduled_event')) {
            $event = empty($args)
                ? wp_get_scheduled_event($hook)
                : wp_get_scheduled_event($hook, $this->listArgs($args));
            if ($event === false) {
                return null;
            }
            if (!is_object($event) || !isset($event->timestamp) || !is_numeric($event->timestamp)) {
                throw new UnexpectedValueException(
                    'wp_get_scheduled_event returned a malformed event for cron hook ' . $hook
                    . ': expected an object with a numeric timestamp.'
                );
            }
            $recurrence = isset($event->schedule) && is_string($event->schedule) && $event->schedule !== ''
                ? $event->schedule
                : null;
            return array('timestamp' => (int)$event->timestamp, 'recurrence' => $recurrence);
        }

        $timestamp = $this->nextScheduledTimestamp($hook, $args);
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
     * Return every stored event for the exact hook/argument identity, ordered
     * by timestamp. This is the convergence read used by recurrence migration:
     * wp_get_scheduled_event() exposes only the next event and cannot reveal a
     * stale recurrence sitting beside a valid replacement.
     *
     * @param array<int, mixed> $args
     * @return list<array{timestamp: int, recurrence: string|null}>
     */
    public function eventsForHook(string $hook, array $args = array()): array {
        if (!function_exists('_get_cron_array')) {
            $current = $this->currentEvent($hook, $args);
            return $current === null ? array() : array($current);
        }

        $cron = _get_cron_array();
        if (!is_array($cron)) {
            throw new UnexpectedValueException('WordPress returned a malformed cron array for hook ' . $hook . '.');
        }

        $targetArgs = $this->listArgs($args);
        $matches = array();
        foreach ($cron as $timestamp => $eventsByHook) {
            if (!is_numeric($timestamp) || !is_array($eventsByHook) || !isset($eventsByHook[$hook])) {
                continue;
            }
            if (!is_array($eventsByHook[$hook])) {
                throw new UnexpectedValueException('WordPress returned malformed events for cron hook ' . $hook . '.');
            }
            foreach ($eventsByHook[$hook] as $event) {
                if (!is_array($event)) {
                    throw new UnexpectedValueException('WordPress returned a malformed event for cron hook ' . $hook . '.');
                }
                if (!isset($event['args']) || !is_array($event['args'])) {
                    throw new UnexpectedValueException(
                        'WordPress returned an event with malformed args for cron hook ' . $hook . '.'
                    );
                }
                $eventArgs = $this->listArgs($event['args']);
                if ($eventArgs !== $targetArgs) {
                    continue;
                }
                $matches[] = array(
                    'timestamp' => (int)$timestamp,
                    'recurrence' => isset($event['schedule']) && is_string($event['schedule'])
                        && $event['schedule'] !== '' ? $event['schedule'] : null,
                );
            }
        }

        usort($matches, static function(array $left, array $right): int {
            return $left['timestamp'] <=> $right['timestamp'];
        });
        return $matches;
    }

    /**
     * Answer whether the cron store already holds an event that was just
     * requested, after a write WordPress reported as failed.
     *
     * WordPress reports a cron write as failed whenever
     * `update_option('cron', ...)` returns false, and option.php returns false
     * for a write that changed NOTHING just as readily as for one that could
     * not be performed: once for its own "the new and old values are the same"
     * short-circuit, and once because `$wpdb->update()` reports 0 affected rows
     * when the stored row already holds byte-identical content.
     *
     * That second case is a lost race, not a failure. Two requests a fraction
     * of a second apart both find the event missing, both build the identical
     * cron array, and the second one writes bytes that are already there. The
     * event the caller asked for exists; only the return value says otherwise.
     * Treating it as a failure reported production error 87a00a2680c1bc07 to
     * the plugin author once per collation-failing query -- fourteen identical
     * ERROR lines inside one second -- for work that had already been done.
     *
     * The caller must invoke {@see refreshCronStoreReads()} after the failed
     * write. Keeping the cache mutation explicit lets this method remain a
     * read while still ensuring it sees the durable state.
     *
     * @param array{hook: string, args: array<int, mixed>, timestamp: int, recurrence: string|null, now: int} $request
     */
    public function requestedEventIsStored(array $request): bool {
        $hook = $request['hook'];
        $args = $request['args'];
        $timestamp = $request['timestamp'];
        $recurrence = $request['recurrence'];
        $now = $request['now'];
        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event($hook, $this->listArgs($args), $timestamp);
            if ($event !== false) {
                $this->assertExactEventShape($event, $hook, $timestamp);
                return $recurrence === null
                    || (isset($event->schedule) && $event->schedule === $recurrence);
            }
        }

        if ($recurrence !== null && function_exists('_get_cron_array')) {
            foreach ($this->eventsForHook($hook, $args) as $event) {
                if ($event['timestamp'] === $timestamp) {
                    return $event['recurrence'] === $recurrence;
                }
            }
            return false;
        }

        $next = $this->nextScheduledTimestamp($hook, $args);
        if ($next === false) {
            return false;
        }
        if ($recurrence !== null) {
            return (int)$next === $timestamp && $this->scheduledRecurrenceMatches(array(
                'hook' => $hook,
                'args' => $args,
                'recurrence' => $recurrence,
            ));
        }
        // WordPress itself refuses to add a second identical event inside its
        // duplicate window, so an event in that window satisfies the request
        // exactly as one at the requested timestamp would have.
        return self::duplicateWindowCovers(array(
            'storedTimestamp' => (int)$next,
            'requestedTimestamp' => $timestamp,
            'now' => $now,
        ));
    }

    /**
     * Answer whether the cron store holds an event for a hook under ANY
     * arguments.
     *
     * The one cron question WordPress has no public API for. Every other read
     * -- wp_next_scheduled(), wp_get_scheduled_event(), wp_get_schedule() --
     * identifies an event by hook AND arguments, hashed as
     * md5(serialize($args)), so none of them can see a chain whose links carry
     * a cursor, an offset or an execution count in their args. Asking one of
     * them anyway is how a self-rescheduling chain fails to recognize itself:
     * ABJ_404_Solution_NGramCacheRebuildScheduler::armedRebuildTimestamp()
     * documents the same trap from the other side, and worked around it by
     * probing the two arg shapes that chain can hold.
     *
     * That workaround does not generalize. The permalink-cache chain's args are
     * `[max_execution_time - 5, executionCount]`, and BOTH move: the execution
     * count walks 2..15, and the budget is whatever ini_get() reports in the
     * request that armed the link, which a WP-Cron request and a front-end
     * request routinely disagree about. Enumerating the tuples would be
     * guessing; reading the store is not.
     *
     * `_get_cron_array()` is core's own accessor for exactly this (it is what
     * wp_next_scheduled() and wp_schedule_single_event() both read), but it is
     * a private function, so a build that does not provide it -- or that
     * answers with something other than an array -- falls back to the no-args
     * probe. That fallback can only under-report, which leaves the caller
     * asking WordPress for an event it may already hold: a benign duplicate
     * refusal this class already settles, and the behaviour that shipped before
     * this method existed. Failing the other way would strand a chain forever.
     *
     * This method does not invalidate WordPress's option cache on its own.
     * Callers whose correctness crosses requests must first call
     * refreshCronStoreReads(); callers making an advisory observation may keep
     * the cheaper process-local view.
     */
    public function anyEventIsStored(string $hook): bool {
        if (function_exists('_get_cron_array')) {
            $crons = _get_cron_array();
            if (is_array($crons)) {
                foreach ($crons as $eventsByHook) {
                    if (is_array($eventsByHook) && !empty($eventsByHook[$hook])) {
                        return true;
                    }
                }
                return false;
            }
        }
        return $this->nextScheduledTimestamp($hook, array()) !== false;
    }

    /**
     * Answer whether the event a removal targeted is gone from the cron store.
     *
     * The mirror image of {@see requestedEventIsStored}: wp_unschedule_event()
     * writes the cron array with the event taken out, so when another request
     * removed it first the write changes nothing and WordPress reports the same
     * `could_not_set` error for an event that is already gone.
     *
     * No duplicate window applies here, unlike its counterpart above:
     * wp_unschedule_event() targets one exact timestamp, so absence means "no
     * event at THAT timestamp" and nothing weaker.
     *
     * @param array{hook: string, args: array<int, mixed>, timestamp: int} $request
     */
    public function requestedEventIsAbsent(array $request): bool {
        $hook = $request['hook'];
        $args = $request['args'];
        $timestamp = $request['timestamp'];
        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event($hook, $this->listArgs($args), $timestamp);
            if ($event === false) {
                return true;
            }
            $this->assertExactEventShape($event, $hook, $timestamp);
            return false;
        }
        if (!function_exists('_get_cron_array')) {
            // WordPress 5.0 has _get_cron_array(); an environment that removes
            // both exact-read APIs cannot prove an exact removal succeeded.
            return false;
        }
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            throw new UnexpectedValueException(
                '_get_cron_array returned malformed data while verifying removal of cron hook '
                . $hook . ' at timestamp ' . $timestamp . '.'
            );
        }
        $argsKey = md5(serialize($this->listArgs($args)));
        return !isset($crons[$timestamp][$hook][$argsKey]);
    }

    /**
     * @param array<int, mixed> $args
     * @return int|false
     */
    private function nextScheduledTimestamp(string $hook, array $args) {
        if (!function_exists('wp_next_scheduled')) {
            return false;
        }
        return empty($args)
            ? wp_next_scheduled($hook)
            : wp_next_scheduled($hook, $this->listArgs($args));
    }

    /**
     * @param array{hook: string, args: array<int, mixed>, recurrence: string} $request
     */
    private function scheduledRecurrenceMatches(array $request): bool {
        $hook = $request['hook'];
        $args = $request['args'];
        $recurrence = $request['recurrence'];
        if (!function_exists('wp_get_schedule')) {
            return false;
        }
        $schedule = empty($args)
            ? wp_get_schedule($hook)
            : wp_get_schedule($hook, $this->listArgs($args));
        return is_string($schedule) && $schedule === $recurrence;
    }

    /**
     * Validate the contract of an exact-timestamp wp_get_scheduled_event()
     * lookup before its answer is allowed to excuse a failed cron write.
     *
     * @param mixed $event
     * @throws UnexpectedValueException when WordPress or a replacement returns
     *   a shape that cannot prove the requested event exists.
     */
    private function assertExactEventShape($event, string $hook, int $timestamp): void {
        if (!is_object($event) || !isset($event->timestamp) || !is_numeric($event->timestamp)) {
            throw new UnexpectedValueException(
                'wp_get_scheduled_event returned a malformed exact event for cron hook ' . $hook
                . ' at timestamp ' . $timestamp . ': expected an object with a numeric timestamp.'
            );
        }
        if ((int)$event->timestamp !== $timestamp) {
            throw new UnexpectedValueException(
                'wp_get_scheduled_event returned timestamp ' . (int)$event->timestamp
                . ' for exact cron hook lookup ' . $hook . ' at timestamp ' . $timestamp . '.'
            );
        }
    }

    /**
     * Drop the cached copy of the `cron` option so the next read comes from
     * durable storage.
     *
     * `cron` is autoloaded on a stock install, so it is served out of the
     * `alloptions` blob; a site that has flipped it to non-autoloaded caches it
     * under its own key, and a site that has never scheduled anything caches
     * its absence in `notoptions`. Same three keys, and the same reason, as
     * ABJ_404_Solution_FeedbackSiteTokenStore::clearOptionCaches().
     *
     * @return void
     */
    public function refreshCronStoreReads(): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete('cron', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * @param array<array-key, mixed> $args
     * @return list<mixed>
     */
    private function listArgs(array $args): array {
        return array_values($args);
    }
}
