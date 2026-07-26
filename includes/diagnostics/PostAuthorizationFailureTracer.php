<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for AJAX failure work reached after authorization.
 *
 * The failure fingerprint is persisted before detail construction or service
 * lookup. Every later blocking boundary carries the same failure id, while
 * exception messages, log lines, paths, SQL, and response details remain out
 * of the diagnostic journal.
 */
final class ABJ_404_Solution_PostAuthorizationFailureTracer {

    /** @var array<int, array{request_id:string,failure_id:string,branch:string}> */
    private static $contexts = array();

    /** @var int */
    private static $operationSequence = 0;

    /**
     * Record the failure first, then trace detail construction and logging.
     *
     * @template T
     * @param Throwable|null $throwable
     * @param callable(): mixed $detailsFactory
     * @param callable(mixed): T $logging
     * @return T
     */
    public static function trace(
        string $branch,
        $throwable,
        callable $detailsFactory,
        callable $logging
    ) {
        $requestId = self::requestId();
        if ($requestId === '') {
            return $logging($detailsFactory());
        }

        $safeBranch = self::safeBranch($branch);
        $failureId = self::operationId($requestId, $safeBranch);
        $fields = array(
            'operation_id' => $failureId,
            'failure_id' => $failureId,
            'branch' => $safeBranch,
        );
        if ($throwable instanceof Throwable) {
            $fields['error'] = self::errorSummary($throwable);
        }

        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'ajax_failure_branch',
            $fields
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'ajax_failure_log_start',
            $fields
        );
        self::$contexts[] = array(
            'request_id' => $requestId,
            'failure_id' => $failureId,
            'branch' => $safeBranch,
        );
        $startedAt = self::nowFloat();

        try {
            $details = self::aroundOperation('detail_construction', $detailsFactory);
            $result = $logging($details);
        } catch (Throwable $error) {
            array_pop(self::$contexts);
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'ajax_failure_log_end',
                array_merge($fields, array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                    'error' => self::errorSummary($error),
                ))
            );
            throw $error;
        }

        array_pop(self::$contexts);
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'ajax_failure_log_end',
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            ))
        );
        return $result;
    }

    /**
     * Trace one failure-logging sub-operation while trace() is active.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function aroundOperation(string $operation, callable $work) {
        $context = self::activeContext();
        if ($context === null) {
            return $work();
        }

        $operationId = self::operationId($context['request_id'], $operation);
        $fields = array(
            'operation_id' => $operationId,
            'failure_id' => $context['failure_id'],
            'branch' => $context['branch'],
            'operation' => self::safeOperation($operation),
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $context['request_id'],
            'ajax_failure_log_operation_start',
            $fields
        );
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $context['request_id'],
                'ajax_failure_log_operation_end',
                array_merge($fields, array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                    'error' => self::errorSummary($error),
                ))
            );
            throw $error;
        }

        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $context['request_id'],
            'ajax_failure_log_operation_end',
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => self::resultSummary($result),
            ))
        );
        return $result;
    }

    /**
     * Map native Logging operations to explicit failure-path terminology.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function aroundNativeOperation(string $operation, callable $work) {
        $mapped = $operation === 'path_resolution'
            ? 'native_path_resolution'
            : ($operation === 'write' ? 'native_write_flush_return' : 'native_' . $operation);
        return self::aroundOperation($mapped, $work);
    }

    public static function isActive(): bool {
        return self::activeContext() !== null;
    }

    /** @return array{request_id:string,failure_id:string,branch:string}|null */
    private static function activeContext(): ?array {
        $context = end(self::$contexts);
        return is_array($context) ? $context : null;
    }

    private static function requestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxRequestLedger')) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
    }

    private static function operationId(string $requestId, string $operation): string {
        self::$operationSequence++;
        return substr(hash(
            'sha256',
            $requestId . '|' . $operation . '|' . self::$operationSequence
        ), 0, 12);
    }

    private static function safeBranch(string $branch): string {
        if (in_array($branch, array('rate_limit', 'exception_caught', 'failure_branch'), true)) {
            return $branch;
        }
        return 'branch#' . substr(hash('sha256', $branch), 0, 12);
    }

    private static function safeOperation(string $operation): string {
        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $operation) === 1
            ? $operation
            : 'operation#' . substr(hash('sha256', $operation), 0, 12);
    }

    /** @return array<string, mixed> */
    private static function errorSummary(Throwable $error): array {
        $message = $error->getMessage();
        $class = get_class($error);
        return array(
            'class' => preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
                ? $class
                : 'class#' . substr(hash('sha256', $class), 0, 12),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $message), 0, 12),
            'message_length' => strlen($message),
        );
    }

    /**
     * @param mixed $result
     * @return array{type:string,value?:bool|int|float|string|null}
     */
    private static function resultSummary($result): array {
        if (is_bool($result) || is_int($result) || is_float($result) || $result === null) {
            return array('type' => gettype($result), 'value' => $result);
        }
        if (!is_object($result)) {
            return array('type' => gettype($result));
        }
        $class = get_class($result);
        return array('type' => preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
            ? 'object:' . $class
            : 'object:class#' . substr(hash('sha256', $class), 0, 12));
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
}
