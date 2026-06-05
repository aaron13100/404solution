<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Warning policy for staged-build MySQL session-variable probes.
 */
class ABJ_404_Solution_ViewBuildSessionVariableWarningClassifier {

    /**
     * Apply SESSION_PROBE_THRESHOLDS and return human-readable warning strings.
     *
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    public function classifySessionVariableWarnings(array $values): array {
        $warnings = array();
        $t = ABJ_404_Solution_ViewBuildConfig::SESSION_PROBE_THRESHOLDS;

        $this->appendSessionLockAndTempWarnings($warnings, $values, $t);
        $this->appendSessionSlowLogAndBufferWarnings($warnings, $values, $t);
        $this->appendSessionTimeoutWarnings($warnings, $values, $t);
        $this->appendSessionCharsetAndDdlWarnings($warnings, $values);
        $this->appendSessionResourceLimitWarnings($warnings, $values, $t);

        return $warnings;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function probeIntFromValues(array $values, string $key): int {
        $v = $values[$key] ?? 0;
        return is_numeric($v) ? (int)$v : 0;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function probeFloatFromValues(array $values, string $key): float {
        $v = $values[$key] ?? 0;
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function probeStringFromValues(array $values, string $key): string {
        $v = $values[$key] ?? '';
        return is_scalar($v) ? (string)$v : '';
    }

    /**
     * @param array<string,mixed> $thresholds
     */
    private static function thresholdInt(array $thresholds, string $key): int {
        $v = $thresholds[$key] ?? 0;
        return is_numeric($v) ? (int)$v : 0;
    }

    /**
     * @param array<string,mixed> $thresholds
     */
    private static function thresholdFloat(array $thresholds, string $key): float {
        $v = $thresholds[$key] ?? 0.0;
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionLockAndTempWarnings(array &$warnings, array $values, array $t): void {
        $iLockWait = self::probeIntFromValues($values, 'innodb_lock_wait_timeout');
        $lockWaitMin = self::thresholdInt($t, 'innodb_lock_wait_timeout_min');
        if ($iLockWait > 0 && $iLockWait < $lockWaitMin) {
            $warnings[] = sprintf(
                'innodb_lock_wait_timeout=%ds (< %ds); the staged build may abort '
                . 'with "Lock wait timeout exceeded" on busy hosts.',
                $iLockWait, $lockWaitMin
            );
        }

        $tmpTable = self::probeIntFromValues($values, 'tmp_table_size');
        $maxHeap = self::probeIntFromValues($values, 'max_heap_table_size');
        $tmpTableMin = self::thresholdInt($t, 'tmp_table_size_min');
        $maxHeapMin = self::thresholdInt($t, 'max_heap_table_size_min');
        if ($tmpTable > 0 && $tmpTable < $tmpTableMin) {
            $warnings[] = sprintf(
                'tmp_table_size=%d (< %d MB); MySQL will spill GROUP BY work to '
                . 'disk earlier and the S9 hits aggregate may slow significantly.',
                $tmpTable, (int)($tmpTableMin / 1048576)
            );
        }
        if ($maxHeap > 0 && $maxHeap < $maxHeapMin) {
            $warnings[] = sprintf(
                'max_heap_table_size=%d (< %d MB); MEMORY-engine temp tables will '
                . 'truncate or spill earlier than expected.',
                $maxHeap, (int)($maxHeapMin / 1048576)
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionSlowLogAndBufferWarnings(array &$warnings, array $values, array $t): void {
        $rawSlow = $values['slow_query_log'] ?? '';
        $rawSlowStr = is_scalar($rawSlow) ? (string)$rawSlow : '';
        $slowOn = (is_numeric($rawSlow) && (int)$rawSlow > 0)
            || strtoupper($rawSlowStr) === 'ON';
        $longTime = self::probeFloatFromValues($values, 'long_query_time');
        $longTimeMin = self::thresholdFloat($t, 'long_query_time_min');
        if ($slowOn && $longTime > 0 && $longTime < $longTimeMin) {
            $warnings[] = sprintf(
                'slow_query_log=ON with long_query_time=%.3fs (< %.1fs); the staged '
                . 'build will flood the slow log with stage-batch queries.',
                $longTime, $longTimeMin
            );
        }

        $bufferPool = self::probeIntFromValues($values, 'innodb_buffer_pool_size');
        $bufferPoolMin = self::thresholdInt($t, 'innodb_buffer_pool_size_min');
        if ($bufferPool > 0 && $bufferPool < $bufferPoolMin) {
            $warnings[] = sprintf(
                'innodb_buffer_pool_size=%d (< %d MB); large logsv2 reads at S2/S9 '
                . 'will thrash the buffer pool.',
                $bufferPool, (int)($bufferPoolMin / 1048576)
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionTimeoutWarnings(array &$warnings, array $values, array $t): void {
        $waitTimeout = self::probeIntFromValues($values, 'wait_timeout');
        $interactiveTimeout = self::probeIntFromValues($values, 'interactive_timeout');
        $waitTimeoutMin = self::thresholdInt($t, 'wait_timeout_min');
        $interactiveTimeoutMin = self::thresholdInt($t, 'interactive_timeout_min');
        if ($waitTimeout > 0 && $waitTimeout < $waitTimeoutMin) {
            $warnings[] = sprintf(
                'wait_timeout=%ds (< %ds); the build connection can drop mid-stage '
                . 'leaving the buffer table orphaned (cf. orphan-table cleanup at runner startup).',
                $waitTimeout, $waitTimeoutMin
            );
        }
        if ($interactiveTimeout > 0 && $interactiveTimeout < $interactiveTimeoutMin) {
            $warnings[] = sprintf(
                'interactive_timeout=%ds (< %ds); same orphan-table risk as wait_timeout.',
                $interactiveTimeout, $interactiveTimeoutMin
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @return void
     */
    private function appendSessionCharsetAndDdlWarnings(array &$warnings, array $values): void {
        $flush = strtoupper(self::probeStringFromValues($values, 'innodb_flush_method'));
        if ($flush === 'O_DSYNC') {
            $warnings[] = 'innodb_flush_method=O_DSYNC; this is the slowest flush '
                . 'mode and large stage writes will be much slower than O_DIRECT.';
        }

        $charset = strtolower(self::probeStringFromValues($values, 'character_set_server'));
        $collation = strtolower(self::probeStringFromValues($values, 'collation_server'));
        if ($charset !== '' && strpos($charset, 'utf8mb4') !== 0) {
            $warnings[] = sprintf(
                'character_set_server=%s (not utf8mb4); 4-byte characters in URLs '
                . 'will be truncated or rejected by the server default.',
                $charset
            );
        }
        if ($collation !== '' && strpos($collation, 'utf8mb4') !== 0) {
            $warnings[] = sprintf(
                'collation_server=%s (not utf8mb4); 4-byte characters in URLs '
                . 'may sort or compare unexpectedly.',
                $collation
            );
        }

        $requirePk = strtoupper(self::probeStringFromValues($values, 'sql_require_primary_key'));
        if ($requirePk === 'ON' || $requirePk === '1') {
            $warnings[] = 'sql_require_primary_key=ON; future CREATE TABLE without '
                . 'a primary key will be rejected by the server.';
        }

        $filePerTable = strtoupper(self::probeStringFromValues($values, 'innodb_file_per_table'));
        if ($filePerTable === 'OFF' || $filePerTable === '0') {
            $warnings[] = 'innodb_file_per_table=OFF; new InnoDB tables share the '
                . 'system tablespace and cannot be reclaimed by DROP.';
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionResourceLimitWarnings(array &$warnings, array $values, array $t): void {
        $threadStack = self::probeIntFromValues($values, 'thread_stack');
        $threadStackMin = self::thresholdInt($t, 'thread_stack_min');
        if ($threadStack > 0 && $threadStack < $threadStackMin) {
            $warnings[] = sprintf(
                'thread_stack=%d bytes (< %dK); deeply nested SQL may exhaust '
                . 'the connection thread stack.',
                $threadStack, (int)($threadStackMin / 1024)
            );
        }
        $openFiles = self::probeIntFromValues($values, 'open_files_limit');
        $openFilesMin = self::thresholdInt($t, 'open_files_limit_min');
        if ($openFiles > 0 && $openFiles < $openFilesMin) {
            $warnings[] = sprintf(
                'open_files_limit=%d (< %d); high-concurrency table opens may '
                . 'fail with "Too many open files".',
                $openFiles, $openFilesMin
            );
        }

        $alterLog = self::probeIntFromValues($values, 'innodb_online_alter_log_max_size');
        $alterLogMin = self::thresholdInt($t, 'innodb_online_alter_log_max_size_min');
        if ($alterLog > 0 && $alterLog < $alterLogMin) {
            $warnings[] = sprintf(
                'innodb_online_alter_log_max_size=%d (< %d MB); the S3 / S10 '
                . 'ALTER TABLE ADD INDEX may fail with "Online DDL log overflow" '
                . 'on busy hosts.',
                $alterLog, (int)($alterLogMin / 1048576)
            );
        }
    }
}
