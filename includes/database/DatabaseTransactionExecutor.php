<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/DatabaseMetadataLockWaitGuard.php';

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

    /** @var ABJ_404_Solution_DatabaseMetadataLockWaitGuard */
    private $metadataLockWaitGuard;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
        $this->metadataLockWaitGuard = new ABJ_404_Solution_DatabaseMetadataLockWaitGuard($logger);
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
                    // Null-safe read (matches DataAccess.php/StatsRepository.php/NGramFilter.php):
                    // a partial test double or a future custom wpdb drop-in that omits
                    // last_error must not raise an "undefined property" notice here.
                    $statementError = trim((string)($wpdb->last_error ?? ''));
                    if ($statementError !== '') {
                        $allIsWell = false;
                        $lastError = $statementError;
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
            $retryable = $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError);
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

    /**
     * Execute one conditional mutation in a SERIALIZABLE transaction.
     *
     * SERIALIZABLE is set for the next transaction only, so the source-range
     * absence read in INSERT ... SELECT ... WHERE NOT EXISTS is protected from
     * a concurrent insert without changing the connection's lasting isolation
     * level. A deadlock loser retries and then observes the winner's row.
     *
     * @param array{sql: string, params: array<int, mixed>, description: string} $request
     * @return array{rows: array<int, mixed>, last_error: string, last_result: array<int, mixed>, rows_affected: int, insert_id: int}
     */
    public function executeSerializableMutation(array $request): array {
        global $wpdb;
        $prepared = $this->prepareMutation($wpdb, $request['sql'], $request['params']);
        $guarded = $this->metadataLockWaitGuard->runWithBoundedWait($wpdb, array(
            'description' => $request['description'],
            'operation' => function () use ($wpdb, $prepared) {
                return $this->executeSerializableMutationWithRetry($wpdb, $prepared);
            },
        ));
        $value = $guarded['value'];
        if ($guarded['status'] !== 'completed' || !is_array($value)
                || !isset($value['rows'], $value['last_error'], $value['last_result'],
                    $value['rows_affected'], $value['insert_id'])
                || !is_array($value['rows']) || !is_string($value['last_error'])
                || !is_array($value['last_result']) || !is_numeric($value['rows_affected'])
                || !is_numeric($value['insert_id'])) {
            $error = $guarded['error'] !== ''
                ? $guarded['error']
                : 'Could not establish a bounded metadata-lock timeout.';
            throw new Exception($error); // allow-raw-error: original database guard failure is the actionable context
        }
        return array(
            'rows' => array_values($value['rows']),
            'last_error' => $value['last_error'],
            'last_result' => array_values($value['last_result']),
            'rows_affected' => (int)$value['rows_affected'],
            'insert_id' => (int)$value['insert_id'],
        );
    }

    /**
     * @param \wpdb $wpdb
     * @param array<int, mixed> $params
     */
    private function prepareMutation($wpdb, string $sql, array $params): string {
        if (empty($params)) {
            return $sql;
        }
        // DAO-bypass-approved: the transaction owns the active connection and
        // must bind on the same wpdb handle that executes the statement.
        $prepared = call_user_func_array(
            array($wpdb, 'prepare'),
            array_merge(array($sql), array_values($params))
        );
        if (!is_string($prepared) || $prepared === '') {
            throw new Exception('wpdb could not prepare the serializable mutation.');
        }
        return $prepared;
    }

    /**
     * @param \wpdb $wpdb
     * @return array{rows: array<int, mixed>, last_error: string, last_result: array<int, mixed>, rows_affected: int, insert_id: int}
     */
    private function executeSerializableMutationWithRetry($wpdb, string $statement): array {
        $maxAttempts = 3;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $transactionStarted = false;
            try {
                $this->runControlStatement($wpdb, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $this->runControlStatement($wpdb, 'START TRANSACTION');
                $transactionStarted = true;

                // DAO-bypass-approved: mutation must share the connection and
                // transaction with its SERIALIZABLE absence check.
                // DAO-bypass-approved: guarded conditional mutation must execute on the active transaction connection.
                $queryResult = $wpdb->query($statement);
                $lastError = trim($wpdb->last_error);
                if ($queryResult === false || $lastError !== '') {
                    throw new Exception($lastError !== '' ? $lastError : 'Database mutation failed.');
                }

                $rowsAffected = (int)$wpdb->rows_affected;
                $insertId = (int)$wpdb->insert_id;
                $lastResult = is_array($wpdb->last_result) ? $wpdb->last_result : array();
                $this->runControlStatement($wpdb, 'COMMIT');

                return array(
                    'rows' => array(),
                    'last_error' => '',
                    'last_result' => $lastResult,
                    'rows_affected' => $rowsAffected,
                    'insert_id' => $insertId,
                );
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                if ($transactionStarted) {
                    // DAO-bypass-approved: failure cleanup on the same active transaction.
                    $wpdb->query('ROLLBACK');
                }
                $retryable = $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError);
                if (!$retryable || $attempt >= $maxAttempts) {
                    throw $exception;
                }
                usleep(100000 + random_int(0, 200000));
            }
        }

        throw new Exception($lastError !== '' ? $lastError : 'Serializable mutation failed.');
    }

    /** @param \wpdb $wpdb */
    private function runControlStatement($wpdb, string $statement): void {
        // DAO-bypass-approved: transaction/isolation boundary on the active connection.
        $result = $wpdb->query($statement);
        $lastError = trim($wpdb->last_error);
        if ($result === false || $lastError !== '') {
            throw new Exception($lastError !== '' ? $lastError : 'Database refused: ' . $statement);
        }
    }
}
