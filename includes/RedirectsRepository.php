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
            // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $this->dbCore->queryAndGetResults($query, array('query_params' => array($cleanedID)));

            abj_service('view_read_service')->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
    }

    /** @inheritDoc */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0, $engine = null, $score = null) {
        if (!is_numeric($type)) {
            $this->logger->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $this->logger->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
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
            $fromURL = $abj404logic->normalizeToRelativePath($fromURL);

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
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
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
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
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
            // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
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
    function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs = null, $endTs = null) {
        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html((string)$type) . ", Dest: " . esc_html($dest) . ", ID(s): " . esc_html((string)$idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return '';
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");

        $updateData = array(
            'url' => $fromURL,
            'status' => $statusType,
            'type' => absint($type),
            'final_dest' => $dest,
            'code' => esc_attr($redirectCode),
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
        // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
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

    // =========================================================================
    // Cron maintenance (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public function cleanupOrphanedAutoRedirects(): int {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableExists($redirectsTable)) {
            $this->logger->warn("Skipping orphaned redirect cleanup: table missing.");
            return 0;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getOrphanedAutoRedirects.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : [];
        $deletedCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0';
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $this->logger->debugMessage('Orphaned auto redirect deleted: "' . $url . '" (dest post ' .
                (isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '?') . ' missing/unpublished).');
            $this->deleteRedirect($id);
            $deletedCount++;
        }

        return $deletedCount;
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
        $logsRepo = abj_service('logs_repository');
        $deletedCount = 0;

        $rawDays = $options[$optionKey] ?? 0;
        $deletionDays = intval(is_scalar($rawDays) ? $rawDays : 0);
        if ($deletionDays <= 0) {
            return 0;
        }
        $deletionTime = $deletionDays * 86400;
        $then = $now - $deletionTime;

        $this->dbCore->setSqlBigSelects();

        if (!$logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipping: logs_hits table missing; scheduling rebuild.");
            $logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', (string)$then, $query);

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        foreach ($rows as $rowRaw) {
            if (!is_array($rowRaw)) {
                continue;
            }
            $row = $rowRaw;
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') .
                    '" deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            } else {
                $this->logger->debugMessage($debugMessageType . " from: " . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') . ' to: ' .
                    (is_string($row['best_guess_dest'] ?? '') ? $row['best_guess_dest'] : '') . ' deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            }

            $this->deleteRedirect(isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0');
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * @param int $daysToKeep
     * @param int $now
     * @return int
     */
    private function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        if ($daysToKeep <= 0) {
            return 0;
        }

        $cutoffTimestamp = max(0, $now - ($daysToKeep * 86400));
        $deletedTotal = 0;
        $batchSize = 2000;
        $maxBatches = 200;

        for ($i = 0; $i < $maxBatches; $i++) {
            $result = $this->dbCore->queryAndGetResults(
                "DELETE FROM {wp_abj404_logsv2} WHERE timestamp <= %d LIMIT %d",
                array(
                    'query_params' => array($cutoffTimestamp, $batchSize),
                    'log_errors' => true,
                )
            );
            $rowsDeletedRaw = $result['rows_affected'] ?? 0;
            $rowsDeleted = (is_int($rowsDeletedRaw) || is_float($rowsDeletedRaw) || is_string($rowsDeletedRaw))
                ? (int)$rowsDeletedRaw
                : 0;
            if ($rowsDeleted <= 0) {
                break;
            }
            $deletedTotal += $rowsDeleted;
            if ($rowsDeleted < $batchSize) {
                break;
            }
        }

        return $deletedTotal;
    }

    /** @inheritDoc */
    function deleteOldRedirectsCron() {
        $viewRead = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogRowsDeletedBySize = 0;
        $oldLogRowsDeletedByAge = 0;

        $manually_fired = abj_service('functions')->getPostOrGetSanitize('manually_fired', 'false');
        if ($this->f->strtolower($manually_fired) == 'true') {
            $manually_fired = true;
        } else {
            $manually_fired = false;
        }

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables(false);

        $this->dbCore->ensureConnection();

        $tempFile = $abj404logic->getExportFilename();
        if (file_exists($tempFile)) {
            ABJ_404_Solution_Functions::safeUnlink($tempFile);
        }

        $duplicateRowsDeleted = $this->removeDuplicatesCron();

        if (array_key_exists('capture_deletion', $options) && $options['capture_deletion'] != '0') {
            $status_list = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;
            $capturedURLsCount = $this->deleteOldRedirectsByType($options, $now, 'capture_deletion', $status_list, 'Captured 404');
            $captureDeletionDays = intval(is_scalar($options['capture_deletion']) ? $options['capture_deletion'] : 0);
            $oldLogRowsDeletedByAge = $this->deleteOldLogsByAge($captureDeletionDays, $now);
        }

        if (isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $status_list = (string)ABJ404_STATUS_AUTO;
            $autoRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'auto_deletion', $status_list, 'Automatic redirect');
        }

        if (isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $status_list = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;
            $manualRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'manual_deletion', $status_list, 'Manual redirect');
        }

        $orphanedCount = $this->cleanupOrphanedAutoRedirects();

        $junkTrashedCount = $this->autoTrashJunkCapturedUrls($options);

        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $maxLogSizeBytes = (array_key_exists('maximum_log_disk_usage', $options) ? $options['maximum_log_disk_usage'] : 100) * 1024 * 1000;

        if ($logsSizeBytes > $maxLogSizeBytes) {
            $totalLogLines = $viewRead->getLogsCount(0);
            $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
            $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
            $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
            if ($logLinesToDelete > 0) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/deleteOldLogs.sql");
                $query = $this->f->str_replace('{lines_to_delete}', (string)$logLinesToDelete, $query);
                $results = $this->dbCore->queryAndGetResults($query);
                $oldLogRowsDeletedBySizeRaw = $results['rows_affected'] ?? 0;
                $oldLogRowsDeletedBySize = (is_int($oldLogRowsDeletedBySizeRaw) || is_float($oldLogRowsDeletedBySizeRaw) || is_string($oldLogRowsDeletedBySizeRaw))
                    ? (int)$oldLogRowsDeletedBySizeRaw
                    : 0;
            }
        }

        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $logSizeMB = round($logsSizeBytes / (1024 * 1000), 2);

        $renamed = $this->limitDebugFileSize();
        $renamed = $renamed ? "true" : "false";

        $oldLogRowsDeleted = $oldLogRowsDeletedByAge + $oldLogRowsDeletedBySize;

        $message = "deleteOldRedirectsCron. Old captured URLs removed: " .
                $capturedURLsCount . ", Old automatic redirects removed: " . $autoRedirectsCount .
                ", Old manual redirects removed: " . $manualRedirectsCount .
                ", Orphaned auto redirects removed: " . $orphanedCount .
                ", Junk URLs auto-trashed: " . $junkTrashedCount .
                ", Old log lines removed: " . $oldLogRowsDeleted .
                " (age: " . $oldLogRowsDeletedByAge . ", size: " . $oldLogRowsDeletedBySize . ")" .
                ", New log size: " . $logSizeMB . "MB" .
                ", Duplicate rows deleted: " . $duplicateRowsDeleted . ", Debug file size limited: " .
                $renamed;

        $adminEmailVal = array_key_exists('admin_notification_email', $options) ? $options['admin_notification_email'] : '';
        if ($adminEmailVal !== null &&
                $this->f->strlen(trim(is_string($adminEmailVal) ? $adminEmailVal : '')) > 5) {

            if ($manually_fired) {
                $message .= ', The admin email notification option is skipped for user '
                        . 'initiated maintenance runs.';
            } else {
                $message .= ', ' . $abj404logic->emailCaptured404Notification();
            }
        } else {
            $message .= ', Admin email notification option turned off.';
        }

        if (isset($options['send_error_logs']) &&
                $options['send_error_logs'] == '1') {
            if ($this->logger->emailErrorLogIfNecessary()) {
                $message .= ", Log file emailed to developer.";
            } else {
                if ($this->logger->sendHeartbeatIfDueRandom(200)) {
                    $message .= ", Heartbeat log emailed to developer.";
                }
            }
        }

        $this->flagDeadDestinationRedirects();

        $abj404permalinkCache = abj_service('permalink_cache');
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;

        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;

        $this->logger->infoMessage($message);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables();

        $this->dbCore->queryAndGetResults("optimize table {wp_abj404_redirects}");

        $upgradesEtc->updatePluginCheck();

        return $message;
    }

    /** @inheritDoc */
    function limitDebugFileSize(): bool {
        $renamed = false;

        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }

        return $renamed;
    }

    /** @inheritDoc */
    function removeDuplicatesCron(): int {
        $rowsDeleted = 0;
        $query = "SELECT COUNT(id) as repetitions, url FROM {wp_abj404_redirects} GROUP BY url HAVING repetitions > 1 ";
        $result = $this->dbCore->queryAndGetResults($query);
        $outerRows = is_array($result['rows']) ? $result['rows'] : array();
        foreach ($outerRows as $outerRow) {
            if (!is_array($outerRow)) {
                continue;
            }
            $row = $outerRow;
            $url = $row['url'];

            $queryr1 = $this->prepare_query_wp(
                "select id from {wp_abj404_redirects} where url = {url} order by timestamp desc limit 0,1",
                array("url" => $url)
            );
            $result = $this->dbCore->queryAndGetResults($queryr1);
            $innerRows = is_array($result['rows']) ? $result['rows'] : array();
            if (count($innerRows) >= 1) {
                $row = is_array($innerRows[0]) ? $innerRows[0] : array();
                $original = isset($row['id']) ? $row['id'] : 0;

                $queryl = $this->prepare_query_wp(
                    "delete from {wp_abj404_redirects} where url = {url} and id != {original}", // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
                    array("url" => $url, "original" => $original)
                );
                $deleteResult = $this->dbCore->queryAndGetResults($queryl);
                $affected = isset($deleteResult['rows_affected']) && is_numeric($deleteResult['rows_affected'])
                    ? (int)$deleteResult['rows_affected'] : 1;
                $rowsDeleted += max($affected, 1);
            }
        }

        if ($rowsDeleted > 0) {
            abj_service('view_read_service')->invalidateStatusCountsCache();
        }

        return $rowsDeleted;
    }

    /** @inheritDoc */
    function autoTrashJunkCapturedUrls(array $options): int {
        $enabled = $options['auto_trash_junk_urls'] ?? '0';
        if ($enabled !== '1') {
            return 0;
        }

        $transientKey = 'abj404_last_auto_trash';
        if (get_transient($transientKey) !== false) {
            return 0;
        }
        set_transient($transientKey, time(), HOUR_IN_SECONDS);

        $patternsRaw = $options['auto_trash_junk_patterns'] ?? '';
        $patternsStr = is_string($patternsRaw) ? $patternsRaw : '';
        $lines = array_filter(array_map('trim', explode("\n", $patternsStr)));

        if (empty($lines)) {
            return 0;
        }

        global $wpdb;
        $totalTrashed = 0;

        $likeClauses = array();
        foreach ($lines as $pattern) {
            $escaped = $wpdb->esc_like($pattern);
            // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; result goes through queryAndGetResults
            $likeClauses[] = $wpdb->prepare("url LIKE %s", '%' . $escaped . '%');
        }

        $wherePatterns = implode(' OR ', $likeClauses);
        // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
        $query = "UPDATE {wp_abj404_redirects}
            SET disabled = 1
            WHERE status = " . ABJ404_STATUS_CAPTURED . "
            AND disabled = 0
            AND (" . $wherePatterns . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        $cutoff = time() - (14 * DAY_IN_SECONDS);
        // allow-no-watermark-bump: DAO layer; admin callers bump via markViewDoneInvalidatedByAdminMutation()
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; result goes through queryAndGetResults
        $query = $wpdb->prepare("UPDATE {wp_abj404_redirects} r
            SET r.disabled = 1
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . "
            AND r.disabled = 0
            AND r.timestamp < %d
            AND NOT EXISTS (
                SELECT 1 FROM {wp_abj404_logsv2} l
                WHERE l.requested_url = r.url
                LIMIT 1
            )",
            $cutoff
        );
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        if ($totalTrashed > 0) {
            $this->logger->infoMessage("Auto-trashed " . $totalTrashed . " junk/stale captured URLs during maintenance.");
            delete_transient(ABJ_404_Solution_DataAccess::CACHE_KEY_CAPTURED_STATUS);
        }

        return $totalTrashed;
    }

    // =========================================================================
    // Redirect maintenance (moved from DataAccessTrait_Maintenance, Phase 5)
    // =========================================================================

    /** @inheritDoc */
    public function flagDeadDestinationRedirects(): void {
        $cutoff = time() - 7 * 86400;
        $flaggedIds = array();

        $hitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $hitsTableExists = $this->dbCore->tableExists($hitsTable);

        if (!$hitsTableExists || !$this->logsHitsHasFailedHitsColumn()) {
            /** @var ABJ_404_Solution_LogsRepository|null $logsRepo */
            $logsRepo = abj_service('logs_repository');
            if ($logsRepo !== null) {
                $logsRepo->scheduleHitsTableRebuild();
            }
            $this->storeDeadDestIdsTransient($flaggedIds);
            return;
        }

        $sql = "SELECT DISTINCT r.id
             FROM {wp_abj404_redirects} r
             INNER JOIN {wp_abj404_logs_hits} h
                 ON BINARY h.requested_url = BINARY CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
             WHERE h.last_used > %d
               AND h.failed_hits > 0
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";
        $sql = $this->dbCore->doTableNameReplacements($sql);

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($cutoff),
            'timeout' => 30,
        ));

        if (empty($result['timed_out']) && (!isset($result['last_error']) || $result['last_error'] == '')) {
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $value = $row['id'] ?? reset($row);
                } elseif (is_object($row)) {
                    $value = $row->id ?? null;
                } else {
                    $value = $row;
                }
                if ($value !== null && $value !== '') {
                    $flaggedIds[] = (string)$value;
                }
            }
        }

        $this->storeDeadDestIdsTransient($flaggedIds);

        if (!empty($flaggedIds)) {
            $this->logger->infoMessage(
                __CLASS__ . '/' . __FUNCTION__ . ': Flagged ' . count($flaggedIds) .
                ' redirect(s) with dead destinations: ' . implode(', ', $flaggedIds)
            );
        }
    }

    /**
     * @param array<int, string> $flaggedIds
     * @return void
     */
    private function storeDeadDestIdsTransient(array $flaggedIds): void {
        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? 25 * (int) HOUR_IN_SECONDS : 90000;
            // allow-cache-empty: flaggedIds is a diagnostic list (dead-destination redirect IDs); an empty array is a valid "no dead destinations" result.
            set_transient('abj404_dead_dest_ids', $flaggedIds, $ttl);
        }
    }

    /**
     * @return bool
     */
    private function logsHitsHasFailedHitsColumn(): bool {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $sql = "SELECT 1 FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = %s "
            . "AND column_name = 'failed_hits' LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($tableName),
            'log_errors' => false,
        ));
        if (!empty($result['last_error'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /** @inheritDoc */
    public function expireOldAutoRedirects(): int {
        $options = abj_service('plugin_logic')->getOptions();
        $daysRaw = isset($options['auto_302_expiration_days']) ? $options['auto_302_expiration_days'] : 0;
        $days = is_numeric($daysRaw) ? (int)$daysRaw : 0;
        if ($days <= 0) {
            return 0;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableExists($redirectsTable)) {
            $this->logger->warn("expireOldAutoRedirects: redirects table missing, skipping.");
            return 0;
        }

        $cutoff = time() - ($days * 86400);

        $sql = "SELECT id FROM `{$redirectsTable}`
             WHERE status = %d
               AND disabled = 0
               AND `timestamp` > 0
               AND `timestamp` < %d";

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array(ABJ404_STATUS_AUTO, $cutoff),
        ));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $value = $row['id'] ?? reset($row);
            } elseif (is_object($row)) {
                $value = $row->id ?? null;
            } else {
                $value = $row;
            }
            if ($value !== null && $value !== '') {
                $ids[] = absint($value);
            }
        }

        if (empty($ids)) {
            return 0;
        }

        $moved = 0;
        foreach ($ids as $id) {
            $this->moveRedirectsToTrash($id, 1);
            $moved++;
        }

        $this->logger->infoMessage("expireOldAutoRedirects: moved {$moved} expired auto-redirect(s) to trash (threshold: {$days} days).");
        return $moved;
    }
}
