<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PhpErrorLogFallback.php';

/**
 * Explicit adapter for wpdb drop-ins whose underlying connection is neither
 * mysqli nor a MySQL PDO connection.
 */
interface ABJ_404_Solution_DatabaseSessionStatementConnection {
    /** @return array{success: bool, error: string} */
    public function executeAbj404SessionStatement(string $sql): array;
}

/**
 * Bounds one metadata-lock-sensitive operation without changing the session's
 * lasting configuration or wpdb's mutable result envelope.
 *
 * MySQL's lock_wait_timeout defaults to 31,536,000 seconds. Unlike
 * innodb_lock_wait_timeout, it governs metadata locks, so even a normally fast
 * SHOW COLUMNS, ALTER TABLE, or INSERT into a table being altered can otherwise
 * hold a PHP request for effectively a year. The guard writes its temporary
 * session settings through the underlying connection rather than wpdb: those
 * bookkeeping statements therefore cannot overwrite last_error, last_query,
 * rows_affected, or appear to callers as application queries.
 */
final class ABJ_404_Solution_DatabaseMetadataLockWaitGuard {

    private const MAX_WAIT_SECONDS = 5;

    /** @var int Distinguishes nested guarded operations on one connection. */
    private static $nextSavedVariableId = 1;

    /** @var ABJ_404_Solution_Logging|(callable(): (ABJ_404_Solution_Logging|null))|null */
    private $logger;

    /** @var (callable(mixed, string): array{success: bool, error: string})|null */
    private $sessionStatementRunner;

    /**
     * @param ABJ_404_Solution_Logging|(callable(): (ABJ_404_Solution_Logging|null))|null $logger
     * @param (callable(mixed, string): array{success: bool, error: string})|null $sessionStatementRunner
     *   Optional connection adapter for database drop-ins whose handle is not
     *   mysqli or PDO. The ordinary WordPress path needs no adapter.
     */
    public function __construct($logger = null, $sessionStatementRunner = null) {
        $this->logger = $logger;
        $this->sessionStatementRunner = is_callable($sessionStatementRunner)
            ? $sessionStatementRunner : null;
    }

    /**
     * @param object $wpdb Active WordPress database handle or compatible drop-in.
     * @param array{description: string, operation: callable(): mixed} $request
     * @return array{status: 'completed'|'setup_failed', value: mixed, error: string}
     */
    public function runWithBoundedWait($wpdb, array $request): array {
        $description = $request['description'];
        $operation = $request['operation'];
        $connection = $this->connectionOf($wpdb);

        if (!$this->canRunSessionStatement($connection)) {
            $error = 'No supported live database session connection is available.';
            $this->restoreLastError($wpdb, $error);
            $this->warnSetupFailure($description, $error);
            return array(
                'status' => 'setup_failed',
                'value' => null,
                'error' => $error,
            );
        }

        $savedVariable = '@abj404_saved_lock_wait_timeout_' . self::$nextSavedVariableId++;
        $setup = $this->runSessionStatement(
            $connection,
            'SET ' . $savedVariable . ' = @@SESSION.lock_wait_timeout'
        );
        if (!$setup['success']) {
            $this->restoreLastError($wpdb, $setup['error']);
            $this->warnSetupFailure($description, $setup['error']);
            return array('status' => 'setup_failed', 'value' => null, 'error' => $setup['error']);
        }

        // Keep an existing stricter host policy rather than widening it to the
        // plugin ceiling. Split this from the save statement because MySQL and
        // MariaDB do not accept the same mixed user/system-variable SET form.
        $setup = $this->runSessionStatement(
            $connection,
            'SET SESSION lock_wait_timeout = CAST(LEAST(' . $savedVariable . ', '
            . self::MAX_WAIT_SECONDS . ') AS UNSIGNED)'
        );
        if (!$setup['success']) {
            $this->clearSavedVariable(array(
                'connection' => $connection,
                'savedVariable' => $savedVariable,
                'description' => $description,
            ));
            $this->restoreLastError($wpdb, $setup['error']);
            $this->warnSetupFailure($description, $setup['error']);
            return array('status' => 'setup_failed', 'value' => null, 'error' => $setup['error']);
        }

        try {
            $value = $operation();
            $operationError = $this->lastError($wpdb);
        } catch (Throwable $exception) {
            $this->restoreSessionTimeout(array(
                'connection' => $connection,
                'savedVariable' => $savedVariable,
                'description' => $description,
            ));
            throw $exception;
        }

        $this->restoreSessionTimeout(array(
            'connection' => $connection,
            'savedVariable' => $savedVariable,
            'description' => $description,
        ));
        return array('status' => 'completed', 'value' => $value, 'error' => $operationError);
    }

    /**
     * @param object $wpdb
     * @return mixed
     */
    private function connectionOf($wpdb) {
        // PDO-backed wpdb adapters commonly keep the live session in a private
        // pdo field instead of exposing WordPress's mysqli-oriented dbh field.
        foreach (array('dbh', 'pdo') as $propertyName) {
            $property = $this->readProperty($wpdb, $propertyName);
            if ($property['found'] && $property['value'] !== null) {
                return $property['value'];
            }
        }
        return null;
    }

    /** @param mixed $connection */
    private function canRunSessionStatement($connection): bool {
        if ($this->sessionStatementRunner !== null) {
            return $connection !== null;
        }
        return (class_exists('mysqli', false) && $connection instanceof mysqli)
            || $this->isMysqlPdo($connection)
            || $connection instanceof ABJ_404_Solution_DatabaseSessionStatementConnection;
    }

    /** @param mixed $connection */
    private function isMysqlPdo($connection): bool {
        if (!$connection instanceof PDO) {
            return false;
        }
        try {
            return $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        } catch (Throwable $exception) {
            $this->warn(
                'Could not identify the PDO driver before applying the database metadata-lock guard. '
                . get_class($exception) . ': ' . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * @param mixed $connection
     * @return array{success: bool, error: string}
     */
    private function runSessionStatement($connection, string $sql): array {
        try {
            if ($this->sessionStatementRunner !== null) {
                $adapterResponse = call_user_func($this->sessionStatementRunner, $connection, $sql);
                if (is_array($adapterResponse)
                    && isset($adapterResponse['success'], $adapterResponse['error'])
                    && is_bool($adapterResponse['success'])
                    && is_string($adapterResponse['error'])) {
                    return $adapterResponse;
                }
                return array(
                    'success' => false,
                    'error' => 'The metadata-lock session adapter returned an invalid result.',
                );
            }

            if ($connection instanceof ABJ_404_Solution_DatabaseSessionStatementConnection) {
                return $connection->executeAbj404SessionStatement($sql);
            }

            if (class_exists('mysqli', false) && $connection instanceof mysqli) {
                $mysqliResponse = mysqli_query($connection, $sql);
                return array(
                    'success' => $mysqliResponse !== false,
                    'error' => $mysqliResponse === false ? (string)mysqli_error($connection) : '',
                );
            }

            if ($connection instanceof PDO) {
                $pdoResponse = $connection->exec($sql);
                $error = $pdoResponse === false ? $connection->errorInfo() : array();
                return array(
                    'success' => $pdoResponse !== false,
                    'error' => $pdoResponse === false && isset($error[2]) ? (string)$error[2] : '',
                );
            }
        } catch (Throwable $t) {
            return array(
                'success' => false,
                'error' => get_class($t) . ': ' . $t->getMessage(),
            );
        }

        return array('success' => false, 'error' => 'No supported database session connection.');
    }

    /** @param array{connection: mixed, savedVariable: string, description: string} $request */
    private function restoreSessionTimeout(array $request): void {
        $connection = $request['connection'];
        $savedVariable = $request['savedVariable'];
        $description = $request['description'];
        $restore = $this->runSessionStatement(
            $connection,
            'SET SESSION lock_wait_timeout = ' . $savedVariable
        );
        if (!$restore['success']) {
            $this->warn(
                'Could not restore the database metadata-lock timeout after ' . $description . '. '
                . 'The connection retains the shorter safety timeout. Database error: '
                . ($restore['error'] !== '' ? $restore['error'] : '(none reported)')
            );
        }
        $this->clearSavedVariable($request);
    }

    /** @param array{connection: mixed, savedVariable: string, description: string} $request */
    private function clearSavedVariable(array $request): void {
        $connection = $request['connection'];
        $savedVariable = $request['savedVariable'];
        $description = $request['description'];
        $clear = $this->runSessionStatement($connection, 'SET ' . $savedVariable . ' = NULL');
        if (!$clear['success']) {
            $this->warn(
                'Could not clear the saved metadata-lock timeout variable after ' . $description . '. '
                . 'Database error: ' . ($clear['error'] !== '' ? $clear['error'] : '(none reported)')
            );
        }
    }

    /** @param object $wpdb */
    private function lastError($wpdb): string {
        $property = $this->readProperty($wpdb, 'last_error');
        return $property['found'] && is_scalar($property['value'])
            ? trim((string)$property['value'])
            : '';
    }

    /** @param object $wpdb */
    private function restoreLastError($wpdb, string $error): void {
        $this->writeProperty($wpdb, 'last_error', $error);
    }

    /**
     * @param object $object
     * @return array{found: bool, value: mixed}
     */
    private function readProperty($object, string $name): array {
        if (property_exists($object, $name)) {
            try {
                $property = new ReflectionProperty($object, $name);
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                return array('found' => true, 'value' => $property->getValue($object));
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not read wpdb field ' . $name . ' around a guarded operation. '
                    . get_class($exception) . ': ' . $exception->getMessage()
                );
                return array('found' => false, 'value' => null);
            }
        }
        if (is_callable(array($object, '__get'))) {
            try {
                return array('found' => true, 'value' => call_user_func(array($object, '__get'), $name));
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not read wpdb drop-in field ' . $name . ' around a guarded operation. '
                    . get_class($exception) . ': ' . $exception->getMessage()
                );
            }
        }
        return array('found' => false, 'value' => null);
    }

    /**
     * @param object $object
     * @param mixed $value
     */
    private function writeProperty($object, string $name, $value): void {
        if (property_exists($object, $name)) {
            try {
                $property = new ReflectionProperty($object, $name);
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $property->setValue($object, $value);
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not restore wpdb field ' . $name . ' after guard setup failed. '
                    . get_class($exception) . ': ' . $exception->getMessage()
                );
            }
            return;
        }
        if (is_callable(array($object, '__set'))) {
            try {
                call_user_func(array($object, '__set'), $name, $value);
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not restore wpdb drop-in field ' . $name . ' after guard setup failed. '
                    . get_class($exception) . ': ' . $exception->getMessage()
                );
            }
        }
    }

    private function warnSetupFailure(string $description, string $error): void {
        $this->warn(
            'Could not establish a bounded database metadata-lock timeout before '
            . $description . '; the potentially unbounded operation was not attempted. '
            . 'Database error: ' . ($error !== '' ? $error : '(none reported)')
        );
    }

    private function warn(string $message): void {
        $logger = $this->logger;
        if (is_callable($logger) && (!is_object($logger) || !method_exists($logger, 'warn'))) {
            try {
                $logger = $logger();
            } catch (Throwable $exception) {
                abj404_logPhpFallback(
                    'service-resolution-fallback',
                    $message . ' Logger resolution also failed: ' . $exception->getMessage()
                );
                return;
            }
        }
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
        }
    }
}
