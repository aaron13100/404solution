<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsRepositoryInterface.php';

/**
 * Log insertion, querying, hits rebuild, GDPR, and lookup operations.
 *
 * Extracted from the DataAccess monolith (Phase 3 of the DataAccess refactor).
 * Methods originate from three sources:
 *   - DataAccessTrait_Logs (entirely absorbed)
 *   - DataAccessTrait_LogsHitsRebuild (entirely absorbed)
 *   - DataAccessTrait_Maintenance::correctDuplicateLookupValues() (relocated)
 *   - DataAccessTrait_ViewQueriesHitsLifecycle (hits lifecycle methods relocated)
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_LogsRepository implements ABJ_404_Solution_LogsRepositoryInterface {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300;
    /** @var int Minimum interval between hits-table rebuild schedules (server-side dedupe). */
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = 30;
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = 180;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    /** @var int Direct-path threshold for hits-table rebuild. */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = 5000;
    /** @var int Max age for cached daily-activity trend data. */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;

    /** @var string Runtime flag: last time we checked whether logs-hits needs rebuild. */
    const HITS_TABLE_LAST_CHECKED_FLAG = 'abj404_logs_hits_last_checked_at';
    /** @var string Runtime flag: last time we scheduled a rebuild. */
    const HITS_TABLE_LAST_SCHEDULED_FLAG = 'abj404_logs_hits_last_scheduled_at';
    /** @var string Runtime flag: last schedule decision. */
    const HITS_TABLE_LAST_DECISION_FLAG = 'abj404_logs_hits_last_decision';
    /** @var string Runtime flag: last successful hits-table rebuild completion. */
    const HITS_TABLE_LAST_REFRESHED_FLAG = 'abj404_logs_hits_last_refreshed_at';
    /** @var string Runtime flag: first stale detection timestamp. */
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = 'abj404_logs_hits_first_stale_detected_at';
    /** @var string Deduplicated admin-notice transient for stale logs_hits rollup. */
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = 'abj404_logs_hits_rollup_stale';
    /** @var int Minimum age (seconds) of stale gap before surfacing admin notice. */
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = 3600;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    /** @var array<int, array<string, mixed>> Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** @var bool Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** @var bool Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        $rebuildHealth = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
    }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    private function resolveRebuildHealthState(): ?ABJ_404_Solution_RebuildHealthState {
        if (function_exists('abj_service')
            && class_exists('ABJ_404_Solution_ServiceContainer')
            && ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
            try {
                $service = abj_service('rebuild_health');
                if ($service instanceof ABJ_404_Solution_RebuildHealthState) {
                    return $service;
                }
            } catch (Throwable $t) {
                $this->logger->debugMessage(__FUNCTION__ . ' rebuild_health service unavailable: ' . $t->getMessage());
            }
        }
        return null;
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
            $logsid = esc_sql($abj404logic->sanitizeForSQL(is_scalar($rawLogsId) ? (string)$rawLogsId : ''));
        }
        $orderbyExpressionByName = array(
            'timestamp'     => '{wp_abj404_logsv2}.timestamp',
            'requested_url' => '{wp_abj404_logsv2}.requested_url',
            'url'           => 'url',
            'id'            => '{wp_abj404_logsv2}.id',
            'min_log_id'    => '{wp_abj404_logsv2}.min_log_id',
        );
        $rawOrderByVal = $tableOptions['orderby'];
        $orderby = sanitize_text_field($abj404logic->sanitizeForSQL(is_string($rawOrderByVal) ? $rawOrderByVal : ''));
        $orderby = array_key_exists($orderby, $orderbyExpressionByName) ? $orderby : 'timestamp';
        $orderbyExpression = $orderbyExpressionByName[$orderby];
        $rawOrderVal2 = $tableOptions['order'];
        $order = strtoupper(sanitize_text_field($abj404logic->sanitizeForSQL(is_string($rawOrderVal2) ? $rawOrderVal2 : '')));
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
        $requested_url = $abj404logic->normalizeToRelativePath($requested_url);

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

        $options = $abj404logic->getOptions(true);
        $referer = function_exists('wp_get_referer') ? wp_get_referer() : '';
        if ($referer !== null && $referer !== false) {
            $referer = function_exists('esc_url_raw') ? esc_url_raw($referer) : (string)$referer;
            $referer = substr($referer, 0, 512);
        } else {
            $referer = '';
        }
        $current_user = function_exists('wp_get_current_user')
            ? ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user())
            : null;
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
    // Collation resolution for hits rebuild (Phase 1, c632)
    // =========================================================================

    /**
     * Resolve the collation from the abj404_redirects.canonical_url column,
     * the actual join partner for the hits rebuild phase2 JOIN.
     *
     * Falls back to utf8mb4_unicode_ci if the column query fails.
     *
     * @return string Sanitized collation identifier.
     */
    public function resolveHitsJoinCollation(): string {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        return $this->dbCore->getColumnCollationString($redirectsTable, 'canonical_url');
    }

    // =========================================================================
    // Hits table rebuild (from DataAccessTrait_LogsHitsRebuild)
    // =========================================================================

    /** @inheritDoc */
    function recordLogsHitsRollupStalenessSignal(): void {
        $currentMaxLogId = $this->getMaxLogId();
        $storedMaxLogId = $this->getStoredMaxLogId();
        if ($currentMaxLogId <= $storedMaxLogId) { $this->clearLogsHitsRollupStaleSignal(); return; }
        $rawFirstStale = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG);
        $firstStale = is_scalar($rawFirstStale) ? (int)$rawFirstStale : 0;
        if ($firstStale <= 0) { $this->dbCore->setRuntimeFlag(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG, time(), 86400); return; }
        $age = time() - $firstStale;
        if ($age >= self::HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS) { $this->setLogsHitsRollupStaleNotice($age); }
    }

    private function clearLogsHitsRollupStaleSignal(): void {
        if (function_exists('delete_transient')) { delete_transient(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG); delete_transient(self::HITS_TABLE_STALE_NOTICE_TRANSIENT); return; }
        if (function_exists('delete_option')) { delete_option(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG); delete_option(self::HITS_TABLE_STALE_NOTICE_TRANSIENT); }
    }

    /** @param int $ageSeconds @return void */
    private function setLogsHitsRollupStaleNotice(int $ageSeconds): void {
        if (!function_exists('set_transient')) { return; }
        $key = self::HITS_TABLE_STALE_NOTICE_TRANSIENT;
        if (function_exists('get_transient') && get_transient($key) !== false) { return; }
        $hours = max(1, intval(floor($ageSeconds / 3600)));
        $template = $this->dbCore->localizeOrDefault('The 404 Solution redirects-hits rollup has been behind MAX(logsv2.id) for at least %d hour(s). The cron-driven rebuild event (abj404_updateLogsHitsTableAction) does not appear to be firing, so the redirects list will show stale "hits" and "last hit" columns until cron resumes. To resolve: if DISABLE_WP_CRON is set in wp-config.php either remove it, or configure a system cron job that requests wp-cron.php periodically. To force a rebuild right now in your browser, open the 404 Solution Redirects page with ?abj404_force_view_rebuild=1 appended to the URL.');
        $payload = array('type' => 'logs_hits_rollup_stale', 'message' => sprintf($template, $hours), 'timestamp' => time(), 'error_string' => '', 'age_hours' => $hours);
        // allow-cache-empty: intentional notice payload; error_string is empty by definition for stale-rollup state.
        set_transient($key, $payload, 86400);
    }

    /** @inheritDoc */
    function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();
        if ($currentMaxId != $storedMaxId) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)"); return true; }
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) { $age = time() - $lastUpdated; if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)"); return true; } }
        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /** @inheritDoc */
    function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;
        $query = "SELECT create_time FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] == null || empty($results['rows'])) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $dateValue = is_array($statusRow) ? ($statusRow['update_time'] ?? ($statusRow['create_time'] ?? '')) : '';
                if ($dateValue !== '') { $fallbackTimestamp = strtotime(is_string($dateValue) ? $dateValue : ''); if ($fallbackTimestamp !== false) { if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $fallbackTimestamp) { return $runtimeRefreshedAt; } return $fallbackTimestamp; } }
            }
            return $runtimeRefreshedAt;
        }
        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? $hitsRows[0] : array();
        $row = array_change_key_case($row);
        $createTime = $row['create_time'] ?? null;
        if ($createTime === null) { return $runtimeRefreshedAt; }
        $schemaTimestamp = strtotime(is_string($createTime) ? $createTime : '');
        if ($schemaTimestamp === false) { return $runtimeRefreshedAt; }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) { return $runtimeRefreshedAt; }
        return $schemaTimestamp;
    }

    /** @return array<string, mixed> */
    private function getLogsHitsTableStatusRow() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) { return array(); }
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $query = $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName);
        if ($query === null) { return array(); }
        $results = $this->dbCore->queryAndGetResults($query, array('log_errors' => false));
        if (!is_array($results['rows']) || empty($results['rows']) || !is_array($results['rows'][0])) { return array(); }
        return array_change_key_case($results['rows'][0], CASE_LOWER);
    }

    /** @inheritDoc */
    function getLogsHitsTableLastUpdatedHuman() {
        $timestamp = $this->getLogsHitsTableLastUpdated();
        if ($timestamp === null) { return ''; }
        $diff = time() - $timestamp;
        if ($diff < 60) { return __('Just now', '404-solution'); }
        elseif ($diff < 3600) { $minutes = (int)floor($diff / 60); return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes); }
        elseif ($diff < 86400) { $hours = (int)floor($diff / 3600); return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours); }
        else { $days = (int)floor($diff / 86400); return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days); }
    }

    /** @inheritDoc */
    function createRedirectsForViewHitsTable(): bool {
        $wasRefreshed = false;
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed.");
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return false;
        }
        if ($this->dbCore->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return false; }
        if (!$this->acquireHitsTableRebuildLock()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400); return false; }
        $preAggTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_preagg");
        try {
            $finalDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}");
            $tempDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
            $this->dbCore->queryAndGetResults("drop table if exists " . $tempDestTable);
            $resolvedCollation = $this->resolveHitsJoinCollation();
            $createTempTableQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogsHitsTempTable.sql");
            $createTempTableQuery = $this->dbCore->doTableNameReplacements($createTempTableQuery);
            $createTempTableQuery = str_replace('{COLLATION}', $resolvedCollation, $createTempTableQuery);
            $this->dbCore->queryAndGetResults($createTempTableQuery);
            // @cache-write-audit: opt-out - truncates an unpublished temp table before rebuilding it.
            $this->dbCore->queryAndGetResults("truncate table " . $tempDestTable);
            $maxLogIdSnapshot = $this->getMaxLogId();
            $minLogId = $this->getMinLogId();
            $idRange = $maxLogIdSnapshot - $minLogId;
            $chunkSize = $this->getHitsRebuildChunkSize($idRange);
            if ($idRange <= self::HITS_TABLE_DIRECT_PATH_THRESHOLD) { $results = $this->hitsTableInsertDirect($tempDestTable); } else { $results = $this->hitsTableInsertChunked($tempDestTable, $preAggTable, $minLogId, $maxLogIdSnapshot, $chunkSize); }
            if ($results === false || !empty($results['timed_out']) || !empty($results['last_error'])) {
                $errorMessage = $results === false ? 'Hits rebuild phase 1 chunk failed.' : (string)($results['last_error'] ?? 'Hits rebuild timed out.');
                $this->recordHitsRebuildFailure($errorMessage);
                if ($idRange > self::HITS_TABLE_DIRECT_PATH_THRESHOLD && $results !== false && (!empty($results['timed_out']) || !empty($results['last_error']))) {
                    $this->recordHitsChunkFailure();
                }
                $this->dbCore->queryAndGetResults("drop table if exists " . $tempDestTable); $this->logger->debugMessage(__FUNCTION__ . " INSERT timed out or errored; aborting rebuild."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return false;
            }
            $elapsedTime = $results['elapsed_time'];
            $comment = $elapsedTime . '|' . $maxLogIdSnapshot;
            // @utf8-audit: opt-out — rebuild table comment is synthesized from numeric timing and ID values.
            $comment = substr(esc_sql($comment), 0, 2048);
            $this->dbCore->queryAndGetResults(sprintf("ALTER TABLE %s COMMENT '%s'", $tempDestTable, $comment));
            $statements = array("drop table if exists " . $finalDestTable, "rename table " . $tempDestTable . ' to ' . $finalDestTable);
            $this->dbCore->executeAsTransaction($statements);
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, time(), 86400);
            $this->recordHitsRebuildSuccess($chunkSize);
            $this->clearLogsHitsRollupStaleSignal();
            $wasRefreshed = true;
            $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . " seconds.");
        } catch (Throwable $e) {
            $this->recordHitsRebuildFailure($e->getMessage());
            $this->logger->errorMessage(__FUNCTION__ . " failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
        } finally {
            $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
            $this->releaseHitsTableRebuildLock();
        }
        return $wasRefreshed;
    }

    /** @param string $tempDestTable @return array<string, mixed> */
    private function hitsTableInsertDirect(string $tempDestTable): array {
        $ttSelectQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForViewTempTable.sql");
        if ($this->isLogsv2CanonicalUrlBackfillComplete()) { $ttSelectQuery = $this->dropLogsv2CanonicalCoalesceWrap($ttSelectQuery); }
        $ttSelectQuery = $this->dbCore->doTableNameReplacements($ttSelectQuery);
        $ttInsertQuery = "/* abj404:src=LogsRepository::hitsTableInsertDirect */ insert into " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) \n " . $ttSelectQuery;
        return $this->dbCore->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false, 'timeout' => 60));
    }

    /** @return bool */
    private function isLogsv2CanonicalUrlBackfillComplete(): bool {
        if (!function_exists('get_option')) { return false; }
        return (bool)get_option('abj404_logsv2_canonical_url_backfill_complete');
    }

    /** @param string $sql @return string */
    private function dropLogsv2CanonicalCoalesceWrap(string $sql): string {
        $pattern = '/COALESCE\(\{wp_abj404_logsv2\}\.canonical_url,\s*CONCAT\(\'\/\',\s*TRIM\(BOTH\s+\'\/\'\s+FROM\s+\{wp_abj404_logsv2\}\.requested_url\)\)\)/';
        $result = preg_replace($pattern, '{wp_abj404_logsv2}.canonical_url', $sql);
        return is_string($result) ? $result : $sql;
    }

    /** @return array<string, mixed>|false */
    private function hitsTableInsertChunked(string $tempDestTable, string $preAggTable, int $minId, int $maxId, int $chunkSize) {
        $logsv2Table = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $resolvedCollation = $this->resolveHitsJoinCollation();
        $startTime = microtime(true);
        $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
        $createPreAggQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogsHitsPreAggTempTable.sql");
        $createPreAggQuery = $this->dbCore->doTableNameReplacements($createPreAggQuery);
        $createPreAggQuery = str_replace('{COLLATION}', $resolvedCollation, $createPreAggQuery);
        $this->dbCore->queryAndGetResults($createPreAggQuery);
        $logsv2CanonicalExpr = $this->isLogsv2CanonicalUrlBackfillComplete() ? "canonical_url" : "COALESCE(canonical_url, CONCAT('/', TRIM(BOTH '/' FROM requested_url)))";
        for ($start = $minId; $start <= $maxId; $start += $chunkSize) {
            $end = $start + $chunkSize;
            $chunkQuery = "/* abj404:src=LogsRepository::hitsTableInsertChunked#phase1Chunk */ INSERT INTO " . $preAggTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT " . $logsv2CanonicalExpr . ", MIN(id), MAX(timestamp), COUNT(*), SUM(CASE WHEN dest_url = '' OR dest_url IS NULL THEN 1 ELSE 0 END) FROM " . $logsv2Table . " WHERE id >= %d AND id < %d GROUP BY " . $logsv2CanonicalExpr;
            $chunkResult = $this->dbCore->queryAndGetResults($chunkQuery, array('log_too_slow' => false, 'timeout' => 10, 'query_params' => array($start, $end)));
            if (!empty($chunkResult['timed_out']) || !empty($chunkResult['last_error'])) { $this->recordHitsChunkFailure(); $this->logger->debugMessage(__FUNCTION__ . " Phase 1 chunk failed at id range [{$start}, {$end}); aborting."); return false; }
        }
        $phase2Query = "/* abj404:src=LogsRepository::hitsTableInsertChunked#phase2Aggregate */ INSERT INTO " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT a.requested_url, MIN(a.logsid), MAX(a.last_used), SUM(a.logshits), SUM(a.failed_hits) FROM " . $preAggTable . " a INNER JOIN " . $redirectsTable . " r ON a.requested_url = (COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url))) COLLATE " . $resolvedCollation . ") GROUP BY a.requested_url";
        $results = $this->dbCore->queryAndGetResults($phase2Query, array('log_too_slow' => false, 'timeout' => 60));
        $results['elapsed_time'] = round(microtime(true) - $startTime, 3);
        $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
        return $results;
    }

    /** @param int $idRange @return int */
    private function getHitsRebuildChunkSize(int $idRange): int {
        if ($this->rebuildHealth === null) {
            return self::HITS_TABLE_PREAGG_CHUNK_SIZE;
        }
        return $this->rebuildHealth->getHitsChunkSize($idRange);
    }

    /** @return void */
    private function recordHitsChunkFailure(): void {
        if ($this->rebuildHealth !== null) {
            $this->rebuildHealth->recordHitsChunkFailure();
        }
    }

    /** @param int $chunkSize @return void */
    private function recordHitsRebuildSuccess(int $chunkSize): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFullRebuildSuccess($chunkSize);
        $this->rebuildHealth->recordSuccess();
    }

    /** @param string $message @return void */
    private function recordHitsRebuildFailure(string $message): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFailure($message, $this->rebuildHealth->classifyError($message));
    }

    // =========================================================================
    // Hits table lifecycle (from DataAccessTrait_ViewQueriesHitsLifecycle)
    // =========================================================================

    /** @inheritDoc */
    function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] != null && !empty($results['rows'])) { return true; }
        if (!empty($results['last_error'])) { return $this->logsHitsTableExistsViaShowTables(); }
        return false;
    }

    /** @return bool */
    private function logsHitsTableExistsViaShowTables(): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) { return false; }
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $showTablesQuery = $wpdb->prepare("SHOW TABLES LIKE %s", $tableName);
        if ($showTablesQuery === null) { return false; }
        $fallback = $this->dbCore->queryAndGetResults($showTablesQuery, array('log_errors' => false));
        if (empty($fallback['rows'])) { return false; }
        $fbRows = is_array($fallback['rows']) ? $fallback['rows'] : array();
        $firstRow = isset($fbRows[0]) ? $fbRows[0] : null;
        if (!is_array($firstRow)) { return false; }
        $value = reset($firstRow);
        return ((string)$value === (string)$tableName);
    }

    /** @inheritDoc */
    function scheduleHitsTableRebuild(): void {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->mayStartExpensiveRebuild()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return; }
        if ($this->dbCore->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return; }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->isHitsTableRebuildLocked()) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400); return; }
            $rawScheduledFlag = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (time() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown."); $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'cooldown', 86400); return; }
            self::$hitsTableRebuildScheduled = true;
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, time(), 86400);
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'scheduled', 86400);
            if ($this->shouldScheduleHitsTableRebuildViaCron()) { $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron."); if (function_exists('wp_schedule_single_event')) { wp_schedule_single_event(time() + 5, 'abj404_updateLogsHitsTableAction'); } return; }
            $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild for shutdown hook.");
            add_action('shutdown', function(): void { $this->createRedirectsForViewHitsTable(); });
        }
    }

    /** @return bool */
    private function shouldScheduleHitsTableRebuildViaCron(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { return true; }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') { return true; }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }

    private function getHitsTableRebuildLockOptionName(): string { return $this->dbCore->getLowercasePrefix() . 'abj404_logs_hits_rebuild_lock'; }

    /** @return bool */
    private function isHitsTableRebuildLocked(): bool {
        if (!function_exists('get_option')) { return false; }
        $lockValue = get_option($this->getHitsTableRebuildLockOptionName(), false);
        if ($lockValue === false || $lockValue === null || $lockValue === '') { return false; }
        if (!is_numeric($lockValue)) { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } return false; }
        $lockTimestamp = (int)$lockValue;
        if ($lockTimestamp > 0 && (time() - $lockTimestamp) > self::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS) { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } return false; }
        return true;
    }

    /** @return bool */
    private function acquireHitsTableRebuildLock(): bool {
        if (!function_exists('add_option')) { return true; }
        if ($this->isHitsTableRebuildLocked()) { return false; }
        return (bool)add_option($this->getHitsTableRebuildLockOptionName(), (string)time(), '', false);
    }

    /** @return void */
    private function releaseHitsTableRebuildLock(): void { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } }

    /** @inheritDoc */
    function getMaxLogId() {
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /** @inheritDoc */
    function getMinLogId() {
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $minId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return is_numeric($minId) ? (int)$minId : 0;
    }

    /** @inheritDoc */
    function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $storedRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($storedRows)) {
            if (!empty($results['last_error'])) { $statusRow = $this->getLogsHitsTableStatusRow(); $commentFromStatus = $statusRow['comment'] ?? ''; if ($commentFromStatus !== '') { $parts = explode('|', is_string($commentFromStatus) ? $commentFromStatus : ''); if (count($parts) >= 2) { return (int)$parts[1]; } } }
            return 0;
        }
        $row = is_array($storedRows[0] ?? null) ? $storedRows[0] : array();
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';
        $parts = explode('|', is_string($comment) ? $comment : '');
        if (count($parts) >= 2) { return (int)$parts[1]; }
        return 0;
    }

    /** @inheritDoc */
    function getLogsHitsTableLastCheckedAt() { $rawTsFlag = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG); $ts = is_scalar($rawTsFlag) ? (int)$rawTsFlag : 0; return $ts > 0 ? $ts : null; }

    /** @inheritDoc */
    function getLogsHitsTableLastScheduledAt() { $rawTsFlag2 = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG); $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0; return $ts > 0 ? $ts : null; }

    /** @inheritDoc */
    function getLogsHitsTableLastDecision(): string { $v = $this->dbCore->getRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG); return is_string($v) ? $v : ''; }
}
