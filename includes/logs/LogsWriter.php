<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsEntrySanitizer.php';
require_once __DIR__ . '/LogsRequestedUrlColumnMetadata.php';
require_once __DIR__ . '/LogsQueueFlusher.php';

/**
 * Log-write pipeline for the wp_abj404_logsv2 table.
 *
 * Responsibilities:
 *  - logRedirectHit: build a log entry from the live request context (IP hash,
 *    referrer, current user, charset-aware requested_url handling) and enqueue it.
 *
 * The queue is process-static so multiple LogsWriter instances within the
 * same request share one batch. Queue flushing, recovery, metadata lookup,
 * and sanitization are delegated to focused collaborators.
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

    /** @var ABJ_404_Solution_LogsLookupRepository */
    private $lookups;

    /** @var ABJ_404_Solution_LogsEntrySanitizer */
    private $entrySanitizer;

    /** @var ABJ_404_Solution_LogsRequestedUrlColumnMetadata */
    private $requestedUrlColumnMetadata;

    /** @var ABJ_404_Solution_LogsWriteRecoveryPolicy */
    private $recoveryPolicy;

    /** @var ABJ_404_Solution_LogsQueueFlusher */
    private $queueFlusher;

    /** @var array<int, array<string, mixed>> Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** @var bool Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** @var bool Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;

    /**
     * Test seam: clear all cached static state (the pending log queue, the
     * shutdown-hook-registered latch, and the flush re-entrancy guard) without
     * private-field reflection (M105 singleton-reset seam).
     *
     * @return void
     */
    public static function resetForTests() {
        self::$logQueue = [];
        self::$shutdownHookRegistered = false;
        self::$isFlushingLogQueue = false;
    }

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
        $this->lookups = $lookups;
        $this->entrySanitizer = new ABJ_404_Solution_LogsEntrySanitizer();
        $this->requestedUrlColumnMetadata = new ABJ_404_Solution_LogsRequestedUrlColumnMetadata($logger);
        $this->recoveryPolicy = new ABJ_404_Solution_LogsWriteRecoveryPolicy(
            $logger,
            $noticeState
        );
        $this->queueFlusher = new ABJ_404_Solution_LogsQueueFlusher($dbCore, $logger, $this->entrySanitizer, $this->recoveryPolicy);
    }

    /**
     * Capture a redirect or 404 event and enqueue it for write at shutdown.
     */
    public function logRedirectHit(ABJ_404_Solution_RedirectHitLogEntry $entry): void {
        $requested_url = $entry->requestedUrl;
        $action = $entry->action;
        $matchReason = $entry->matchReason;
        $requestedURLDetail = $entry->requestedUrlDetail;
        $pipelineTrace = $entry->pipelineTrace;
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');
        $logTableName = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $now = abj_clock()->now();
        $requested_url = preg_replace('/[\x00-\x1F\x7F]/u', '', $requested_url) ?? $requested_url;
        $requested_url = $abj404logic->urlNormalization()->normalizeToRelativePath($requested_url);

        $requestedUrlCharset = null;
        $requestedUrlCollation = null;
        try {
            $columnMeta = $this->requestedUrlColumnMetadata->resolveRequestedUrlColumnMeta($logTableName, $wpdb);
            $requestedUrlCharset = is_array($columnMeta) ? ($columnMeta['charset_name'] ?? null) : null;
            $requestedUrlCollation = is_array($columnMeta) ? ($columnMeta['collation_name'] ?? null) : null;
            if (!empty($requestedUrlCharset) && strpos(strtolower($requestedUrlCharset), 'utf8') === false) {
                $requested_url = abj_service('url_encoder')->encodeUrlForLegacyMatch($requested_url);
                $this->requestedUrlColumnMetadata->warnLogsCharsetMismatchOnce($logTableName, $requestedUrlCharset);
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
            // Index-assisted: the bare `requested_url = %s` predicate is sargable
            // on the requested_url(190) prefix index and narrows to the rows
            // sharing this URL; the CAST...COLLATE refinement then enforces the
            // utf8mb4-harmonized comparison (its original purpose -- it equalizes
            // utf8 vs utf8mb4 charset, not case). Without the bare predicate the
            // CAST wraps the column and forces a full table scan of logsv2 on
            // EVERY 404 log write -- the hottest path under scanner-flood traffic
            // on a large log table. A bound %s is collation-coercible to the
            // column, so the prefilter neither throws an illegal-mix error nor
            // changes which row is found (min_log_id only drives the admin logs
            // unique-URL dropdown, so the comparison need not be byte-exact).
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s AND CAST(requested_url AS CHAR CHARACTER SET utf8mb4) COLLATE " . $comparisonCollation . " = %s \n LIMIT 1";
            $checkMinIDParams = array($requested_url, $requested_url);
        } else {
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s \n LIMIT 1";
            $checkMinIDParams = array($requested_url);
        }
        $primaryResult = $this->dbCore->queryAndGetResults($checkMinIDSql, array('query_params' => $checkMinIDParams, 'log_errors' => false));
        $checkMinIDQueryResults = is_array($primaryResult['rows'] ?? null) ? $primaryResult['rows'] : array();
        $lastErrorRaw = $primaryResult['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if ($lastError !== '' && $this->errorClassifier->taxonomy()->schema()->isInvalidDataError($lastError) && $canUseUtf8Cast) {
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
        $this->logger->debugMessage("Logging redirect. Referer: " . $escapeHtml($referer) . " | Current user: " . $current_user_name . " | From: " . abj_service('sanitizer')->normalizeUrlString($requestUri) . $escapeHtml(" to: ") . $escapeHtml($action) . ', Reason: ' . $matchReason . ", Ignore msg(s): " . $reasonMessage . ', Execution time: ' . round((float)$helperFunctions->getExecutionTime(), 2) . ' seconds, permalinks found: ' . $permalinksKept);

        $usernameLookupID = $this->lookups->insertLookupValueAndGetID($current_user_name);

        $reqUrlForLog = function_exists('esc_url_raw') ? esc_url_raw($requested_url) : $requested_url;
        $reqUrlForLogStr = is_string($reqUrlForLog) ? $reqUrlForLog : '';
        $this->queueLogEntry([
            'timestamp' => $now, 'user_ip' => $ipAddressToSave, 'referrer' => $referer,
            'dest_url' => $action, 'requested_url' => $reqUrlForLogStr,
            'requested_url_detail' => $requestedURLDetail, 'username' => $usernameLookupID,
            'min_log_id' => $minLogID, 'engine' => substr($matchReason, 0, 64),
            'pipeline_trace' => $this->entrySanitizer->serializePipelineTrace($pipelineTrace),
            'canonical_url' => '/' . trim($reqUrlForLogStr, '/'),
        ]);
    }

    /**
     * Enqueue a sanitized entry to be flushed at shutdown.
     *
     * @param array<string, mixed> $entry
     */
    public function queueLogEntry(array $entry): void {
        $this->queueFlusher->queueLogEntry(self::$logQueue, self::$shutdownHookRegistered, [$this, 'flushLogQueue'], $entry);
    }

    /**
     * Flush the pending log queue to logsv2 as one INSERT IGNORE batch, with
     * per-failure recovery (table-full auto-trim, shared-connection reset,
     * and per-row fallback).
     */
    public function flushLogQueue(): void {
        $this->queueFlusher->flushLogQueue(self::$logQueue, self::$shutdownHookRegistered, self::$isFlushingLogQueue);
    }

    public function isTableFullError(string $error): bool {
        return $this->recoveryPolicy->isTableFullError($error);
    }

    /**
     * Free space in logsv2 by deleting the oldest 1000 entries. Rate-limited
     * to once per hour via a transient cooldown so a repeated table-full
     * error doesn't drain the table.
     */
    public function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        return $this->recoveryPolicy->autoTrimLogsv2IfNeeded($tableName, $errorMessage);
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
        return $this->entrySanitizer->sanitizeLogEntry($entry);
    }
}
