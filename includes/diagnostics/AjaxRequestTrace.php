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
 *
 * This class owns the request's stages while it runs. What happens to the
 * process AFTER the response is complete belongs to
 * ABJ_404_Solution_AjaxTeardownRecorder, which the sentinels below delegate to.
 */
final class ABJ_404_Solution_AjaxRequestTrace implements ABJ_404_Solution_DiagnosticInternalHookObserver {

    const SCHEMA_VERSION = 1;

    /** @var array<string, scalar> */
    private $context;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
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
    /** @var ABJ_404_Solution_AjaxTeardownRecorder Owns everything after the response is complete. */
    private $teardownRecorder;
    /** @var ABJ_404_Solution_ShutdownCallbackTracer|null */
    private $shutdownCallbackTracer;
    /**
     * Every trace in this process whose PHP-shutdown sentinels are still
     * armed. The shutdown queue itself already keeps each of these traces
     * alive until process exit, so this registry adds no retention beyond
     * what register_shutdown_function() imposes; it exists so the test
     * harness can retire the sentinels of traces whose "request" (one
     * PHPUnit test) has already ended.
     * @var array<int, self>
     */
    private static $tracesWithArmedSentinels = array();

    /**
     * Start tracing for an authorized AJAX request. Failure is non-fatal.
     *
     * @param array<string, mixed> $context
     * @return self|null
     */
    public static function start(array $context): ?self {
        try {
            $directory = ABJ_404_Solution_DiagnosticDirectoryResolver::resolve($context);
            if ($directory === '') {
                self::reportStaticFailure('AJAX trace uploads directory is unavailable.');
                return null;
            }
            if (!ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                self::reportStaticFailure('AJAX trace directory could not be created: ' . $directory);
                return null;
            }
            $trace = new self($context, $directory, abj_clock());
            $trace->journal->recoverAbandoned();
            // Handler-entry and response-time sentinels preserve evidence if one
            // shutdown mechanism is skipped. The tracer brackets WordPress's
            // shutdown action and attributes its callbacks through shared hook
            // instrumentation. finish() does not disarm them; PHPUnit retires
            // them between simulated requests via disarmTeardownSentinelsForTests().
            register_shutdown_function(array($trace, 'recordShutdown'));
            self::$tracesWithArmedSentinels[] = $trace;
            if (function_exists('add_action')) {
                $trace->shutdownCallbackTracer = new ABJ_404_Solution_ShutdownCallbackTracer(
                    (string)($trace->context['request_id'] ?? ''), rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
                );
                $trace->shutdownCallbackTracer->registerSentinels(array($trace, 'recordShutdownActionEarly'),
                    array($trace, 'recordShutdownActionLate'));
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
        $this->requestStartedAt = $clock->nowFloat();
        $this->context = $this->normalizeContext($context);
        $stamp = str_replace('.', '', sprintf('%.6f', $this->requestStartedAt));
        $pendingPath = $directory . 'abj404_ajax_trace_'
            . $this->context['request_id'] . '_' . $this->context['part'] . '_'
            . $this->context['retry_count'] . '_' . getmypid() . '_' . $stamp . '.pending.jsonl';
        $this->journal = new ABJ_404_Solution_AjaxTraceJournal($directory, $pendingPath, $clock);
        $this->teardownRecorder = new ABJ_404_Solution_AjaxTeardownRecorder(
            $clock, $directory, $this->journal);

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
     * Complete the request. Promotion uses the journal's bounded try-lock;
     * on contention the support-readable pending spool stays intact for a
     * shutdown retry after detachment. Arms a response-time-anchored teardown
     * sentinel; see recordShutdownAtResponseTime().
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
        if ($this->shutdownCallbackTracer !== null) {
            $this->shutdownCallbackTracer->arm();
        }
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
        $this->recordTeardown('shutdown',
            ABJ_404_Solution_AjaxTeardownRecorder::MECHANISM_SHUTDOWN_FUNCTION,
            ABJ_404_Solution_AjaxTeardownRecorder::ARMED_HANDLER_ENTRY);
    }

    /** Response-time teardown sentinel; armed a second time in finish(). */
    public function recordShutdownAtResponseTime(): void {
        $this->recordTeardown('shutdown_response_time',
            ABJ_404_Solution_AjaxTeardownRecorder::MECHANISM_SHUTDOWN_FUNCTION,
            ABJ_404_Solution_AjaxTeardownRecorder::ARMED_RESPONSE_TIME);
    }

    /**
     * WP 'shutdown' action at PHP_INT_MIN: the earliest possible read on
     * shutdown-time state, before any other plugin's own shutdown hook has
     * had a chance to run. Opens the WordPress-shutdown-action bracket; see
     * ABJ_404_Solution_ShutdownTeardownBracket.
     */
    public function recordShutdownActionEarly(): void {
        // The earliest point at which post-detach work begins. A census row
        // still reading this phase minutes later is a worker holding its
        // process slot while owing the browser nothing -- the shape report 193
        // showed four times over, and a different failure from one stranded
        // before the response was delivered.
        ABJ_404_Solution_SameSiteRequestCensus::markPhase(
            ABJ_404_Solution_SameSiteRequestCensus::PHASE_SHUTDOWN);
        ABJ_404_Solution_AjaxStageDiagnostics::recordRequestPhase((string)($this->context['request_id'] ?? ''), 'wordpress_shutdown');
        $this->teardownRecorder->noteWpActionStart($this->clock->nowFloat());
        $this->recordTeardown('shutdown_action_min',
            ABJ_404_Solution_AjaxTeardownRecorder::MECHANISM_WP_ACTION,
            ABJ_404_Solution_AjaxTeardownRecorder::ARMED_HANDLER_ENTRY);
    }

    /**
     * WP 'shutdown' action at PHP_INT_MAX: fires after every other plugin's
     * default-priority shutdown hook has already run, so it can catch delay
     * or damage they caused that recordShutdownActionEarly could not see.
     * Closes the WordPress-shutdown-action bracket.
     */
    public function recordShutdownActionLate(): void {
        $this->teardownRecorder->noteWpActionEnd($this->clock->nowFloat());
        $this->recordTeardown('shutdown_action_max',
            ABJ_404_Solution_AjaxTeardownRecorder::MECHANISM_WP_ACTION,
            ABJ_404_Solution_AjaxTeardownRecorder::ARMED_HANDLER_ENTRY);
        ABJ_404_Solution_AjaxStageDiagnostics::recordRequestPhase((string)($this->context['request_id'] ?? ''), 'wordpress_shutdown', 'complete');
    }

    /**
     * Test-harness end-of-request: mark every armed teardown sentinel in this
     * process inert.
     *
     * In production a trace and its shutdown sentinels live exactly as long
     * as one request, and the trace directory outlives them both, so the
     * sentinels are deliberately NEVER disarmed there (see recordShutdown()
     * for the beta.1 defect that rule replaced) and nothing in production
     * calls this. A PHPUnit worker breaks the premise the sentinels rely on:
     * it replays hundreds of requests in one process, each against a
     * per-test temp directory that tearDown deletes, while
     * register_shutdown_function() keeps every test's trace queued until the
     * whole process exits. Without this seam each of those traces flushes
     * into its deleted directory at process exit and reports 'AJAX trace
     * file could not be opened' to stderr -- noise that buries real
     * trace-write failures. Wired into ABJ404_RequestScopedStateReset next
     * to the request-context resets that exist for the same reason.
     *
     * @return void
     */
    public static function disarmTeardownSentinelsForTests(): void {
        foreach (self::$tracesWithArmedSentinels as $trace) {
            $trace->teardownRecorder->disarm();
        }
        self::$tracesWithArmedSentinels = array();
    }

    /**
     * Hand this request's state to the teardown recorder and retire the trace
     * if it wrote.
     *
     * The trace stays active when the write did not happen, which is what lets
     * a later sentinel try again: a retired recorder and a failed write are
     * both cases where nothing was recorded, and marking the request torn down
     * on either would discard the one remaining chance to record it.
     *
     * @param string $mechanism One of ABJ_404_Solution_AjaxTeardownRecorder's
     *                          MECHANISM_* constants.
     * @param string $armedAt   One of its ARMED_* constants.
     */
    private function recordTeardown(string $event, string $mechanism, string $armedAt): void {
        if ($this->teardownRecorder->record($event, $mechanism, $armedAt, array(
            'envelope' => $this->baseRecord(),
            'request_started_at' => $this->requestStartedAt,
            'response_emitted_at' => $this->responseEmittedAt,
            'already_finished' => !$this->active,
            'current_stage' => $this->currentStage,
        ))) {
            $this->active = false;
        }
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
            // + AjaxAdminEndpointSupport). session_id and retry_parent_id ride
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
