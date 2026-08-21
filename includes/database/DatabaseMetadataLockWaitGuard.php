<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounds one metadata-lock-sensitive operation without changing the session's
 * lasting configuration.
 *
 * MySQL's lock_wait_timeout defaults to 31,536,000 seconds. Unlike
 * innodb_lock_wait_timeout, it governs metadata locks, so even a normally fast
 * SHOW COLUMNS, ALTER TABLE, or INSERT into a table being altered can otherwise
 * hold a PHP request for effectively a year. The guard saves the exact session
 * value in a connection-local user variable, narrows it for one operation, and
 * restores it in a finally path.
 */
final class ABJ_404_Solution_DatabaseMetadataLockWaitGuard {

    private const MAX_WAIT_SECONDS = 5;

    /** @var int Distinguishes nested guarded operations on one connection. */
    private static $nextSavedVariableId = 1;

    /** @var ABJ_404_Solution_Logging|(callable(): (ABJ_404_Solution_Logging|null))|null */
    private $logger;

    /** @param ABJ_404_Solution_Logging|(callable(): (ABJ_404_Solution_Logging|null))|null $logger */
    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /**
     * @param object $wpdb Active WordPress database handle or compatible drop-in.
     * @param array{description: string, operation: callable(): mixed} $request
     * @return array{status: 'completed'|'setup_failed', value: mixed, error: string}
     */
    public function run($wpdb, array $request): array {
        $description = $request['description'];
        $operation = $request['operation'];
        $savedVariable = '@abj404_saved_lock_wait_timeout_' . self::$nextSavedVariableId++;
        $saveSql = 'SET ' . $savedVariable . ' = @@SESSION.lock_wait_timeout';

        // DAO-bypass-approved: session-scoped guard for a raw operation that
        // may itself be required while the normal DAO is bootstrapping.
        $setupResult = $this->query($wpdb, $saveSql);
        $setupError = $this->lastError($wpdb);
        if ($setupResult === false || $setupError !== '') {
            $this->restoreLastError($wpdb, $setupError);
            $this->warn(
                'Could not establish a bounded database metadata-lock timeout before '
                . $description . '; the potentially unbounded operation was not attempted. '
                . 'Database error: ' . ($setupError !== '' ? $setupError : '(none reported)')
            );
            return array('status' => 'setup_failed', 'value' => null, 'error' => $setupError);
        }

        // Keep an existing stricter host policy rather than widening it to the
        // plugin ceiling. Split this from the save statement because MySQL and
        // MariaDB do not accept the same mixed user/system-variable SET form.
        // DAO-bypass-approved: second half of the session-scoped guard.
        $setupResult = $this->query(
            $wpdb,
            'SET SESSION lock_wait_timeout = CAST(LEAST(' . $savedVariable . ', '
            . self::MAX_WAIT_SECONDS . ') AS UNSIGNED)'
        );
        $setupError = $this->lastError($wpdb);
        if ($setupResult === false || $setupError !== '') {
            // DAO-bypass-approved: discard a saved value whose guard never started.
            $this->clearSavedVariable($wpdb, $savedVariable, $description);
            $this->restoreLastError($wpdb, $setupError);
            $this->warn(
                'Could not establish a bounded database metadata-lock timeout before '
                . $description . '; the potentially unbounded operation was not attempted. '
                . 'Database error: ' . ($setupError !== '' ? $setupError : '(none reported)')
            );
            return array('status' => 'setup_failed', 'value' => null, 'error' => $setupError);
        }

        $connectionBefore = $this->connectionIdentity($wpdb);
        $operationState = array();
        try {
            $value = $operation();
            $operationState = $this->snapshotWpdbState($wpdb);
        } catch (Throwable $exception) {
            $operationState = $this->snapshotWpdbState($wpdb);
            $this->restoreSessionTimeout($wpdb, $savedVariable, $description, $operationState, $connectionBefore);
            throw $exception;
        }

        $this->restoreSessionTimeout($wpdb, $savedVariable, $description, $operationState, $connectionBefore);
        $operationError = isset($operationState['last_error']) && is_string($operationState['last_error'])
            ? $operationState['last_error']
            : '';
        return array('status' => 'completed', 'value' => $value, 'error' => $operationError);
    }

    /**
     * @param object $wpdb
     * @param array<string, mixed> $operationState
     * @param mixed $connectionBefore
     */
    private function restoreSessionTimeout(
        $wpdb,
        string $savedVariable,
        string $description,
        array $operationState,
        $connectionBefore
    ): void {
        if ($connectionBefore !== $this->connectionIdentity($wpdb)) {
            // A reconnect creates a fresh server session. It never received
            // this guard's SET, and its copy of the user variable does not
            // exist, so restoring against it would be both wrong and invalid.
            $this->restoreWpdbState($wpdb, $operationState);
            return;
        }

        // DAO-bypass-approved: paired restoration for the session SET above.
        $restoreResult = $this->query($wpdb, 'SET SESSION lock_wait_timeout = ' . $savedVariable);
        $restoreError = $this->lastError($wpdb);
        if ($restoreResult === false || $restoreError !== '') {
            $this->warn(
                'Could not restore the database metadata-lock timeout after ' . $description . '. '
                . 'The connection retains the shorter safety timeout. Database error: '
                . ($restoreError !== '' ? $restoreError : '(none reported)')
            );
        }
        // DAO-bypass-approved: clear the connection-local scratch value after restoration.
        $this->clearSavedVariable($wpdb, $savedVariable, $description);

        // wpdb's mutable result fields belong to the guarded operation, not
        // the SET statements that followed it. Preserve the full envelope so
        // callers still see rows_affected, insert_id, last_result, and the SQL
        // error/query that actually belong to their operation.
        $this->restoreWpdbState($wpdb, $operationState);
    }

    /**
     * @param object $wpdb
     * @return mixed
     */
    private function connectionIdentity($wpdb) {
        $property = $this->readProperty($wpdb, 'dbh');
        return $property['found'] ? $property['value'] : null;
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
     * @param object $wpdb
     * @return array<string, mixed>
     */
    private function snapshotWpdbState($wpdb): array {
        $state = array();
        foreach (array(
            'last_error', 'last_result', 'rows_affected', 'insert_id', 'num_rows', 'last_query'
        ) as $property) {
            $read = $this->readProperty($wpdb, $property);
            if ($read['found']) {
                $state[$property] = $read['value'];
            }
        }
        return $state;
    }

    /**
     * @param object $wpdb
     * @param array<string, mixed> $state
     */
    private function restoreWpdbState($wpdb, array $state): void {
        foreach ($state as $property => $value) {
            $this->writeProperty($wpdb, $property, $value);
        }
    }

    /**
     * @param object $wpdb
     * @return int|bool
     */
    private function query($wpdb, string $sql) {
        if (!is_callable(array($wpdb, 'query'))) {
            return false;
        }
        $result = call_user_func(array($wpdb, 'query'), $sql);
        return is_int($result) || is_bool($result) ? $result : false;
    }

    /**
     * @param object $object
     * @return array{found: bool, value: mixed}
     */
    private function readProperty($object, string $name): array {
        if (property_exists($object, $name)) {
            try {
                $property = new ReflectionProperty($object, $name);
                $property->setAccessible(true);
                return array('found' => true, 'value' => $property->getValue($object));
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not read wpdb result field ' . $name . ' around a guarded operation. '
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
                    'Could not read wpdb drop-in result field ' . $name . ' around a guarded operation. '
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
                $property->setAccessible(true);
                $property->setValue($object, $value);
            } catch (Throwable $exception) {
                $this->warn(
                    'Could not restore wpdb result field ' . $name . ' after a guarded operation. '
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
                    'Could not restore wpdb drop-in result field ' . $name . ' after a guarded operation. '
                    . get_class($exception) . ': ' . $exception->getMessage()
                );
            }
        }
    }

    /**
     * @param object $wpdb
     */
    private function clearSavedVariable($wpdb, string $savedVariable, string $description): void {
        $clearResult = $this->query($wpdb, 'SET ' . $savedVariable . ' = NULL');
        $clearError = $this->lastError($wpdb);
        if ($clearResult === false || $clearError !== '') {
            $this->warn(
                'Could not clear the saved metadata-lock timeout variable after ' . $description . '. '
                . 'Database error: ' . ($clearError !== '' ? $clearError : '(none reported)')
            );
        }
    }

    private function warn(string $message): void {
        $logger = $this->logger;
        if (is_callable($logger) && (!is_object($logger) || !method_exists($logger, 'warn'))) {
            try {
                $logger = $logger();
            } catch (Throwable $exception) {
                error_log($message . ' Logger resolution also failed: ' . $exception->getMessage());
                return;
            }
        }
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
        }
    }
}
