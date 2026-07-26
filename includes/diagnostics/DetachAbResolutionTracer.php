<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for detach A/B resolution after the response flush.
 *
 * The bounded experiment resolves its enablement filter and then reads/writes
 * a transient attempt counter before the connection-detach call. A foreign
 * callback, persistent object cache, or database-backed transient can stop
 * inside any of those boundaries. This tracer lands a two-sink write-ahead
 * start before resolution and before each transient call, then writes a
 * matching end only after the operation returns.
 *
 * Session ids, transient contents, cache endpoints, callback arguments, and
 * exception messages never enter the records. The transient key and error
 * message are one-way fingerprints; cache evidence is limited to selected
 * backend, class, and public capability availability.
 */
final class ABJ_404_Solution_DetachAbResolutionTracer {
    /** @var int */
    private static $operationSequence = 0;

    /**
     * Bracket the full resolution decision.
     *
     * @param callable(): array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    public static function traceResolution(
        string $sessionId,
        string $part,
        string $payloadKey,
        callable $resolution
    ): array {
        $requestId = self::requestId();
        if ($requestId === '') {
            return $resolution();
        }
        $fields = array(
            'operation_id' => self::operationId($requestId, 'resolve_detach_ab_mode'),
            'operation' => 'resolve_detach_ab_mode',
            'session_state' => $sessionId === '' ? 'absent' : 'present',
            'part' => self::safePart($part),
            'payload_key' => self::payloadFingerprint($payloadKey),
        );
        $checkpointId = self::recordStart(
            $requestId,
            'detach_ab_resolution_start',
            $fields
        );
        $startedAt = self::nowFloat();
        try {
            $result = $resolution();
        } catch (Throwable $e) {
            self::recordEnd(
                $requestId,
                'detach_ab_resolution_end',
                $checkpointId,
                array_merge($fields, self::errorFields($e), array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                    'counter_status' => 'error',
                ))
            );
            throw $e;
        }
        self::recordEnd(
            $requestId,
            'detach_ab_resolution_end',
            $checkpointId,
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'mode' => self::safeMode($result['mode'] ?? null),
                'diagnostic_enabled' => ($result['diagnostic_enabled'] ?? false) === true,
                'counter_status' => self::counterStatus($sessionId, $result),
            ))
        );
        return $result;
    }

    /**
     * Bracket one get_transient() or set_transient() call.
     *
     * @template T
     * @param callable(): T $operationCall
     * @return T
     */
    public static function traceTransientOperation(
        string $operation,
        string $transientKey,
        callable $operationCall
    ) {
        $requestId = self::requestId();
        if ($requestId === '') {
            return $operationCall();
        }
        $operation = in_array($operation, array('get_transient', 'set_transient'), true)
            ? $operation : 'unknown_transient_operation';
        $fields = array_merge(
            array(
                'operation_id' => self::operationId($requestId, $operation),
                'operation' => $operation,
                'transient_key' => 'transient#' . substr(hash('sha256', $transientKey), 0, 12),
            ),
            self::cacheBackendFields()
        );
        $checkpointId = self::recordStart(
            $requestId,
            'detach_ab_operation_start',
            $fields
        );
        $startedAt = self::nowFloat();
        try {
            $result = $operationCall();
        } catch (Throwable $e) {
            self::recordEnd(
                $requestId,
                'detach_ab_operation_end',
                $checkpointId,
                array_merge($fields, self::errorFields($e), array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                ))
            );
            throw $e;
        }
        self::recordEnd(
            $requestId,
            'detach_ab_operation_end',
            $checkpointId,
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => self::resultSummary($operation, $result),
            ))
        );
        return $result;
    }

    private static function requestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxRequestLedger')) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
    }

    /** @return array<string, string> */
    private static function cacheBackendFields(): array {
        $externalFlag = $GLOBALS['_wp_using_ext_object_cache'] ?? null;
        $backend = $externalFlag === true
            ? 'persistent_object_cache'
            : ($externalFlag === false
                ? 'database_fallback'
                : 'undetermined');
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        $backendClass = is_object($cache)
            ? self::safeClassName(get_class($cache))
            : 'unavailable';
        $capabilities = array(
            'external_flag=' . ($externalFlag === null ? 'unknown' : ($externalFlag ? '1' : '0')),
            'cache_object=' . (is_object($cache) ? '1' : '0'),
            'wp_using_ext_object_cache=' . (function_exists('wp_using_ext_object_cache') ? '1' : '0'),
            'wp_cache_get=' . (function_exists('wp_cache_get') ? '1' : '0'),
            'wp_cache_set=' . (function_exists('wp_cache_set') ? '1' : '0'),
            'get_transient=' . (function_exists('get_transient') ? '1' : '0'),
            'set_transient=' . (function_exists('set_transient') ? '1' : '0'),
        );
        return array(
            'cache_backend' => $backend,
            'cache_backend_class' => $backendClass,
            'cache_capabilities' => implode(',', $capabilities),
        );
    }

    /**
     * @param mixed $result
     */
    private static function resultSummary(string $operation, $result): string {
        if ($operation === 'get_transient') {
            if ($result === false) {
                return 'miss';
            }
            return is_numeric($result) ? 'numeric' : 'unexpected_' . self::safeType($result);
        }
        if (is_bool($result)) {
            return $result ? 'success' : 'failure';
        }
        return 'unexpected_' . self::safeType($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function counterStatus(string $sessionId, array $result): string {
        if (($result['diagnostic_enabled'] ?? false) !== true) {
            return 'disabled';
        }
        if ($sessionId === '') {
            return 'no_session';
        }
        $attemptIndex = $result['attempt_index'] ?? null;
        return !is_int($attemptIndex) || $attemptIndex < 0
            ? 'transient_api_unavailable'
            : 'attempt_resolved';
    }

    /** @return array{error_class: string, error_code: int, error: string} */
    private static function errorFields(Throwable $error): array {
        $message = $error->getMessage();
        return array(
            'error_class' => self::safeClassName(get_class($error)),
            'error_code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'error' => 'message#' . substr(hash('sha256', $message), 0, 12),
        );
    }

    /** @param mixed $value */
    private static function safeType($value): string {
        $type = gettype($value);
        return preg_match('/^[a-z_]{1,32}$/', $type) === 1 ? $type : 'unknown';
    }

    /** @param mixed $mode */
    private static function safeMode($mode): string {
        return is_string($mode) && in_array($mode, array('inert', 'on', 'off', 'default'), true)
            ? $mode : 'unknown';
    }

    private static function safePart(string $part): string {
        return in_array($part, array('all', 'table', 'counts', 'pagination'), true)
            ? $part : 'all';
    }

    private static function payloadFingerprint(string $payloadKey): string {
        $normalized = preg_match('/^[a-f0-9]{40}$/', $payloadKey) === 1
            ? $payloadKey
            : sha1($payloadKey === '' ? 'legacy-payload' : $payloadKey);
        return 'payload#' . substr(hash('sha256', $normalized), 0, 12);
    }

    private static function safeClassName(string $class): string {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
            ? $class
            : 'class#' . substr(hash('sha256', $class), 0, 12);
    }

    private static function operationId(string $requestId, string $operation): string {
        self::$operationSequence++;
        return substr(hash(
            'sha256',
            $requestId . '|' . $operation . '|' . self::$operationSequence
        ), 0, 12);
    }

    /** @param array<string, mixed> $fields */
    private static function recordStart(
        string $requestId,
        string $event,
        array $fields
    ): string {
        try {
            return ABJ_404_Solution_DurableOperationRecorder::recordStart(
                $requestId,
                $event,
                $fields
            );
        } catch (Throwable $e) {
            self::reportFailure($event . ' write failed: ' . $e->getMessage());
            return '';
        }
    }

    /** @param array<string, mixed> $fields */
    private static function recordEnd(
        string $requestId,
        string $event,
        string $checkpointId,
        array $fields
    ): void {
        try {
            ABJ_404_Solution_DurableOperationRecorder::recordEnd(
                $requestId,
                $event,
                $checkpointId,
                $fields
            );
        } catch (Throwable $e) {
            self::reportFailure($event . ' write failed: ' . $e->getMessage());
        }
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

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        if ($startedAt === null) {
            return null;
        }
        $finishedAt = self::nowFloat();
        return $finishedAt === null
            ? null
            : max(0, (int)round(($finishedAt - $startedAt) * 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('detach-ab-resolution-tracer', $message);
    }
}
