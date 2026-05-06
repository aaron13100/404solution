<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side helpers for the staged getRedirectsForView pipeline.
 *
 * The build-side helpers in {@see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait}
 * populate `{wp_abj404_view_done}`. This sibling trait is responsible for
 * everything a request needs to fetch the page rows out of that table:
 *
 *   readFromViewDone() / buildViewDoneReadQuery() / buildViewDoneCountQuery():
 *     translate the public $tableOptions filter/order/paging into SQL that
 *     runs against precomputed view_done columns (no JOINs, no CASE
 *     recomputation; legacy filter-text composite LIKE preserved).
 *
 *   resolveStatusTypeList() / resolveOrderByColumn():
 *     bounded sanitisation of caller-supplied status filter and orderby
 *     column so the resulting SQL is safe to interpolate.
 *
 *   viewBuildOnlyTranslations():
 *     labels that get baked into status_for_view / type_for_view at build
 *     time so the read path can serve them without round-tripping through
 *     __(); kept here because the labels logically describe view rendering.
 *
 * Composed alongside the build trait into ABJ_404_Solution_DataAccess so the
 * cross-trait calls (queryAndGetResults, viewDoneTableName,
 * stagedQueryOptions) resolve through the shared $this.
 */
trait ABJ_404_Solution_DataAccess_ViewQueriesStagedReadTrait {

    /**
     * Read page from the served view_done table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    private function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->queryAndGetResults($query, $this->stagedQueryOptions());
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
    private function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
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
    private function viewBuildOnlyTranslations(): array {
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
