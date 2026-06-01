<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsRepositoryInterface.php';
require_once __DIR__ . '/LogsHitsRollupServiceInterface.php';
require_once __DIR__ . '/LogsHitsRollupService.php';

/**
 * Log insertion, log read queries, GDPR/anonymization, and lookup-table CRUD.
 *
 * Extracted from the DataAccess monolith (Phase 3 of the DataAccess refactor).
 * Hits-table rollup lifecycle (rebuild + scheduling + locking + staleness
 * signaling + max/min/stored-max id queries + last-updated reads) lives in
 * ABJ_404_Solution_LogsHitsRollupService. The rollup-related methods on
 * this class's interface are thin forwarders to the rollup service.
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_LogsRepository implements ABJ_404_Solution_LogsRepositoryInterface {

    // Backwards-compatible constants for callers reaching at
    // ABJ_404_Solution_LogsRepository::CONST_NAME. Authoritative copies
    // live on ABJ_404_Solution_LogsHitsRollupService.
    const UPDATE_LOGS_HITS_TABLE_HOOK = ABJ_404_Solution_LogsHitsRollupService::UPDATE_LOGS_HITS_TABLE_HOOK;
    const HITS_TABLE_MAX_AGE_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_MAX_AGE_SECONDS;
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS;
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS;
    const HITS_TABLE_PREAGG_CHUNK_SIZE = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_PREAGG_CHUNK_SIZE;
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_DIRECT_PATH_THRESHOLD;
    const HITS_TABLE_LAST_CHECKED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_CHECKED_FLAG;
    const HITS_TABLE_LAST_SCHEDULED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_SCHEDULED_FLAG;
    const HITS_TABLE_LAST_DECISION_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_DECISION_FLAG;
    const HITS_TABLE_LAST_REFRESHED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_REFRESHED_FLAG;
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_FIRST_STALE_DETECTED_FLAG;
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_STALE_NOTICE_TRANSIENT;
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS;

    /** @var int Max age for cached daily-activity trend data. */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_LogsHitsRollupServiceInterface */
    private $rollup;

    /** @var array<int, array<string, mixed>> Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** @var bool Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** @var bool Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth Forwarded to the rollup service when one is constructed internally.
     * @param ABJ_404_Solution_LogsHitsRollupServiceInterface|null $rollup Pre-built rollup service (preferred wiring). When null, an internal one is constructed.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        $rebuildHealth = null,
        $rollup = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        if ($rollup instanceof ABJ_404_Solution_LogsHitsRollupServiceInterface) {
            $this->rollup = $rollup;
        } else {
            $this->rollup = new ABJ_404_Solution_LogsHitsRollupService($dbCore, $this->logger, $rebuildHealth);
        }
    }

    /**
     * Accessor for callers that need to drive the rollup directly (e.g. tests
     * inspecting scheduling state). Not part of the LogsRepository interface.
     *
     * @return ABJ_404_Solution_LogsHitsRollupServiceInterface
     */
    public function getRollupService(): ABJ_404_Solution_LogsHitsRollupServiceInterface {
        return $this->rollup;
    }

    // =========================================================================
    // Log data population and querying (from DataAccessTrait_Logs)
    // =========================================================================

    /** @inheritDoc */
    function populateLogsData($rows) {
        if (empty($rows)) {
            return $rows;
        }

        $urls = array();
        foreach ($rows as $row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $variants = $this->buildHitsLookupUrlVariants($row['url']);
                foreach ($variants as $variant) {
                    $urls[] = $variant;
                }
            }
        }

        if (empty($urls)) {
            return $rows;
        }

        $urls = array_values(array_unique($urls));

        if (!$this->logsHitsTableExists()) {
            $this->scheduleHitsTableRebuild();
            return $rows;
        }

        $logsHitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $batchSize = 200;
        $logsResults = array();
        $urlChunks = array_chunk($urls, $batchSize);

        foreach ($urlChunks as $urlChunk) {
            $placeholders = implode(',', array_fill(0, count($urlChunk), '%s'));
            $sql = "SELECT requested_url, logsid, last_used, logshits "
                 . "FROM {$logsHitsTable} "
                 . "WHERE BINARY requested_url IN ($placeholders)";

            $chunkResult = $this->dbCore->queryAndGetResults($sql, array(
                'query_params' => $urlChunk,
                'log_too_slow' => false,
            ));

            if (!empty($chunkResult['timed_out']) ||
                (isset($chunkResult['last_error']) && $chunkResult['last_error'] != '')) {
                $errRaw = $chunkResult['last_error'] ?? '';
                $err = is_string($errRaw) ? $errRaw : '';
                if ($err !== '' && strpos($err, 'logs_hits') !== false) {
                    $this->scheduleHitsTableRebuild();
                }
                return $rows;
            }

            $chunkResults = is_array($chunkResult['rows'] ?? null) ? $chunkResult['rows'] : array();
            if (!empty($chunkResults)) {
                $logsResults = array_merge($logsResults, $chunkResults);
            }
        }

        $logsDataByUrl = array();
        foreach ($logsResults as $logRow) {
            $canonicalUrl = $this->canonicalizeUrlForHitsMatch($logRow['requested_url'] ?? '');
            if ($canonicalUrl === '') {
                continue;
            }
            if (!isset($logsDataByUrl[$canonicalUrl])) {
                $logsDataByUrl[$canonicalUrl] = array(
                    'logsid' => (int)($logRow['logsid'] ?? 0),
                    'logshits' => (int)($logRow['logshits'] ?? 0),
                    'last_used' => (int)($logRow['last_used'] ?? 0),
                );
                continue;
            }
            $existing = $logsDataByUrl[$canonicalUrl];
            $currentLogsid = (int)($logRow['logsid'] ?? 0);
            $existingLogsid = (int)$existing['logsid'];
            $logsDataByUrl[$canonicalUrl]['logsid'] = ($existingLogsid > 0 && $currentLogsid > 0)
                ? min($existingLogsid, $currentLogsid)
                : max($existingLogsid, $currentLogsid);
            $logsDataByUrl[$canonicalUrl]['logshits'] = (int)$existing['logshits'] + (int)($logRow['logshits'] ?? 0);
            $logsDataByUrl[$canonicalUrl]['last_used'] = max((int)$existing['last_used'], (int)($logRow['last_used'] ?? 0));
        }

        foreach ($rows as &$row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $canonicalUrl = $this->canonicalizeUrlForHitsMatch($row['url']);
                if (isset($logsDataByUrl[$canonicalUrl])) {
                    $logData = $logsDataByUrl[$canonicalUrl];
                    $row['logsid'] = $logData['logsid'];
                    $row['logshits'] = $logData['logshits'];
                    $row['last_used'] = $logData['last_used'];
                }
            }
        }

        return $rows;
    }

    /** @param mixed $url @return string */
    private function canonicalizeUrlForHitsMatch($url): string {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $fragment = '';
        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($url, $fragmentPos);
            $url = substr($url, 0, $fragmentPos);
        }
        $query = '';
        $queryPos = strpos($url, '?');
        if ($queryPos !== false) {
            $query = substr($url, $queryPos);
            $url = substr($url, 0, $queryPos);
        }
        $path = trim($url, '/');
        $normalizedPath = ($path === '') ? '/' : '/' . $path;
        return $normalizedPath . $query . $fragment;
    }

    /** @param mixed $url @return array<int, string> */
    private function buildHitsLookupUrlVariants($url) {
        $variants = array();
        if (!is_string($url)) {
            return $variants;
        }
        $raw = trim($url);
        if ($raw !== '') {
            $variants[] = $raw;
        }
        $canonical = $this->canonicalizeUrlForHitsMatch($url);
        if ($canonical !== '') {
            $variants[] = $canonical;
            $parts = $this->splitCanonicalHitsUrl($canonical);
            $pathPart = $parts['path'];
            $suffixPart = $parts['suffix'];
            $pathVariants = array($pathPart);
            $noLeadingPath = ltrim($pathPart, '/');
            if ($noLeadingPath !== '') {
                $pathVariants[] = $noLeadingPath;
            }
            if ($pathPart !== '/') {
                if (substr($pathPart, -1) === '/') {
                    $toggleTrailingPath = rtrim($pathPart, '/');
                } else {
                    $toggleTrailingPath = $pathPart . '/';
                }
                $pathVariants[] = $toggleTrailingPath;
                $toggleNoLeadingPath = ltrim($toggleTrailingPath, '/');
                if ($toggleNoLeadingPath !== '') {
                    $pathVariants[] = $toggleNoLeadingPath;
                }
            }
            foreach (array_unique($pathVariants) as $pathVariant) {
                $variants[] = $pathVariant . $suffixPart;
            }
        }
        return array_values(array_unique($variants));
    }

    /** @return array{path: string, suffix: string} */
    private function splitCanonicalHitsUrl(string $canonicalUrl): array {
        $firstQueryPos = strpos($canonicalUrl, '?');
        $firstFragmentPos = strpos($canonicalUrl, '#');
        if ($firstQueryPos === false && $firstFragmentPos === false) {
            return array('path' => $canonicalUrl, 'suffix' => '');
        }
        if ($firstQueryPos === false) {
            $splitPos = $firstFragmentPos;
        } elseif ($firstFragmentPos === false) {
            $splitPos = $firstQueryPos;
        } else {
            $splitPos = min($firstQueryPos, $firstFragmentPos);
        }
        return array(
            'path' => substr($canonicalUrl, 0, $splitPos),
            'suffix' => substr($canonicalUrl, $splitPos),
        );
    }

    /** @inheritDoc */
    function getDistinctLoggedUrls(): array {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getDistinctLoggedUrls.sql");
        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        $urls = array();
        foreach ($rows as $row) {
            $url = isset($row['requested_url']) && is_string($row['requested_url']) ? $row['requested_url'] : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** @inheritDoc */
    function getLogsIDandURL($specificURL = '') {
        global $wpdb;
        $whereClause = '';
        if ($specificURL != '') {
            $specificURL = $this->f->sanitizeInvalidUTF8($specificURL);
            $escapedURL = esc_sql($specificURL);
            $whereClause = "where requested_url = '" . $escapedURL . "'";
        }
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURL.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /** @inheritDoc */
    function getLogsIDandURLLike($specificURL, $limitResults) {
        global $wpdb;
        $whereClause = '';
        if ($specificURL != '') {
            $likePattern = '%' . $wpdb->esc_like($specificURL) . '%';
            $escapedURL = esc_sql($likePattern);
            $whereClause = "where lower(requested_url) like lower('" . $escapedURL . "')\n";
            $whereClause .= "and min_log_id = true";
        }
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURLForAjax.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $query = $this->f->str_replace('{limit-results}', 'limit ' . absint($limitResults), $query);
        $results = $this->dbCore->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /** @inheritDoc */
    function getLogRecords($tableOptions) {
        $abj404logic = abj_service('plugin_logic');
        $logsid_included = '';
        $logsid = '';
        $rawLogsId = $tableOptions['logsid'];
        if ($rawLogsId != 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = esc_sql($abj404logic->settingsUpdate()->sanitizeForSQL(is_scalar($rawLogsId) ? (string)$rawLogsId : ''));
        }
        $orderbyExpressionByName = array(
            'timestamp'     => '{wp_abj404_logsv2}.timestamp',
            'requested_url' => '{wp_abj404_logsv2}.requested_url',
            'url'           => 'url',
            'id'            => '{wp_abj404_logsv2}.id',
            'min_log_id'    => '{wp_abj404_logsv2}.min_log_id',
        );
        $rawOrderByVal = $tableOptions['orderby'];
        $orderby = sanitize_text_field($abj404logic->settingsUpdate()->sanitizeForSQL(is_string($rawOrderByVal) ? $rawOrderByVal : ''));
        $orderby = array_key_exists($orderby, $orderbyExpressionByName) ? $orderby : 'timestamp';
        $orderbyExpression = $orderbyExpressionByName[$orderby];
        $rawOrderVal2 = $tableOptions['order'];
        $order = strtoupper(sanitize_text_field($abj404logic->settingsUpdate()->sanitizeForSQL(is_string($rawOrderVal2) ? $rawOrderVal2 : '')));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }
        $paged = absint(is_scalar($tableOptions['paged'] ?? 1) ? ($tableOptions['paged'] ?? 1) : 1);
        if ($paged < 1) { $paged = 1; }
        $perpage = absint(is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? ($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE);
        if ($perpage < 1) { $perpage = ABJ404_OPTION_DEFAULT_PERPAGE; }
        $start = ($paged - 1) * $perpage;
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogRecords.sql");
        $query = $this->f->str_replace('{logsid_included}', $logsid_included, $query);
        $query = $this->f->str_replace('{logsid}', $logsid, $query);
        $query = $this->f->str_replace('{orderby}', $orderbyExpression, $query);
        $query = $this->f->str_replace('{order}', $order, $query);
        $query = $this->f->str_replace('{start}', (string)$start, $query);
        $query = $this->f->str_replace('{perpage}', (string)$perpage, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        $rawRows = $results['rows'];
        return is_array($rawRows) ? $rawRows : array();
    }

    /** @inheritDoc */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100) {
        $lkupValue = trim($lkupValue);
        if ($lkupValue === '') { return array(); }
        $page = max(1, absint($page));
        $perPage = max(1, min(500, absint($perPage)));
        $offset = ($page - 1) * $perPage;
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $lookupTable = $this->dbCore->doTableNameReplacements("{wp_abj404_lookup}");
        $sql = "SELECT l.id FROM `{$logsTable}` l INNER JOIN `{$lookupTable}` u ON l.username = u.id WHERE u.lkup_value = %s ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => array($lkupValue, $perPage, $offset)));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return array(); }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) { $ids[] = absint($row['id']); }
        }
        return array_values(array_filter($ids));
    }

    /** @inheritDoc */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50) {
        $ids = $this->getLogsv2IdsForLookupValue($lkupValue, $page, $perPage);
        if (empty($ids)) { return array(); }
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, timestamp, user_ip, referrer, requested_url, requested_url_detail, dest_url FROM `{$logsTable}` WHERE id IN ({$placeholders}) ORDER BY id DESC";
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $ids));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return array(); }
        return is_array($result['rows'] ?? null) ? $result['rows'] : array();
    }

    /** @inheritDoc */
    public function anonymizeLogsv2RowsByIds($ids) {
        if (!is_array($ids) || empty($ids)) { return true; }
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (empty($ids)) { return true; }
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "UPDATE `{$logsTable}` SET user_ip = %s, referrer = NULL, requested_url_detail = NULL, username = NULL WHERE id IN ({$placeholders})";
        $params = array_merge(array('(Anonymized)'), $ids);
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $params));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return false; }
        return true;
    }

    // =========================================================================
    // Log insertion and queue (from DataAccessTrait_Logs)
    // =========================================================================

    /** @inheritDoc */
    function logRedirectHit(string $requested_url, string $action, string $matchReason, ?string $requestedURLDetail = null, ?array $pipelineTrace = null): void {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');
        $logTableName = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $now = time();
        $requested_url = preg_replace('/[\x00-\x1F\x7F]/u', '', $requested_url) ?? $requested_url;
        $requested_url = $abj404logic->urlNormalization()->normalizeToRelativePath($requested_url);

        try {
            static $requestedUrlColumnMeta = null;
            if ($requestedUrlColumnMeta === null && function_exists('get_transient')) {
                $requestedUrlColumnMeta = get_transient('abj404_logs_requested_url_column_meta');
                if ($requestedUrlColumnMeta === false) { $requestedUrlColumnMeta = null; }
            }
            if ($requestedUrlColumnMeta === null && function_exists('get_transient')) {
                $legacyCharset = get_transient('abj404_logs_requested_url_charset');
                if (is_string($legacyCharset) && $legacyCharset !== '') {
                    $requestedUrlColumnMeta = array('charset_name' => $legacyCharset, 'collation_name' => null);
                }
            }
            $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
            if ($requestedUrlColumnMeta === null && $dbName !== '' && is_object($wpdb)) {
                $getCharsetQuery = $wpdb->prepare("SELECT character_set_name as charset_name, collation_name as collation_name \n FROM information_schema.columns \n WHERE lower(table_schema) = lower(%s) \n AND lower(table_name) = lower(%s) \n AND lower(column_name) = lower(%s) ", $dbName, $logTableName, 'requested_url');
                $resultArray = $wpdb->get_results($getCharsetQuery, ARRAY_A);
                if (!empty($resultArray)) {
                    $requestedUrlColumnMeta = array(
                        'charset_name' => $resultArray[0]['charset_name'] ?? $resultArray[0]['CHARSET_NAME'] ?? null,
                        'collation_name' => $resultArray[0]['collation_name'] ?? $resultArray[0]['COLLATION_NAME'] ?? null,
                    );
                    if (function_exists('set_transient')) {
                        // @cache-write-audit: opt-out - schema metadata probe cache, not user-query result data.
                        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                        set_transient('abj404_logs_requested_url_column_meta', $requestedUrlColumnMeta, $ttl);
                        if (!empty($requestedUrlColumnMeta['charset_name'])) {
                            set_transient('abj404_logs_requested_url_charset', $requestedUrlColumnMeta['charset_name'], $ttl);
                        }
                    }
                }
            }
            $requestedUrlCharset = is_array($requestedUrlColumnMeta) ? ($requestedUrlColumnMeta['charset_name'] ?? null) : null;
            $requestedUrlCollation = is_array($requestedUrlColumnMeta) ? ($requestedUrlColumnMeta['collation_name'] ?? null) : null;
            if (!empty($requestedUrlCharset) && strpos(strtolower($requestedUrlCharset), 'utf8') === false) {
                $requested_url = $this->f->encodeUrlForLegacyMatch($requested_url);
                if (function_exists('get_transient') && function_exists('set_transient')) {
                    $warnKey = 'abj404_warned_logs_charset_mismatch';
                    $warnVal = $logTableName . '|' . strtolower($requestedUrlCharset);
                    $already = get_transient($warnKey);
                    if ($already !== $warnVal) {
                        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                        // @cache-write-audit: opt-out - charset warning dedup marker, not query result data.
                        set_transient($warnKey, $warnVal, $ttl);
                        $this->logger->warn("Logs table column charset is '{$requestedUrlCharset}' for {$logTableName}. URL-encoding stored requested URLs to avoid charset issues.");
                    }
                }
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

        $minLogID = false;
        $comparisonCollation = $this->dbCore->sanitizeCollationIdentifier(isset($requestedUrlCollation) ? (string)$requestedUrlCollation : '');
        if ($comparisonCollation === '' || stripos($comparisonCollation, 'utf8mb4') === false) {
            $comparisonCollation = $this->dbCore->getPreferredUtf8mb4Collation();
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
        if ($lastError !== '' && $this->dbCore->isInvalidDataError($lastError) && $canUseUtf8Cast) {
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

        $usernameLookupID = $this->insertLookupValueAndGetID($current_user_name);

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

    /** @inheritDoc */
    function queueLogEntry(array $entry): void {
        self::$logQueue[] = $entry;
        if (!self::$shutdownHookRegistered) {
            self::$shutdownHookRegistered = true;
            add_action('shutdown', [$this, 'flushLogQueue'], 9);
        }
    }

    /** @inheritDoc */
    function flushLogQueue(): void {
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

    /** @param string $error @return bool */
    public function isTableFullError(string $error): bool {
        $lower = strtolower($error);
        return stripos($lower, 'is full') !== false || stripos($lower, 'table full') !== false;
    }

    /** @param string $tableName @param string $errorMessage @return bool */
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

    /** @param string $errorMessage @return void */
    private function setLogsv2FullNotice(string $errorMessage): void {
        $message = $this->dbCore->localizeOrDefault('The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.');
        $this->dbCore->setPluginDbNotice('log_table_full', $message, $errorMessage);
    }

    /** @return wpdb|null */
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

    /** @return string */
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

    /** @param array<string, mixed> $entry @return array<string, mixed>|null */
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

    /** @param array<int, array{step: string, outcome: string, detail: string}>|null $trace @return string|null */
    private function serializePipelineTrace(?array $trace): ?string {
        if ($trace === null || empty($trace)) { return null; }
        $json = json_encode($trace);
        if ($json === false) { return null; }
        $compressed = gzcompress($json, 6);
        if ($compressed === false) { return null; }
        return base64_encode($compressed);
    }

    /** @inheritDoc */
    public static function decompressPipelineTrace(?string $raw): ?array {
        if ($raw === null || $raw === '') { return null; }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) { return null; }
        $json = @gzuncompress($decoded);
        if ($json === false) { return null; }
        $result = json_decode($json, true);
        return is_array($result) ? $result : null;
    }

    // =========================================================================
    // Lookup table (from DataAccessTrait_Logs + Maintenance)
    // =========================================================================

    /** @inheritDoc */
    function insertLookupValueAndGetID($valueToInsert) {
        global $wpdb;
        $query = "INSERT INTO {wp_abj404_lookup} (lkup_value) VALUES (%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
        $this->dbCore->queryAndGetResults($query, array('query_params' => array($valueToInsert)));
        return intval($wpdb->insert_id);
    }

    /** @inheritDoc */
    function getLookupIDForUser($userName) {
        $query = "select id from {wp_abj404_lookup} where lkup_value = %s";
        $results = $this->dbCore->queryAndGetResults($query, array('query_params' => array($userName)));
        $lookupRows = is_array($results['rows']) ? $results['rows'] : array();
        if (count($lookupRows) > 0) {
            $row1 = is_array($lookupRows[0]) ? $lookupRows[0] : array();
            $id = isset($row1['id']) ? $row1['id'] : 0;
            return is_scalar($id) ? intval($id) : 0;
        }
        return -1;
    }

    /** @inheritDoc */
    public function correctDuplicateLookupValues(): void {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/correctLookupTableIssue.sql");
        $this->dbCore->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));
    }

    /** @inheritDoc */
    public function getDailyActivityTrend(int $days = 30): array {
        $days = max(1, min(90, $days));
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = function_exists('absint') ? absint(get_current_blog_id()) : abs(intval(get_current_blog_id()));
            if ($blogId <= 0) { $blogId = 1; }
        }
        $maxLogId = 0;
        try {
            $maxLogId = intval($this->getMaxLogId());
            if ($maxLogId < 0) { $maxLogId = 0; }
        } catch (Throwable $e) {
            $this->logger->debugMessage(__FUNCTION__ . ' getMaxLogId() failed: ' . $e->getMessage() . '. Falling back to maxLogId=0 (cache key uses 0).');
            $maxLogId = 0;
        }
        $cacheKey = 'abj404_trend_v1_' . $blogId . '_' . $days . '_' . $maxLogId;
        if (function_exists('get_transient')) { $cached = get_transient($cacheKey); if (is_array($cached)) { return $cached; } }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $cutoff = time() - ($days * 86400);
        $notFoundDest = '404';
        $query = "SELECT DATE(FROM_UNIXTIME(`timestamp`)) AS `date`, SUM(CASE WHEN `dest_url` = %s THEN 1 ELSE 0 END) AS `hits_404`, SUM(CASE WHEN `dest_url` <> %s THEN 1 ELSE 0 END) AS `hits_redirect` FROM " . $logsTable . " WHERE `timestamp` >= " . intval($cutoff) . " GROUP BY DATE(FROM_UNIXTIME(`timestamp`)) ORDER BY `date` ASC";
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => array($notFoundDest, $notFoundDest)));
        $hadError = !empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] !== '');
        $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();
        $byDate = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $d = isset($row['date']) ? (string)$row['date'] : '';
            if ($d === '') { continue; }
            $byDate[$d] = array('date' => $d, 'hits_404' => intval($row['hits_404'] ?? 0), 'hits_redirect' => intval($row['hits_redirect'] ?? 0), 'new_captures' => intval($row['hits_404'] ?? 0));
        }
        $output = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - ($i * 86400));
            $output[] = isset($byDate[$d]) ? $byDate[$d] : array('date' => $d, 'hits_404' => 0, 'hits_redirect' => 0, 'new_captures' => 0);
        }
        if (!$hadError && function_exists('set_transient')) { set_transient($cacheKey, $output, self::TREND_DATA_CACHE_TTL_SECONDS); }
        return $output;
    }

    // =========================================================================
    // Hits-table rollup: thin forwarders to ABJ_404_Solution_LogsHitsRollupService
    // =========================================================================

    /** @inheritDoc */
    function recordLogsHitsRollupStalenessSignal(): void { $this->rollup->recordLogsHitsRollupStalenessSignal(); }

    /** @inheritDoc */
    function hitsTableNeedsRebuild() { return $this->rollup->hitsTableNeedsRebuild(); }

    /** @inheritDoc */
    function getLogsHitsTableLastUpdated() { return $this->rollup->getLogsHitsTableLastUpdated(); }

    /** @inheritDoc */
    function getLogsHitsTableLastUpdatedHuman() { return $this->rollup->getLogsHitsTableLastUpdatedHuman(); }

    /** @inheritDoc */
    function createRedirectsForViewHitsTable(): bool { return $this->rollup->createRedirectsForViewHitsTable(); }

    /** @inheritDoc */
    function logsHitsTableExists() { return $this->rollup->logsHitsTableExists(); }

    /** @inheritDoc */
    function scheduleHitsTableRebuild(): void { $this->rollup->scheduleHitsTableRebuild(); }

    /** @inheritDoc */
    function getMaxLogId() { return $this->rollup->getMaxLogId(); }

    /** @inheritDoc */
    function getMinLogId() { return $this->rollup->getMinLogId(); }

    /** @inheritDoc */
    function getStoredMaxLogId() { return $this->rollup->getStoredMaxLogId(); }

    /** @inheritDoc */
    function getLogsHitsTableLastCheckedAt() { return $this->rollup->getLogsHitsTableLastCheckedAt(); }

    /** @inheritDoc */
    function getLogsHitsTableLastScheduledAt() { return $this->rollup->getLogsHitsTableLastScheduledAt(); }

    /** @inheritDoc */
    function getLogsHitsTableLastDecision(): string { return $this->rollup->getLogsHitsTableLastDecision(); }

    /**
     * Backwards-compatible accessor used by some integration tests that need
     * the rollup-internal collation resolution. Delegates to the rollup
     * service. Not part of LogsRepositoryInterface.
     *
     * @return string
     */
    public function resolveHitsJoinCollation(): string {
        if (method_exists($this->rollup, 'resolveHitsJoinCollation')) {
            return $this->rollup->resolveHitsJoinCollation();
        }
        return '';
    }
}
