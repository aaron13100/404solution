<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRepositoryInterface.php';

/**
 * Redirect CRUD, conditions, regex matching, cleanup, and cron maintenance.
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
    const REGEX_CACHE_MAX_COUNT = 50;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var array<int, array<string, mixed>>|null Per-request cache for regex redirects */
    private static $regexRedirectsCache = null;

    /** @var bool Flag indicating if regex cache should be skipped (too many redirects) */
    private static $regexCacheDisabled = false;

    /**
     * Per-instance memoized cache of column-existence probes against the
     * redirects table.
     *
     * @var array<string, bool>
     */
    private $redirectsTableColumnsCache = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    // =========================================================================
    // Regex cache accessors (static state moved from DataAccess)
    // =========================================================================

    /** @inheritDoc */
    public function clearRegexRedirectsCache(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        return self::$regexRedirectsCache;
    }

    /** @param array<int, array<string, mixed>>|null $cache @return void */
    public static function setRegexRedirectsCache($cache): void {
        self::$regexRedirectsCache = $cache;
    }

    /** @return bool */
    public static function isRegexCacheDisabled(): bool {
        return self::$regexCacheDisabled;
    }

    /** @param bool $disabled @return void */
    public static function setRegexCacheDisabled(bool $disabled): void {
        self::$regexCacheDisabled = $disabled;
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
        $cleanedID = absint(sanitize_text_field((string)$id));

        if (is_numeric($id)) {
            // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $this->dbCore->queryAndGetResults($query, array('query_params' => array($cleanedID)));

            abj_service('view_read_service')->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
    }

    /** @inheritDoc */
    function setupRedirect(ABJ_404_Solution_RedirectSpec $spec) {
        $fromURL = $spec->getFromURL();
        $status = $spec->getStatus();
        $type = $spec->getType();
        $final_dest = $spec->getFinalDest();
        $code = $spec->getCode();
        $disabled = $spec->getDisabled();
        $engine = $spec->getEngine();
        $score = $spec->getScore();

        if (!is_numeric($type)) {
            $this->logger->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html((string)$type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $this->logger->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html((string)$type) . ", Status: " . $status);
        }

        $statusAsInt = is_numeric($status) ? absint($status) : -1;
        $typeAsInt = is_numeric($type) ? absint($type) : -1;

        if ($statusAsInt === ABJ404_STATUS_AUTO &&
                !$this->isValidAutomaticRedirectDestination($typeAsInt, $final_dest)) {
            $this->logger->debugMessage("Skipping automatic redirect with invalid destination. " .
                    "From: " . esc_url($fromURL) . ", Dest: " . esc_html((string)$final_dest) .
                    ", Type: " . esc_html((string)$type) . ", Status: " . esc_html((string)$status));
            return 0;
        }

        $insertId = 0;

        if (!abj_service('request_context')->ignore_doprocess) {
            $now = time();
            $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");

            $abj404logic = abj_service('plugin_logic');
            $fromURL = $abj404logic->urlNormalization()->normalizeToRelativePath($fromURL);

            $insertData = array(
                'url' => $fromURL,
                'status' => $status,
                'type' => $type,
                'final_dest' => $final_dest,
                'code' => $code,
                'disabled' => $disabled,
                'timestamp' => $now,
            );
            $insertFormats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');

            if ($this->redirectsTableHasColumn('canonical_url')) {
                $insertData['canonical_url'] = self::computeRedirectsCanonicalUrl($fromURL);
                $insertFormats[] = '%s';
            }
            if ($engine !== null) {
                $insertData['engine'] = substr((string)$engine, 0, 64);
                $insertFormats[] = '%s';
            }
            if ($score !== null) {
                $insertData['score'] = round((float)$score, 2);
                $insertFormats[] = '%f';
            }

            $insertSql = "INSERT INTO `" . $redirectsTable . "` (`" .
                implode('`, `', array_keys($insertData)) . "`) VALUES (" .
                implode(', ', $insertFormats) . ")";
            $insertResult = $this->dbCore->queryAndGetResults($insertSql, array(
                'query_params' => array_values($insertData),
            ));
            $insertIdRaw = $insertResult['insert_id'] ?? 0;
            $insertId = is_scalar($insertIdRaw) ? (int)$insertIdRaw : 0;

            abj_service('view_read_service')->invalidateStatusCountsCache();
            if ($status == ABJ404_STATUS_REGEX) {
                $this->clearRegexRedirectsCache();
            }
        }

        return $insertId;
    }

    /**
     * @param int $type
     * @param mixed $finalDest
     * @return bool
     */
    private function isValidAutomaticRedirectDestination($type, $finalDest) {
        $destId = absint(is_scalar($finalDest) ? $finalDest : 0);

        if ($type === ABJ404_TYPE_POST) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_post')) {
                return true;
            }
            $ref = ABJ_404_Solution_PostRef::fromWpPost(get_post($destId));
            if ($ref === null) {
                return false;
            }
            return $ref->isPublished();
        }

        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_term')) {
                return true;
            }
            $taxonomy = ($type === ABJ404_TYPE_CAT) ? 'category' : 'post_tag';
            $term = get_term($destId, $taxonomy);
            if ($term === null || is_wp_error($term)) {
                return false;
            }
            return is_object($term);
        }

        if ($type === ABJ404_TYPE_HOME) {
            return true;
        }

        return false;
    }

    /** @inheritDoc */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        $url = $this->f->sanitizeInvalidUTF8($url);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        $abj404logic = abj_service('plugin_logic');
        $candidates = $abj404logic->urlNormalization()->getNormalizedUrlCandidates($url);
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

        $abj404logic = abj_service('plugin_logic');
        $candidates = $abj404logic->urlNormalization()->getNormalizedUrlCandidates($url);
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

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");

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

    /**
     * @param string $columnName
     * @return bool
     */
    private function redirectsTableHasColumn(string $columnName): bool {
        $key = strtolower($columnName);
        if ($this->redirectsTableColumnsCache !== array()) {
            return isset($this->redirectsTableColumnsCache[$key]);
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return true;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        // @utf8-audit: opt-out — redirectsTableHasColumn probes an internally resolved plugin table name.
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM `" . esc_sql($redirectsTable) . "`",
            array('log_errors' => false, 'log_too_slow' => false)
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if ($rows === array()) {
            return true;
        }
        $primed = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            foreach ($row as $field => $value) {
                if (strtolower((string)$field) !== 'field') { continue; }
                $primed[strtolower((string)$value)] = true;
            }
        }
        if ($primed === array()) {
            return true;
        }
        $this->redirectsTableColumnsCache = $primed;
        return isset($this->redirectsTableColumnsCache[$key]);
    }

    /** @inheritDoc */
    function deleteSpecifiedRedirects() {
        $message = "";

        if (!array_key_exists('sanity_purge', $_POST) || $_POST['sanity_purge'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }

        if (!isset($_POST['types']) || $_POST['types'] == '') {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            return $message;
        }

        if (is_array($_POST['types'])) {
            $type = array_map('sanitize_text_field', $_POST['types']);
        } else {
            $type = sanitize_text_field($_POST['types']);
        }

        if (!is_array($type)) {
            $message = __('An unknown error has occurred.', '404-solution');
            return $message;
        }

        $redirectTypes = array();
        foreach ($type as $aType) {
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_404_DISPLAYED)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post((string)json_encode($redirectTypes)));
            return $message;
        }
        $purge = isset($_POST['purgetype']) ? sanitize_text_field($_POST['purgetype']) : '';

        if ($purge != 'abj404_logs' && $purge != 'abj404_redirects') {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post((string)json_encode($purge)));
            return $message;
        }

        array_push($redirectTypes, 0);

        $redirectTypes = array_map('absint', $redirectTypes);
        $typesForSQL = implode(',', $redirectTypes);

        if ($purge == 'abj404_redirects') {
            // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
            $query = "update {wp_abj404_redirects} set disabled = 1 where status in (" . $typesForSQL . ")";
            $purgeResult = $this->dbCore->queryAndGetResults($query);
            $rowsAffectedRaw = $purgeResult['rows_affected'] ?? 0;
            $redirectCount = is_scalar($rowsAffectedRaw) ? (int)$rowsAffectedRaw : 0;

            abj_service('view_read_service')->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();

            $message .= sprintf( _n( '%s redirect entry was moved to the trash.',
                    '%s redirect entries were moved to the trash.', $redirectCount, '404-solution'), $redirectCount);
        }

        return $message;
    }

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public function getRedirectConditions(int $redirectId): array {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        if (!$this->dbCore->tableExists($table)) {
            return [];
        }

        $result = $this->dbCore->queryAndGetResults(
            "SELECT id, redirect_id, logic, condition_type, operator, value, sort_order
             FROM `{$table}`
             WHERE redirect_id = %d
             ORDER BY sort_order ASC, id ASC",
            array('query_params' => array($redirectId), 'log_errors' => false)
        );

        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("getRedirectConditions: DB error for redirect_id={$redirectId}: " . $lastError);
            return [];
        }

        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        return $rows;
    }

    /** @inheritDoc */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        if (!$this->dbCore->tableExists($table)) {
            $this->logger->warn("saveRedirectConditions: conditions table missing, skipping save for redirect_id={$redirectId}.");
            return;
        }

        $deleteResult = $this->dbCore->queryAndGetResults(
            "DELETE FROM `{$table}` WHERE redirect_id = %d",
            array('query_params' => array($redirectId), 'log_errors' => false)
        );
        $deleteError = isset($deleteResult['last_error']) && is_string($deleteResult['last_error']) ? $deleteResult['last_error'] : '';
        if ($deleteError !== '') {
            $this->logger->warn("saveRedirectConditions: error deleting old conditions for redirect_id={$redirectId}: " . $deleteError);
        }

        if (empty($conditions)) {
            return;
        }

        $allowedTypes = [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
        $allowedOperators = [
            'equals', 'contains', 'regex',
            'not_equals', 'not_contains', 'cidr',
        ];
        $allowedLogic = ['AND', 'OR'];

        foreach ($conditions as $index => $cond) {
            if (!is_array($cond)) {
                continue;
            }

            $logic    = isset($cond['logic']) && is_string($cond['logic'])
                ? strtoupper(trim($cond['logic'])) : 'AND';
            $type     = isset($cond['condition_type']) && is_string($cond['condition_type'])
                ? trim($cond['condition_type']) : '';
            $operator = isset($cond['operator']) && is_string($cond['operator'])
                ? trim($cond['operator']) : 'equals';
            $value    = isset($cond['value']) && is_string($cond['value'])
                ? trim($cond['value']) : '';
            $sortOrder = isset($cond['sort_order']) ? absint(is_scalar($cond['sort_order']) ? $cond['sort_order'] : 0) : $index;

            if (!in_array($logic, $allowedLogic, true)) {
                $logic = 'AND';
            }
            if (!in_array($type, $allowedTypes, true)) {
                $this->logger->warn("saveRedirectConditions: unknown condition_type '{$type}', skipping.");
                continue;
            }
            if (!in_array($operator, $allowedOperators, true)) {
                $operator = 'equals';
            }
            if (strlen($value) > 1024) {
                $value = substr($value, 0, 1024);
            }

            $insertResult = $this->dbCore->queryAndGetResults(
                "INSERT INTO `{$table}` (`redirect_id`, `logic`, `condition_type`, `operator`, `value`, `sort_order`)
                 VALUES (%d, %s, %s, %s, %s, %d)",
                array(
                    'query_params' => array($redirectId, $logic, $type, $operator, $value, $sortOrder),
                    'log_errors' => false,
                )
            );
            $insertError = isset($insertResult['last_error']) && is_string($insertResult['last_error']) ? $insertResult['last_error'] : '';
            if ($insertError !== '') {
                $this->logger->warn("saveRedirectConditions: error inserting condition #{$index} for redirect_id={$redirectId}: " . $insertError);
            }
        }
    }

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /** @inheritDoc */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        $type = $update->getType();
        $idForUpdate = $update->getId();
        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html((string)$type) . ", Dest: " . esc_html($update->getDestination()) .
                ", ID(s): " . esc_html((string)$idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return '';
        }

        $startTs = $update->getStartTs();
        $endTs = $update->getEndTs();

        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");

        $updateData = array(
            'url' => $update->getFromUrl(),
            'status' => $update->getStatusType(),
            'type' => absint($type),
            'final_dest' => $update->getDestination(),
            'code' => esc_attr($update->getCode()),
        );
        $updateFormats = array('%s', '%d', '%d', '%s', '%d');

        if ($startTs !== null) {
            $updateData['start_ts'] = (int)$startTs;
            $updateFormats[] = '%d';
        }
        if ($endTs !== null) {
            $updateData['end_ts'] = (int)$endTs;
            $updateFormats[] = '%d';
        }

        $setFragments = array();
        $idx = 0;
        foreach ($updateData as $col => $unusedValue) {
            $format = isset($updateFormats[$idx]) ? $updateFormats[$idx] : '%s';
            $setFragments[] = '`' . $col . '` = ' . $format;
            $idx++;
        }
        $updateSql = "UPDATE `" . $redirectsTable . "` SET " . implode(', ', $setFragments) .
            " WHERE `id` = %d";
        $updateParams = array_values($updateData);
        $updateParams[] = absint($idForUpdate);
        $this->dbCore->queryAndGetResults($updateSql, array('query_params' => $updateParams));

        $nullParts = [];
        if ($startTs === null) {
            $nullParts[] = '`start_ts` = NULL';
        }
        if ($endTs === null) {
            $nullParts[] = '`end_ts` = NULL';
        }
        if (!empty($nullParts)) {
            $nullSql = "UPDATE `" . $redirectsTable . "` SET " . implode(', ', $nullParts) .
                " WHERE id = %d";
            $this->dbCore->queryAndGetResults($nullSql, array('query_params' => array(absint($idForUpdate))));
        }

        abj_service('view_read_service')->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        $this->moveRedirectsToTrash(absint($idForUpdate), 0);

        return '';
    }

    /** @inheritDoc */
    function getRedirectsByIDs($ids) {
        if (!is_array($ids) || empty($ids)) {
            return array();
        }
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);

        $query = "select id, url, type, status, final_dest, code, COALESCE(engine, '') as engine, start_ts, end_ts from {wp_abj404_redirects} " .
                "where id in (" . $multipleIds . ")";
        $result = $this->dbCore->queryAndGetResults($query);
        $rawRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();

        $rows = array();
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @inheritDoc */
    function updateRedirectTypeStatus($id, $newstatus) {
        // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
        $query = "update {wp_abj404_redirects} set status = %s where id = %d";
        $result = $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array($newstatus, absint($id))
        ));

        abj_service('view_read_service')->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        return is_string($result['last_error']) ? $result['last_error'] : '';
    }

    /** @inheritDoc */
    function moveRedirectsToTrash($id, $trash) {
        $message = "";
        $hadError = false;
        if ($this->f->regexMatch('[0-9]+', '' . $id)) {

            $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
            $updateResult = $this->dbCore->queryAndGetResults(
                "UPDATE `" . $redirectsTable . "` SET disabled = %d WHERE id = %d",
                array('query_params' => array(absint(esc_html((string)$trash)), absint($id)))
            );
            $updateError = isset($updateResult['last_error']) && is_string($updateResult['last_error']) ? $updateResult['last_error'] : '';
            $hadError = $updateError !== '';

            abj_service('view_read_service')->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        } else {
            $hadError = true;
        }
        if ($hadError) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

}
