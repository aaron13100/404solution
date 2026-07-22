<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable write-ahead journal for one admin AJAX request.
 *
 * Each stage -- starting with request_start, the very first flushed write --
 * is appended and flushed to a request-local pending file before work
 * starts. Every request is ALWAYS promoted into one bounded, rotated JSONL
 * journal (matrix coverage req. 4): there is no fast-complete deletion, so a
 * successful request under the retention threshold cannot silently vanish
 * the way beta.1's trace did. Rotation is the only bound on retention.
 * A hard worker kill can skip PHP shutdown, so stale pending files are recovered
 * into the journal by a later request instead of losing the last started stage.
 */
final class ABJ_404_Solution_AjaxRequestTrace {

    const SHUTDOWN_INVENTORY_MARKER = 'abj404_ajax_shutdown_inventory.marker';
    const SCHEMA_VERSION = 1;

    /** Teardown sentinel armed with register_shutdown_function(): runs BELOW WordPress. */
    const MECHANISM_SHUTDOWN_FUNCTION = 'php_shutdown_function';
    /** Teardown sentinel armed as a WordPress 'shutdown' action callback. */
    const MECHANISM_WP_ACTION = 'wp_shutdown_action';
    /** Sentinel registered when the AJAX handler was entered. */
    const ARMED_HANDLER_ENTRY = 'handler_entry';
    /** Sentinel registered when the response was emitted, in finish(). */
    const ARMED_RESPONSE_TIME = 'response_time';

    /** @var array<string, scalar> */
    private $context;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var string */
    private $directory;
    /** @var ABJ_404_Solution_AjaxTraceJournal Durable storage + retention for this request's records. */
    private $journal;
    /** @var float */
    private $requestStartedAt;
    /** @var float|null */
    private $stageStartedAt = null;
    /** @var string */
    private $currentStage = '';
    /** @var array<string, scalar> */
    private $stageMetadata = array();
    /** @var bool */
    private $active = true;
    /** @var float|null Set when finish() runs; lets the teardown recorder measure PHP-shutdown lag after the response was logically complete. */
    private $responseEmittedAt = null;
    /** @var ABJ_404_Solution_ShutdownTeardownBracket Splits shutdown time into WordPress-action vs below-WordPress. */
    private $teardownBracket;
    /**
     * Guards the one-time-per-rotation $wp_filter['shutdown'] inventory.
     * Keyed by trace directory (not a single scalar) so unrelated trace
     * directories -- distinct sites, or distinct tests in the same worker
     * process -- never share a dedup decision.
     * @var array<string, string>
     */
    private static $shutdownInventoryCapturedForRotation = array();

    /**
     * Start tracing for an authorized AJAX request. Failure is non-fatal.
     *
     * @param array<string, mixed> $context
     * @return self|null
     */
    public static function start(array $context): ?self {
        try {
            $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
            if (function_exists('apply_filters')) {
                $directory = (string)apply_filters('abj404_ajax_trace_directory', $directory, $context);
            }
            if ($directory === '') {
                self::reportStaticFailure('AJAX trace uploads directory is unavailable.');
                return null;
            }
            if (!ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                self::reportStaticFailure('AJAX trace directory could not be created: ' . $directory);
                return null;
            }
            $trace = new self($context, rtrim($directory, '/\\') . DIRECTORY_SEPARATOR, abj_clock());
            $trace->journal->recoverAbandoned();
            // Handler-entry teardown sentinels. Multiple independent shutdown-time
            // hooks (two register_shutdown_function callbacks -- one armed here,
            // one armed again in finish() at response time -- plus WP 'shutdown'
            // action callbacks at the earliest and latest possible priority) so
            // that if one mechanism is itself skipped or broken, another still
            // produces evidence. None of them are disarmed by finish(); see
            // recordShutdown()'s docblock for the beta.1 defect this replaces.
            register_shutdown_function(array($trace, 'recordShutdown'));
            if (function_exists('add_action')) {
                add_action('shutdown', array($trace, 'recordShutdownActionEarly'), PHP_INT_MIN);
                add_action('shutdown', array($trace, 'recordShutdownActionLate'), PHP_INT_MAX);
            }
            return $trace;
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace initialization failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(array $context, string $directory, ABJ_404_Solution_Clock $clock) {
        $this->clock = $clock;
        $this->directory = $directory;
        $this->teardownBracket = new ABJ_404_Solution_ShutdownTeardownBracket();
        $this->requestStartedAt = $clock->nowFloat();
        $this->context = $this->normalizeContext($context);
        $stamp = str_replace('.', '', sprintf('%.6f', $this->requestStartedAt));
        $pendingPath = $directory . 'abj404_ajax_trace_'
            . $this->context['request_id'] . '_' . $this->context['part'] . '_'
            . $this->context['retry_count'] . '_' . getmypid() . '_' . $stamp . '.pending.jsonl';
        $this->journal = new ABJ_404_Solution_AjaxTraceJournal($directory, $pendingPath, $clock);

        // request_start MUST be the first flushed write for this request: it is
        // the evidence that the trace even started, before any stage runs. If
        // gathering the full field set itself throws, still flush a minimal
        // record rather than silently losing the "we got this far" signal.
        try {
            $this->appendRecord($this->buildRequestStartRecord($context));
        } catch (Throwable $e) {
            $this->appendRecord(array(
                'event' => 'request_start',
                'request_start_error' => substr($e->getMessage(), 0, 300),
            ));
        }
    }

    /**
     * The request_start record: the ledger/event fields this class owns,
     * merged with the process/build/runtime capture that
     * ABJ_404_Solution_RequestEnvironmentFingerprint owns.
     *
     * @param array<string, mixed> $rawContext
     * @return array<string, mixed>
     */
    private function buildRequestStartRecord(array $rawContext): array {
        $clientSentAtRaw = $rawContext['client_sent_at'] ?? '';
        $handlerClassRaw = $rawContext['handler_class'] ?? '';
        $handlerClass = is_scalar($handlerClassRaw) && (string)$handlerClassRaw !== '' ? (string)$handlerClassRaw : null;
        $environment = new ABJ_404_Solution_RequestEnvironmentFingerprint($this->clock);

        return array_merge(array(
            'event' => 'request_start',
            'client_sent_at' => is_scalar($clientSentAtRaw) ? substr((string)$clientSentAtRaw, 0, 64) : '',
        ), $environment->capture($handlerClass, 'abj404_trace_probe_' . $this->context['request_id']));
    }

    /** Begin and flush a stage before its work runs. */
    public function beginStage(string $stage): void {
        if (!$this->active) {
            return;
        }
        if ($this->currentStage !== '') {
            $this->endStage('superseded');
        }
        $this->currentStage = substr($stage, 0, 128);
        $this->stageStartedAt = $this->clock->nowFloat();
        $this->stageMetadata = array();
        $this->appendRecord(array('event' => 'stage_start', 'stage' => $this->currentStage));
    }

    /** @param array<string, scalar> $metadata */
    public function addStageMetadata(array $metadata): void {
        if (!$this->active || $this->currentStage === '') {
            return;
        }
        $changed = array();
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if ($key === 'db_timeout_mode') {
                $value = $this->strongestTimeoutMode((string)($this->stageMetadata[$key] ?? ''), (string)$value);
            }
            $value = is_string($value) ? substr($value, 0, 256) : $value;
            if (array_key_exists($key, $this->stageMetadata) && $this->stageMetadata[$key] === $value) {
                continue;
            }
            $this->stageMetadata[$key] = $value;
            $changed[$key] = $value;
        }
        if ($changed !== array()) {
            $startedAt = $this->stageStartedAt ?? $this->clock->nowFloat();
            $this->appendRecord(array_merge(array(
                'event' => 'stage_metadata',
                'stage' => $this->currentStage,
                'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
            ), $changed));
        }
    }

    public function endStage(string $status = 'complete'): void {
        if (!$this->active || $this->currentStage === '') {
            return;
        }
        $startedAt = $this->stageStartedAt ?? $this->clock->nowFloat();
        $record = array_merge(array(
            'event' => 'stage_end',
            'stage' => $this->currentStage,
            'status' => substr($status, 0, 32),
            'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
        ), $this->stageMetadata);
        $this->appendRecord($record);
        $this->currentStage = '';
        $this->stageStartedAt = null;
        $this->stageMetadata = array();
    }

    /**
     * Complete the request. Always promoted into the durable journal now
     * (matrix coverage req. 4): the prior <10s fast-complete delete is
     * removed permanently, so a rotation-bounded journal is the only bound
     * on retention. Arms a second, response-time-anchored teardown sentinel;
     * see recordShutdownAtResponseTime().
     */
    public function finish(string $status): void {
        if (!$this->active) {
            return;
        }
        if ($this->currentStage !== '') {
            $this->endStage($status === 'complete' ? 'complete' : 'error');
        }
        $now = $this->clock->nowFloat();
        $elapsedMs = max(0, (int)round(($now - $this->requestStartedAt) * 1000));
        $this->responseEmittedAt = $now;
        $this->appendRecord(array(
            'event' => 'request_end',
            'status' => substr($status, 0, 32),
            'elapsed_ms' => $elapsedMs,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'connection_aborted' => function_exists('connection_aborted') ? connection_aborted() : 0,
        ));
        $this->active = false;
        $this->journal->promote();
        register_shutdown_function(array($this, 'recordShutdownAtResponseTime'));
    }

    /**
     * Handler-entry teardown sentinel, armed once in start(). Beta.1's
     * defect: this no-op'd once finish() had run (`if (!$this->active)
     * return;`), so a slow sibling shutdown hook or a lingering
     * client-abort that stalled the worker AFTER the response was handed
     * off produced zero evidence -- exactly the gap that made beta.1's
     * trace come back empty. It always writes now; already_finished and
     * elapsed_since_response_emitted_ms tell a reader whether shutdown ran
     * promptly after finish() or something held the process open (cause G
     * in the timeout matrix).
     */
    public function recordShutdown(): void {
        $this->recordTeardown('shutdown', self::MECHANISM_SHUTDOWN_FUNCTION, self::ARMED_HANDLER_ENTRY);
    }

    /** Response-time teardown sentinel; armed a second time in finish(). */
    public function recordShutdownAtResponseTime(): void {
        $this->recordTeardown('shutdown_response_time', self::MECHANISM_SHUTDOWN_FUNCTION, self::ARMED_RESPONSE_TIME);
    }

    /**
     * WP 'shutdown' action at PHP_INT_MIN: the earliest possible read on
     * shutdown-time state, before any other plugin's own shutdown hook has
     * had a chance to run. Opens the WordPress-shutdown-action bracket; see
     * ABJ_404_Solution_ShutdownTeardownBracket.
     */
    public function recordShutdownActionEarly(): void {
        $this->teardownBracket->noteWpActionStart($this->clock->nowFloat());
        $this->recordTeardown('shutdown_action_min', self::MECHANISM_WP_ACTION, self::ARMED_HANDLER_ENTRY);
    }

    /**
     * WP 'shutdown' action at PHP_INT_MAX: fires after every other plugin's
     * default-priority shutdown hook has already run, so it can catch delay
     * or damage they caused that recordShutdownActionEarly could not see.
     * Closes the WordPress-shutdown-action bracket.
     */
    public function recordShutdownActionLate(): void {
        $this->teardownBracket->noteWpActionEnd($this->clock->nowFloat());
        $this->recordTeardown('shutdown_action_max', self::MECHANISM_WP_ACTION, self::ARMED_HANDLER_ENTRY);
    }

    /**
     * Shared teardown body for every shutdown-time sentinel. Never disarmed
     * by finish() and never throws -- a teardown recorder that itself can
     * fatal would defeat its own purpose.
     *
     * @param string $mechanism  One of the MECHANISM_* constants: which of the
     *                           two shutdown mechanisms invoked this sentinel.
     * @param string $armedAt    One of the ARMED_* constants: where the callback
     *                           was registered, which is what fixes its position
     *                           in PHP's registration-ordered shutdown queue.
     */
    private function recordTeardown(string $event, string $mechanism, string $armedAt): void {
        try {
            $lastError = error_get_last();
            $now = $this->clock->nowFloat();
            $record = array_merge(array(
                'event' => $event,
                'elapsed_ms' => max(0, (int)round(($now - $this->requestStartedAt) * 1000)),
                'elapsed_since_response_emitted_ms' => $this->responseEmittedAt !== null
                    ? max(0, (int)round(($now - $this->responseEmittedAt) * 1000))
                    : null,
                'already_finished' => !$this->active,
                'current_stage' => $this->currentStage,
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'connection_aborted' => function_exists('connection_aborted') ? connection_aborted() : 0,
                // connection_status() carries the TIMEOUT bit that
                // connection_aborted() cannot express, and session_status()
                // turning ACTIVE between request_start and teardown is the
                // evidence for a session write at shutdown (cause class G).
                'connection_status' => function_exists('connection_status') ? connection_status() : null,
                'session_status' => function_exists('session_status') ? session_status() : null,
                'php_error_type' => is_array($lastError) ? (int)$lastError['type'] : 0,
                'php_error_message' => is_array($lastError) ? substr((string)$lastError['message'], 0, 500) : '',
                'php_error_file' => is_array($lastError) ? (string)$lastError['file'] : '',
                'php_error_line' => is_array($lastError) ? (int)$lastError['line'] : 0,
            ), $this->teardownBracket->attribution(
                $mechanism, $armedAt, $mechanism === self::MECHANISM_SHUTDOWN_FUNCTION, $now));
            $this->appendRecord($record);
            $this->active = false;
            $this->journal->promote();
            $this->maybeRecordShutdownEnvironmentInventory();
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX teardown recorder failed (' . $event . '): ' . $e->getMessage());
        }
    }

    /**
     * Capture the shutdown-time environment
     * (ABJ_404_Solution_ShutdownEnvironmentInventory) once per journal rotation
     * rather than on every request: the roster and extension list are static
     * within a deploy, so per-request capture would only bloat the journal. The
     * rotated file's mtime stands in for "which rotation" -- a marker file
     * records the last rotation actually inventoried, and a same-process static
     * short-circuits repeat requests inside one worker.
     *
     * Called from every teardown sentinel, not just the WordPress-action one:
     * the case this inventory exists to explain (shutdown work that is not a
     * WordPress shutdown-action callback) includes the case where the
     * WordPress shutdown action never runs at all, and an inventory only that
     * action can write would be missing exactly then.
     */
    private function maybeRecordShutdownEnvironmentInventory(): void {
        $rotatedPath = $this->directory . ABJ_404_Solution_AjaxTraceJournal::ROTATED_FILE;
        $rotationMtime = @filemtime($rotatedPath);
        $rotationKey = is_int($rotationMtime) ? (string)$rotationMtime : 'never-rotated';
        if ((self::$shutdownInventoryCapturedForRotation[$this->directory] ?? null) === $rotationKey) {
            return;
        }
        $markerPath = $this->directory . self::SHUTDOWN_INVENTORY_MARKER;
        $existingMarker = @file_get_contents($markerPath);
        if ($existingMarker === $rotationKey) {
            self::$shutdownInventoryCapturedForRotation[$this->directory] = $rotationKey;
            return;
        }
        self::$shutdownInventoryCapturedForRotation[$this->directory] = $rotationKey;
        $this->appendRecord(array_merge(array(
            'event' => 'shutdown_hook_inventory',
            'rotation_key' => $rotationKey,
        ), ABJ_404_Solution_ShutdownEnvironmentInventory::capture()));
        @file_put_contents($markerPath, $rotationKey, LOCK_EX);
        // Promote here rather than relying on a later sentinel: the sentinel
        // that writes this may be the last one to run, and an unpromoted spool
        // waits 300 seconds for another request to recover it.
        $this->journal->promote();
    }

    /**
     * Wrap a record in this request's envelope (schema version, timestamp,
     * ledger context) and hand it to durable storage. Deciding what the
     * envelope contains is the trace's job; writing it durably is not.
     *
     * @param array<string, mixed> $record
     */
    private function appendRecord(array $record): void {
        $this->journal->append(array_merge($this->baseRecord(), $record));
    }

    /** @return array<string, scalar> */
    private function baseRecord(): array {
        return array_merge(array(
            'schema_version' => self::SCHEMA_VERSION,
            'ts' => $this->clock->nowFloat(),
        ), $this->context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{request_id: string, plugin_version: string, action: string, subpage: string, part: string, retry_count: int}
     */
    private function normalizeContext(array $context): array {
        $part = self::readScalarString($context, 'part', 'all');
        $retryCountRaw = $context['retry_count'] ?? 0;
        $retryCount = is_numeric($retryCountRaw) ? (int)$retryCountRaw : 0;
        return array(
            // Immutable request ledger (matrix coverage req. 1): the trace journal
            // is one of the channels a request ID must be recoverable from; the
            // others are the POST body / query string, the X-ABJ404-Request-ID
            // request/response headers, and error payloads (Ajax_GetPaginationLinks
            // + Ajax_AdminEndpointSupport). session_id and retry_parent_id ride
            // along so a retried request can be joined back to its parent attempt.
            'request_id' => self::readIdField($context, 'request_id', 'unknown00'),
            'plugin_version' => defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : 'unknown',
            'action' => substr(self::readScalarString($context, 'action'), 0, 64),
            'subpage' => substr(self::readScalarString($context, 'subpage'), 0, 64),
            'part' => substr($part, 0, 32),
            'retry_count' => max(0, min(2, $retryCount)),
            'session_id' => substr(self::readScalarString($context, 'session_id'), 0, 64),
            'retry_parent_id' => self::readIdField($context, 'retry_parent_id', ''),
            'header_request_id' => self::readIdField($context, 'header_request_id', ''),
            'cf_ray' => substr(self::readScalarString($context, 'cf_ray'), 0, 64),
        );
    }

    /** @param array<string, mixed> $context */
    private static function readScalarString(array $context, string $key, string $default = ''): string {
        $raw = $context[$key] ?? $default;
        return is_scalar($raw) ? (string)$raw : $default;
    }

    /** @param array<string, mixed> $context */
    private static function readIdField(array $context, string $key, string $fallback): string {
        $candidate = self::readScalarString($context, $key);
        return preg_match('/^[A-Za-z0-9]{8,64}$/', $candidate) === 1 ? $candidate : $fallback;
    }

    private function strongestTimeoutMode(string $current, string $incoming): string {
        $rank = array('' => 0, 'none' => 1, 'wrapped' => 2, 'unwrapped' => 3);
        return ($rank[$incoming] ?? 0) >= ($rank[$current] ?? 0) ? $incoming : $current;
    }

    private static function reportStaticFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
