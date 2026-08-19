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
                return array('timestamp' => 0, 'recurrence' => null);
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
     * The option cache is dropped before reading, because the write that
     * "failed" also left this request's cached copy of `cron` untouched and
     * therefore still blind to the event the other request stored.
     *
     * @param array<int, mixed> $args
     * @param string|null $recurrence Required recurrence, or null for a single event.
     */
    public function requestedEventIsStored(string $hook, array $args, int $timestamp, ?string $recurrence): bool {
        $this->forgetCachedCronOption();

        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event($hook, $this->listArgs($args), $timestamp);
            if (is_object($event)) {
                return $recurrence === null
                    || (isset($event->schedule) && $event->schedule === $recurrence);
            }
        }

        $next = $this->nextScheduledTimestamp($hook, $args);
        if ($next === false) {
            return false;
        }
        if ($recurrence !== null) {
            return $this->scheduledRecurrenceMatches($hook, $args, $recurrence);
        }
        // WordPress itself refuses to add a second identical event this close
        // to an existing one, so an event inside the window satisfies the
        // request exactly as the requested timestamp would have.
        return abs((int)$next - $timestamp) <= self::DUPLICATE_EVENT_WINDOW_SECONDS;
    }

    /**
     * Answer whether the event a removal targeted is gone from the cron store.
     *
     * The mirror image of {@see requestedEventIsStored}: wp_unschedule_event()
     * writes the cron array with the event taken out, so when another request
     * removed it first the write changes nothing and WordPress reports the same
     * `could_not_set` error for an event that is already gone.
     *
     * @param array<int, mixed> $args
     */
    public function requestedEventIsAbsent(string $hook, array $args, int $timestamp): bool {
        $this->forgetCachedCronOption();

        if (function_exists('wp_get_scheduled_event')) {
            return !is_object(wp_get_scheduled_event($hook, $this->listArgs($args), $timestamp));
        }
        return $this->nextScheduledTimestamp($hook, $args) !== $timestamp;
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
     * @param array<int, mixed> $args
     */
    private function scheduledRecurrenceMatches(string $hook, array $args, string $recurrence): bool {
        if (!function_exists('wp_get_schedule')) {
            return false;
        }
        $schedule = empty($args)
            ? wp_get_schedule($hook)
            : wp_get_schedule($hook, $this->listArgs($args));
        return is_string($schedule) && $schedule === $recurrence;
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
    private function forgetCachedCronOption(): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete('cron', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * @param array<int, mixed> $args
     * @return list<mixed>
     */
    private function listArgs(array $args): array {
        return array_values($args);
    }
}
