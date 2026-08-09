<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseConnectionManager {

    /** @var int Hard stop for result sets abandoned on the shared connection. */
    private const MAX_PENDING_RESULT_SETS = 64;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
    }

    /**
     * Forward DatabaseCore infrastructure calls that remain owned by the core.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->core->$name(...$arguments);
    }

    /**
     * Probe wpdb's check_connection method defensively for custom wpdb
     * drop-ins (HyperDB, LudicrousDB, mu-cluster proxies) that may not
     * implement it. WordPress core has shipped this method since 4.1,
     * but a `wp-content/db.php` drop-in can replace `$wpdb` with a
     * subclass that omits it. Calling an undefined method on such a
     * subclass throws a fatal Error before we'd hit a try/catch.
     *
     * Returns true (assume connected) when the method is missing -- the
     * absence of a probe is not a connection failure, and the standard
     * wpdb default is also "no probe == connected".
     *
     * @param object $wpdb The current $wpdb instance (or subclass).
     * @param bool   $allowReconnect Passed through to check_connection().
     * @return bool True if connected (or unable to probe); false if probed and disconnected.
     */
    public function safeCheckConnection($wpdb, bool $allowReconnect = false): bool {
        if (!is_object($wpdb)) {
            return true;
        }
        if (!method_exists($wpdb, 'check_connection') && !is_callable(array($wpdb, 'check_connection'))) {
            return true;
        }
        return (bool) $wpdb->check_connection($allowReconnect);
    }

    /**
     * Ensure database connection is active and reconnect if necessary.
     *
     * @return bool True if connection is active, false otherwise
     */
    public function ensureConnection(
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ) {
        global $wpdb;

        if (!isset($wpdb)) {
            return true;
        }

        try {
            $initialCheck = fn(): bool => $this->safeCheckConnection($wpdb, false);
            $isConnected = $preflight === null
                ? $initialCheck()
                : $preflight->trace(
                    ABJ_404_Solution_DatabaseQueryPreflightTracer::CONNECTION_CHECK,
                    $initialCheck,
                    array('fields' => array('check_attempt' => 'initial'))
                );

            if (!$isConnected) {
                $this->logger->debugMessage("Database connection lost, attempting to reconnect...");

                if (is_object($wpdb) && method_exists($wpdb, 'db_connect')) {
                    $reconnect = static fn() => $wpdb->db_connect();
                    if ($preflight === null) {
                        $reconnect();
                    } else {
                        $preflight->trace(
                            ABJ_404_Solution_DatabaseQueryPreflightTracer::CONNECTION_RECONNECT,
                            $reconnect
                        );
                    }
                }

                $recheck = fn(): bool => $this->safeCheckConnection($wpdb, false);
                $reconnected = $preflight === null
                    ? $recheck()
                    : $preflight->trace(
                        ABJ_404_Solution_DatabaseQueryPreflightTracer::CONNECTION_CHECK,
                        $recheck,
                        array('fields' => array('check_attempt' => 'post_reconnect'))
                    );
                if ($reconnected) {
                    $this->logger->debugMessage("Database reconnection successful");
                    return true;
                }

                $this->logger->errorMessage("Failed to reconnect to database");
                return false;
            }
        } catch (Exception $e) {
            $this->logger->debugMessage("Connection check failed: " . $e->getMessage());
            return true;
        } catch (Error $e) {
            $this->logger->debugMessage("Connection check not available: " . $e->getMessage());
            return true;
        }

        return true;
    }

    /**
     * Reset wpdb state before a recovery retry.
     *
     * WordPress's wpdb::flush() drains multi-query results only when
     * wpdb::$result is a mysqli_result. A query that fails with client error
     * 2014 leaves that property as false, so flush alone cannot recover the
     * shared connection. Once a later query has received error 2014, the
     * pending results have already been abandoned by their owner and must be
     * consumed before any component can use the handle again.
     *
     * Custom wpdb drop-ins may expose PDO or proxy handles instead of mysqli.
     * Those cannot be drained here; return false so callers do not issue a
     * retry that is guaranteed to fail.
     *
     * @param string $errorText Error produced by the attempt being recovered.
     * @return bool True when retrying is safe, false when connection state
     *              could not be recovered.
     */
    public function resetForRetry(string $errorText = ''): bool {
        global $wpdb;

        $commandsOutOfSync = $this->core->errorClassifier()
            ->taxonomy()
            ->connectivity()
            ->isCommandsOutOfSyncError($errorText);
        if ($commandsOutOfSync && !$this->drainPendingMysqliResults($wpdb ?? null)) {
            return false;
        }

        if (!is_object($wpdb) || !is_callable(array($wpdb, 'flush'))) {
            if ($commandsOutOfSync) {
                $this->logger->warn(
                    'Commands-out-of-sync recovery could not reset wpdb bookkeeping: flush() is unavailable.'
                );
                return false;
            }
            return true;
        }

        try {
            $wpdb->flush();
        } catch (Throwable $e) {
            $this->logger->warn(
                'Database retry reset failed while flushing wpdb state: '
                . get_class($e) . ': ' . $e->getMessage()
            );
            return false;
        }
        return true;
    }

    /**
     * Consume abandoned result sets from WordPress's shared mysqli handle.
     *
     * The fixed bound prevents a malformed or wedged handle from spinning at
     * shutdown. Each loop consumes the current result before advancing, which
     * is required by mysqli after multi_query().
     *
     * @param mixed $wpdb Current WordPress database object or custom drop-in.
     * @return bool True only when no pending result set remains.
     */
    private function drainPendingMysqliResults($wpdb): bool {
        $dbh = is_object($wpdb) && isset($wpdb->dbh) ? $wpdb->dbh : null;
        if (!extension_loaded('mysqli') || !$dbh instanceof \mysqli) {
            $handleType = is_object($dbh) ? get_class($dbh) : gettype($dbh);
            $this->logger->warn(
                'Commands-out-of-sync recovery requires a mysqli handle; current wpdb handle type is '
                . $handleType . '.'
            );
            return false;
        }

        try {
            for ($processed = 0; $processed < self::MAX_PENDING_RESULT_SETS; $processed++) {
                $pendingResult = $dbh->store_result();
                if ($pendingResult instanceof \mysqli_result) {
                    $pendingResult->free();
                }
                if (!$dbh->more_results()) {
                    return true;
                }
                if ($processed + 1 >= self::MAX_PENDING_RESULT_SETS) {
                    $this->logger->warn(
                        'Commands-out-of-sync recovery stopped after the fixed limit of '
                        . self::MAX_PENDING_RESULT_SETS . ' pending result sets.'
                    );
                    return false;
                }
                if (!$dbh->next_result()) {
                    $this->logger->warn(
                        'Commands-out-of-sync recovery could not advance to the next pending result: '
                        . (string)$dbh->error . ' (mysqli errno ' . (int)$dbh->errno . ').'
                    );
                    return false;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn(
                'Commands-out-of-sync recovery failed while draining mysqli results: '
                . get_class($e) . ': ' . $e->getMessage()
            );
            return false;
        }

        return false;
    }
}
