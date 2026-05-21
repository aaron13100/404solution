<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL query construction and execution for admin list views.
 *
 * Builds the SQL for redirect/captured list queries, view_done reads,
 * status type resolution, order-by mapping, and translation maps.
 */
class ABJ_404_Solution_ViewQueryBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewReadServiceInterface|null */
    private $host;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logsRepo = $logsRepo;
        $this->logger = $logger;
    }

    /**
     * @param ABJ_404_Solution_ViewReadServiceInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewReadServiceInterface $host): void {
        $this->host = $host;
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewQueryBuilder requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /**
     * @return string Fully-replaced SQL (table-name placeholders resolved).
     */
    public function buildHighImpactCapturedCountQuery(): string {
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
     * @return array<int, array<string, mixed>>
     */
    public function queryRegexRedirects() {
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
    public function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
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
    public function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
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

        if ($selectCountOnly) {
        	$selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n" .
        		"count(*) as count\n /* only selecting for count";
        }

        if ($queryAllRowsAtOnce && !$selectCountOnly) {
            if ($this->host !== null) {
                $this->host->maybeUpdateRedirectsForViewHitsTable();
            }

            if ($this->logsRepo->logsHitsTableExists()) {
                $logsTableColumns = "logstable.logshits as logshits, \n" .
                    "logstable.logsid, \n" .
                    "logstable.last_used, \n";

                $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " .
                        "  on binary logstable.requested_url = " .
                        "binary COALESCE(wp_abj404_redirects.canonical_url, " . // allow-prefix-literal: SQL alias
                        "concat('/', trim(both '/' from wp_abj404_redirects.url))) \n "; // allow-prefix-literal: SQL alias
            } else {
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
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            $trashValue = 0;
        } else {
            $trashValue = 0;
        }

        $orderByString = '';
        if (!$selectCountOnly) {
            $rawOrderBy = $tableOptions['orderby'] ?? '';
            $orderBy = $this->f->strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
            if ($orderBy == "final_dest") {
                // TODO change the final dest type to an integer and store external URLs somewhere else.
                $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
            } else {
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
                $searchFilterForRedirectsExists = ' filter text enabled */';

            } else if ($sub == 'abj404_captured') {
                $searchFilterForCapturedExists = ' filter text enabled */';

            } else {
                throw new Exception("Unrecognized page for filter text request."); // allow-raw-error: legacy assertion, pre-existing code moved from ViewReadService.php
            }
        }

        $filterTextRaw = str_replace(array('*', '/', '$'), '', $rawFilterText);
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            /** @var wpdb $wpdb */
            $filterTextRaw = $wpdb->esc_like($filterTextRaw);
        } else {
            $filterTextRaw = addcslashes($filterTextRaw, '_%\\');
        }
        $filterText = esc_sql($filterTextRaw);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
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
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types, $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
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
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        global $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
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
    public function resolveStatusTypeList(string $sub, array $tableOptions): string {
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
    public function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'url';
        }
        return $orderBy;
    }

    /**
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
