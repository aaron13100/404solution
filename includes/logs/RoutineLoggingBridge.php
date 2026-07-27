<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Diagnostics-neutral adapter for request-scoped routine-log attribution. */
final class ABJ_404_Solution_RoutineLoggingBridge {

    /** @var callable(string,array<string,mixed>,callable):mixed|null */
    private static $tracer = null;

    /** @var callable(string,string,callable):mixed|null */
    private static $authorizationTracer = null;

    /** @param callable(string,array<string,mixed>,callable):mixed|null $tracer */
    public static function setTracer($tracer): void {
        self::$tracer = is_callable($tracer) ? $tracer : null;
    }

    /** @param callable(string,string,callable):mixed|null $tracer */
    public static function setAuthorizationTracer($tracer): void {
        self::$authorizationTracer = is_callable($tracer) ? $tracer : null;
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    public static function trace(string $operation, array $fields, callable $work) {
        return is_callable(self::$tracer)
            ? call_user_func(self::$tracer, $operation, $fields, $work)
            : $work();
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function traceAuthorized(
        string $authorizationOperation,
        string $routineOperation,
        callable $work
    ) {
        if (is_callable(self::$authorizationTracer)) {
            return call_user_func(
                self::$authorizationTracer,
                $authorizationOperation,
                $routineOperation,
                $work
            );
        }
        return self::trace($routineOperation, array(), $work);
    }
}
