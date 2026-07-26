<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, privacy-safe attribution for ordinary logging reached during an
 * instrumented table request outside the authorization and failure scopes.
 */
final class ABJ_404_Solution_RoutineLogTracer {

    /** @var array<int,string> */
    private static $operationStack = array();
    /** @var int */
    private static $operationSequence = 0;
    /** @var bool */
    private static $recording = false;

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    public static function trace(string $operation, array $fields, callable $work) {
        if (self::$recording
                || ABJ_404_Solution_AuthorizationLogTracer::isActive()
                || ABJ_404_Solution_PostAuthorizationFailureTracer::isActive()) {
            return $work();
        }
        $requestId = self::requestId();
        if ($requestId === '') {
            return $work();
        }

        $safeOperation = self::safeToken($operation, 'operation');
        $operationId = self::operationId($requestId, $safeOperation);
        $identity = array(
            'operation_id' => $operationId,
            'operation' => $safeOperation,
        );
        $parent = end(self::$operationStack);
        if (is_string($parent) && $parent !== '') {
            $identity['parent_operation_id'] = $parent;
        }
        if (isset($fields['level']) && is_string($fields['level'])) {
            $identity['level'] = self::safeToken($fields['level'], 'level');
        }

        self::recordStart($requestId, $identity);
        self::$operationStack[] = $operationId;
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            array_pop(self::$operationStack);
            self::recordEnd($requestId, array_merge($identity, array(
                'status' => 'error',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => 'error',
                'error' => self::errorSummary($error),
            )));
            throw $error;
        }
        array_pop(self::$operationStack);
        self::recordEnd($requestId, array_merge($identity, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            'result' => self::resultSummary($result),
        )));
        return $result;
    }

    /** @param array<string,mixed> $fields */
    private static function recordStart(string $requestId, array $fields): void {
        self::$recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'routine_log_operation_start',
                $fields
            );
        } finally {
            self::$recording = false;
        }
    }

    /** @param array<string,mixed> $fields */
    private static function recordEnd(string $requestId, array $fields): void {
        self::$recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'routine_log_operation_end',
                $fields
            );
        } finally {
            self::$recording = false;
        }
    }

    private static function requestId(): string {
        return class_exists('ABJ_404_Solution_AjaxRequestLedger')
            ? ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext()
            : '';
    }

    private static function operationId(string $requestId, string $operation): string {
        self::$operationSequence++;
        return substr(hash('sha256', $requestId . '|' . $operation . '|' . self::$operationSequence), 0, 12);
    }

    private static function safeToken(string $value, string $fallback): string {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1
            ? $value
            : $fallback . '#' . substr(hash('sha256', $value), 0, 12);
    }

    /** @param mixed $result */
    private static function resultSummary($result): string {
        if (is_bool($result)) {
            return $result ? 'true' : 'false';
        }
        return gettype($result);
    }

    /** @return array{class:string,code:int,message:string} */
    private static function errorSummary(Throwable $error): array {
        return array(
            'class' => get_class($error),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $error->getMessage()), 0, 12),
        );
    }

    private static function nowFloat(): ?float {
        return function_exists('abj_clock') ? abj_clock()->nowFloat() : null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        if ($startedAt === null) {
            return null;
        }
        $finishedAt = self::nowFloat();
        return $finishedAt === null ? null : max(0, (int)round(($finishedAt - $startedAt) * 1000));
    }
}
