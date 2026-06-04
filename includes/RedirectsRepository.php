<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/RedirectsRetentionService.php';
require_once __DIR__ . '/RedirectConditionsRepository.php';
require_once __DIR__ . '/RedirectRegexCacheStore.php';
require_once __DIR__ . '/RedirectWriteService.php';

/**
 * Redirect lookup repository and compatibility facade.
 *
 * Extracted from the DataAccess monolith (Phase 2 of the DataAccess refactor).
 * Methods originate from two sources:
 *   - DataAccessTrait_Redirects (entirely absorbed)
 *   - DataAccessTrait_Stats (redirect update/query methods relocated)
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_RedirectsRepository implements ABJ_404_Solution_RedirectsRepositoryInterface {

    /** Maximum number of regex redirects to cache per-request (memory guard) */
    const REGEX_CACHE_MAX_COUNT = ABJ_404_Solution_RedirectRegexCacheStore::MAX_COUNT;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization|null DI seam for URL normalization. Resolved lazily through plugin_logic when null. */
    private $urlNormalization;

    /** @var ABJ_404_Solution_RedirectConditionsRepository */
    private $conditionsRepository;

    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCacheStore;

    /** @var ABJ_404_Solution_RedirectWriteService */
    private $writeService;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null,
        ?ABJ_404_Solution_RedirectConditionsRepository $conditionsRepository = null,
        ?ABJ_404_Solution_RedirectRegexCacheStore $regexCacheStore = null,
        ?ABJ_404_Solution_RedirectWriteService $writeService = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->urlNormalization = $urlNormalization;
        $this->conditionsRepository = $conditionsRepository !== null
            ? $conditionsRepository
            : new ABJ_404_Solution_RedirectConditionsRepository($dbCore, $this->logger);
        $this->regexCacheStore = $regexCacheStore !== null
            ? $regexCacheStore
            : new ABJ_404_Solution_RedirectRegexCacheStore();
        $this->writeService = $writeService !== null
            ? $writeService
            : new ABJ_404_Solution_RedirectWriteService(
                $dbCore,
                $this->f,
                $this->logger,
                $this->regexCacheStore,
                $urlNormalization
            );
    }

    /**
     * Resolve the URL-normalization helper, preferring the injected instance
     * so callers can construct a repo without a fully-initialized PluginLogic
     * singleton. Production wiring leaves this null and resolves through
     * plugin_logic on first use.
     *
     * @return ABJ_404_Solution_PluginLogicUrlNormalization
     */
    private function urlNormalization() {
        if ($this->urlNormalization !== null) {
            return $this->urlNormalization;
        }
        return abj_service('plugin_logic')->urlNormalization();
    }

    /** @return ABJ_404_Solution_RedirectsRetentionService */
    private function retentionService() {
        return new ABJ_404_Solution_RedirectsRetentionService($this->dbCore, $this, $this->f, $this->logger);
    }

    /** Legacy facade retained for integrations that still call RedirectsRepository directly. */
    function deleteOldRedirectsCron() {
        return $this->retentionService()->deleteOldRedirectsCron();
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @return int
     */
    private function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        return $this->retentionService()->deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType);
    }

    private function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        return $this->retentionService()->deleteOldLogsByAge($daysToKeep, $now);
    }

    // =========================================================================
    // Regex cache accessors (static state moved from DataAccess)
    // =========================================================================

    /** @inheritDoc */
    public function clearRegexRedirectsCache(): void {
        $this->regexCacheStore->clear();
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        return ABJ_404_Solution_RedirectRegexCacheStore::getRegexRedirectsCache();
    }

    /** @param array<int, array<string, mixed>>|null $cache @return void */
    public static function setRegexRedirectsCache($cache): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexRedirectsCache($cache);
    }

    /** @return bool */
    public static function isRegexCacheDisabled(): bool {
        return ABJ_404_Solution_RedirectRegexCacheStore::isRegexCacheDisabled();
    }

    /** @param bool $disabled @return void */
    public static function setRegexCacheDisabled(bool $disabled): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexCacheDisabled($disabled);
    }

    // =========================================================================
    // Static utilities (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public static function computeRedirectsCanonicalUrl($url): string {
        if (!is_string($url)) {
            return '/';
        }
        $trimmed = trim($url, '/');
        if ($trimmed === '') {
            return '/';
        }
        return '/' . $trimmed;
    }

    /** @inheritDoc */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string {
        return "CONCAT('/', TRIM(BOTH '/' FROM " . $columnExpr . "))";
    }

    // =========================================================================
    // Query preparation helpers (from DataAccessTrait_ViewQueries, shared utility)
    // =========================================================================

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function prepare_query($query, $data) {
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

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
     */
    private function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; callers execute the result through queryAndGetResults
        return $wpdb->prepare($prepared_query, $ordered_values);
    }

    // =========================================================================
    // Redirect CRUD (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    function deleteRedirect($id) {
        $this->writeService->deleteRedirect($id);
    }

    /** @inheritDoc */
    function setupRedirect(ABJ_404_Solution_RedirectSpec $spec) {
        return $this->writeService->setupRedirect($spec);
    }

    /** @inheritDoc */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        $url = $this->f->sanitizeInvalidUTF8($url);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        $candidates = $this->urlNormalization()->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getActiveRedirectForNormalizedUrl($candidate, $degradedMode);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    /** @inheritDoc */
    function getExistingRedirectForURL($url) {
        $url = $this->f->sanitizeInvalidUTF8($url);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        $candidates = $this->urlNormalization()->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getExistingRedirectForNormalizedUrl($candidate);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    private function getActiveRedirectForNormalizedUrl($url, $degradedMode = false) {
        $redirect = array();

        $url1 = $url;
        $url2 = $url;
        if (substr($url, -1) === '/') {
            $url2 = rtrim($url, '/');
        } else {
            $url2 = $url2 . '/';
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");

        if ($degradedMode && $this->redirectsTableMissingScheduledColumns()) {
            $query = $this->stripScheduledRedirectPredicates($query);
        }

        $query = $this->prepare_query_wp($query, array("url1" => $url1, "url2" => $url2));
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }

    /**
     * @return bool
     */
    private function redirectsTableMissingScheduledColumns(): bool {
        $cacheKey = 'abj404_redirects_scheduled_cols_status';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if ($cached === 'missing') { return true; }
            if ($cached === 'present') { return false; }
        }

        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $columns = $this->getRedirectsTableColumns($tableName);

        if (empty($columns)) {
            return false;
        }

        $colsLower = array_map('strtolower', $columns);
        $missing = !in_array('start_ts', $colsLower, true)
                || !in_array('end_ts', $colsLower, true);

        if (function_exists('set_transient')) {
            $hour = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
            // allow-cache-empty: value is always 'missing' or 'present' (non-empty string literal)
            set_transient(
                $cacheKey,
                $missing ? 'missing' : 'present',
                $missing ? 5 * 60 : 24 * $hour
            );
        }

        return $missing;
    }

    /**
     * @param string $tableName
     * @return array<int, string>
     */
    private function getRedirectsTableColumns(string $tableName): array {
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }
        // @utf8-audit: opt-out — getRedirectsTableColumns receives system-generated redirects table names only.
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM `" . esc_sql($tableName) . "`",
            array('log_errors' => false)
        );
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            return [];
        }
        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }
        return $columns;
    }

    /**
     * @param string $sql
     * @return string
     */
    private function stripScheduledRedirectPredicates(string $sql): string {
        $stripped = preg_replace(
            '/^[^\n]*\br\.(?:start_ts|end_ts)\b[^\n]*\R?/m',
            '',
            $sql
        );
        return is_string($stripped) ? $stripped : $sql;
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    private function getExistingRedirectForNormalizedUrl($url) {
        $redirect = array();

        $query = $this->prepare_query_wp('select * from {wp_abj404_redirects} where BINARY url = BINARY {url} ' .
            " and disabled = 0 ", array("url" => $url));
        $results = $this->dbCore->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }

    /** @inheritDoc */
    function deleteSpecifiedRedirects(array $types, string $purgeType): array {
        return $this->writeService->deleteSpecifiedRedirects($types, $purgeType);
    }

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public function getRedirectConditions(int $redirectId): array {
        return $this->conditionsRepository->getRedirectConditions($redirectId);
    }

    /** @inheritDoc */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $this->conditionsRepository->saveRedirectConditions($redirectId, $conditions);
    }

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /** @inheritDoc */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        return $this->writeService->updateRedirect($update);
    }

    /** @inheritDoc */
    function getRedirectsByIDs($ids) {
        return $this->writeService->getRedirectsByIDs($ids);
    }

    /** @inheritDoc */
    function updateRedirectTypeStatus($id, $newstatus) {
        return $this->writeService->updateRedirectTypeStatus($id, $newstatus);
    }

    /** @inheritDoc */
    function moveRedirectsToTrash($id, $trash) {
        return $this->writeService->moveRedirectsToTrash($id, $trash);
    }

}
