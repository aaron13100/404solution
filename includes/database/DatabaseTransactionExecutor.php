<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes SQL statements inside a transaction with deadlock-aware retry.
 *
 * Transaction lifecycle is separate from the single-query pipeline: it owns
 * BEGIN/COMMIT/ROLLBACK bookkeeping, infrastructure-error classification for
 * statement failures, and retry delay for deadlock or lock-wait timeouts.
 */
class ABJ_404_Solution_DatabaseTransactionExecutor {

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
     * @param array<int, string> $statementArray
     * @return void
     */
    public function executeAsTransaction(array $statementArray): void {
        global $wpdb;
        $maxAttempts = 3;
        $lastException = null;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $allIsWell = true;
            $lastError = '';
            $lastException = null;
            try {
                // DAO-bypass-approved: transaction boundary must run on the active wpdb connection before grouped statements execute.
                $wpdb->query('START TRANSACTION');
                foreach ($statementArray as $statement) {
                    // DAO-bypass-approved: transaction executor must preserve same-connection transaction state and last_error per statement.
                    $wpdb->query($statement);
                    if ($wpdb->last_error != null && trim((string)$wpdb->last_error) !== '') {
                        $allIsWell = false;
                        $lastError = (string)$wpdb->last_error;
                        if (!$this->core->errorClassifier()->classifyAndHandleInfrastructureError($lastError)) {
                            $this->logger->errorMessage("Error executing SQL transaction: " . $lastError);
                            $this->logger->errorMessage("SQL causing the transaction error: " . $statement);
                        }
                        break;
                    }
                }
            } catch (Throwable $ex) {
                $allIsWell = false;
                $lastException = $ex;
                $lastError = $ex->getMessage();
            }

            if ($allIsWell && $lastException == null) {
                // DAO-bypass-approved: transaction boundary must commit the active wpdb connection.
                $wpdb->query('commit');
                return;
            }

            // DAO-bypass-approved: transaction boundary must roll back the active wpdb connection after any grouped statement failure.
            $wpdb->query('rollback');
            $retryable = $this->core->errorClassifier()->taxonomy()->connectivity()->isDeadlockOrLockTimeoutError($lastError);
            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }
            $sleepMicros = 100000 + random_int(0, 200000);
            usleep($sleepMicros);
        }

        if ($lastException != null) {
            throw $lastException;
        }
        if ($lastError !== '') {
            throw new Exception($lastError); // allow-raw-error: behavior preserved from pre-extraction DatabaseCore::executeAsTransaction
        }
    }
}
