<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performs normalized redirect row lookups against the redirects table.
 */
class ABJ_404_Solution_RedirectLookupRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $functions) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    public function getActiveRedirectForNormalizedUrl($url, $degradedMode = false): array {
        $url1 = $url;
        $url2 = $url;
        if (substr($url, -1) === '/') {
            $url2 = rtrim($url, '/');
        } else {
            $url2 = $url2 . '/';
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPermalinkFromURL.sql");

        if ($degradedMode && $this->redirectsTableMissingScheduledColumns()) {
            $query = $this->stripScheduledRedirectPredicates($query);
        }

        $query = $this->prepare_query_wp($query, array("url1" => $url1, "url2" => $url2));
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);

        return $this->firstRedirectRow($results);
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForNormalizedUrl($url): array {
        // Index-assisted exact match: the plain `url = {url}` predicate is
        // sargable on the `url`(190) prefix index and narrows to the (typically
        // one) row sharing this URL ignoring collation; the `BINARY url = BINARY
        // {url}` refinement then enforces byte-exact equality. BINARY is the
        // strictest possible comparison, so its match set is always a subset of
        // the column-collation equality -- the result is identical to the bare
        // BINARY predicate, but the optimizer can use the index instead of
        // full-scanning the redirects table on every new-URL capture (a hot path
        // under scanner-flood 404 traffic).
        // allow-unbounded-select: single-URL equality lookup on a hot capture path; returns the few rows matching one URL
        $query = $this->prepare_query_wp('select * from {wp_abj404_redirects} where url = {url} and BINARY url = BINARY {url} ' .
            " and disabled = 0 ", array("url" => $url));
        $results = $this->dbCore->queryAndGetResults($query);

        return $this->firstRedirectRow($results);
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    private function firstRedirectRow(array $results): array {
        $redirect = array();
        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : array();

        if (empty($rows) || !is_array($rows[0])) {
            return array('id' => 0);
        }

        foreach ($rows[0] as $key => $value) {
            $redirect[$key] = $value;
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
        // @utf8-audit: opt-out - getRedirectsTableColumns receives system-generated redirects table names only.
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
            if (!is_array($row)) {
                continue;
            }
            // Case-insensitive: MySQL drivers return SHOW COLUMNS metadata
            // key casing inconsistently (Field vs field).
            $row = array_change_key_case($row, CASE_LOWER);
            if (isset($row['field']) && is_string($row['field'])) {
                $columns[] = $row['field'];
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
}
