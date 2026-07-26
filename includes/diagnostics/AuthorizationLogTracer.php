<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for the synchronous successful-authorization log call.
 *
 * Bruno's affected requests all wrote "AJAX authorized" and then went silent.
 * The existing auth_check pair therefore proves that authorization began but
 * cannot distinguish a logger call that made its line visible before its
 * underlying file operation returned. This tracer reserves an outer pair
 * before logger resolution and, while that call is active, lets the native
 * Logging adapter attribute path resolution and the final write/return.
 *
 * State is a stack rather than a boolean: nested authorization calls cannot
 * clear an outer call's context, and unrelated logging is a pure pass-through.
 * Paths and messages never enter the checkpoint records.
 */
final class ABJ_404_Solution_AuthorizationLogTracer {

    /** @var array<int, array{request_id: string, operation_id: string}> */
    private static $contexts = array();

    /** @var int */
    private static $operationSequence = 0;

    /**
     * Trace logger resolution plus the successful authorization audit call.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function trace(callable $work) {
        $requestId = self::requestId();
        if ($requestId === '') {
            return $work();
        }

        $operationId = self::operationId($requestId, 'authorize_admin_with_nonce');
        $fields = array(
            'operation_id' => $operationId,
            'operation' => 'authorize_admin_with_nonce',
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'auth_log_start',
            $fields
        );
        self::$contexts[] = array(
            'request_id' => $requestId,
            'operation_id' => $operationId,
        );
        $startedAt = self::nowFloat();

        try {
            $result = $work();
        } catch (Throwable $error) {
            array_pop(self::$contexts);
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'auth_log_end',
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
            'auth_log_end',
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            ))
        );
        return $result;
    }

    /**
     * Attribute one native logging sub-operation while trace() is active.
     * Outside that scope this is behavior-identical to invoking $work directly.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function aroundOperation(string $operation, callable $work) {
        $context = self::activeContext();
        if ($context === null) {
            if (class_exists('ABJ_404_Solution_PostAuthorizationFailureTracer')
                    && ABJ_404_Solution_PostAuthorizationFailureTracer::isActive()) {
                return ABJ_404_Solution_PostAuthorizationFailureTracer::aroundNativeOperation(
                    $operation,
                    $work
                );
            }
            return $work();
        }

        $operationId = self::operationId($context['request_id'], $operation);
        $fields = array(
            'operation_id' => $operationId,
            'parent_operation_id' => $context['operation_id'],
            'operation' => $operation,
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $context['request_id'],
            'auth_log_operation_start',
            $fields
        );
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $context['request_id'],
                'auth_log_operation_end',
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
            'auth_log_operation_end',
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => self::resultSummary($result),
            ))
        );
        return $result;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function aroundRoutineOperation(
        string $authorizationOperation,
        string $routineOperation,
        callable $work
    ) {
        return self::aroundOperation(
            $authorizationOperation,
            static fn() => ABJ_404_Solution_RoutineLoggingBridge::trace(
                $routineOperation,
                array(),
                $work
            )
        );
    }

    public static function isActive(): bool {
        return self::activeContext() !== null;
    }

    /** @return array{request_id: string, operation_id: string}|null */
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

    /** @return array<string, mixed> */
    private static function errorSummary(Throwable $error): array {
        $message = $error->getMessage();
        return array(
            'class' => self::safeClassName(get_class($error)),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $message), 0, 12),
            'message_length' => strlen($message),
        );
    }

    /**
     * @param mixed $result
     * @return array{type: string, value?: bool|int|float|string|null}
     */
    private static function resultSummary($result): array {
        if (is_bool($result) || is_int($result) || is_float($result) || $result === null) {
            return array('type' => gettype($result), 'value' => $result);
        }
        return array('type' => is_object($result)
            ? 'object:' . self::safeClassName(get_class($result))
            : gettype($result));
    }

    private static function safeClassName(string $class): string {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
            ? $class
            : 'class#' . substr(hash('sha256', $class), 0, 12);
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
