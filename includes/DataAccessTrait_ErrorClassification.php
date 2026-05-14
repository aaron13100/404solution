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
            $this->isGaleraConflictError($errorText) ||
            $this->isTransientConnectionError($errorText) ||
            $this->isQueryTimeoutError($errorText) ||
            $this->isAccessDeniedError($errorText)
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
        // Numeric client-error codes for the connection-drop class. Some PDO
        // / driver surfaces (and translated MySQL builds where the English
        // text is missing) emit "(2006)", "[2006]", "errno 2006", or a
        // SQLSTATE-formatted "SQLSTATE[HY000]: General error: 2006 ..." with
        // no canonical "server has gone away" wording. Match the unambiguous
        // bracketed / parenthesized / errno-prefixed forms so a bare "2006"
        // appearing in some unrelated text (year, ID, row count) does not
        // misclassify. 2006 = CR_SERVER_GONE_ERROR, 2013 = CR_SERVER_LOST.
        foreach (array('2006', '2013') as $code) {
            if ($this->f->strpos($lower, '[' . $code . ']') !== false
                || $this->f->strpos($lower, '(' . $code . ')') !== false
                || $this->f->strpos($lower, 'errno ' . $code) !== false
                || $this->f->strpos($lower, 'errno: ' . $code) !== false
                || $this->f->strpos($lower, 'error: ' . $code . ' ') !== false
                || $this->f->strpos($lower, 'error ' . $code . ':') !== false) {
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

    /**
     * Detect MySQL/MariaDB access-denied errors. ER_DBACCESS_DENIED_ERROR
     * (1044) and ER_TABLEACCESS_DENIED_ERROR (1142) fire when the configured
     * DB user lacks rights for the requested operation: typical on hosting
     * providers where the plugin's CREATE TABLE / DROP TABLE privileges are
     * revoked, or where wp_options has been moved between databases.
     * Server config issue, not a plugin bug. Should be a WARN, not an ERROR.
     *
     * @param string $errorText
     * @return bool
     */
    private function isAccessDeniedError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'access denied') !== false ||
            $this->f->strpos($lower, 'command denied') !== false);
    }

    /**
     * True when an error indicates that the `SET STATEMENT max_statement_time=N FOR ...`
     * timeout wrapper itself was rejected by the server (privilege denied or
     * syntax not understood). Distinct from an error in the wrapped query.
     *
     * Hosts that reject the wrapper:
     *   1. MariaDB requiring SUPER for SET STATEMENT (errno 1227 / SQLSTATE 42000)
     *   2. ProxySQL / older audit firewalls that do not parse the prefix and
     *      return a syntax error (errno 1064 / SQLSTATE 42000) on "SET STATEMENT"
     *   3. Galera clusters that reject SET STATEMENT in some replication modes
     *
     * The caller MUST also confirm the failed query actually started with
     * a `SET STATEMENT max_statement_time=` prefix before treating the error
     * as a wrapper rejection. Generic access-denied or syntax errors on
     * other query shapes are not recoverable by stripping a wrapper that
     * was never there.
     *
     * @param string $errorText
     * @return bool
     */
    private function classifySetStatementFailure(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        // SUPER privilege required (MariaDB SET STATEMENT requires SUPER on
        // some configurations). The error is access-denied class, but the
        // SUPER-privilege phrasing is the unambiguous tell. Generic
        // table-access-denied uses "for user" or names a table.
        if ($this->f->strpos($lower, 'super privilege') !== false ||
            $this->f->strpos($lower, 'super_privilege') !== false ||
            $this->f->strpos($lower, '(at least one of) the super') !== false) {
            return true;
        }
        // ProxySQL / firewall syntax-error path: "syntax error" or
        // "you have an error in your sql syntax" combined with "SET STATEMENT"
        // mentioned in the error context. The wpdb->last_error often echoes
        // a leading slice of the offending query.
        if (($this->f->strpos($lower, 'syntax error') !== false ||
             $this->f->strpos($lower, 'error in your sql syntax') !== false ||
             $this->f->strpos($lower, '1064') !== false) &&
            $this->f->strpos($lower, 'set statement') !== false) {
            return true;
        }
        return false;
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

    /** Detect MySQL MAX_EXECUTION_TIME (errno 3024) and MariaDB max_statement_time (errno 1969) timeouts.
     * @param string $errorText @return bool */
    private function isQueryTimeoutError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        return (strpos($errorText, '3024') !== false ||
            strpos($errorText, '1969') !== false ||
            stripos($errorText, 'max_execution_time') !== false ||
            stripos($errorText, 'max_statement_time') !== false);
    }

    /**
     * Detect MySQL/MariaDB max_allowed_packet errors. Default error message:
     * "Got a packet bigger than 'max_allowed_packet' bytes" (errno 1153).
     * Routed by the staged-build orchestrator into batch-shrink recovery so
     * a host with a small packet limit doesn't loop forever on the same
     * oversized INSERT.
     *
     * @param string $errorText
     * @return bool
     */
    private function isPacketTooLarge(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'max_allowed_packet') !== false ||
            $this->f->strpos($lower, 'got a packet bigger') !== false ||
            $this->f->strpos($lower, '1153') !== false);
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
     * Detect MariaDB Galera optimistic-concurrency rejections.
     *
     * Galera (wsrep) clusters use optimistic concurrency control: a node
     * accepts a write locally, then certifies it against the cluster on
     * commit. If another node already wrote to the same row, certification
     * fails and the local transaction is rolled back with errno 1020 /
     * ER_CHECKREAD ("Record has changed since last read in table 'X'").
     * Other related markers carry "wsrep_" or "cluster conflict" wording.
     *
     * Structurally this is the same retry-able conflict shape as InnoDB
     * deadlock (errno 1213) and lock-wait timeout (errno 1205), but the
     * error wording is different so isDeadlockOrLockTimeoutError() does
     * not match. Like deadlock, the next cron tick can simply retry; it
     * is a server-side coordination failure, not a plugin bug, and must
     * be logged at WARN (not ERROR, which emails the admin).
     *
     * Source: 4.1.15 site (ohafiatv) running MariaDB 11.8.3 emitted 3 of
     * these errors from updatePermalinkCache.sql; another cluster node was
     * writing the same {prefix}_abj404_permalink_cache row.
     *
     * @param string $errorText
     * @return bool
     */
    private function isGaleraConflictError(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'record has changed since last read') !== false ||
            $this->f->strpos($lower, 'wsrep_local_state') !== false ||
            $this->f->strpos($lower, 'cluster conflict') !== false);
    }

    /**
     * Detect PHP "Allowed memory size of N bytes exhausted" / "Out of memory"
     * messages. Real OOM is a fatal that bypasses try/catch, but a Throwable
     * wrapper (e.g. PHP 8 Error subclass surfaced from a memory-aware hook,
     * or an explicit guard that pre-rejects an over-budget allocation) can
     * carry the same wording. Routed through the staged-build classifier so
     * S9 (optional) skips on OOM instead of bubbling out as a stage error.
     *
     * @param string $errorText
     * @return bool
     */
    public function isOutOfMemoryError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'allowed memory size') !== false ||
            $this->f->strpos($lower, 'out of memory') !== false ||
            $this->f->strpos($lower, 'memory exhausted') !== false ||
            $this->f->strpos($lower, 'memory_limit') !== false);
    }

    /**
     * True when an error from a staged-build query represents a permanent
     * host-side environmental constraint we cannot recover from by retrying:
     * GRANT-revoked privilege (CREATE TEMPORARY TABLES, ALTER, RENAME),
     * read-only replica, exhausted disk/quota, table marked crashed (a
     * crashed plugin table on a stage that does DDL we can't repair our
     * way out of), or a PHP-side OOM. Re-running the same query on the
     * next cron tick will just produce the same error.
     *
     * Used by classifyStageFailure() to decide between "skip optional stage"
     * and "halt critical stage". Resumable kills (max_statement_time, lock
     * waits, gone-away) are NOT permanent and are already handled by
     * isResumableStagedKill().
     *
     * Programmer-class errors (syntax, undefined column, unknown function)
     * deliberately return false: we want those to surface as bugs, not be
     * silently degraded around. (Codex pushback in test docblock for
     * testStage9SyntaxErrorIsNotSilentlySkipped.)
     *
     * @param string $errorText
     * @return bool
     */
    private function isPermanentHostSideStagedFailure(string $errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        if ($this->isResumableStagedKill($errorText)) {
            return false;
        }
        return ($this->isAccessDeniedError($errorText)
            || $this->isReadOnlyError($errorText)
            || $this->isDiskFullError($errorText)
            || $this->isQuotaLimitError($errorText)
            || $this->isOutOfMemoryError($errorText));
    }

    /**
     * Per-stage classification for an error raised inside the staged view
     * build. Routes the orchestrator's catch block instead of the legacy
     * binary "resumable-kill or rethrow" decision: the staged build has
     * stages that can be skipped without breaking publication (S3/S9/S10:
     * adds/aggregates) and stages that genuinely cannot proceed without
     * (S1 create, S2 insert, S11 swap).
     *
     * Returns one of:
     *   - 'resumable' : kill class the next tick can retry (existing behavior)
     *   - 'skip'      : permanent host failure on an optional stage; mark
     *                   the stage permanently skipped, advance past it
     *   - 'halt'      : permanent host failure on a critical stage; stop
     *                   re-trying, surface a deduplicated admin notice
     *   - 'rethrow'   : programmer-class or unknown error; let it propagate
     *                   so the dev mailbox carries actionable context
     *
     * The per-stage policy lives on
     * ABJ_404_Solution_ViewBuildConfig::stageFailurePolicy() so it can be
     * tuned without touching this classifier.
     *
     * @param int    $stageNumber  1..11
     * @param string $errorText
     * @return string
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        if (!is_string($errorText) || $errorText === '') {
            return 'rethrow';
        }
        if ($this->isResumableStagedKill($errorText)) {
            return 'resumable';
        }
        if (!$this->isPermanentHostSideStagedFailure($errorText)) {
            return 'rethrow';
        }
        $policy = ABJ_404_Solution_ViewBuildConfig::stageFailurePolicy($stageNumber);
        return $policy === 'optional' ? 'skip' : 'halt';
    }

    /**
     * True when an error from a staged-build query represents a kill the
     * host inflicted on us (out of our control) that the build can resume
     * from on the next request. The staged pipeline persists progress
     * (current_stage, batch high-water ids) on every batch boundary, so
     * any of these classes can be safely converted to "yield this tick"
     * without losing work.
     *
     * Covered: query-timeout kills (max_statement_time / max_execution_time
     * exceeded, "Query execution was interrupted"), transient connection
     * loss ("server has gone away", "Lost connection"), and lock-wait /
     * deadlock kills. Any of these on a slow shared host will end the
     * stage's query without ending the request, and we want the build to
     * keep making forward progress on the next tick instead of returning
     * a 500 that breaks the JS poll loop.
     *
     * @param string $errorText
     * @return bool
     */
    private function isResumableStagedKill(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        if ($this->isQueryTimeoutError($errorText)) {
            return true;
        }
        if ($this->isTransientConnectionError($errorText)) {
            return true;
        }
        if ($this->isDeadlockOrLockTimeoutError($errorText)) {
            return true;
        }
        // "Query execution was interrupted" is the bare MariaDB / MySQL
        // message variant that does not always carry the "max_statement_time"
        // substring (server-side KILL QUERY, client cancellation, replica
        // failover). Same resume semantics.
        if (stripos($errorText, 'query execution was interrupted') !== false) {
            return true;
        }
        // max_allowed_packet exceeded: the next tick's batch-shrink path
        // will halve the batch size and retry, exactly like a host-killed
        // batch. Without this, an oversized INSERT loops with the same
        // packet error and never converges (same infinite-retry shape as
        // the access-denied bug pre-classifier).
        if ($this->isPacketTooLarge($errorText)) {
            return true;
        }
        return false;
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
        if (defined('DB_NAME')) {
            $dbName = (string)DB_NAME;
        } else {
            // Per-request warn once: silent empty-string fallback hides
            // whether the schema-probe is actually working in tests that
            // forget to define DB_NAME (Smell 1 from error-swallow audit).
            static $warnedNoDbName = false;
            if (!$warnedNoDbName) {
                $warnedNoDbName = true;
                $this->logger->warn(__METHOD__ . ': DB_NAME undefined; using empty schema in InnoDB probe');
            }
            $dbName = '';
        }
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
        if (trim($errorText) === '') {
            return;
        }
        if ($this->isDiskFullError($errorText)) {
            $this->serverSideIssueNoted = true;
            $this->setRuntimeFlag('abj404_db_disk_full_until', $this->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);

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
            $this->setRuntimeFlag('abj404_db_quota_cooldown_until', $this->clock()->now() + self::DB_QUOTA_COOLDOWN_SECONDS, self::DB_QUOTA_COOLDOWN_SECONDS);
            $this->setPluginDbNotice('query_quota', $this->localizeOrDefault('Database query quota was exceeded (for example max_questions). Non-essential plugin background tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isReadOnlyError($errorText)) {
            $this->serverSideIssueNoted = true;
            $this->setRuntimeFlag('abj404_db_read_only_until', $this->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
            $this->setPluginDbNotice('read_only', $this->localizeOrDefault('Database appears to be in read-only mode. Plugin write operations are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isCollationError($errorText)) {
            // Per owner directive: collation issues must NEVER surface as user notices.
            // The plugin auto-recovers by running correctCollations() at query time
            // (see DataAccess::recoverFromCollationMismatchAndRetry()).  Here we only
            // log the original error at debug level so developers can see it in
            // debug.txt without the user ever being notified.
            $this->logger->debugMessage("Collation mismatch detected (auto-recovery will run): " . $errorText);
        }
    }

    /** @return bool */
    private function isQuotaCooldownActive(): bool {
        $rawQuotaFlag = $this->getRuntimeFlag('abj404_db_quota_cooldown_until');
        $until = is_scalar($rawQuotaFlag) ? (int)$rawQuotaFlag : 0;
        return ($until > $this->clock()->now());
    }

    /** @param string $errorText @return bool */
    private function isMissingPluginTableError(string $errorText): bool {
        if ($errorText === '') {
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
     * The staged-view-build pipeline owns three transient tables
     * (view_build, view_done, view_deleteme). They are created and dropped
     * between build cycles by design; discoverPermanentDDLFiles() already
     * excludes them from createDatabaseTables(). A SELECT that hits a
     * swap-window race against any of them is not a corruption signal and
     * must not surface the missing_table admin notice or engage the 1h
     * repair cooldown.
     *
     * @param string $errorText
     * @return bool
     */
    private function isTransientViewBuildTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, '_abj404_view_build') !== false ||
            $this->f->strpos($lower, '_abj404_view_done') !== false ||
            $this->f->strpos($lower, '_abj404_view_deleteme') !== false);
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

        // Transient staged-view-build tables (view_build, view_done, view_deleteme)
        // are owned by the staged-build pipeline and are created and dropped
        // between cycles. discoverPermanentDDLFiles() excludes them from
        // createDatabaseTables(), so the repair path cannot recreate them and
        // would fall straight into the failed-repair branch, setting the
        // missing_table admin notice on every plugin page and engaging a 1h
        // cooldown that blocks legit missing-table repair for the redirects /
        // logsv2 / etc. core tables. Silently degrade: clear last_error so the
        // caller treats the swap-window race as an empty result, and skip the
        // notice / cooldown side effects entirely.
        $observedError = is_string($result['last_error']) ? $result['last_error'] : '';
        if ($this->isTransientViewBuildTableError($observedError)) {
            $this->logger->debugMessage(
                "Transient staged-view-build table missing (swap-window race, expected): "
                . $observedError
            );
            $result['last_error'] = '';
            return;
        }

        // Rate-limit repeated failures: after a failed repair, downgrade subsequent
        // occurrences to WARNING for 1 hour so cron-per-run error storms don't
        // generate email reports. The first failure still logs ERROR and attempts repair.
        // 1 hour (not 24h) — a transient race during the repair (e.g. concurrent wp-cron
        // firing) can cause one failure that would clear by the next page load. A 24h
        // lockout permanently disables self-healing for the rest of the admin session.
        $repairCooldownKey = 'abj404_missing_table_repair_cooldown';
        $cooldownTtlSeconds = 3600;
        $cooldownUntil = $this->getRuntimeFlag($repairCooldownKey);
        if (is_scalar($cooldownUntil) && (int)$cooldownUntil > $this->clock()->now()) {
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
        $missingTable = $this->extractMissingTableNameFromError($originalSqlError);
        $this->logger->infoMessage("Missing plugin table detected during query. "
            . "Attempting auto-repair. SQL error: " . $originalSqlError);

        self::$tableRepairInProgress = true;
        try {
            $upgrades = abj_service('database_upgrades');
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

                // Repair failed. Log at WARN, not ERROR. Per the self-healing
                // philosophy in CLAUDE.md (item 4): "Notify if recovery fails ...
                // Never send email." The admin notice set below is the user-facing
                // surface, gated to the plugin's own admin page. errorMessage()
                // triggers the daily email digest; warn() does not. Previously
                // this site emailed the developer once per cooldown expiry (every
                // 1h) for any permanently-broken table, which is the email-storm
                // pattern Bruno's and the kstal-site logs both exhibit.
                // Include the specific table that failed plus an explicit post-CREATE
                // existence check so the debug log distinguishes "CREATE didn't materialize
                // the table" (concurrency race, swallowed SQL error in queryAndGetResults,
                // insufficient privileges) from other retry-failure modes.
                $tableStillMissing = ($missingTable !== '' && !$this->tableExists($missingTable));
                $tableContext = ($missingTable !== '')
                    ? " Table: " . $missingTable . "."
                    : '';
                $existenceContext = $tableStillMissing
                    ? ' Table is still missing after CREATE TABLE ran. '
                    . 'createDatabaseTables() did not materialize this table '
                    . '(likely a concurrent DROP, swallowed SQL error in queryAndGetResults, '
                    . 'or insufficient CREATE TABLE privileges).'
                    : '';
                $this->logger->warn("Missing plugin table auto-repair failed."
                    . $tableContext
                    . $existenceContext
                    . " Original error: " . $originalSqlError
                    . ", Retry error: " . $result['last_error']
                    . $prefixDiag);
                // Engage 1h cooldown and surface a single admin notice on
                // the plugin screen so the admin knows to investigate.
                // Never email; never show on all wp-admin pages.
                $this->setRuntimeFlag($repairCooldownKey, $this->clock()->now() + $cooldownTtlSeconds, $cooldownTtlSeconds);
                $tableLabel = ($missingTable !== '') ? "'" . $missingTable . "'" : 'a plugin database table';
                $rawError = is_string($result['last_error']) ? $result['last_error'] : '';
                $adminMsg =
                      '404 Solution cannot function correctly: the database table '
                    . $tableLabel . ' is missing, and the plugin tried to recreate it '
                    . 'but the CREATE TABLE statement could not be executed. '
                    . 'This almost always means the WordPress database user does not '
                    . 'have permission to run CREATE TABLE (and likely ALTER TABLE / '
                    . 'CREATE INDEX) on this database. Until this is fixed, the plugin '
                    . 'cannot record 404s, serve redirects, or generate suggestions. '
                    . 'To fix it: ask your hosting provider or database administrator '
                    . 'to grant CREATE, ALTER, and INDEX privileges to the WordPress '
                    . 'database user for this site, then reload this page. '
                    . 'Alternatively, restore the missing table from a recent database backup.';
                if ($rawError !== '') {
                    $adminMsg .= ' Original database error: ' . $rawError;
                }
                if ($prefixDiag !== '') {
                    $adminMsg .= ' ' . $prefixDiag;
                }
                $noticePayload = array(
                    'type'         => 'missing_table',
                    'message'      => $this->localizeOrDefault($adminMsg),
                    'timestamp'    => $this->clock()->now(),
                    'error_string' => $rawError,
                );
                $this->setRuntimeFlag('abj404_plugin_db_notice', $noticePayload, 86400);
            }
        } catch (Throwable $e) {
            $this->logger->warn("Missing-table auto-repair failed: " . $e->getMessage());
            $this->setRuntimeFlag($repairCooldownKey, $this->clock()->now() + $cooldownTtlSeconds, $cooldownTtlSeconds);
        } finally {
            self::$tableRepairInProgress = false;
        }
    }

    /**
     * Extract the unprefixed-by-database table name from a MySQL "doesn't exist"
     * error message. Returns the bare table name (e.g. "wp_abj404_redirects")
     * or empty string if the error format does not match.
     *
     * MySQL emits errors as either:
     *   Table 'dbname.tablename' doesn't exist
     *   Table 'tablename' doesn't exist
     * The database-name segment is stripped because callers want the live
     * table name suitable for SHOW TABLES LIKE.
     *
     * @param string $errorText
     * @return string
     */
    private function extractMissingTableNameFromError(string $errorText): string {
        if ($errorText === '') {
            return '';
        }
        if (!preg_match("/Table '([^']+)' doesn't exist/i", $errorText, $matches)) {
            return '';
        }
        $fullName = $matches[1];
        $dotPos = strrpos($fullName, '.');
        return $dotPos !== false ? substr($fullName, $dotPos + 1) : $fullName;
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
            // @utf8-audit: opt-out — $wpdb->dbname is set by WordPress at
            // bootstrap from wp-config.php; never user input.
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
