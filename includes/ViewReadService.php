<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewSnapshotCache.php';

/**
 * Admin list view read path, snapshot caching, and status counts.
 *
 * Extracted from DataAccess in Phase 6 of the DataAccess refactor.
 * Delegates to four collaborators: ViewQueryBuilder (SQL construction),
 * ViewDiagnostics (failure diagnostics), ViewCacheInvalidator (cache
 * clearing), and ViewSnapshotCache (cache CRUD and warmup).
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
class ABJ_404_Solution_ViewReadService implements ABJ_404_Solution_ViewReadServiceInterface, ABJ_404_Solution_ViewSnapshotCacheHostInterface {
    /** @var bool Legacy reflection bridge for tests and old diagnostics. */
    private static $viewSnapshotTableEnsured = false;

    // --- Constants ---

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL;
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS;
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS;
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES;
    const HITS_TABLE_LAST_CHECKED_FLAG = ABJ_404_Solution_ViewReadRuntimeState::HITS_TABLE_LAST_CHECKED_FLAG;
    const HITS_TABLE_LAST_DECISION_FLAG = ABJ_404_Solution_ViewReadRuntimeState::HITS_TABLE_LAST_DECISION_FLAG;
    const LOGS_COUNT_CACHE_TTL_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::LOGS_COUNT_CACHE_TTL_SECONDS;

    // --- Static properties ---

    /**
     * Per-request "bulk mutation in progress" flag.
     *
     * @var bool
     */
    public static $bulkMutationInProgress = false;

    // --- Dependencies (constructor injection) ---

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    // --- Collaborators ---

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_ViewDiagnostics */
    private $diagnostics;

    /** @var ABJ_404_Solution_ViewCacheInvalidator */
    private $cacheInvalidator;

    /** @var ABJ_404_Solution_ViewSnapshotCache */
    private $snapshotCache;

    // --- ViewBuildOrchestrator bridge ---

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    // --- Instance property ---

    /** @var array<string, int> */
    private $redirectsForViewCountRequestCache = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_RedirectsRepository $redirectsRepo,
        $f = null,
        $logger = null
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');

        $this->diagnostics = new ABJ_404_Solution_ViewDiagnostics($dbCore);
        $this->cacheInvalidator = new ABJ_404_Solution_ViewCacheInvalidator(
            $dbCore, $redirectsRepo, $this->viewDoneFreshnessOptionName()
        );
        $this->queryBuilder = new ABJ_404_Solution_ViewQueryBuilder(
            $dbCore, $this->f, $logsRepo, $this->logger
        );
        $this->queryBuilder->setHost($this);
        $this->snapshotCache = new ABJ_404_Solution_ViewSnapshotCache($dbCore, $this->logger);
        $this->snapshotCache->setHost($this);
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
        $this->cacheInvalidator->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->queryBuilder->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->snapshotCache->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewReadService requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
        ABJ_404_Solution_ViewSnapshotCache::setViewSnapshotTableEnsured($value);
    }

    /** @return bool */
    public static function isViewSnapshotTableEnsured(): bool {
        return self::$viewSnapshotTableEnsured;
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function scalarToInt($value): int {
        return is_scalar($value) ? intval($value) : 0;
    }

    // =========================================================================
    // Interface-satisfying read methods and lightweight accessors
    // =========================================================================

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    function getRedirectStatusCounts($bypassCache = false): array {
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_REDIRECT_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        $query = "SELECT
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_MANUAL . " THEN 1 ELSE 0 END) as manual_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_AUTO . " THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_REGEX . " THEN 1 ELSE 0 END) as regex_count,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash_count
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ", " . ABJ404_STATUS_REGEX . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $activeCount = $row['active_count'] ?? 0;
            $manualCount = $row['manual_count'] ?? 0;
            $autoCount = $row['auto_count'] ?? 0;
            $regexCount = $row['regex_count'] ?? 0;
            $trashCount = $row['trash_count'] ?? 0;
            $counts = array(
                'all' => self::scalarToInt($activeCount),
                'manual' => self::scalarToInt($manualCount),
                'auto' => self::scalarToInt($autoCount),
                'regex' => self::scalarToInt($regexCount),
                'trash' => self::scalarToInt($trashCount)
            );
        }

        if (!$hadError && !$bypassCache) {
            set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    function getCapturedStatusCounts($bypassCache = false): array {
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_CAPTURED_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_CAPTURED . " THEN 1 ELSE 0 END) as captured,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_IGNORED . " THEN 1 ELSE 0 END) as ignored,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_LATER . " THEN 1 ELSE 0 END) as later,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $activeCount = $row['active'] ?? 0;
            $capturedCount = $row['captured'] ?? 0;
            $ignoredCount = $row['ignored'] ?? 0;
            $laterCount = $row['later'] ?? 0;
            $trashCount = $row['trash'] ?? 0;
            $counts = array(
                'all' => self::scalarToInt($activeCount),
                'captured' => self::scalarToInt($capturedCount),
                'ignored' => self::scalarToInt($ignoredCount),
                'later' => self::scalarToInt($laterCount),
                'trash' => self::scalarToInt($trashCount)
            );
        }

        if (!$hadError && !$bypassCache) {
            set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * @return int
     */
    function getHighImpactCapturedCount(): int {
        $cached = get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        if ($cached !== false) {
            return intval(is_scalar($cached) ? $cached : 0);
        }

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = $this->queryBuilder->buildHighImpactCapturedCountQuery();

        $result = $this->queryWithTimeout($query, 60);
        $timedOut = !empty($result['timed_out']);
        $hadError = !empty($result['last_error']) || $timedOut;
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $firstRow = (!empty($rows) && is_array($rows[0] ?? null)) ? $rows[0] : array();
        $count = self::scalarToInt($firstRow['cnt'] ?? 0);

        if ($timedOut) {
            $this->logsRepo->scheduleHitsTableRebuild();
            // allow-cache-empty: timeout self-heal sentinel, 5-minute window. Real value returns once the rebuild completes and the short cache expires.
            set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, 0, self::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL);
            return 0;
        }

        if ($hadError) {
            return 0;
        }

        if ($count === 0) {
            if ($this->isHitsTableEmpty()) {
                $this->logsRepo->scheduleHitsTableRebuild();
                return 0;
            }
        }

        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, $count, self::STATUS_CACHE_TTL);

        return $count;
    }

    /**
     * @return bool
     */
    private function isHitsTableEmpty(): bool {
        $check = "SELECT 1 FROM {wp_abj404_logs_hits} LIMIT 1";
        $check = $this->dbCore->doTableNameReplacements($check);
        $result = $this->dbCore->queryAndGetResults($check);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return empty($rows);
    }

    /**
     * @param string $query
     * @param int $timeoutSeconds
     * @return array<string, mixed>
     */
    private function queryWithTimeout(string $query, int $timeoutSeconds = 60): array {
        return $this->dbCore->queryAndGetResults($query, array(
            'timeout' => $timeoutSeconds,
        ));
    }

    /** @return string|null */
    private function logsCountCacheKey(int $logID): ?string {
        if ($logID !== 0 || !function_exists('get_transient')) {
            return null;
        }

        return 'abj404_logs_count_v1_' . $this->currentBlogIdForCache() . '_' . $this->maxLogIdForCache();
    }

    /** @return int */
    private function currentBlogIdForCache(): int {
        if (!function_exists('get_current_blog_id')) {
            return 1;
        }

        $rawBlogId = function_exists('absint')
            ? absint(get_current_blog_id())
            : abs(intval(get_current_blog_id()));

        return $rawBlogId > 0 ? $rawBlogId : 1;
    }

    /** @return int */
    private function maxLogIdForCache(): int {
        try {
            return max(0, intval($this->logsRepo->getMaxLogId()));
        } catch (Throwable $e) {
            $this->logger->debugMessage(__FUNCTION__ . ' getMaxLogId() failed: '
                . $e->getMessage() . '. Falling back to maxLogId=0.');
            return 0;
        }
    }

    /**
     * @param int $logID
     * @return int
     */
    function getLogsCount($logID) {
        $logID = absint($logID);

        $cacheKey = $this->logsCountCacheKey($logID);
        if ($cacheKey !== null) {
            $cached = get_transient($cacheKey);
            if (is_numeric($cached)) {
                return (int)$cached;
            }
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsCount.sql");

        if ($logID != 0) {
            $query = $this->f->str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = $this->f->str_replace('{logID}', (string)$logID, $query);
        }

        $result = $this->dbCore->queryAndGetResults($query);
        $hadError = !empty($result['timed_out'])
            || (isset($result['last_error']) && $result['last_error'] != '');

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $count = 0;
        if (!empty($rows)) {
            $first = $rows[0];
            $value = is_array($first) ? reset($first) : $first;
            $count = self::scalarToInt($value);
        }

        if (!$hadError && $cacheKey !== null && function_exists('set_transient')
            && empty($GLOBALS['abj404_feedback_preview_readonly'])) {
            set_transient($cacheKey, $count, self::LOGS_COUNT_CACHE_TTL_SECONDS);
        }

        return function_exists('apply_filters')
            ? (int) apply_filters('abj404_logs_count', $count, $logID)
            : $count;
    }

    /** @return array<int, array<string, mixed>> */
    function getRedirectsAll() {
        $query = "select id, url from {wp_abj404_redirects} order by url";

        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /** @param string $tempFile @return void */
    function doRedirectsExport(string $tempFile): void {
    	global $wpdb;

    	if (file_exists($tempFile)) {
    		ABJ_404_Solution_Functions::safeUnlink($tempFile);
    	}

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/getRedirectsExport.sql");
    	$query = $this->dbCore->doTableNameReplacements($query);

    	$result = mysqli_query($wpdb->dbh, $query);
    	if ($result instanceof \mysqli_result) {
    		$fh = fopen($tempFile, 'w');
    		if ($fh === false) {
    			return;
    		}
    		fputcsv($fh, array('from_url', 'status', 'type', 'to_url', 'wp_type', 'engine', 'code'), ',', '"', '\\');

    		while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
    			fputcsv($fh, array(
    				$row['from_url'],
    				$row['status'],
    				$row['type'],
    				$row['to_url'],
    				$row['type_wp'],
    				isset($row['engine']) ? $row['engine'] : '',
    				isset($row['code']) ? $row['code'] : '301'
    			), ',', '"', '\\');
    		}
    		fclose($fh);
    		mysqli_free_result($result);
    	}
    }

    /** @return array<int, array<string, mixed>> */
    function getRedirectsWithLogs() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsWithLogs.sql");

        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    function getRedirectsWithRegEx() {
        $cached = ABJ_404_Solution_RedirectsRepository::getRegexRedirectsCache();
        $disabled = ABJ_404_Solution_RedirectsRepository::isRegexCacheDisabled();

        if ($cached !== null && !$disabled) {
            return $cached;
        }

        if ($disabled) {
            return $this->queryBuilder->queryRegexRedirects();
        }

        $results = $this->queryBuilder->queryRegexRedirects();

        if (count($results) <= ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT) {
            ABJ_404_Solution_RedirectsRepository::setRegexRedirectsCache($results);
        } else {
            ABJ_404_Solution_RedirectsRepository::setRegexCacheDisabled(true);
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    function getManualRedirectsWithRegexMetachars() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status = " . ABJ404_STATUS_MANUAL . " \n " .
                "     and disabled = 0 \n " .
                "     and (INSTR(`url`, '*') > 0 " .
                "       OR INSTR(`url`, '[') > 0 " .
                "       OR INSTR(`url`, ']') > 0 " .
                "       OR INSTR(`url`, '|') > 0 " .
                "       OR INSTR(`url`, '^') > 0 " .
                "       OR INSTR(`url`, '\\\\') > 0 " .
                "       OR INSTR(`url`, '{') > 0 " .
                "       OR INSTR(`url`, '}') > 0)";
        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    function getRedirectsForView($sub, $tableOptions) {
        $canUseSnapshotCache = $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $snapshotCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
            $cachedRowsFromTable = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
            if (is_array($cachedRowsFromTable)) {
                return $cachedRowsFromTable;
            }
            if (function_exists('get_transient')) {
                $cachedRows = get_transient($snapshotCacheKey);
                if (is_array($cachedRows)) {
                    return $cachedRows;
                }
            }
        }

        try {
            $rows = $this->requireViewBuildOrchestrator()->runRedirectsForViewStaged((string)$sub, is_array($tableOptions) ? $tableOptions : array());
        } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
            if ($throwOnQueryError) {
                throw $pending;
            }
            $this->logger->debugMessage('[staged] getRedirectsForView pending: ' . $pending->getMessage());
            return array();
        } catch (Throwable $e) {
            if ($throwOnQueryError) {
                $stagedFailureMarker = '/* staged: ' . $e->getMessage() . ' */';
                $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                    (string)$sub,
                    $stagedFailureMarker,
                    is_array($tableOptions) ? $tableOptions : array(),
                    array('last_error' => $e->getMessage(), 'timed_out' => false)
                );
                $diagnostics['failed_query_label'] = 'getRedirectsForView';
                $diagnostics['staged_error'] = $e->getMessage();
                $message = 'getRedirectsForView failed; last_error=' . $e->getMessage()
                    . '; timed_out=false; sql_source=' . $stagedFailureMarker;
                throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
            }
            $this->logger->errorMessage('[staged] getRedirectsForView failed: ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null);
            return array();
        }

        $this->logger->debugMessage(sprintf(
            '[staged] getRedirectsForView returned %d rows for page %s',
            count($rows),
            (string)$sub
        ));

        if ($canUseSnapshotCache && $snapshotCacheKey === '') {
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $snapshotCacheKey !== '') {
            $this->snapshotCache->setViewRowsSnapshotToTable($snapshotCacheKey, $sub, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            if (function_exists('set_transient')) {
                // allow-cache-empty: empty $rows is a legitimate result on a fresh install (no redirects yet); error paths early-return above without reaching this line
                set_transient($snapshotCacheKey, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            }
        }

        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewRowsSnapshotAvailable($sub, array $tableOptions): bool {
        $canUseSnapshotCache = $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        $freshRows = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
        if (is_array($freshRows)) {
            return true;
        }
        $recentRows = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, true, true);
        if (is_array($recentRows)) {
            return true;
        }
        if (function_exists('get_transient')) {
            $transientRows = get_transient($snapshotCacheKey);
            if (is_array($transientRows)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewTableSnapshotAvailable($sub, array $tableOptions): bool {
        if (!$this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            return false;
        }

        $canUseSnapshotCache = function_exists('get_transient')
            && $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        return get_transient($countCacheKey) !== false;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $canUseSnapshotCache = function_exists('get_transient')
            && $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));
        $countCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
            $cachedCount = get_transient($countCacheKey);
            if ($cachedCount !== false) {
                return intval(is_scalar($cachedCount) ? $cachedCount : 0);
            }
        }
        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText === '') {
            $query = $this->queryBuilder->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
            $this->cacheInvalidator->setSqlBigSelects();
            $queryOptions = $queryTimeout > 0 ? array('timeout' => $queryTimeout) : array();
            $results = $this->dbCore->queryAndGetResults($query, $queryOptions);
            $lastErrorRaw = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        } else {
            try {
                $countValue = $this->requireViewBuildOrchestrator()->runRedirectsForViewCountStaged((string)$sub, $tableOptions);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
                if ($canUseSnapshotCache && $countCacheKey === '') {
                    $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
                }
                if ($canUseSnapshotCache && $countCacheKey !== '') {
                    // allow-cache-empty: $countValue=0 is a legitimate result when no rows match the search filter; the staged pending/error paths throw above without reaching this line
                    set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
                }
                return $countValue;
            } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
                if ($throwOnQueryError) {
                    throw $pending;
                }
                $this->logger->debugMessage('[staged] getRedirectsForViewCount pending: ' . $pending->getMessage());
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            } catch (Throwable $e) {
                if ($throwOnQueryError) {
                    $stagedFailureMarker = '/* staged-count: ' . $e->getMessage() . ' */';
                    $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                        (string)$sub,
                        $stagedFailureMarker,
                        $tableOptions,
                        array('last_error' => $e->getMessage(), 'timed_out' => false)
                    );
                    $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
                    $diagnostics['staged_error'] = $e->getMessage();
                    throw new ABJ_404_Solution_ViewQueryFailureException($e->getMessage(), $diagnostics);
                }
                $this->logger->errorMessage('[staged] getRedirectsForViewCount failed: ' . $e->getMessage(),
                    $e instanceof \Exception ? $e : null);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            }
        }

        if ($throwOnQueryError && (!empty($results['timed_out']) || $lastError !== '')) {
            $message = $this->diagnostics->formatViewQueryFailureMessage('getRedirectsForViewCount', $query, $results);
            $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
        }

        if ($lastError != '' && trim($lastError) != '') {
            $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException(
                "Error getting redirect count: " . esc_html($lastError),
                $diagnostics
            );
        }
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
        	return -1;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $rawCount = $row['count'] ?? $row['COUNT(*)'] ?? reset($row);
        $countValue = intval(is_scalar($rawCount) ? $rawCount : 0);
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        if ($canUseSnapshotCache && $countCacheKey === '') {
            $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $countCacheKey !== '') {
            set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
        }
        return $countValue;
    }

    /** @param array<int, string> $postIDs @return array<int, mixed> */
    function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(", ", $postIDs);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getAdditionalPostData.sql");
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, mixed> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
     */
    function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; callers execute the result through queryAndGetResults
        return $wpdb->prepare($prepared_query, $ordered_values);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                return $matches[0];
            }
            $value = $data[$key];

            $ordered_values[] = $value;

            $placeholder_type = is_int($value) ? '%d' : '%s';

            return $placeholder_type;
        }, $query);

        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
    }

    // =========================================================================
    // Delegated: ViewQueryBuilder
    // =========================================================================

    /** @return string */
    function buildHighImpactCapturedCountQuery(): string {
        return $this->queryBuilder->buildHighImpactCapturedCountQuery();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
    	$limitStart, $limitEnd, $selectCountOnly) {
        return $this->queryBuilder->getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
            $limitStart, $limitEnd, $selectCountOnly);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->queryBuilder->readFromViewDone($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->queryBuilder->buildViewDoneCountQuery($sub, $tableOptions);
    }

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array {
        return $this->queryBuilder->viewBuildOnlyTranslations();
    }

    // =========================================================================
    // Delegated: ViewCacheInvalidator
    // =========================================================================

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work) {
        return $this->cacheInvalidator->runWithDeferredInvalidation($work);
    }

    /** @return void */
    function invalidateStatusCountsCache(): void {
        $this->cacheInvalidator->invalidateStatusCountsCache();
    }

    /** @return void */
    function invalidateViewSnapshotCache(): void {
        $this->cacheInvalidator->invalidateViewSnapshotCache();
    }

    /** @return void */
    function clearRegexRedirectsCache(): void {
        $this->cacheInvalidator->clearRegexRedirectsCache();
    }

    // =========================================================================
    // Delegated: ViewDiagnostics
    // =========================================================================

    /**
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $queryResult
     * @return array<string, mixed>
     */
    public function captureViewQueryFailureDiagnostics(string $sub, string $failedQuery, array $tableOptions, array $queryResult): array {
        return $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $failedQuery, $tableOptions, $queryResult);
    }

    // =========================================================================
    // Delegated: ViewSnapshotCache
    // =========================================================================

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        return $this->snapshotCache->warmViewTableSnapshotStage($sub, $tableOptions);
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return $this->snapshotCache->getViewBuildProgressFingerprint();
    }

    // =========================================================================
    // ViewMetadata (originally in host, not from traits)
    // =========================================================================

    /** @return array<string, mixed> */
    function getTableEngines() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
    	$results = $this->dbCore->queryAndGetResults($query);
    	return $results;
    }

    /** @return bool */
    function isMyISAMSupported(): bool {
        $supportResults = $this->dbCore->queryAndGetResults("SELECT ENGINE, SUPPORT " .
            "FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false));

        if (!empty($supportResults) && !empty($supportResults['rows']) && is_array($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $supportValue = array_key_exists('support', $row) ? (string)($row['support'] ?? '') :
            (array_key_exists('SUPPORT', $row) ? (string)($row['SUPPORT'] ?? '') : "nope");

            return strtolower($supportValue) == 'yes';
        }
        return false;
    }

    /**
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->dbCore->doTableNameReplacements($tableName);

        $columns = array();
        $placeholders = array();
        $values = array();

        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';

            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = is_scalar($value) ? (string)$value : '';
                }
            }
        }

        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return $this->dbCore->queryAndGetResults($sql, ['query_params' => $values]);
    }

   /** @return int */
   function getCapturedCount() {
       $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);

       $result = $this->dbCore->queryAndGetResults($query);
       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           return 0;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }
       $first = $rows[0];
       $value = is_array($first) ? reset($first) : $first;
       return self::scalarToInt($value);
   }

   /** @return array<int, string> */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $results = $this->dbCore->queryAndGetResults($query);
       $rows = $results['rows'];

       $postType = array();

       if (is_array($rows)) {
           foreach ($rows as $row) {
               array_push($postType, $row['post_type']);
           }
       }

       return $postType;
   }

   /** @return int */
   function getLogDiskUsage() {
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables '
               . 'WHERE table_name=\'{wp_abj404_logsv2}\'';

       $result = $this->dbCore->queryAndGetResults($query);

       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           $err = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
           if ($err !== '') {
               $this->logger->errorMessage("Error: " . esc_html($err));
           }
           return -1;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }

       $row = is_array($rows[0] ?? null) ? $rows[0] : array();
       $size = $row['tablesize'] ?? null;
       if ($size === null || !is_scalar($size)) {
           return 0;
       }
       $bytes = intval($size);
       return function_exists('apply_filters')
           ? (int) apply_filters('abj404_log_disk_usage', $bytes)
           : $bytes;
   }

    /**
     * @param array<int, int> $types
     * @param int $trashed
     * @return int
     */
    function getRecordCount($types = array(), $trashed = 0) {
        $recordCount = 0;

        if (count($types) >= 1) {
            $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in (";

            $filteredTypes = array_map('absint', $types);
            $typesForSQL = implode(", ", $filteredTypes);
            $query .= $typesForSQL . "))";
            $query .= " and disabled = " . absint($trashed);

            $result = $this->dbCore->queryAndGetResults($query);
            $rows = is_array($result['rows']) ? $result['rows'] : array();
            if (!empty($rows)) {
	            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
	            $recordCount = isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
            }
        }

        return intval($recordCount);
    }

    // =========================================================================
    // ViewQueriesHitsLifecycle
    // =========================================================================

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        if (function_exists('abj_service')) {
            $upgradesEtc = abj_service('database_upgrades');
            if (is_object($upgradesEtc) && method_exists($upgradesEtc, 'scheduleLogsv2CanonicalUrlBackfill')) {
                $upgradesEtc->scheduleLogsv2CanonicalUrlBackfill();
            }
        }

        if ($this->dbCore->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->logsRepo->scheduleHitsTableRebuild();
            return;
        }

        $this->logsRepo->recordLogsHitsRollupStalenessSignal();

        if (!$this->logsRepo->hitsTableNeedsRebuild()) {
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        $this->logsRepo->scheduleHitsTableRebuild();
    }
}
