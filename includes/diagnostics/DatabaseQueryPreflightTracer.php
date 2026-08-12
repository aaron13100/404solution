<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for work before the first per-query SQL probe.
 *
 * DatabaseQueryExecutor opens this boundary before connection inspection and
 * closes it only after result-shape detection. Nested operations identify the
 * exact preflight call that failed or never returned. The tracer never stores
 * SQL, parameters, cache values, exception messages, or anonymous-class source
 * paths; every field is a controlled enum, bounded source label, count, or
 * privacy-safe class identity.
 *
 * Calls are no-ops outside the AJAX request ledger. Diagnostic persistence is
 * failure-safe, while exceptions from traced database work are recorded and
 * rethrown unchanged.
 *
 * allow-no-test-found: exercised through both real table AJAX entry points in tests/AjaxQueryPreflightAttributionTest.php
 */
final class ABJ_404_Solution_DatabaseQueryPreflightTracer {

    const CONNECTION_CHECK = 'connection_check';
    const CONNECTION_RECONNECT = 'connection_reconnect';
    const PARAMETER_PREPARATION = 'parameter_preparation';
    const ENGINE_DETECTION = 'engine_detection';
    const TIMEOUT_CAPABILITY_CACHE = 'timeout_capability_cache';
    const TIMEOUT_POLICY = 'timeout_policy';
    const DIAGNOSTIC_LATENCY = 'diagnostic_latency';
    const RESULT_SHAPE_DETECTION = 'result_shape_detection';

    /** @var string */
    private static $sequenceRequestId = '';

    /** @var int */
    private static $preflightSequence = 0;

    /** @var string */
    private $requestId;

    /** @var string */
    private $preflightId;

    /** @var string */
    private $source;

    /** @var string */
    private $stage;

    /** @var int */
    private $operationSequence = 0;

    /** @var bool */
    private $completed = false;

    /**
     * Begin the query-wide preflight before any database boundary is touched.
     *
     * @param mixed $wpdb
     */
    public static function begin(string $source, $wpdb): self {
        $requestId = self::armedRequestId();
        if ($requestId !== self::$sequenceRequestId) {
            self::$sequenceRequestId = $requestId;
            self::$preflightSequence = 0;
        }
        if ($requestId !== '') {
            self::$preflightSequence++;
        }
        $preflightId = $requestId === ''
            ? ''
            : substr(hash('sha256', $requestId . '|' . self::$preflightSequence . '|' . $source), 0, 12);
        $tracer = new self(
            $requestId,
            $preflightId,
            self::safeLabel($source, 200),
            self::currentStage()
        );
        $tracer->write('query_preflight_start', array_merge(
            $tracer->baseFields($preflightId),
            self::connectionIdentity($wpdb)
        ));
        return $tracer;
    }

    private function __construct(
        string $requestId,
        string $preflightId,
        string $source,
        string $stage
    ) {
        $this->requestId = $requestId;
        $this->preflightId = $preflightId;
        $this->source = $source;
        $this->stage = $stage;
    }

    /** Correlation ID added to the first SQL probe after preflight succeeds. */
    public function preflightId(): string {
        return $this->preflightId;
    }

    /**
     * Run one nested preflight operation with a durable start and end.
     *
     * Options:
     * - fields: scalar privacy-safe identity added to both edges.
     * - result_fields: callable that maps the result to scalar end fields.
     *
     * @template T
     * @param callable():T $work
     * @param array{
     *   fields?: array<string, scalar|null>,
     *   result_fields?: callable(T):array<string, scalar|null>
     * } $options
     * @return T
     */
    public function trace(string $operation, callable $work, array $options = array()) {
        if ($this->requestId === '') {
            return $work();
        }
        $this->operationSequence++;
        $operation = self::safeOperation($operation);
        $operationId = substr(hash(
            'sha256',
            $this->preflightId . '|' . $this->operationSequence . '|' . $operation
        ), 0, 12);
        $identity = array_merge(
            $this->baseFields($operationId),
            array('operation' => $operation),
            self::safeScalarFields($options['fields'] ?? array())
        );
        $this->write('query_preflight_operation_start', $identity);
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->write('query_preflight_operation_end', array_merge($identity, array(
                'status' => 'failed',
                'failure_class' => self::safeClassName(get_class($e)),
            )));
            throw $e;
        }

        $resultFields = array();
        if (is_callable($options['result_fields'] ?? null)) {
            try {
                $described = call_user_func($options['result_fields'], $result);
                $resultFields = is_array($described) ? self::safeScalarFields($described) : array();
            } catch (Throwable $e) {
                self::reportFailure('result-description', $e);
            }
        }
        $this->write('query_preflight_operation_end', array_merge(
            $identity,
            array('status' => 'complete'),
            $resultFields
        ));
        return $result;
    }

    /** Close the query-wide preflight once, preserving only failure class. */
    public function complete(string $status = 'complete', ?Throwable $failure = null): void {
        if ($this->completed) {
            return;
        }
        $this->completed = true;
        $fields = array_merge(
            $this->baseFields($this->preflightId),
            array('status' => $status === 'complete' ? 'complete' : 'failed')
        );
        if ($failure !== null) {
            $fields['failure_class'] = self::safeClassName(get_class($failure));
        }
        $this->write('query_preflight_end', $fields);
    }

    /** @return array<string, string> */
    private function baseFields(string $operationId): array {
        return array(
            'operation_id' => $operationId,
            'preflight_id' => $this->preflightId,
            'src' => $this->source,
            'stage' => $this->stage,
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, scalar|null>
     */
    private static function safeScalarFields(array $fields): array {
        $safe = array();
        foreach ($fields as $name => $value) {
            if (is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,47}$/', $name) === 1
                    && (is_scalar($value) || $value === null)) {
                $safe[$name] = is_string($value) ? self::safeLabel($value, 96) : $value;
            }
        }
        return $safe;
    }

    /**
     * @param mixed $wpdb
     * @return array<string, string>
     */
    private static function connectionIdentity($wpdb): array {
        $hasProbe = is_object($wpdb)
            && (method_exists($wpdb, 'check_connection')
                || is_callable(array($wpdb, 'check_connection')));
        $hasReconnect = is_object($wpdb) && method_exists($wpdb, 'db_connect');
        if (!$hasProbe) {
            $policy = 'no_connection_probe';
        } elseif ($hasReconnect) {
            $policy = 'db_connect_then_check_connection_false';
        } else {
            $policy = 'check_only_no_db_connect';
        }
        return array(
            'wpdb_class' => self::wpdbClassName($wpdb),
            'wpdb_kind' => self::wpdbKind($wpdb),
            'reconnect_policy' => $policy,
        );
    }

    /** @param mixed $wpdb */
    private static function wpdbClassName($wpdb): string {
        if (!is_object($wpdb)) {
            return 'unavailable';
        }
        $className = get_class($wpdb);
        if (strpos($className, '@anonymous') !== false) {
            return 'anonymous-wpdb-compatible';
        }
        return self::safeClassName($className);
    }

    /** @param mixed $wpdb */
    private static function wpdbKind($wpdb): string {
        if (!is_object($wpdb)) {
            return 'unavailable';
        }
        if (strcasecmp(get_class($wpdb), 'wpdb') === 0) {
            return 'core_wpdb';
        }
        return is_a($wpdb, 'wpdb') ? 'wpdb_subclass' : 'wpdb_compatible';
    }

    private static function safeClassName(string $className): string {
        return self::safeLabel($className, 96);
    }

    private static function safeOperation(string $operation): string {
        $allowed = array(
            self::CONNECTION_CHECK,
            self::CONNECTION_RECONNECT,
            self::PARAMETER_PREPARATION,
            self::ENGINE_DETECTION,
            self::TIMEOUT_CAPABILITY_CACHE,
            self::TIMEOUT_POLICY,
            self::DIAGNOSTIC_LATENCY,
            self::RESULT_SHAPE_DETECTION,
        );
        return in_array($operation, $allowed, true) ? $operation : 'unknown';
    }

    private static function safeLabel(string $value, int $length): string {
        $normalized = preg_replace('/[^A-Za-z0-9_:#.\\\\-]/', '_', $value);
        return substr(is_string($normalized) ? $normalized : 'unknown', 0, $length);
    }

    private static function currentStage(): string {
        $context = $GLOBALS['abj404_ajax_context'] ?? null;
        $stage = is_array($context) && is_scalar($context['stage'] ?? null)
            ? (string)$context['stage']
            : '';
        return self::safeLabel($stage, 64);
    }

    private static function armedRequestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxQueryTimeline')
                || !ABJ_404_Solution_AjaxQueryTimeline::isArmed()) {
            return '';
        }
        return ABJ_404_Solution_AjaxQueryTimeline::armedRequestId();
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields): void {
        if ($this->requestId === '') {
            return;
        }
        try {
            ABJ_404_Solution_AjaxCheckpointBoundaryWriter::record(
                $this->requestId,
                $event,
                $fields
            );
        } catch (Throwable $e) {
            self::reportFailure('checkpoint-write:' . $event, $e);
        }
    }

    private static function reportFailure(string $context, Throwable $failure): void {
        abj404_logPhpFallback(
            'database-query-preflight',
            self::safeLabel($context, 96)
                . ' failed; exception=' . self::safeClassName(get_class($failure))
                . '; code=' . (string)$failure->getCode()
        );
    }

    /** Reset request sequence state. Test-only; production never calls this. */
    public static function resetForTests(): void {
        self::$sequenceRequestId = '';
        self::$preflightSequence = 0;
    }
}
