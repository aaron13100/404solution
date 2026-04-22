<?php
/**
 * Error classification, infrastructure error handling, missing-table repair,
 * and prefix mismatch diagnostics for DataAccess.
 *
 * Extracted from DataAccess.php to keep the main class under the file-size limit.
 * All methods are called via $this-> from DataAccess (trait context).
 *
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ErrorClassificationTrait {

    /**
     * Determine whether an error indicates invalid text/charset payload.
     *
     * @param string $errorText
     * @return bool
     */
    private function isInvalidDataError($errorText) {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return (
            $this->f->strpos($lower, 'contains invalid data') !== false ||
            $this->f->strpos($lower, 'incorrect string value') !== false ||
            $this->f->strpos($lower, 'invalid utf8') !== false
        );
    }

    /**
     * Classify a $wpdb->last_error as an infrastructure issue (disk full, read-only, etc.).
     * If it IS an infrastructure error: logs WARN and calls noteDatabaseIssueFromError().
     * If it is NOT: returns false (caller is responsible for logging at ERROR level).
     *
     * Use this at call sites that bypass queryAndGetResults() and call $wpdb directly.
     * Public so that NGramFilter, DatabaseUpgradesEtc, and other classes can call it
     * via $this->dao->classifyAndHandleInfrastructureError().
     *
     * @param string $errorText The value of $wpdb->last_error.
     * @return bool True if the error was classified as infrastructure (already handled).
     */
    public function classifyAndHandleInfrastructureError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }

        if ($this->isDiskFullError($errorText) ||
            $this->isReadOnlyError($errorText) ||
            $this->isQuotaLimitError($errorText) ||
            $this->isInvalidDataError($errorText) ||
            $this->isCollationError($errorText) ||
            $this->isMissingPluginTableError($errorText) ||
            $this->isIncorrectKeyFileError($errorText) ||
            $this->isCrashedTableError($errorText) ||
            $this->isDeadlockOrLockTimeoutError($errorText) ||
            $this->isTransientConnectionError($errorText)
        ) {
            $this->logger->warn("Server-side DB issue (handled): " . $errorText);
            $this->noteDatabaseIssueFromError($errorText);
            return true;
        }

        return false;
    }

    /** @param string|null $errorText @return bool */
    private function isTransientConnectionError(?string $errorText): bool {
        $errorText = $errorText ?? '';
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        $transientMarkers = array(
            'server has gone away',
            'lost connection to mysql server during query',
            'error while sending query packet',
            'packets out of order',
            'connection was killed',
        );
        foreach ($transientMarkers as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param string $errorText @return bool */
    private function isQuotaLimitError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'max_questions') !== false ||
            $this->f->strpos($lower, 'resource') !== false && $this->f->strpos($lower, 'question') !== false);
    }

    /** @param string $errorText @return bool */
    private function isDiskFullError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        // "Got error 28 from storage engine" (ER_GET_ERRNO with POSIX ENOSPC)
        // "errno: 28" / "Errcode: 28" (ER_DISK_FULL, ER_ERROR_ON_WRITE)
        // "No space left on device" (OS strerror for ENOSPC, English only)
        // "The table '...' is full" (ER_RECORD_FILE_FULL / error 1114)
        // "Disk full" (ER_DISK_FULL)
        // Note: on servers with non-English lc_messages, the text around "28"
        // may be translated (e.g. "erreur 28" in French), but the numeric 28
        // always appears. The strpos checks cover all known English MySQL/MariaDB
        // message formats; non-English servers are rare in WordPress hosting.
        return ($this->f->strpos($lower, 'error 28') !== false ||
            $this->f->strpos($lower, 'errno: 28') !== false ||
            $this->f->strpos($lower, 'errcode: 28') !== false ||
            $this->f->strpos($lower, 'no space left on device') !== false ||
            $this->f->strpos($lower, "' is full") !== false ||
            $this->f->strpos($lower, 'table is full') !== false ||
            $this->f->strpos($lower, 'disk full') !== false);
    }

    /** @param string $errorText @return bool */
    private function isReadOnlyError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'read only') !== false ||
            $this->f->strpos($lower, 'read-only') !== false ||
            $this->f->strpos($lower, 'super_read_only') !== false);
    }

    /** @param string $errorText @return bool */
    private function isCollationError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'illegal mix of collations') !== false ||
            $this->f->strpos($lower, 'unknown collation') !== false ||
            $this->f->strpos($lower, 'collation') !== false && $this->f->strpos($lower, 'not valid') !== false);
    }

    /** @param string $errorText @return bool */
    private function isCrashedTableError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        return stripos($errorText, 'is marked as crashed') !== false;
    }

    /** @param string $errorText @return bool */
    private function isIncorrectKeyFileError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        return stripos($errorText, 'Incorrect key file') !== false;
    }

    /** @param string $errorText @return bool */
    private function isDeadlockOrLockTimeoutError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'deadlock found') !== false ||
            $this->f->strpos($lower, 'lock wait timeout exceeded') !== false ||
            $this->f->strpos($lower, 'error 1213') !== false ||
            $this->f->strpos($lower, 'error 1205') !== false);
    }

    /**
     * Extract a table name from a MySQL "table is full" error message.
     * MySQL formats this as: The table 'table_name' is full
     * @param string $errorText
     * @return string|null The table name, or null if not parseable.
     */
    private function extractTableNameFromFullError(string $errorText): ?string {
        if (preg_match("/table '([^']+)' is full/i", $errorText, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Check if a given table uses the InnoDB storage engine.
     * Returns false on any query failure (safe default).
     * @param string $tableName
     * @return bool
     */
    private function isInnoDBTable(string $tableName): bool {
        global $wpdb;
        /** @var wpdb $wpdb */
        if (!method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false; // Safe default when $wpdb is a partial stub
        }
        $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                $dbName,
                $tableName
            )
        );
        return is_string($engine) && strtolower($engine) === 'innodb';
    }

    private function noteDatabaseIssueFromError(string $errorText): void {
        if (!is_string($errorText) || trim($errorText) === '') {
            return;
        }
        if ($this->isDiskFullError($errorText)) {
            $this->serverSideIssueNoted = true;
            $this->setRuntimeFlag('abj404_db_disk_full_until', time() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);

            // Disambiguate InnoDB tablespace exhaustion from actual disk full or MyISAM limit.
            // "table is full" for InnoDB means the shared tablespace (ibdata1) is at capacity —
            // trimming plugin rows will NOT free space; the host must expand the tablespace.
            $tableFull = stripos($errorText, 'table') !== false && stripos($errorText, 'is full') !== false;
            if ($tableFull) {
                $tableName = $this->extractTableNameFromFullError($errorText);
                if ($tableName !== null && $this->isInnoDBTable($tableName)) {
                    $this->setPluginDbNotice('disk_full', $this->localizeOrDefault('The InnoDB tablespace appears to be exhausted. Deleting plugin data will NOT free this space. Contact your hosting provider to expand the InnoDB tablespace (ibdata1).'), $errorText);
                    return;
                }
            }

            $this->setPluginDbNotice('disk_full', $this->localizeOrDefault('Database storage appears full (disk/engine space). Plugin write-heavy tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isQuotaLimitError($errorText)) {
            $this->serverSideIssueNoted = true;
            $this->setRuntimeFlag('abj404_db_quota_cooldown_until', time() + self::DB_QUOTA_COOLDOWN_SECONDS, self::DB_QUOTA_COOLDOWN_SECONDS);
            $this->setPluginDbNotice('query_quota', $this->localizeOrDefault('Database query quota was exceeded (for example max_questions). Non-essential plugin background tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isReadOnlyError($errorText)) {
            $this->serverSideIssueNoted = true;
            $this->setRuntimeFlag('abj404_db_read_only_until', time() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
            $this->setPluginDbNotice('read_only', $this->localizeOrDefault('Database appears to be in read-only mode. Plugin write operations are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isCollationError($errorText)) {
            $this->setPluginDbNotice('collation', $this->localizeOrDefault('Database collation mismatch was detected. A compatibility fallback was used where possible.'), $errorText);
        }
    }

    /** @return bool */
    private function isQuotaCooldownActive(): bool {
        $rawQuotaFlag = $this->getRuntimeFlag('abj404_db_quota_cooldown_until');
        $until = is_scalar($rawQuotaFlag) ? (int)$rawQuotaFlag : 0;
        return ($until > time());
    }

    /** @param string $errorText @return bool */
    private function isMissingPluginTableError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        if ($this->f->strpos($lower, '_abj404_logs_hits') !== false) {
            return false;
        }
        return ($this->f->strpos($lower, "doesn't exist") !== false &&
            $this->f->strpos($lower, '_abj404_') !== false);
    }

    /**
     * Attempt one auto-repair pass for missing plugin tables, then retry query once.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    private function attemptMissingTableRepairAndRetry($query, &$result) {
        if (self::$tableRepairInProgress) {
            return;
        }

        // Rate-limit repeated failures: after a failed repair, downgrade subsequent
        // occurrences to WARNING for 24 hours so cron-per-run error storms don't
        // generate email reports. The first failure still logs ERROR and attempts repair.
        $repairCooldownKey = 'abj404_missing_table_repair_cooldown';
        $cooldownUntil = $this->getRuntimeFlag($repairCooldownKey);
        if (is_scalar($cooldownUntil) && (int)$cooldownUntil > time()) {
            $this->logger->warn("Missing plugin table (repair previously failed, cooldown active): "
                . $result['last_error']);
            // Clear last_error so the caller (queryAndGetResults) does not
            // double-report this error as "Ugh. SQL query error" ERROR.
            $result['last_error'] = '';
            return;
        }

        // During upgrades and nightly maintenance, createDatabaseTables() runs
        // proactively before any queries.  If we reach this point, a plugin table
        // went missing during normal usage.  Log as INFO while we attempt repair;
        // only escalate to ERROR if repair fails (avoids flooding admin with
        // error emails for transient issues that auto-repair resolves).
        $originalSqlError = is_string($result['last_error']) ? $result['last_error'] : '';
        $this->logger->infoMessage("Missing plugin table detected during query. "
            . "Attempting auto-repair. SQL error: " . $originalSqlError);

        self::$tableRepairInProgress = true;
        try {
            $upgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
            // Pass $force = true so the repair bypasses the concurrency lock — if another
            // request holds the lock (e.g. a concurrent upgrade), calling createDatabaseTables
            // without $force would silently return without creating anything, leaving the
            // missing table unrepaired.  Concurrent CREATE TABLE IF NOT EXISTS calls are safe
            // (idempotent), so bypassing the lock here is correct.
            $upgrades->createDatabaseTables(false, true);

            global $wpdb;
            $wpdb->flush();
            // Suppress WP's own error output for the retry — if it also fails, we
            // report it ourselves below.  Without this, WP logs a second
            // "WordPress database error" entry on top of the first, producing
            // duplicate noise in debug.log for every failed cron run.
            $prevSuppressState = $wpdb->suppress_errors(true);
            $result['rows'] = $wpdb->get_results($query, $this->currentResultType);
            $wpdb->suppress_errors($prevSuppressState);
            $this->harvestWpdbResult($result);

            if ($result['last_error'] === '') {
                $this->logger->infoMessage("Missing-table auto-repair succeeded.");
                // Clear any active cooldown — repair is now working.
                if (function_exists('delete_transient')) {
                    delete_transient($repairCooldownKey);
                } elseif (function_exists('delete_option')) {
                    delete_option($repairCooldownKey);
                }
                // If a stale missing_table notice exists from an earlier failed
                // repair attempt, clear it immediately now that repair succeeded.
                $this->clearPluginDbNoticeIfType('missing_table');
            } else {
                // Check for prefix mismatch: plugin tables may exist under a
                // different $table_prefix than the current $wpdb->prefix (common
                // after site migrations or hosting panel clones).
                $prefixDiag = $this->diagnosePrefixMismatch();

                // Multisite cross-prefix: a query referenced another subsite's table.
                // The plugin correctly created tables for the current site, but cannot
                // fix another subsite's missing tables from this request context.
                // That subsite will get its tables when its own cron fires.
                if ($this->isMultisiteCrossPrefixError($originalSqlError)) {
                    $this->logger->warn("Multisite cross-prefix table reference (not actionable from this site). "
                        . "Current prefix: " . ($wpdb->prefix ?? '')
                        . ", Original error: " . $originalSqlError . $prefixDiag);
                    // Clear last_error so queryAndGetResults() does not double-report.
                    $result['last_error'] = '';
                    return;
                }

                // Repair failed — now escalate to ERROR so it triggers email notification.
                $this->logger->errorMessage("Missing plugin table auto-repair failed. "
                    . "Original error: " . $originalSqlError
                    . ", Retry error: " . $result['last_error']
                    . $prefixDiag);
                // Engage 24h cooldown and surface a single admin notice on
                // the plugin screen so the admin knows to investigate (e.g. missing CREATE
                // privilege or wrong DB user).  Never email; never show on all wp-admin pages.
                $this->setRuntimeFlag($repairCooldownKey, time() + 86400, 86400);
                $adminMsg = 'A plugin database table is missing and could not be repaired automatically. '
                    . 'Try deactivating and reactivating 404 Solution, or verify that your database user has CREATE TABLE privileges.';
                if ($prefixDiag !== '') {
                    $adminMsg .= ' ' . $prefixDiag;
                }
                $noticePayload = array(
                    'type'         => 'missing_table',
                    'message'      => $this->localizeOrDefault($adminMsg),
                    'timestamp'    => time(),
                    'error_string' => $result['last_error'],
                );
                $this->setRuntimeFlag('abj404_plugin_db_notice', $noticePayload, 86400);
            }
        } catch (Throwable $e) {
            $this->logger->warn("Missing-table auto-repair failed: " . $e->getMessage());
            $this->setRuntimeFlag($repairCooldownKey, time() + 86400, 86400);
        } finally {
            self::$tableRepairInProgress = false;
        }
    }

    /**
     * Check whether plugin tables exist under a different prefix than $wpdb->prefix.
     *
     * After site migrations or hosting panel clones, $table_prefix in wp-config.php
     * may differ from the prefix used when the plugin tables were originally created.
     * Returns a diagnostic string if a mismatch is detected, empty string otherwise.
     *
     * @return string Diagnostic message or empty string.
     */
    private function diagnosePrefixMismatch(): string {
        global $wpdb;
        try {
            $dbName = $wpdb->dbname ?? '';
            if ($dbName === '') {
                return '';
            }
            $dbNameEscaped = esc_sql($dbName);
            $dbNameStr = is_array($dbNameEscaped) ? '' : $dbNameEscaped;
            // Find any table containing 'abj404_redirects' in this database.
            $rows = $wpdb->get_results(
                "SELECT table_name FROM information_schema.tables "
                . "WHERE table_schema = '{$dbNameStr}' "
                . "AND LOWER(table_name) LIKE '%abj404\_redirects'",
                ARRAY_A
            );
            if (!is_array($rows) || empty($rows)) {
                return '';
            }
            $expectedTable = $this->getLowercasePrefix() . 'abj404_redirects';
            $foundTables = [];
            foreach ($rows as $row) {
                // Case-insensitive key lookup (MySQL driver inconsistency).
                $name = null;
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'table_name') {
                        $name = (string)$value;
                        break;
                    }
                }
                if ($name !== null) {
                    $foundTables[] = $name;
                }
            }
            // Filter out the table we're already looking for.
            $mismatched = array_filter($foundTables, function ($t) use ($expectedTable) {
                return strtolower($t) !== strtolower($expectedTable);
            });
            if (empty($mismatched)) {
                return '';
            }
            $msg = ', PREFIX MISMATCH DETECTED: $wpdb->prefix is "' . ($wpdb->prefix ?? '')
                . '" (expected table: ' . $expectedTable . ') but plugin tables exist as: '
                . implode(', ', $mismatched) . '.';
            if (function_exists('is_multisite') && is_multisite()) {
                $msg .= ' This is a multisite installation — the other prefixes likely belong to other subsites (normal).';
            } else {
                $msg .= ' Check $table_prefix in wp-config.php.';
            }
            return $msg;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Detect whether a missing-table error references a different multisite subsite's prefix.
     *
     * On network-activated multisite, wp-cron can fire queries that reference tables
     * from a different subsite's prefix (e.g. wp_4_abj404_* while current prefix is wp_).
     * This is not an error — the other subsite's tables exist under its own prefix and
     * will be serviced when that subsite's cron fires.
     *
     * @param string $errorText The MySQL error string.
     * @return bool True if the error references a different multisite subsite's prefix.
     */
    private function isMultisiteCrossPrefixError(string $errorText): bool {
        if ($errorText === '' || !function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        global $wpdb;
        // Extract table name from error. MySQL formats:
        //   Table 'dbname.tablename' doesn't exist
        //   Table `dbname`.`tablename` doesn't exist
        if (!preg_match("/['\x60](?:[^'\x60]+\.)?([^'\x60]*abj404_[^'\x60]+)['\x60]/i", $errorText, $matches)) {
            return false;
        }
        $referencedTable = strtolower($matches[1]);

        $currentPrefix = strtolower($wpdb->prefix ?? 'wp_');
        $basePrefix = strtolower($wpdb->base_prefix ?? 'wp_');

        // If the table starts with the current prefix, it's genuinely missing for THIS site.
        if (strpos($referencedTable, $currentPrefix . 'abj404_') === 0) {
            return false;
        }

        // Check if it matches {base_prefix}{N}_abj404_ (a different subsite's table).
        $pattern = '/^' . preg_quote($basePrefix, '/') . '(\d+)_abj404_/';
        if (preg_match($pattern, $referencedTable)) {
            return true;
        }

        return false;
    }
}
