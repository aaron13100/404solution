<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns deferred log queue flushing, including batch INSERT construction,
 * schema-column validation, and per-row fallback when a batch fails.
 */
class ABJ_404_Solution_LogsQueueFlusher {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_LogsEntrySanitizer */
    private $entrySanitizer;

    /** @var ABJ_404_Solution_LogsWriteRecoveryPolicy */
    private $recovery;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_LogsEntrySanitizer $entrySanitizer
     * @param ABJ_404_Solution_LogsWriteRecoveryPolicy $recovery
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger,
        ABJ_404_Solution_LogsEntrySanitizer $entrySanitizer,
        ABJ_404_Solution_LogsWriteRecoveryPolicy $recovery
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->entrySanitizer = $entrySanitizer;
        $this->recovery = $recovery;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param-out array<int, array<string, mixed>> $queue
     * @param callable $flushCallback
     * @param array<string, mixed> $entry
     */
    public function queueLogEntry(array &$queue, bool &$shutdownHookRegistered, callable $flushCallback, array $entry): void {
        $queue[] = $entry;
        if (!$shutdownHookRegistered) {
            $shutdownHookRegistered = true;
            add_action('shutdown', $flushCallback, 9);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     */
    public function flushLogQueue(array &$queue, bool &$shutdownHookRegistered, bool &$isFlushingLogQueue): void {
        if ($isFlushingLogQueue) {
            return;
        }
        $isFlushingLogQueue = true;
        $batchDatabaseError = '';

        try {
            if (empty($queue)) {
                return;
            }

            global $wpdb;
            $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');

            $columns = array_keys($queue[0]);
            $validatedColumns = [];
            foreach ($columns as $col) {
                if (preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) {
                    $validatedColumns[] = $col;
                }
            }
            $schemaColumns = $this->dbCore->tableNameResolver()->getTableColumnNames($tableName);
            if (!empty($schemaColumns)) {
                $validatedColumns = array_intersect($validatedColumns, $schemaColumns);
            }
            if (empty($validatedColumns)) {
                return;
            }

            $columnList = '`' . implode('`, `', $validatedColumns) . '`';
            $sanitizedEntries = [];
            foreach ($queue as $entry) {
                $entryColumns = array_keys($entry);
                $missingCols = array_diff($validatedColumns, $entryColumns);
                if (!empty($missingCols)) {
                    continue;
                }
                $sanitized = $this->entrySanitizer->sanitizeLogEntry($entry);
                if ($sanitized === null) {
                    continue;
                }
                $sanitizedEntries[] = $sanitized;
            }
            if (empty($sanitizedEntries)) {
                return;
            }

            list($formats, $flattenedValues) = $this->buildValuePlaceholders($sanitizedEntries, $validatedColumns);
            $sql = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $formats);
            // DAO-bypass-approved: queue flusher batches dynamic INSERT placeholders and must inspect wpdb last_error on the same connection.
            $prepared = $wpdb->prepare($sql, $flattenedValues);
            $wpdb->flush();
            // DAO-bypass-approved: batch log insert must preserve same-handle last_error for recovery classification.
            $result = $wpdb->query($prepared);

            if ($result === false && !empty($wpdb->last_error)) {
                $batchDatabaseError = (string)$wpdb->last_error;
                $this->recoverFailedBatch($tableName, $columnList, $prepared, $sql, $flattenedValues, $sanitizedEntries, $validatedColumns, $batchDatabaseError);
            }
        } catch (\Throwable $e) {
            // This runs on the 'shutdown' action, which fires on every
            // request that queued a log entry. finally (below) already
            // resets the queue/flags; without this catch, an uncaught
            // throwable here would propagate out of do_action('shutdown')
            // and abort any other plugin's later shutdown cleanup too, not
            // just lose this batch of log rows.
            $failureMessage = 'flushLogQueue failed: ' . get_class($e) . ': ' . $e->getMessage();
            if ($batchDatabaseError !== '' && $this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($batchDatabaseError)) {
                $this->logger->warn($failureMessage . ' | database_error=' . $batchDatabaseError);
            } else {
                $this->logger->errorMessage(
                    $failureMessage,
                    $e instanceof \Exception ? $e : null
                );
            }
        } finally {
            $queue = [];
            $shutdownHookRegistered = false;
            $isFlushingLogQueue = false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, string> $validatedColumns
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function buildValuePlaceholders(array $entries, array $validatedColumns): array {
        $formats = [];
        $flattenedValues = [];
        foreach ($entries as $entry) {
            $rowFormats = [];
            foreach ($validatedColumns as $col) {
                $value = $entry[$col];
                if ($value === null) {
                    $rowFormats[] = 'NULL';
                    continue;
                }
                if (is_int($value)) {
                    $rowFormats[] = '%d';
                } else {
                    $rowFormats[] = '%s';
                }
                $flattenedValues[] = $value;
            }
            $formats[] = '(' . implode(', ', $rowFormats) . ')';
        }
        return array($formats, $flattenedValues);
    }

    /**
     * @param array<int, mixed> $flattenedValues
     * @param array<int, array<string, mixed>> $sanitizedEntries
     * @param array<int, string> $validatedColumns
     */
    private function recoverFailedBatch(
        string $tableName,
        string $columnList,
        string $prepared,
        string $sql,
        array $flattenedValues,
        array $sanitizedEntries,
        array $validatedColumns,
        string $batchError
    ): void {
        global $wpdb;

        if ($this->recovery->isTableFullError($batchError)) {
            $trimmed = $this->recovery->autoTrimLogsv2IfNeeded($tableName, $batchError);
            if ($trimmed) {
                $wpdb->flush();
                // DAO-bypass-approved: table-full recovery retries the already-prepared batch on the same wpdb connection.
                $retryResult = $wpdb->query($prepared);
                if ($retryResult !== false) {
                    return;
                }
                $batchError = (string)$wpdb->last_error;
            }
            $this->recovery->setLogsv2FullNotice($batchError);
        }

        if ($this->recovery->isCommandsOutOfSyncError($batchError)) {
            $isolated = $this->recovery->getIsolatedWpdb();
            if ($isolated !== null) {
                $isolated->flush();
                $isolatedResult = $isolated->query($prepared);
                if ($isolatedResult !== false) {
                    $context = $this->recovery->getWpdbRecentQueryContextForLogs();
                    $suffix = ($context !== '') ? " | savequeries_context={$context}" : '';
                    $this->logger->warn("flushLogQueue batch INSERT succeeded using isolated DB connection (commands out of sync on shared connection).{$suffix}");
                    return;
                }
                $batchError .= " | isolated_error=" . $isolated->last_error;
            } else {
                $batchError .= " | isolated_error=no_isolated_connection";
            }
        }

        $this->recoverEntriesIndividually($tableName, $columnList, $sanitizedEntries, $validatedColumns, $batchError);
    }

    /**
     * @param array<int, array<string, mixed>> $sanitizedEntries
     * @param array<int, string> $validatedColumns
     */
    private function recoverEntriesIndividually(
        string $tableName,
        string $columnList,
        array $sanitizedEntries,
        array $validatedColumns,
        string $batchError
    ): void {
        global $wpdb;
        $successCount = 0;
        $failCount = 0;
        $failureDetails = [];

        foreach ($sanitizedEntries as $index => $entry) {
            $rowFormats = [];
            $rowValues = [];
            foreach ($validatedColumns as $col) {
                $value = $entry[$col];
                if ($value === null) {
                    $rowFormats[] = 'NULL';
                } else {
                    $rowFormats[] = is_int($value) ? '%d' : '%s';
                    $rowValues[] = $value;
                }
            }
            $rowPlaceholder = '(' . implode(', ', $rowFormats) . ')';
            /** @var literal-string $singleSqlTemplate */
            $singleSqlTemplate = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES {$rowPlaceholder}";
            // DAO-bypass-approved: row-level fallback reuses wpdb prepare/query so recovery can classify last_error per row.
            $singleSql = $wpdb->prepare($singleSqlTemplate, $rowValues);
            $wpdb->flush();
            // DAO-bypass-approved: row-level retry must preserve same-handle last_error for per-row recovery.
            $singleResult = $wpdb->query((string)$singleSql);

            if ($singleResult === false && !empty($wpdb->last_error)) {
                $lastError = (string)$wpdb->last_error;
                if ($this->recovery->isCommandsOutOfSyncError($wpdb->last_error)) {
                    $isolated = $this->recovery->getIsolatedWpdb();
                    if ($isolated !== null) {
                        $isolated->flush();
                        $isolatedSingleSql = $isolated->prepare($singleSqlTemplate, $rowValues);
                        $isolatedSingleResult = $isolated->query((string)$isolatedSingleSql);
                        if ($isolatedSingleResult !== false) {
                            $successCount++;
                            continue;
                        }
                        $lastError = $lastError . " | isolated_error=" . $isolated->last_error;
                    } else {
                        $lastError = $lastError . " | isolated_error=no_isolated_connection";
                    }
                }
                $failCount++;
                $payload = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);
                if (is_string($payload) && strlen($payload) > 1024) {
                    $payload = substr($payload, 0, 1024) . '...';
                }
                $failureDetails[] = ['index' => $index, 'error' => $lastError, 'payload' => $payload];
            } else {
                $successCount++;
            }
        }

        if ($failCount > 0) {
            $detailsParts = [];
            foreach (array_slice($failureDetails, 0, 3) as $detail) {
                $detailsParts[] = "entry {$detail['index']}: {$detail['error']} | payload={$detail['payload']}";
            }
            $detailsSuffix = count($failureDetails) > 3 ? ' | (additional failures omitted)' : '';
            $context = $this->recovery->getWpdbRecentQueryContextForLogs();
            $contextSuffix = ($context !== '') ? (" | savequeries_context=" . $context) : '';
            if ($this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($batchError)) {
                $this->logger->warn("flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed. | batch_error=" . $batchError . " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix . $contextSuffix);
            } else {
                $this->logger->errorMessage("flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed. | batch_error=" . $batchError . " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix . $contextSuffix);
            }
        } else {
            $this->logger->warn("flushLogQueue batch INSERT failed but recovered: all {$successCount} entries inserted individually. | batch_error=" . $batchError);
        }
    }
}
