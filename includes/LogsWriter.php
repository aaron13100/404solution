<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log-write pipeline for the wp_abj404_logsv2 table.
 *
 * Responsibilities:
 *  - logRedirectHit: build a log entry from the live request context (IP hash,
 *    referrer, current user, charset probe for requested_url, pipeline trace
 *    serialization) and enqueue it.
 *  - queueLogEntry / flushLogQueue: shutdown-deferred batched INSERT IGNORE
 *    with allow-list column validation and per-entry sanitization.
 *  - Recovery: table-full auto-trim, isolated wpdb fallback on
 *    "commands out of sync", per-row retry when the batch fails.
 *
 * The queue is process-static so multiple LogsWriter instances within the
 * same request share one batch. The shutdown hook is registered once.
 *
 * Extracted from LogsRepository under M201. Consumed by the LogsRepository
 * facade.
 */
class ABJ_404_Solution_LogsWriter {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_DatabaseCollationHelper */
    private $collationHelper;

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_LogsLookupRepository */
    private $lookups;

    /** @var array<int, array<string, mixed>> Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** @var bool Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** @var bool Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;

    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        $logger,
        ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier,
        ABJ_404_Solution_DatabaseCollationHelper $collationHelper,
        ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState,
        ABJ_404_Solution_LogsLookupRepository $lookups
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
        $this->errorClassifier = $errorClassifier;
        $this->collationHelper = $collationHelper;
        $this->noticeState = $noticeState;
        $this->lookups = $lookups;
    }

    /**
     * Capture a redirect or 404 event and enqueue it for write at shutdown.
     */
    public function logRedirectHit(string $requested_url, string $action, string $matchReason, ?string $requestedURLDetail = null, ?array $pipelineTrace = null): void {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');
        $logTableName = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $now = time();
        $requested_url = preg_replace('/[\x00-\x1F\x7F]/u', '', $requested_url) ?? $requested_url;
        $requested_url = $abj404logic->urlNormalization()->normalizeToRelativePath($requested_url);

        $requestedUrlCharset = null;
        $requestedUrlCollation = null;
        try {
            $columnMeta = $this->resolveRequestedUrlColumnMeta($logTableName, $wpdb);
            $requestedUrlCharset = is_array($columnMeta) ? ($columnMeta['charset_name'] ?? null) : null;
            $requestedUrlCollation = is_array($columnMeta) ? ($columnMeta['collation_name'] ?? null) : null;
            if (!empty($requestedUrlCharset) && strpos(strtolower($requestedUrlCharset), 'utf8') === false) {
                $requested_url = abj_service('url_encoder')->encodeUrlForLegacyMatch($requested_url);
                $this->warnLogsCharsetMismatchOnce($logTableName, $requestedUrlCharset);
            }
        } catch (Exception $e) {
            $this->logger->debugMessage(__FUNCTION__ . " error. Issue getting character set for table: " . $logTableName . ", column: requested_url. Error message: " . $e->getMessage());
        }

        $options = abj_service('options_repository')->getOptions(true);
        $referer = function_exists('wp_get_referer') ? wp_get_referer() : '';
        if ($referer !== null && $referer !== false) {
            $referer = function_exists('esc_url_raw') ? esc_url_raw($referer) : (string)$referer;
            $referer = substr($referer, 0, 512);
        } else {
            $referer = '';
        }
        $current_user = null;
        if (function_exists('wp_get_current_user')) {
            try {
                $current_user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
            } catch (\Throwable $e) {
                // allow-silent-catch: optional WP user context for log enrichment; absence leaves user_login blank.
                $current_user = null;
            }
        }
        $current_user_name = $current_user !== null ? $current_user->getLogin() : '';
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipAddressToSave = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        $ipAddressToSave = filter_var($ipAddressToSave, FILTER_VALIDATE_IP)
            ? (function_exists('esc_sql') ? esc_sql($ipAddressToSave) : $ipAddressToSave)
            : '';
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $ipAddressToSave = $this->f->md5lastOctet($ipAddressToSave);
        }
        if (!empty($ipAddressToSave)) {
            $ipAddressToSave = substr($ipAddressToSave, 0, 512);
        } else {
            $ipAddressToSave = '(Unknown)';
        }

        // Probe whether the requested_url is new to logsv2. The result becomes the
        // min_log_id flag on the row about to be enqueued. CAST + COLLATE makes
        // the lookup case-sensitive even on a ci-collated column; the raw
        // equality fallback handles non-utf8 columns and strict-mode CAST errors.
        $minLogID = false;
        $comparisonCollation = $this->collationHelper->sanitizeCollationIdentifier(isset($requestedUrlCollation) ? (string)$requestedUrlCollation : '');
        if ($comparisonCollation === '' || stripos($comparisonCollation, 'utf8mb4') === false) {
            $comparisonCollation = $this->collationHelper->getPreferredUtf8mb4Collation();
        }
        $requestedUrlCharsetLower = isset($requestedUrlCharset) ? strtolower((string)$requestedUrlCharset) : '';
        $canUseUtf8Cast = ($requestedUrlCharsetLower === '' || strpos($requestedUrlCharsetLower, 'utf8') !== false);
        if ($canUseUtf8Cast) {
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n WHERE CAST(requested_url AS CHAR CHARACTER SET utf8mb4) COLLATE " . $comparisonCollation . " = %s \n LIMIT 1";
        } else {
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s \n LIMIT 1";
        }
        $primaryResult = $this->dbCore->queryAndGetResults($checkMinIDSql, array('query_params' => array($requested_url), 'log_errors' => false));
        $checkMinIDQueryResults = is_array($primaryResult['rows'] ?? null) ? $primaryResult['rows'] : array();
        $lastErrorRaw = $primaryResult['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if ($lastError !== '' && $this->errorClassifier->isInvalidDataError($lastError) && $canUseUtf8Cast) {
            $fallbackResult = $this->dbCore->queryAndGetResults("SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s \n LIMIT 1", array('query_params' => array($requested_url), 'log_errors' => false));
            $checkMinIDQueryResults = is_array($fallbackResult['rows'] ?? null) ? $fallbackResult['rows'] : array();
        }
        if (empty($checkMinIDQueryResults)) { $minLogID = true; }

        if (trim($action) != "404") {
            $action = function_exists('esc_url_raw') ? esc_url_raw($action) : $action;
        }

        $helperFunctions = abj_service('functions');
        $reasonMessage = trim(implode(", ", array_filter(array(abj_service('request_context')->ignore_doprocess ?: '', abj_service('request_context')->ignore_donotprocess ?: ''))));
        $permalinksKept = '(not set)';
        $ctx = abj_service('request_context');
        if ($this->logger->isDebug() && !empty($ctx->permalinks_found)) {
            $permalinksKept = $ctx->permalinks_kept;
        }
        $requestUri = is_string($_SERVER['REQUEST_URI'] ?? '') ? (string)($_SERVER['REQUEST_URI'] ?? '') : '';
        $escapeHtml = function($value) {
            return function_exists('esc_html') ? esc_html($value) : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };
        $this->logger->debugMessage("Logging redirect. Referer: " . $escapeHtml($referer) . " | Current user: " . $current_user_name . " | From: " . $helperFunctions->normalizeUrlString($requestUri) . $escapeHtml(" to: ") . $escapeHtml($action) . ', Reason: ' . $matchReason . ", Ignore msg(s): " . $reasonMessage . ', Execution time: ' . round((float)$helperFunctions->getExecutionTime(), 2) . ' seconds, permalinks found: ' . $permalinksKept);

        $usernameLookupID = $this->lookups->insertLookupValueAndGetID($current_user_name);

        $reqUrlForLog = function_exists('esc_url_raw') ? esc_url_raw($requested_url) : $requested_url;
        $reqUrlForLogStr = is_string($reqUrlForLog) ? $reqUrlForLog : '';
        $this->queueLogEntry([
            'timestamp' => $now, 'user_ip' => $ipAddressToSave, 'referrer' => $referer,
            'dest_url' => $action, 'requested_url' => $reqUrlForLogStr,
            'requested_url_detail' => $requestedURLDetail, 'username' => $usernameLookupID,
            'min_log_id' => $minLogID, 'engine' => substr($matchReason, 0, 64),
            'pipeline_trace' => $this->serializePipelineTrace($pipelineTrace),
            'canonical_url' => '/' . trim($reqUrlForLogStr, '/'),
        ]);
    }

    /**
     * Look up (and cache for a week) the character set + collation of the
     * logsv2.requested_url column. Used to decide whether to legacy-encode
     * the URL before storage and how to compare against existing rows.
     *
     * @param string $logTableName
     * @param mixed $wpdb
     * @return array{charset_name: ?string, collation_name: ?string}|null
     */
    private function resolveRequestedUrlColumnMeta(string $logTableName, $wpdb): ?array {
        static $cached = null;
        if ($cached !== null) { return $cached; }
        if (function_exists('get_transient')) {
            $existing = get_transient('abj404_logs_requested_url_column_meta');
            if (is_array($existing)) { $cached = $existing; return $existing; }
            $legacyCharset = get_transient('abj404_logs_requested_url_charset');
            if (is_string($legacyCharset) && $legacyCharset !== '') {
                $cached = array('charset_name' => $legacyCharset, 'collation_name' => null);
                return $cached;
            }
        }
        $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
        if ($dbName === '' || !is_object($wpdb)) {
            return null;
        }
        $getCharsetQuery = $wpdb->prepare("SELECT character_set_name as charset_name, collation_name as collation_name \n FROM information_schema.columns \n WHERE lower(table_schema) = lower(%s) \n AND lower(table_name) = lower(%s) \n AND lower(column_name) = lower(%s) ", $dbName, $logTableName, 'requested_url');
        $resultArray = $wpdb->get_results($getCharsetQuery, ARRAY_A);
        if (empty($resultArray)) { return null; }
        $meta = array(
            'charset_name' => $resultArray[0]['charset_name'] ?? $resultArray[0]['CHARSET_NAME'] ?? null,
            'collation_name' => $resultArray[0]['collation_name'] ?? $resultArray[0]['COLLATION_NAME'] ?? null,
        );
        if (function_exists('set_transient')) {
            // @cache-write-audit: opt-out - schema metadata probe cache, not user-query result data.
            // allow-cache-empty: $meta is built from a non-empty $resultArray (early-return above), so charset_name / collation_name are populated DB metadata, not a failed-fetch sentinel.
            $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
            set_transient('abj404_logs_requested_url_column_meta', $meta, $ttl);
            if (!empty($meta['charset_name'])) {
                set_transient('abj404_logs_requested_url_charset', $meta['charset_name'], $ttl);
            }
        }
        $cached = $meta;
        return $meta;
    }

    /** Emit a one-shot warning the first time a non-utf8 logs charset is observed. */
    private function warnLogsCharsetMismatchOnce(string $logTableName, string $charset): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) { return; }
        $warnKey = 'abj404_warned_logs_charset_mismatch';
        $warnVal = $logTableName . '|' . strtolower($charset);
        $already = get_transient($warnKey);
        if ($already === $warnVal) { return; }
        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        // @cache-write-audit: opt-out - charset warning dedup marker, not query result data.
        set_transient($warnKey, $warnVal, $ttl);
        $this->logger->warn("Logs table column charset is '{$charset}' for {$logTableName}. URL-encoding stored requested URLs to avoid charset issues.");
    }

    /**
     * Enqueue a sanitized entry to be flushed at shutdown.
     *
     * @param array<string, mixed> $entry
     */
    public function queueLogEntry(array $entry): void {
        self::$logQueue[] = $entry;
        if (!self::$shutdownHookRegistered) {
            self::$shutdownHookRegistered = true;
            add_action('shutdown', [$this, 'flushLogQueue'], 9);
        }
    }

    /**
     * Flush the pending log queue to logsv2 as one INSERT IGNORE batch, with
     * per-failure recovery (table-full auto-trim, isolated wpdb retry,
     * per-row fallback).
     */
    public function flushLogQueue(): void {
        if (self::$isFlushingLogQueue) { return; }
        self::$isFlushingLogQueue = true;
        if (empty(self::$logQueue)) {
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        global $wpdb;
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');

        $columns = array_keys(self::$logQueue[0]);
        $validatedColumns = [];
        foreach ($columns as $col) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) { $validatedColumns[] = $col; }
        }
        $schemaColumns = $this->dbCore->getTableColumnNames($tableName);
        if (!empty($schemaColumns)) { $validatedColumns = array_intersect($validatedColumns, $schemaColumns); }
        if (empty($validatedColumns)) {
            self::$logQueue = []; self::$shutdownHookRegistered = false; self::$isFlushingLogQueue = false;
            return;
        }

        $columnList = '`' . implode('`, `', $validatedColumns) . '`';
        $sanitizedEntries = [];
        foreach (self::$logQueue as $entry) {
            foreach ($entry as $val) {
                if (is_object($val) || is_array($val)) { break; }
            }
            $entryColumns = array_keys($entry);
            $missingCols = array_diff($validatedColumns, $entryColumns);
            if (!empty($missingCols)) { continue; }
            $sanitized = $this->sanitizeLogEntry($entry);
            if ($sanitized === null) { continue; }
            $sanitizedEntries[] = $sanitized;
        }
        if (empty($sanitizedEntries)) {
            self::$logQueue = []; self::$shutdownHookRegistered = false; self::$isFlushingLogQueue = false;
            return;
        }

        $formats = [];
        $flattenedValues = [];
        foreach ($sanitizedEntries as $entry) {
            $rowFormats = [];
            foreach ($validatedColumns as $col) {
                $value = $entry[$col];
                if ($value === null) { $rowFormats[] = 'NULL'; continue; }
                if (is_int($value)) { $rowFormats[] = '%d'; } else { $rowFormats[] = '%s'; }
                $flattenedValues[] = $value;
            }
            $formats[] = '(' . implode(', ', $rowFormats) . ')';
        }

        $sql = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $formats);
        $prepared = $wpdb->prepare($sql, $flattenedValues);
        $wpdb->flush();
        $result = $wpdb->query($prepared);

        if ($result === false && !empty($wpdb->last_error)) {
            $batchError = $wpdb->last_error;

            if ($this->isTableFullError($batchError)) {
                $trimmed = $this->autoTrimLogsv2IfNeeded($tableName, $batchError);
                if ($trimmed) {
                    $wpdb->flush();
                    $retryResult = $wpdb->query($prepared);
                    if ($retryResult !== false) {
                        self::$logQueue = []; self::$shutdownHookRegistered = false; self::$isFlushingLogQueue = false;
                        return;
                    }
                    $batchError = $wpdb->last_error;
                }
                $this->setLogsv2FullNotice($batchError);
            }

            if ($this->isCommandsOutOfSyncError($batchError)) {
                $isolated = $this->getIsolatedWpdb();
                if ($isolated !== null) {
                    $isolated->flush();
                    $isolatedPrepared = $isolated->prepare($sql, $flattenedValues);
                    $isolatedResult = $isolated->query($isolatedPrepared !== null ? $isolatedPrepared : $sql);
                    if ($isolatedResult !== false) {
                        self::$logQueue = []; self::$shutdownHookRegistered = false; self::$isFlushingLogQueue = false;
                        $context = $this->getWpdbRecentQueryContextForLogs();
                        $suffix = ($context !== '') ? " | savequeries_context={$context}" : '';
                        $this->logger->warn("flushLogQueue batch INSERT succeeded using isolated DB connection (commands out of sync on shared connection).{$suffix}");
                        return;
                    }
                    $batchError .= " | isolated_error=" . $isolated->last_error;
                } else {
                    $batchError .= " | isolated_error=no_isolated_connection";
                }
            }

            $successCount = 0; $failCount = 0; $failureDetails = [];
            foreach ($sanitizedEntries as $index => $entry) {
                $rowFormats = []; $rowValues = [];
                foreach ($validatedColumns as $col) {
                    $value = $entry[$col];
                    if ($value === null) { $rowFormats[] = 'NULL'; } else { $rowFormats[] = is_int($value) ? '%d' : '%s'; $rowValues[] = $value; }
                }
                $rowPlaceholder = '(' . implode(', ', $rowFormats) . ')';
                /** @var literal-string $singleSqlTemplate */
                $singleSqlTemplate = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES {$rowPlaceholder}";
                $singleSql = $wpdb->prepare($singleSqlTemplate, $rowValues);
                $wpdb->flush();
                $singleResult = $wpdb->query((string)$singleSql);

                if ($singleResult === false && !empty($wpdb->last_error)) {
                    $lastError = $wpdb->last_error;
                    if ($this->isCommandsOutOfSyncError($wpdb->last_error)) {
                        $isolated = $this->getIsolatedWpdb();
                        if ($isolated !== null) {
                            $isolated->flush();
                            $isolatedSingleSql = $isolated->prepare($singleSqlTemplate, $rowValues);
                            $isolatedSingleResult = $isolated->query((string)$isolatedSingleSql);
                            if ($isolatedSingleResult !== false) { $successCount++; continue; }
                            $lastError = $lastError . " | isolated_error=" . $isolated->last_error;
                        } else {
                            $lastError = $lastError . " | isolated_error=no_isolated_connection";
                        }
                    }
                    $failCount++;
                    $payload = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);
                    if (is_string($payload) && strlen($payload) > 1024) { $payload = substr($payload, 0, 1024) . '...'; }
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
                $context = $this->getWpdbRecentQueryContextForLogs();
                $contextSuffix = ($context !== '') ? (" | savequeries_context=" . $context) : '';
                if ($this->dbCore->classifyAndHandleInfrastructureError($batchError)) {
                    $this->logger->warn("flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed. | batch_error=" . $batchError . " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix . $contextSuffix);
                } else {
                    $this->logger->errorMessage("flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed. | batch_error=" . $batchError . " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix . $contextSuffix);
                }
            } else {
                $this->logger->warn("flushLogQueue batch INSERT failed but recovered: all {$successCount} entries inserted individually. | batch_error=" . $batchError);
            }
        }

        self::$logQueue = []; self::$shutdownHookRegistered = false; self::$isFlushingLogQueue = false;
    }

    private function isCommandsOutOfSyncError(string $error): bool {
        return stripos($error, 'commands out of sync') !== false;
    }

    public function isTableFullError(string $error): bool {
        $lower = strtolower($error);
        return stripos($lower, 'is full') !== false || stripos($lower, 'table full') !== false;
    }

    /**
     * Free space in logsv2 by deleting the oldest 1000 entries. Rate-limited
     * to once per hour via a transient cooldown so a repeated table-full
     * error doesn't drain the table.
     */
    public function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || strpos($tableName, 'abj404_logsv2') === false) {
            $this->logger->warn("autoTrimLogsv2IfNeeded: rejected unexpected table name: " . substr($tableName, 0, 100));
            return false;
        }
        $cooldownKey = 'abj404_logsv2_trim_cooldown_until';
        $alreadyTrimmed = function_exists('get_transient') ? get_transient($cooldownKey) : false;
        if ($alreadyTrimmed) { return false; }
        global $wpdb;
        $trimSql = "DELETE FROM `{$tableName}` ORDER BY timestamp ASC LIMIT 1000";
        $wpdb->query($trimSql);
        $ttl = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        // @cache-write-audit: opt-out - log-trim cooldown marker, not query result data.
        if (function_exists('set_transient')) { set_transient($cooldownKey, 1, $ttl); }
        if (!empty($wpdb->last_error)) {
            $this->logger->warn("Log table full: auto-trim failed: " . $wpdb->last_error);
        } else {
            $this->logger->warn("Log table full: auto-trimmed 1000 oldest entries to free space.");
        }
        return true;
    }

    private function setLogsv2FullNotice(string $errorMessage): void {
        $message = $this->noticeState->localizeOrDefault('The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.');
        $this->dbCore->setPluginDbNotice('log_table_full', $message, $errorMessage);
    }

    /**
     * Construct an isolated wpdb connection used as a fallback when the
     * primary connection enters a "commands out of sync" state and the
     * prepared statement cannot be re-issued on it.
     *
     * @return wpdb|null
     */
    public function getIsolatedWpdb(): ?wpdb {
        static $isolated = null;
        if ($isolated !== null) { return $isolated; }
        if (!class_exists('wpdb')) { return null; }
        if (!defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_HOST')) {
            static $warnedNoDbConsts = false;
            if (!$warnedNoDbConsts) { $warnedNoDbConsts = true; $this->logger->warn(__METHOD__ . ': DB_USER/DB_PASSWORD/DB_NAME/DB_HOST undefined; isolated wpdb unavailable'); }
            return null;
        }
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb
        $isolated = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $isolated->show_errors(false);
        $isolated->suppress_errors(true);
        return $isolated;
    }

    private function getWpdbRecentQueryContextForLogs(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return ''; }
        if (!defined('SAVEQUERIES') || SAVEQUERIES !== true) { return ''; }
        if (empty($wpdb->queries) || !is_array($wpdb->queries)) { return ''; }
        $recent = array_slice($wpdb->queries, -5);
        $parts = [];
        foreach ($recent as $q) {
            $sql = $q[0] ?? ''; $time = $q[1] ?? null; $caller = $q[2] ?? '';
            $hash = is_string($sql) ? substr(sha1($sql), 0, 10) : 'n/a';
            $who = $this->extractWpComponentFromString(is_string($caller) ? $caller : '');
            $t = is_numeric($time) ? round((float)$time, 3) : 'n/a';
            $parts[] = "{$who}:{$hash}@{$t}";
        }
        return implode(', ', $parts);
    }

    private function extractWpComponentFromString(string $text): string {
        $normalized = str_replace('\\', '/', $text);
        foreach (array('/wp-content/mu-plugins/' => 'mu-plugin', '/wp-content/plugins/' => 'plugin', '/wp-content/themes/' => 'theme') as $needle => $label) {
            $pos = strpos($normalized, $needle);
            if ($pos !== false) {
                $rest = substr($normalized, $pos + strlen($needle));
                $name = explode('/', ltrim($rest, '/'))[0] ?? '';
                return $name !== '' ? "{$label}:{$name}" : "{$label}:unknown";
            }
        }
        return 'unknown';
    }

    /**
     * Normalize a log entry into the strict shape expected by INSERT IGNORE.
     * Returns null when a required column is missing or sanitization rejects
     * the entry (empty requested_url, empty dest_url, or non-scalar payload).
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public function sanitizeLogEntry(array $entry): ?array {
        $required = array('timestamp', 'user_ip', 'referrer', 'dest_url', 'requested_url', 'requested_url_detail', 'username', 'min_log_id', 'engine');
        foreach ($required as $key) { if (!array_key_exists($key, $entry)) { return null; } }
        $normalizeString = function($value, $maxLen) {
            if (is_object($value) || is_array($value)) { return null; }
            $str = (string)$value;
            if (function_exists('mb_convert_encoding')) { $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8'); }
            return substr($str, 0, $maxLen);
        };
        $sanitized = array();
        $tsVal = $entry['timestamp'] ?? time();
        $sanitized['timestamp'] = absint(is_scalar($tsVal) ? $tsVal : time());
        $sanitized['user_ip'] = $normalizeString($entry['user_ip'], 512);
        $sanitized['referrer'] = $normalizeString($entry['referrer'], 512);
        $sanitized['dest_url'] = $normalizeString($entry['dest_url'], 512);
        $sanitized['requested_url'] = $normalizeString($entry['requested_url'], 2048);
        $sanitized['requested_url_detail'] = $normalizeString($entry['requested_url_detail'], 2048);
        $reqUrlSafe = is_string($sanitized['requested_url']) ? $sanitized['requested_url'] : '';
        if (array_key_exists('canonical_url', $entry) && is_string($entry['canonical_url'])) { $canonical = $entry['canonical_url']; } else { $canonical = '/' . trim($reqUrlSafe, '/'); }
        $sanitized['canonical_url'] = substr($canonical, 0, 2048);
        $usernameVal = $entry['username'] ?? null;
        $sanitized['username'] = ($usernameVal === null || !is_scalar($usernameVal)) ? null : absint($usernameVal);
        $minLogIdVal = $entry['min_log_id'] ?? null;
        $sanitized['min_log_id'] = ($minLogIdVal === null || !is_scalar($minLogIdVal)) ? null : absint($minLogIdVal);
        $sanitized['engine'] = $normalizeString($entry['engine'], 64);
        if (array_key_exists('pipeline_trace', $entry)) {
            $traceVal = $entry['pipeline_trace'];
            $sanitized['pipeline_trace'] = ($traceVal === null || is_string($traceVal)) ? $traceVal : null;
        } else {
            $sanitized['pipeline_trace'] = null;
        }
        if ($sanitized['requested_url'] === '' || $sanitized['dest_url'] === '') { return null; }
        return $sanitized;
    }

    /**
     * gz + base64 the pipeline trace payload for storage.
     *
     * @param array<int, array{step: string, outcome: string, detail: string}>|null $trace
     */
    private function serializePipelineTrace(?array $trace): ?string {
        if ($trace === null || empty($trace)) { return null; }
        $json = json_encode($trace);
        if ($json === false) { return null; }
        $compressed = gzcompress($json, 6);
        if ($compressed === false) { return null; }
        return base64_encode($compressed);
    }
}
