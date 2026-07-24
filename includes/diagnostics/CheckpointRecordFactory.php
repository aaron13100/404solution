<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shapes the three schema-versioned checkpoint journal record kinds.
 *
 * This class performs no journal or directory I/O. The logger owns lifecycle,
 * phase timing, ordering, and failure handling; this factory owns the compact
 * on-disk representation those operations persist.
 */
final class ABJ_404_Solution_CheckpointRecordFactory {

    const SCHEMA_VERSION = 5;
    const ENVELOPE_FULL = 'full';
    const ENVELOPE_FREQUENT = 'frequent';
    const ENVELOPE_INTENT = 'intent';

    /**
     * getrusage() keys worth carrying on every full checkpoint, mapped to the
     * compact names written to the journal.
     *
     * The full 17-key array was the single largest thing in the journal: 305
     * of the 545 bytes an average record occupied, repeated on all 27 records
     * of every request, most of it fields that are structurally zero on Linux
     * (ixrss/idrss/isrss/nswap) or irrelevant to a stall (msgsnd/msgrcv/
     * nsignals). What survives is what a stall is actually diagnosed with:
     * the user/system CPU split (CPU burn vs blocked), resident memory,
     * voluntary vs involuntary context switches (blocked-on-IO vs preempted,
     * the signature of host-level throttling), page faults, and block IO.
     */
    const RUSAGE_FIELDS = array(
        'maxrss' => 'ru_maxrss',
        'minflt' => 'ru_minflt',
        'majflt' => 'ru_majflt',
        'nvcsw' => 'ru_nvcsw',
        'nivcsw' => 'ru_nivcsw',
        'inblock' => 'ru_inblock',
        'oublock' => 'ru_oublock',
    );

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function full(array $context): array {
        $record = array(
            'schema_version' => self::SCHEMA_VERSION,
            'envelope' => self::ENVELOPE_FULL,
            'ts' => $context['ts'] ?? null,
            'hrtime_ns' => $context['hrtime_ns'] ?? null,
            'rusage' => self::resourceUsage(),
            'host_pressure' => $context['host_pressure'] ?? array(
                'status' => 'unavailable',
                'reason' => 'sampler_result_unavailable',
            ),
        );
        // Host-WIDE pressure above; THIS SITE's own concurrency next. A
        // per-account worker cap (LiteSpeed/CloudLinux LVE) throttles a site
        // whose box looks idle, so the two answer different questions and a
        // record carrying only the first cannot tell them apart. The census
        // owns the shape of its own contribution; see
        // ABJ_404_Solution_SameSiteRequestCensus::checkpointFields().
        $record += class_exists('ABJ_404_Solution_SameSiteRequestCensus')
            ? ABJ_404_Solution_SameSiteRequestCensus::checkpointFields()
            : array('same_site_requests' => -1);
        $record['previous_checkpoint_write'] = $context['previous_checkpoint_write'];
        $record['request_id'] = $context['request_id'];
        $record['event'] = $context['event'];
        $record['checkpoint_id'] = $context['checkpoint_id'];
        $record['pid'] = $context['pid'];
        return $record;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function intent(array $context): array {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'envelope' => self::ENVELOPE_INTENT,
            'hrtime_ns' => $context['hrtime_ns'] ?? null,
            'request_id' => $context['request_id'],
            'event' => 'checkpoint_intent',
            'intended_event' => $context['event'],
            'checkpoint_id' => $context['checkpoint_id'],
            'pid' => $context['pid'],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function frequent(array $context): array {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'envelope' => self::ENVELOPE_FREQUENT,
            'ts' => $context['ts'] ?? null,
            'hrtime_ns' => $context['hrtime_ns'] ?? null,
            'request_id' => $context['request_id'],
            'event' => $context['event'],
            'pid' => $context['pid'],
        );
    }

    /**
     * Shape the completed call telemetry embedded in the next full record.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function completedWriteTelemetry(array $context): array {
        $write = is_array($context['write'] ?? null) ? $context['write'] : array();
        $intent = is_array($context['intent'] ?? null) ? $context['intent'] : array();
        $phases = is_array($context['phases_us'] ?? null) ? $context['phases_us'] : array();
        $telemetry = array(
            'status' => is_string($write['status'] ?? null) ? $write['status'] : 'failed',
            'request_id' => $context['request_id'],
            'event' => $context['event'],
            'checkpoint_id' => $context['checkpoint_id'],
            'elapsed_us' => is_numeric($phases['append'] ?? null) ? max(0, (int)$phases['append']) : 0,
            'total_us' => is_numeric($context['total_us'] ?? null) ? max(0, (int)$context['total_us']) : 0,
            'phases_us' => $phases,
        );
        return array_merge($telemetry, self::failureDetails($write, $intent));
    }

    /**
     * @param array<string, mixed> $write
     * @param array<string, mixed> $intent
     * @return array<string, string>
     */
    private static function failureDetails(array $write, array $intent): array {
        $details = array();
        $intentStatus = is_string($intent['status'] ?? null) ? $intent['status'] : 'failed';
        if ($intentStatus !== 'complete') {
            $details['intent_status'] = $intentStatus;
        }
        if (is_string($write['reason'] ?? null) && $write['reason'] !== '') {
            $details['reason'] = $write['reason'];
        }
        if (is_string($intent['reason'] ?? null) && $intent['reason'] !== '') {
            $details['intent_reason'] = $intent['reason'];
        }
        return $details;
    }

    /**
     * The diagnostic subset of getrusage(), or null where it is unavailable.
     *
     * Absolute counters rather than deltas against a previous record: the
     * excerpt that carries these is allowed to drop records it cannot afford,
     * and a delta chain with a hole in it is unreadable, while an absolute
     * sample stays interpretable on its own. CPU times are folded into single
     * microsecond fields so the tv_sec/tv_usec pairs do not have to be
     * recombined by hand at read time.
     *
     * @return array<string, int>|null
     */
    private static function resourceUsage(): ?array {
        $rusage = function_exists('getrusage') ? getrusage() : null;
        if (!is_array($rusage)) {
            return null;
        }
        $usage = array(
            'utime_us' => self::microseconds($rusage, 'ru_utime'),
            'stime_us' => self::microseconds($rusage, 'ru_stime'),
        );
        foreach (self::RUSAGE_FIELDS as $name => $key) {
            if (isset($rusage[$key]) && is_numeric($rusage[$key])) {
                $usage[$name] = (int)$rusage[$key];
            }
        }
        return $usage;
    }

    /**
     * One getrusage() tv_sec/tv_usec pair as microseconds.
     *
     * @param array<string, mixed> $rusage
     */
    private static function microseconds(array $rusage, string $prefix): int {
        $seconds = isset($rusage[$prefix . '.tv_sec']) && is_numeric($rusage[$prefix . '.tv_sec'])
            ? (int)$rusage[$prefix . '.tv_sec'] : 0;
        $micros = isset($rusage[$prefix . '.tv_usec']) && is_numeric($rusage[$prefix . '.tv_usec'])
            ? (int)$rusage[$prefix . '.tv_usec'] : 0;
        return ($seconds * 1000000) + $micros;
    }
}
