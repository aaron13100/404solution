<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Independent, minimal JSONL append-only logger for AJAX request checkpoints.
 *
 * Deliberately separate from ABJ_404_Solution_AjaxRequestTrace: every normal
 * record opens, locks, appends, flushes, unlocks, and closes immediately. A
 * minimal intent lands first through ABJ_404_Solution_CheckpointIntentStore's
 * fixed system-temp sink, before uploads resolution can block. There is no
 * pending/promotion state machine or in-memory batching, so a bug in the trace
 * class under test cannot erase this evidence.
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

    /**
     * Compiled release marker. DiagnosticModuleManifestTest recomputes it
     * from canonical source and prevents a covered code change from shipping
     * with an old marker.
     */
    const DIAGNOSTIC_BUILD_ID = '55bf53685624aeb5204c697bdf2994dcd83e65e1';

    const CHECKPOINT_FILE = ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE;
    const ROTATED_FILE = ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE;
    const LOCK_FILE = ABJ_404_Solution_CheckpointJournalWriter::LOCK_FILE;
    const MAX_CHECKPOINT_BYTES = ABJ_404_Solution_CheckpointJournalWriter::MAX_CHECKPOINT_BYTES;

    /** @var array<string, mixed>|null */
    private static $previousWriteTelemetry = null;

    /** @var int */
    private static $checkpointSequence = 0;

    /**
     * Nesting depth for checkpoint persistence itself.
     *
     * Render-scope hook instrumentation uses this to avoid treating the
     * logger's own path-resolution filters as application render work. Without
     * the guard, every checkpoint can recursively install another set of hook
     * instrumentation records and exhaust the bounded evidence channel.
     *
     * @var int
     */
    private static $recordingDepth = 0;

    /**
     * 1: full getrusage() array on every record.
     * 2: the diagnostic subset of it (see envelope()), which halves the cost
     *    of a record and therefore doubles how much of a failing session fits
     *    inside the support payload.
     * 3: host-pressure probes plus the preceding checkpoint write's own cost.
     * 4: a second record kind (see recordFrequent()) for the intra-stage
     *    per-query and per-row-batch channels, and an explicit `envelope`
     *    field on every record so which kind it is never has to be inferred.
     * 5: pre-enrichment intent records and whole-call phase telemetry, so the
     *    recorder cannot charge its own probes to the operation under test or
     *    disappear without evidence when enrichment itself stalls.
     * 6: intents move to the independent system-temp sink before uploads
     *    resolution/filtering/creation, and frequent records gain exact
     *    intent correlation.
     * 7: foreign operations carry complete privacy-safe identity through a
     *    fixed-sink intent, the ordinary sink, and an armed/complete state.
     */
    const SCHEMA_VERSION = ABJ_404_Solution_CheckpointRecordFactory::SCHEMA_VERSION;

    /** A boundary record: the full environment sample described by envelope(). */
    const ENVELOPE_FULL = ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL;

    /**
     * A high-frequency record: identity and timing only. Named on the record
     * rather than left to inference, so a missing `rusage` reads as "this kind
     * of record does not carry one" and never as "getrusage() was unavailable".
     */
    const ENVELOPE_FREQUENT = ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FREQUENT;

    /** A minimal record written before full-envelope enrichment begins. */
    const ENVELOPE_INTENT = ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_INTENT;

    /**
     * Resolve the same directory ABJ_404_Solution_AjaxRequestTrace uses, via
     * the same filter, so checkpoints and the trace journal live side by
     * side and share one support-payload excerpt. The resolution itself lives
     * in ABJ_404_Solution_DiagnosticDirectoryResolver -- a leaf with no
     * dependencies of its own, so a bug in the trace class still cannot take
     * this channel down, and one request cannot pay for the same two filter
     * dispatches thousands of times.
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
        return ABJ_404_Solution_DiagnosticDirectoryResolver::resolve();
    }

    /**
     * Append one checkpoint record. Never throws.
     *
     * @param array<string, mixed> $fields
     */
    public static function record(string $requestId, string $event, array $fields = array()): void {
        if ($requestId === '') {
            return;
        }
        self::$recordingDepth++;
        try {
            $callStartedNs = self::monotonicNanoseconds();
            $checkpointId = self::checkpointId($callStartedNs);
            $intentWrite = self::appendIntent($requestId, $event, $checkpointId);
            $phaseStartedNs = self::monotonicNanoseconds();
            $directory = self::resolveDirectoryPath();
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::rememberResolvedDirectory(
                $requestId,
                $directory
            );
            $phases = array(
                'intent_append' => self::nonNegativeInt($intentWrite['elapsed_us'] ?? null),
                'directory_resolve' => self::elapsedMicroseconds($phaseStartedNs),
            );
            if ($directory === '') {
                return;
            }
            $phaseStartedNs = self::monotonicNanoseconds();
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return;
            }
            $phases['directory_create'] = self::elapsedMicroseconds($phaseStartedNs);

            $phaseStartedNs = self::monotonicNanoseconds();
            $hostPressure = class_exists('ABJ_404_Solution_HostPressureSampler')
                ? ABJ_404_Solution_HostPressureSampler::capture($requestId)
                : array('status' => 'unavailable', 'reason' => 'sampler_class_unavailable');
            $phases['host_pressure_probe'] = self::elapsedMicroseconds($phaseStartedNs);

            $phaseStartedNs = self::monotonicNanoseconds();
            $record = array_merge(
                $fields,
                ABJ_404_Solution_CheckpointRecordFactory::full(array(
                    'ts' => self::nowFloat(),
                    'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                    'host_pressure' => $hostPressure,
                    'previous_checkpoint_write' => self::previousWriteTelemetry($requestId),
                    'request_id' => $requestId,
                    'event' => $event,
                    'checkpoint_id' => $checkpointId,
                    'pid' => getmypid(),
                ))
            );
            $phases['envelope_build'] = self::elapsedMicroseconds($phaseStartedNs);

            $writeTelemetry = ABJ_404_Solution_CheckpointJournalWriter::append($directory, $record);
            $phases['append'] = self::nonNegativeInt($writeTelemetry['elapsed_us'] ?? null);
            self::$previousWriteTelemetry =
                ABJ_404_Solution_CheckpointRecordFactory::completedWriteTelemetry(array(
                'write' => $writeTelemetry,
                'intent' => $intentWrite,
                'request_id' => $requestId,
                'event' => $event,
                'checkpoint_id' => $checkpointId,
                'total_us' => self::elapsedMicroseconds($callStartedNs),
                'phases_us' => $phases,
            ));
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint record failed: ' . $e->getMessage());
        } finally {
            self::$recordingDepth = max(0, self::$recordingDepth - 1);
        }
    }

    /**
     * Append one HIGH-FREQUENCY checkpoint record. Never throws.
     *
     * The intra-stage channels (per-query attribution, row-loop progress) emit
     * tens of records per request where the boundary channel emits one, so
     * they cannot afford the boundary envelope. Every full record samples
     * getrusage() AND ABJ_404_Solution_HostPressureSampler, which reads procfs
     * and the process environment. The sampler request-caches its expensive
     * same-UID process walk, but paying even the remaining probes per query
     * would add measurable syscall load to the path being measured. That is
     * the observer-effect gap G2 raised about the recorder.
     * It would also add
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
        if ($requestId === '') {
            return;
        }
        self::$recordingDepth++;
        try {
            $checkpointId = self::checkpointId(self::monotonicNanoseconds());
            self::appendIntent($requestId, $event, $checkpointId);
            $directory = self::resolveDirectoryPath();
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::rememberResolvedDirectory(
                $requestId,
                $directory
            );
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::append(
                $requestId,
                $event,
                $fields,
                $directory,
                false,
                $checkpointId
            );
        } catch (Throwable $e) {
            self::reportFailure('AJAX frequent checkpoint record failed: ' . $e->getMessage());
        } finally {
            self::$recordingDepth = max(0, self::$recordingDepth - 1);
        }
    }

    /** True while this logger is persisting one of its own records. */
    public static function isRecording(): bool {
        return self::$recordingDepth > 0;
    }

    /**
     * Replace one fixed-size post-cap operation state. Never throws.
     *
     * The independent intent lands before directory filtering, creation, or
     * active-state file work. A recorder stall therefore remains distinct
     * from the late query/callback/cache operation this state identifies.
     * The active record reuses the intent's checkpoint ID so support
     * compaction removes only the exact intent that reached its durable end.
     *
     * The allowlist is the privacy boundary. Callers cannot accidentally put
     * SQL, URLs, cache values, or callback arguments into this file because
     * only the redacted identity fields below cross it.
     *
     * @param array<string, mixed> $fields
     */
    public static function recordActiveOperation(
        string $requestId,
        string $boundary,
        string $state,
        array $fields
    ): void {
        if ($requestId === '') {
            return;
        }
        ABJ_404_Solution_DurableOperationRecorder::recordActiveOperation(
            $requestId,
            $boundary,
            $state,
            $fields
        );
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
     * @param array<string, mixed>|null $endFields Fields populated by $work for the end record.
     * @return T
     */
    public static function around(
        string $requestId,
        string $label,
        callable $work,
        array $startFields = array(),
        ?array &$endFields = null
    ) {
        if ($requestId === '') {
            return $work();
        }
        self::record($requestId, $label . '_start', $startFields);
        $startedAt = self::nowFloat();
        $status = 'complete';
        try {
            return $work();
        } catch (Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            self::record($requestId, $label . '_end', array_merge($endFields ?? array(), array(
                'status' => $status,
                'elapsed_ms' => self::elapsedMs($startedAt),
            )));
        }
    }

    /** @return array<string, mixed> */
    private static function previousWriteTelemetry(string $requestId): array {
        $previous = self::$previousWriteTelemetry;
        if (!is_array($previous) || ($previous['request_id'] ?? '') !== $requestId) {
            return array('status' => 'unavailable', 'reason' => 'no_previous_write');
        }
        return $previous;
    }

    /** @return array<string, mixed> */
    private static function appendIntent(
        string $requestId,
        string $event,
        string $checkpointId
    ): array {
        return ABJ_404_Solution_CheckpointIntentStore::append(
            ABJ_404_Solution_CheckpointRecordFactory::intent(array(
                'request_id' => $requestId,
                'event' => $event,
                'checkpoint_id' => $checkpointId,
                'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                'pid' => getmypid(),
            ))
        );
    }

    private static function checkpointId(int $startedNs): string {
        self::$checkpointSequence++;
        $pid = getmypid();
        return self::alphabeticHex(is_int($pid) ? $pid : 0) . '-'
            . self::alphabeticHex($startedNs) . '-'
            . self::alphabeticHex(self::$checkpointSequence);
    }

    /**
     * Hex-shaped compactness without decimal substrings that can impersonate
     * a redacted numeric URL/id in diagnostic leak checks.
     */
    private static function alphabeticHex(int $value): string {
        return strtr(dechex($value), '0123456789abcdef', 'ghijklmnopqrstuv');
    }

    /** @param mixed $value */
    private static function nonNegativeInt($value): int {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private static function monotonicNanoseconds(): int {
        return function_exists('hrtime') ? (int)hrtime(true) : 0;
    }

    private static function elapsedMicroseconds(int $startedNs): int {
        return max(0, (int)round((self::monotonicNanoseconds() - $startedNs) / 1000));
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
