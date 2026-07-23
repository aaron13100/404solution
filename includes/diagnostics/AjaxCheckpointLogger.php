<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Independent, minimal JSONL append-only logger for AJAX request checkpoints.
 *
 * Deliberately separate from ABJ_404_Solution_AjaxRequestTrace: every record
 * opens, locks, appends, flushes, unlocks, and closes the file immediately.
 * There is no pending/promotion state machine and no in-memory batching, so
 * a bug in the trace class under test (a stuck pending file, a rotation
 * failure, a construction exception) cannot erase this evidence. It is also
 * the channel ABJ_404_Solution_DiagnosticDirectoryProbe journals its
 * per-request round trip through, so a bug in the trace journal itself
 * cannot hide the probe's result.
 *
 * Every public method is failure-safe: it never lets an internal write
 * failure escape as an exception. around() re-throws only the wrapped
 * work's own exception, never a logging failure.
 *
 * This class is the journal's WRITER only. The read side -- the bounded
 * support excerpt, the collection-manifest source, and the whole-file
 * archive paths -- lives in ABJ_404_Solution_CheckpointJournalReader, which
 * depends on this class's directory resolution; nothing here ever calls it.
 */
final class ABJ_404_Solution_AjaxCheckpointLogger {

    const CHECKPOINT_FILE = ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE;
    const ROTATED_FILE = ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE;
    const LOCK_FILE = ABJ_404_Solution_CheckpointJournalWriter::LOCK_FILE;
    const MAX_CHECKPOINT_BYTES = ABJ_404_Solution_CheckpointJournalWriter::MAX_CHECKPOINT_BYTES;

    /** @var array<string, mixed>|null */
    private static $previousWriteTelemetry = null;

    /**
     * 1: full getrusage() array on every record.
     * 2: the diagnostic subset of it (see envelope()), which halves the cost
     *    of a record and therefore doubles how much of a failing session fits
     *    inside the support payload.
     * 3: host-pressure probes plus the preceding checkpoint write's own cost.
     * 4: a second record kind (see recordFrequent()) for the intra-stage
     *    per-query and per-row-batch channels, and an explicit `envelope`
     *    field on every record so which kind it is never has to be inferred.
     */
    const SCHEMA_VERSION = 4;

    /** A boundary record: the full environment sample described by envelope(). */
    const ENVELOPE_FULL = 'full';

    /**
     * A high-frequency record: identity and timing only. Named on the record
     * rather than left to inference, so a missing `rusage` reads as "this kind
     * of record does not carry one" and never as "getrusage() was unavailable".
     */
    const ENVELOPE_FREQUENT = 'frequent';

    /**
     * getrusage() keys worth carrying on every checkpoint, mapped to the names
     * they are written under.
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
     * Resolve the same directory ABJ_404_Solution_AjaxRequestTrace uses, via
     * the same filter, so checkpoints and the trace journal live side by
     * side and share one support-payload excerpt. Resolved independently
     * (not delegated to the trace class) so a bug there cannot take this
     * down too.
     *
     * @return string Empty string when unavailable.
     */
    public static function resolveDirectory(): string {
        try {
            $directory = self::resolveDirectoryPath();
            if ($directory === '') {
                return '';
            }
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return '';
            }
            return $directory;
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint directory resolution failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The path this channel resolves to BEFORE the usability check, with a
     * trailing separator, or '' when even the uploads directory is unknown.
     *
     * Split out of resolveDirectory() so a directory that could not be created
     * is still NAMEABLE in the support-collection manifest. Collapsing an
     * unusable path to '' is what made "the collector resolved somewhere it
     * cannot write" indistinguishable from "there was nothing to read".
     * Public for exactly that consumer:
     * ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource().
     */
    public static function resolveDirectoryPath(): string {
        $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
        if (function_exists('apply_filters')) {
            $directory = (string)apply_filters('abj404_ajax_trace_directory', $directory, array());
        }
        $directory = (string)$directory;
        return $directory === '' ? '' : rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Append one checkpoint record. Never throws.
     *
     * @param array<string, mixed> $fields
     */
    public static function record(string $requestId, string $event, array $fields = array()): void {
        try {
            $directory = self::resolveDirectory();
            if ($directory === '') {
                return;
            }
            self::writeRecord($directory, array_merge(self::envelope($requestId, $event), $fields));
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint record failed: ' . $e->getMessage());
        }
    }

    /**
     * Append one HIGH-FREQUENCY checkpoint record. Never throws.
     *
     * The intra-stage channels (per-query attribution, row-loop progress) emit
     * tens of records per request where the boundary channel emits one, so
     * they cannot afford the boundary envelope. Every full record samples
     * getrusage() AND ABJ_404_Solution_HostPressureSampler, which reads two
     * /proc files and scans $_SERVER twice; paying that per query would add
     * measurable syscall load to the very path being measured -- the exact
     * observer effect gap G2 raised about the recorder itself -- and would add
     * several hundred bytes per record to a support excerpt that is already
     * the scarce resource.
     *
     * What is kept is what a stall is actually read from: the two clocks, the
     * request ID that joins the record to everything else, the event name, and
     * the PID. Host pressure is still sampled ~27 times across the same
     * request by the boundary records these sit between.
     *
     * @param array<string, mixed> $fields
     */
    public static function recordFrequent(string $requestId, string $event, array $fields = array()): void {
        try {
            $directory = self::resolveDirectory();
            if ($directory === '') {
                return;
            }
            self::writeRecord($directory, array_merge(self::frequentEnvelope($requestId, $event), $fields));
        } catch (Throwable $e) {
            self::reportFailure('AJAX frequent checkpoint record failed: ' . $e->getMessage());
        }
    }

    /**
     * Record one boot lifecycle waypoint (Bruno timeout cause matrix, gap
     * G3): our own plugin file's first executable line, `plugins_loaded`,
     * `init`, `admin_init`, or admin-ajax action dispatch. Every record
     * carries the delta from REQUEST_TIME_FLOAT (via
     * ABJ_404_Solution_RequestEnvironmentFingerprint::bootDelta(), the same
     * formula request_start uses), so the gaps between consecutive
     * waypoints localize a slow boot to a phase instead of a single total,
     * and a request that dies before trace construction still has its boot
     * cost attributable from whichever waypoints it reached.
     *
     * Scope is gated by ABJ_404_Solution_AjaxRequestLedger::bootWaypointRequestId()
     * to our own table-AJAX and canary-ladder requests: never for ordinary
     * front-end page views, where the write cost would land on the hot 404
     * path. Never throws.
     */
    public static function recordBootWaypoint(string $event): void {
        try {
            $requestId = ABJ_404_Solution_AjaxRequestLedger::bootWaypointRequestId();
            if ($requestId === '') {
                return;
            }
            $now = self::nowFloat();
            // A waypoint with no clock is still worth recording: WHICH boot
            // phase was reached is the measurement, and the delta is the
            // refinement. Sending a stand-in number into bootDelta() would
            // publish a boot duration that no clock produced.
            self::record($requestId, $event, $now === null
                ? array('request_time_float' => null, 'boot_delta_ms' => null)
                : ABJ_404_Solution_RequestEnvironmentFingerprint::bootDelta($now));
        } catch (Throwable $e) {
            self::reportFailure('AJAX boot waypoint record failed (' . $event . '): ' . $e->getMessage());
        }
    }

    /**
     * Record a checkpoint pair (`${label}_start` / `${label}_end`) around a
     * unit of work and return its result. The end record always fires (a
     * finally block), and always carries elapsed_ms and status; the work's
     * own exception (if any) propagates to the caller unchanged. elapsed_ms
     * is null only when this process has no clock at all (see nowFloat()),
     * which is a different finding from a stage that took no measurable time.
     *
     * @template T
     * @param callable():T $work
     * @param array<string, mixed> $startFields
     * @return T
     */
    public static function around(string $requestId, string $label, callable $work, array $startFields = array()) {
        $startedAt = self::nowFloat();
        self::record($requestId, $label . '_start', $startFields);
        $status = 'complete';
        try {
            return $work();
        } catch (Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            self::record($requestId, $label . '_end', array(
                'status' => $status,
                'elapsed_ms' => self::elapsedMs($startedAt),
            ));
        }
    }

    /** @return array<string, mixed> */
    private static function envelope(string $requestId, string $event): array {
        $envelope = array(
            'schema_version' => self::SCHEMA_VERSION,
            'envelope' => self::ENVELOPE_FULL,
            'ts' => self::nowFloat(),
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'rusage' => self::resourceUsage(),
            'host_pressure' => class_exists('ABJ_404_Solution_HostPressureSampler')
                ? ABJ_404_Solution_HostPressureSampler::capture()
                : array('status' => 'unavailable', 'reason' => 'sampler_class_unavailable'),
        );
        // Host-WIDE pressure above; THIS SITE's own concurrency next. A
        // per-account worker cap (LiteSpeed/CloudLinux LVE) throttles a site
        // whose box looks idle, so the two answer different questions and a
        // record carrying only the first cannot tell them apart. The census
        // owns the shape of its own contribution; see
        // ABJ_404_Solution_SameSiteRequestCensus::checkpointFields().
        $envelope += class_exists('ABJ_404_Solution_SameSiteRequestCensus')
            ? ABJ_404_Solution_SameSiteRequestCensus::checkpointFields()
            : array('same_site_requests' => -1);
        $envelope['previous_checkpoint_write'] = self::previousWriteTelemetry($requestId);
        $envelope['request_id'] = $requestId;
        $envelope['event'] = $event;
        $envelope['pid'] = getmypid();
        return $envelope;
    }

    /**
     * The reduced envelope described by recordFrequent().
     *
     * @return array<string, mixed>
     */
    private static function frequentEnvelope(string $requestId, string $event): array {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'envelope' => self::ENVELOPE_FREQUENT,
            'ts' => self::nowFloat(),
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'request_id' => $requestId,
            'event' => $event,
            'pid' => getmypid(),
        );
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

    /** @return array<string, mixed> */
    private static function previousWriteTelemetry(string $requestId): array {
        $previous = self::$previousWriteTelemetry;
        if (!is_array($previous) || ($previous['request_id'] ?? '') !== $requestId) {
            return array('status' => 'unavailable', 'reason' => 'no_previous_write');
        }
        return $previous;
    }

    /** @param array<string, mixed> $record */
    private static function writeRecord(string $directory, array $record): void {
        self::$previousWriteTelemetry = ABJ_404_Solution_CheckpointJournalWriter::append($directory, $record);
    }

    /**
     * Seconds as a float from the clock seam, or null when this process has
     * no clock at all. Three states, because this logger runs in all three:
     *
     *  1. Container up: abj_clock(), so FrozenClock drives it in tests.
     *  2. Boot window: 404-solution.php records `boot_plugin_entry` right
     *     after spl_autoload_register(), long before Loader.php requires
     *     service-locator.php. SystemClock is what abj_clock() would return
     *     there anyway, so this is the same reading, not a second source.
     *  3. Neither: a corrupt plugin directory (the safe autoloader returns
     *     SILENTLY for a missing class) or the response-tail subprocess
     *     probe, whose file set has no clock in it. Constructing SystemClock
     *     fatals there, and a logger built to keep recording while the rest
     *     of the stack is broken must not be what kills the request. Null
     *     instead, so an absent `ts` reads as "no clock was reachable"
     *     rather than as a fabricated timestamp; hrtime_ns, pid and the
     *     request id still identify the record.
     *
     * Mirrors ABJ_404_Solution_SameSiteRequestCensus::nowFloat(), and is
     * deliberately inline rather than a shared helper class: such a class
     * would be one more file that has to exist for state 3 to work.
     */
    /**
     * Milliseconds since $startedAt, or null when either end of the interval
     * had no clock to read. Never a number derived from only one reading.
     */
    private static function elapsedMs(?float $startedAt): ?int {
        if ($startedAt === null) {
            return null;
        }
        $now = self::nowFloat();
        return $now === null ? null : max(0, (int)round(($now - $startedAt) * 1000));
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function reportFailure(string $message): void {
        // Unconditional: abj404_logPhpFallback() is defined at plugin entry
        // (404-solution.php), before any class here can be autoloaded, so a raw
        // error_log() second sink was unreachable and made this file an
        // offender in the centralized-error-log audit.
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
