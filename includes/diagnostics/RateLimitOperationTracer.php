<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, request-scoped attribution for rate-limit cache operations.
 *
 * The table AJAX handler used to wrap the whole limiter in one checkpoint,
 * which left backend selection and up to three persistent-cache commands
 * indistinguishable. This tracer records a bounded start/end pair around
 * those four fixed operations. The DB fallback is intentionally not wrapped:
 * its statements already flow through AjaxQueryTimeline.
 *
 * Cache keys and groups are hashed before they reach the journal. Backend and
 * public connection objects are identified only by class and a process-local
 * object hash; endpoint properties and connection methods are never read.
 * Thrown errors are summarized safely in the journal and rethrown unchanged.
 */
final class ABJ_404_Solution_RateLimitOperationTracer {

    /** The limiter has one selection plus at most three cache commands. */
    const MAX_OPERATIONS_PER_CALL = 4;

    /** @var int */
    private static $operationSequence = 0;

    /** @var string */
    private static $budgetRequestId = '';

    /** @var int */
    private static $budgetOperations = 0;

    /**
     * Trace the cache-vs-DB decision.
     *
     * @param callable(): bool $selection
     */
    public static function selectBackend(callable $selection): bool {
        self::beginOperationBudget();
        return (bool)self::trace('backend_selection', '', '', $selection, true);
    }

    /**
     * Trace one persistent object-cache command.
     *
     * @param callable(): mixed $command
     * @return mixed
     */
    public static function cacheCommand(
        string $operation,
        string $key,
        string $group,
        callable $command
    ) {
        return self::trace($operation, $key, $group, $command, false);
    }

    /**
     * @param callable(): mixed $work
     * @return mixed
     */
    private static function trace(
        string $operation,
        string $key,
        string $group,
        callable $work,
        bool $backendSelection
    ) {
        $requestId = self::requestId();
        if ($requestId === '' || !self::claimOperationBudget($requestId)) {
            return $work();
        }

        $fields = self::operationFields($requestId, $operation, $key, $group);
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'rate_limit_operation_start',
            $fields
        );
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'rate_limit_operation_end',
                array_merge($fields, array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                    'error' => self::errorSummary($e),
                ))
            );
            throw $e;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'rate_limit_operation_end',
            array_merge($fields, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => self::resultSummary($result, $backendSelection),
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

    private static function beginOperationBudget(): void {
        self::$budgetRequestId = self::requestId();
        self::$budgetOperations = 0;
    }

    private static function claimOperationBudget(string $requestId): bool {
        if (self::$budgetRequestId !== $requestId
                || self::$budgetOperations >= self::MAX_OPERATIONS_PER_CALL) {
            return false;
        }
        self::$budgetOperations++;
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operationFields(
        string $requestId,
        string $operation,
        string $key,
        string $group
    ): array {
        $backend = self::backendSnapshot();
        $fields = array(
            'operation_id' => self::operationId($requestId, $operation),
            'operation' => $operation,
            'backend_class' => $backend['class'],
            'capabilities' => $backend['capabilities'],
            'connection' => $backend['connection'],
            'max_operations' => self::MAX_OPERATIONS_PER_CALL,
        );
        if ($key !== '') {
            $fields['key'] = self::hashedValue($key, 'key');
        }
        if ($group !== '') {
            $fields['group'] = self::hashedValue($group, 'group');
        }
        return $fields;
    }

    /**
     * @return array{class: string, capabilities: array<string, bool>, connection: array<string, mixed>}
     */
    private static function backendSnapshot(): array {
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        $isObject = is_object($cache);
        return array(
            'class' => $isObject ? self::safeClassName(get_class($cache)) : 'unavailable',
            'capabilities' => array(
                'wp_using_ext_object_cache' => function_exists('wp_using_ext_object_cache'),
                'wp_cache_add' => function_exists('wp_cache_add'),
                'wp_cache_incr' => function_exists('wp_cache_incr'),
                'backend_add' => $isObject && is_callable(array($cache, 'add')),
                'backend_incr' => $isObject && is_callable(array($cache, 'incr')),
            ),
            'connection' => $isObject
                ? self::connectionIdentity($cache)
                : array('status' => 'unavailable'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function connectionIdentity(object $cache): array {
        $public = get_object_vars($cache);
        foreach (array('redis', 'client', 'connection', 'conn', 'memcached', 'mc', 'store') as $name) {
            if (!array_key_exists($name, $public)) {
                continue;
            }
            $identity = self::identityForValue($public[$name], $name);
            if ($identity !== null) {
                return $identity;
            }
        }
        return array('status' => 'unavailable');
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function identityForValue($value, string $source): ?array {
        if (is_object($value)) {
            $class = self::safeClassName(get_class($value));
            return array(
                'status' => 'available',
                'source' => $source,
                'class' => $class,
                'id' => 'connection#' . substr(
                    hash('sha256', $class . '|' . spl_object_id($value)),
                    0,
                    12
                ),
            );
        }
        if (is_resource($value)) {
            $type = get_resource_type($value);
            return array(
                'status' => 'available',
                'source' => $source,
                'class' => 'resource:' . self::safeToken($type),
                'id' => 'connection#' . substr(
                    // Casting a resource to int is the PHP 7.4-compatible
                    // resource identity primitive; get_resource_id() starts
                    // at PHP 8.0, above this plugin's supported floor.
                    hash('sha256', $type . '|' . (int)$value),
                    0,
                    12
                ),
            );
        }
        return null;
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>
     */
    private static function resultSummary($result, bool $backendSelection): array {
        if ($backendSelection) {
            return array(
                'type' => 'backend',
                'value' => $result ? 'persistent_cache' : 'database_fallback',
            );
        }
        if (is_bool($result)) {
            return array('type' => 'boolean', 'value' => $result);
        }
        if (is_int($result)) {
            return array('type' => 'integer', 'value' => $result);
        }
        if (is_float($result)) {
            return array('type' => 'float', 'value' => $result);
        }
        if ($result === null) {
            return array('type' => 'null', 'value' => null);
        }
        if (is_object($result)) {
            return array('type' => 'object', 'class' => self::safeClassName(get_class($result)));
        }
        $encoded = json_encode($result);
        $encoded = is_string($encoded) ? $encoded : gettype($result);
        return array(
            'type' => gettype($result),
            'value_hash' => substr(hash('sha256', $encoded), 0, 12),
            'value_length' => strlen($encoded),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorSummary(Throwable $error): array {
        $message = $error->getMessage();
        return array(
            'class' => self::safeClassName(get_class($error)),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $message), 0, 12),
            'message_length' => strlen($message),
        );
    }

    private static function operationId(string $requestId, string $operation): string {
        self::$operationSequence++;
        return substr(hash(
            'sha256',
            $requestId . '|' . $operation . '|' . self::$operationSequence
        ), 0, 12);
    }

    private static function hashedValue(string $value, string $kind): string {
        return $kind . '#' . substr(hash('sha256', $value), 0, 12);
    }

    private static function safeClassName(string $class): string {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
            ? $class
            : 'class#' . substr(hash('sha256', $class), 0, 12);
    }

    private static function safeToken(string $value): string {
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) === 1
            ? $value
            : 'token#' . substr(hash('sha256', $value), 0, 12);
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
