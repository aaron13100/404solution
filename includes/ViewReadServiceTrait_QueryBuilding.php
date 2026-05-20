<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL query construction methods for ViewReadService.
 *
 * Extracted from ViewReadService to keep the host class under the 1500-line
 * modularity limit. Contains the query builders for admin list views,
 * status type resolution, and order-by mapping.
 */
trait ABJ_404_Solution_ViewReadServiceTrait_QueryBuilding {

    /**
     * Build the SQL for getHighImpactCapturedCount(). Exposed so structural
     * regression tests can assert no logsv2 access and verify the EXPLAIN plan.
     *
     * @return string Fully-replaced SQL (table-name placeholders resolved).
     */
    function buildHighImpactCapturedCountQuery(): string {
        // logs_hits.requested_url is stored in canonical form (leading '/',
        // no trailing '/') by createRedirectsForViewHitsTable(). Match against
        // the persisted r.canonical_url column (added 4.1.10) so the JOIN is
        // an indexed equality lookup instead of CONCAT/TRIM per row. The
        // COALESCE fallback covers rows from upgraded sites where the chunked
        // backfill hasn't reached yet.
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * Execute the regex redirects query.
     * Separated from getRedirectsWithRegEx() for cache logic clarity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryRegexRedirects() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status in (" . ABJ404_STATUS_REGEX . ") \n " .
                "     and disabled = 0";
        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;

        $statusTypes = '';
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);
            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);
            }
        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        $trashValue = ($tableOptions['filter'] == ABJ404_TRASH_FILTER) ? 1 : 0;

        $scoreRangeClause = '';
        $rawScoreRange = is_string($tableOptions['score_range'] ?? '') ? ($tableOptions['score_range'] ?? 'all') : 'all';
        // Each `wp_abj404_redirects.*` reference below is the SQL alias bound by the
        // `FROM {wp_abj404_redirects} wp_abj404_redirects` clause in the assembled
        // query, not a hardcoded table-name literal. Per-line markers keep the
        // lint window (+/- 1 line) honest.
        switch ($rawScoreRange) {
            case 'high': $scoreRangeClause = 'AND wp_abj404_redirects.score >= 80'; break; // allow-prefix-literal: SQL alias, see comment above
            case 'medium': $scoreRangeClause = 'AND wp_abj404_redirects.score >= 50 AND wp_abj404_redirects.score < 80'; break; // allow-prefix-literal: SQL alias
            case 'low': $scoreRangeClause = 'AND wp_abj404_redirects.score IS NOT NULL AND wp_abj404_redirects.score < 50'; break; // allow-prefix-literal: SQL alias
            case 'manual': $scoreRangeClause = 'AND wp_abj404_redirects.score IS NULL'; break; // allow-prefix-literal: SQL alias
        }

        $query = "SELECT COUNT(*) AS count\n" .
                 "FROM {wp_abj404_redirects} wp_abj404_redirects\n" . // allow-prefix-literal: second token is the SQL alias name, not a table reference
                 "WHERE 1 and status IN (" . $statusTypes . ") AND disabled = " . intval($trashValue) . "\n" .
                 $scoreRangeClause;

        return $this->dbCore->doTableNameReplacements($query);
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
        global $abj404_redirect_types;
        global $abj404_captured_types;
        global $wpdb;

        $logsTableColumns = '';
        $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
        $logsTableJoin = '';
        $statusTypes = '';
        $trashValue = '';
        $selectCountReplacement = '/* selecting data as usual */';

        /* if we only want the count(*) then comment out everything else. */
        if ($selectCountOnly) {
        	$selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n" .
        		"count(*) as count\n /* only selecting for count";
        }

        if ($queryAllRowsAtOnce && !$selectCountOnly) {
            // create a temp table and use that instead of a subselect to avoid the sql error
            // "The SELECT would examine more than MAX_JOIN_SIZE rows"
            $this->maybeUpdateRedirectsForViewHitsTable();

            // Verify table was actually created before using it (handles silent creation failures)
            if ($this->logsRepo->logsHitsTableExists()) {
                // if we're showing all rows include all of the log data in the query already. this makes the query very slow.
                // this should be replaced by the dynamic loading of log data using ajax queries as the page is viewed.
                $logsTableColumns = "logstable.logshits as logshits, \n" .
                    "logstable.logsid, \n" .
                    "logstable.last_used, \n";

                // canonical_url is the persisted CONCAT('/', TRIM(BOTH '/' FROM url))
                // form (added 4.1.10) so this JOIN is a single indexed equality
                // lookup against logs_hits.requested_url instead of evaluating
                // the function on every redirects row. The COALESCE fallback
                // covers rows from upgraded sites where the chunked backfill
                // hasn't reached yet -- those rows merge in via the original
                // expression so behavior matches pre-upgrade exactly.
                // wp_abj404_redirects below is the SQL alias from the assembled FROM clause, not a hardcoded table name.
                $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " .
                        "  on binary logstable.requested_url = " .
                        "binary COALESCE(wp_abj404_redirects.canonical_url, " . // allow-prefix-literal: SQL alias
                        "concat('/', trim(both '/' from wp_abj404_redirects.url))) \n "; // allow-prefix-literal: SQL alias
            } else {
                // Fall back to null columns if table creation failed
                $this->logger->debugMessage("logs_hits table not available, falling back to null columns");
            }
        }

        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);

            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);

            } else {
                $this->logger->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }

        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));

        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Composite filter: Ignored + Later (Simple mode "Handled" tab)
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Show both active (disabled=0) and trashed (disabled=1) in Handled view
            $trashValue = 0;
        } else {
            $trashValue = 0;
        }

        /* only try to order by if we're actually selecting data and not only
         * counting the number of rows. */
        $orderByString = '';
        if (!$selectCountOnly) {
            $rawOrderBy = $tableOptions['orderby'] ?? '';
            $orderBy = $this->f->strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
            if ($orderBy == "final_dest") {
                // TODO change the final dest type to an integer and store external URLs somewhere else.
                $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
            } else {
                // only allow letters and the underscore in the orderby string.
                $orderBy = preg_replace('/[^a-zA-Z_]/', '', trim($orderBy));
            }
            $rawOrderVal = $tableOptions['order'] ?? '';
            $rawOrderValX = is_string($rawOrderVal) ? $rawOrderVal : '';
            $order = strtoupper((string)preg_replace('/[^a-zA-Z_]/', '', trim($rawOrderValX)));
            if ($order !== 'DESC') {
                $order = 'ASC';
            }
            $orderByString = "order by published_status asc, " . $orderBy . " " . $order .
                ", wp_abj404_redirects.url ASC, wp_abj404_redirects.id " . $order; // allow-prefix-literal: SQL alias bound by `FROM {wp_abj404_redirects} wp_abj404_redirects`
        }

        // Score range filter clause. wp_abj404_redirects below is the SQL alias from the assembled FROM clause, not a hardcoded table name.
        $rawScoreRange = is_string($tableOptions['score_range'] ?? '') ? ($tableOptions['score_range'] ?? 'all') : 'all';
        switch ($rawScoreRange) {
            case 'high':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 80'; // allow-prefix-literal: SQL alias
                break;
            case 'medium':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 50 AND wp_abj404_redirects.score < 80'; // allow-prefix-literal: SQL alias
                break;
            case 'low':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NOT NULL AND wp_abj404_redirects.score < 50'; // allow-prefix-literal: SQL alias
                break;
            case 'manual':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NULL'; // allow-prefix-literal: SQL alias
                break;
            default:
                $scoreRangeClause = '';
                break;
        }

        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        $filterText = '';
        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText != '') {
            if ($sub == 'abj404_redirects') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForRedirectsExists = ' filter text enabled */';

            } else if ($sub == 'abj404_captured') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForCapturedExists = ' filter text enabled */';

            } else {
                throw new Exception("Unrecognized page for filter text request."); // allow-raw-error: legacy assertion, pre-existing code moved from ViewReadService.php
            }
        }

        // Sanitize filter text for use inside LIKE; strip comment markers and escape for SQL LIKE.
        $filterTextRaw = str_replace(array('*', '/', '$'), '', $rawFilterText);
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            /** @var wpdb $wpdb */
            $filterTextRaw = $wpdb->esc_like($filterTextRaw);
        } else {
            $filterTextRaw = addcslashes($filterTextRaw, '_%\\');
        }
        $filterText = esc_sql($filterTextRaw);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
        // Ensure consistent collation for string operations (e.g., REPLACE/LOWER) to avoid
        // "Illegal mix of collations" errors when plugin tables use *_bin collations.
        $wpdbCollate = 'utf8mb4_unicode_ci';
        $hasForcedCollate = false;
        if (array_key_exists('forceCollate', $tableOptions) && !empty($tableOptions['forceCollate'])) {
            $rawForceCollateVal = $tableOptions['forceCollate'];
            $rawForceCollate = is_string($rawForceCollateVal) ? $rawForceCollateVal : '';
            $forced = preg_replace('/[^A-Za-z0-9_]/', '', $rawForceCollate);
            if ($forced !== '') {
                $wpdbCollate = $forced;
                $hasForcedCollate = true;
            }
        }
        if (!$hasForcedCollate && isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollate = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
        }
        if ($wpdbCollate === '') {
            $wpdbCollate = 'utf8mb4_unicode_ci';
        }
        $query = $this->f->str_replace('{selecting-for-count-true-false}', $selectCountReplacement, $query);
        $query = $this->f->str_replace('{statusTypes}', $statusTypes, $query);
        $query = $this->f->str_replace('{orderByString}', $orderByString, $query);
        $query = $this->f->str_replace('{limitStart}', (string)$limitStart, $query);
        $query = $this->f->str_replace('{limitEnd}', (string)$limitEnd, $query);
        $query = $this->f->str_replace('{searchFilterForRedirectsExists}', $searchFilterForRedirectsExists, $query);
        $query = $this->f->str_replace('{searchFilterForCapturedExists}', $searchFilterForCapturedExists, $query);
        $query = $this->f->str_replace('{filterText}', $filterText, $query);
        $query = $this->f->str_replace('{wpdb_collate}', $wpdbCollate, $query);
        $query = $this->f->str_replace('{logsTableColumns}', $logsTableColumns, $query);
        $query = $this->f->str_replace('{logsTableJoin}', $logsTableJoin, $query);
        $query = $this->f->str_replace('{trashValue}', (string)$trashValue, $query);
        $query = $this->f->str_replace('{scoreRangeClause}', $scoreRangeClause, $query);
        $query = $this->dbCore->doTableNameReplacements($query);

        if (array_key_exists('translations', $tableOptions) && is_array($tableOptions['translations'])) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_values($tableOptions['translations']);
            /** @var array<int, string> $keys */
            $query = $this->f->str_replace($keys, array_map('strval', $values), $query);
        }

        $query = $this->f->doNormalReplacements($query);

        return $query;
    }

    /**
     * Read page from the served view_done table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->dbCore->queryAndGetResults($query, $this->requireViewBuildOrchestrator()->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the WHERE/ORDER/LIMIT SELECT against view_done. Mirrors the
     * legacy filter-text composite LIKE so search semantics are preserved,
     * but reads against precomputed columns (no JOINs, no CASE
     * recomputation).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types, $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: every tab including HANDLED filters by
        // disabled = 0 (active rows) except the dedicated TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $orderBy = $this->resolveOrderByColumn($tableOptions);
        $rawOrderVal = $tableOptions['order'] ?? '';
        $rawOrderValStr = is_string($rawOrderVal) ? $rawOrderVal : '';
        $order = strtoupper((string)preg_replace('/[^a-zA-Z]/', '', trim($rawOrderValStr)));
        if ($order !== 'DESC') { $order = 'ASC'; }

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        $done = $this->viewDoneTableName();
        $query = "SELECT id, url, status, status_for_view, type, type_for_view,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY published_status ASC, " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
        return $query;
    }

    /**
     * COUNT(*) variant of buildViewDoneReadQuery. Same WHERE clauses, no
     * ORDER BY, no LIMIT.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        global $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: HANDLED filter shows active rows only
        // (disabled = 0), same as every non-TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $done = $this->viewDoneTableName();
        $query = "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
        return $query;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveStatusTypeList(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;
        $filter = $tableOptions['filter'] ?? 0;
        $statusTypes = '';
        if ($filter == 0 || $filter == ABJ404_TRASH_FILTER) {
            if ($sub === 'abj404_redirects') {
                $types = array();
                if (is_array($abj404_redirect_types)) {
                    foreach ($abj404_redirect_types as $t) {
                        $types[] = is_scalar($t) ? intval($t) : 0;
                    }
                }
                $statusTypes = implode(', ', $types);
            } else if ($sub === 'abj404_captured') {
                $types = array();
                if (is_array($abj404_captured_types)) {
                    foreach ($abj404_captured_types as $t) {
                        $types[] = is_scalar($t) ? intval($t) : 0;
                    }
                }
                $statusTypes = implode(', ', $types);
            }
        } else if ($filter == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($filter == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = is_scalar($filter) ? (string)$filter : '';
        }
        $cleaned = preg_replace('/[^\d, ]/', '', $statusTypes);
        return is_string($cleaned) ? $cleaned : '';
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            // Same as legacy: treat empty dest as last.
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'url';
        }
        return $orderBy;
    }

    /**
     * Translations for status_for_view, type_for_view, and the special
     * 404-displayed label. Everything else (`{wp_*}`, `{ABJ404_TYPE_X}`)
     * is handled by doTableNameReplacements + doNormalReplacements.
     *
     * @return array<string, string>
     */
    public function viewBuildOnlyTranslations(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Manual', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}'   => __('Automatic', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}'  => __('Regex', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}'      => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}'      => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}'     => __('Home', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(404 page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}'  => __('Special', '404-solution'),
        );
    }
}
