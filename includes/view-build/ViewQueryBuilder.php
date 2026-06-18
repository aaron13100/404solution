<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin view query construction and staged view_done reads.
 *
 * Builds the SQL for the admin redirect/captured lists (high-impact captured
 * count, regex redirects, optimized count) and owns the read path against the
 * staged view_done admin table: it constructs the read/count SQL and executes
 * the read through the staged-query options supplied by the view-build
 * orchestrator. The view_done read/count SQL used to live in two single-consumer
 * leaf classes (ViewDoneReader, ViewDoneQueryBuilder); they were folded in here
 * to remove a three-file single-consumer chain.
 */
class ABJ_404_Solution_ViewQueryBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
        $this->policy = new ABJ_404_Solution_ViewQueryPolicy();
    }

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string {
        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON h.requested_url =
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
               AND BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * @param int|null $limit
     * @return array<int, array<string, mixed>>
     */
    public function queryRegexRedirects(?int $limit = null) {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
            . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
            . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n "
            . "from {wp_abj404_redirects}\n "
            . "  LEFT OUTER JOIN {wp_posts} \n "
            . "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n "
            . "where status in (" . ABJ404_STATUS_REGEX . ") \n "
            . "     and disabled = 0\n"
            . "order by {wp_abj404_redirects}.id ASC";
        if ($limit !== null) {
            $query .= "\nlimit " . max(1, intval($limit));
        }

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows'] ?? null) ? $results['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = $this->policy->resolveTrashValue($tableOptions);
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, 'wp_abj404_redirects.'); // allow-prefix-literal: SQL alias bound by FROM clause

        $query = "SELECT COUNT(*) AS count\n"
            . "FROM {wp_abj404_redirects} wp_abj404_redirects\n" // allow-prefix-literal: second token is the SQL alias name, not a table reference
            . "WHERE 1 and status IN (" . $statusTypes . ") AND disabled = " . intval($trashValue) . "\n"
            . $scoreRangeClause;

        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * Execute the single-table redirects read for one page (Denorm Step 3b).
     *
     * Serves rows straight off wp_abj404_redirects, where every sortable column
     * (url, status, type, code, timestamp, score, logshits, last_used,
     * dest_for_view) is a real column -> a single-table filesort over at most one
     * page of rows, no temp-table materialize, no join. The four derived columns
     * are refreshed live per visible row by RedirectsViewLiveResolver after this
     * read; this method only fetches the ordered/filtered page.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent Whether the four denorm columns exist on the
     *   redirects table (schema-drift tolerance: false selects base columns only
     *   and the live resolver fills the derived values).
     * @return array<int, array<string, mixed>>
     */
    public function readRedirectsSingleTable(string $sub, array $tableOptions, bool $derivedPresent = true): array {
        $query = $this->buildRedirectsSingleTableReadQuery($sub, $tableOptions, $derivedPresent);
        $result = $this->dbCore->queryAndGetResults($query, $this->resolveReadTimeoutOptions($tableOptions));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the queryAndGetResults options for the single-table read/count from
     * the optional per-request timeout, with a level-9-safe numeric guard.
     *
     * @param array<string, mixed> $tableOptions
     * @return array<string, int>
     */
    private function resolveReadTimeoutOptions(array $tableOptions): array {
        $raw = $tableOptions['_abj404_query_timeout'] ?? null;
        if (!is_numeric($raw)) {
            return array();
        }
        $timeout = (int)$raw;
        return $timeout > 0 ? array('timeout' => $timeout) : array();
    }

    /**
     * Execute the single-table filtered count against wp_abj404_redirects.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return int
     */
    public function countRedirectsSingleTable(string $sub, array $tableOptions, bool $derivedPresent = true): int {
        $query = $this->buildRedirectsSingleTableCountQuery($sub, $tableOptions, $derivedPresent);
        $result = $this->dbCore->queryAndGetResults($query, $this->resolveReadTimeoutOptions($tableOptions));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $raw = $rows[0]['cnt'] ?? reset($rows[0]);
        return is_scalar($raw) ? intval($raw) : 0;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return string
     */
    public function buildRedirectsSingleTableReadQuery(string $sub, array $tableOptions, bool $derivedPresent = true): string {
        $orderBy = $this->resolveSingleTableOrderByColumn($tableOptions, $derivedPresent);
        $order = $this->policy->resolveOrderDirection($tableOptions);

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        // The derived columns are projected only when they exist, purely so the
        // live resolver can dedupe its write-back against the stored values; the
        // rendered values come from the live resolution either way. On a
        // schema-drifted table without them, the base-column projection still
        // renders a complete page.
        $derivedProjection = $derivedPresent
            ? ",\n       dest_for_view, published_status, logshits, last_used" : "";

        return "SELECT id, url, status, type, final_dest, code, timestamp, engine, score"
            . $derivedProjection . "\n"
            . "FROM {wp_abj404_redirects}\n"
            . $this->buildSingleTableWhere($sub, $tableOptions, $derivedPresent)
            . "ORDER BY " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return string
     */
    public function buildRedirectsSingleTableCountQuery(string $sub, array $tableOptions, bool $derivedPresent = true): string {
        return "SELECT COUNT(*) AS cnt\n"
            . "FROM {wp_abj404_redirects}\n"
            . $this->buildSingleTableWhere($sub, $tableOptions, $derivedPresent);
    }

    /**
     * The shared WHERE body for the single-table read and count: status filter,
     * trash (disabled) filter, score range, and the dest_for_view-aware
     * filterText search. Identical between read and count so a filtered count
     * always equals the unpaginated row set (i457 invariant). The filterText
     * destination-title match drops to url/label matching when the dest_for_view
     * column is absent (schema-drift tolerance).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return string
     */
    private function buildSingleTableWhere(string $sub, array $tableOptions, bool $derivedPresent = true): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions, true, $derivedPresent);

        return "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n";
    }

    /**
     * Resolve the ORDER BY column for the single-table read, applying the
     * derived-sort fallback: a sort on a derived column (logshits / last_used /
     * dest) falls back to the always-correct native url column while the
     * post-upgrade denorm backfill is still in flight (completion flag not set)
     * OR when the derived columns are absent entirely (schema drift). Ordering on
     * a half-populated or missing column would be wrong/erroring; the url
     * fallback is always correct and never blank. The default url sort is never
     * affected. Once the backfill completes and the columns exist, derived sorts
     * use the real column.
     *
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return string
     */
    private function resolveSingleTableOrderByColumn(array $tableOptions, bool $derivedPresent = true): string {
        $rawOrderByValue = $tableOptions['orderby'] ?? '';
        $rawOrderBy = strtolower(is_string($rawOrderByValue) ? $rawOrderByValue : '');
        $derivedSorts = array('logshits', 'last_used', 'dest', 'final_dest');
        if (in_array($rawOrderBy, $derivedSorts, true) && (!$derivedPresent || !$this->denormBackfillComplete())) {
            return 'url';
        }
        return $this->policy->resolveOrderByColumn($tableOptions);
    }

    /** @return bool Whether the post-upgrade denorm backfill has fully populated the derived columns. */
    private function denormBackfillComplete(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        return (bool) get_option(
            ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_COMPLETE_OPTION,
            false
        );
    }

    /**
     * Execute a staged read against the view_done admin table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->dbCore->queryAndGetResults($query, array());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions);

        return "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $this->viewDoneTableName() . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveStatusTypeList(string $sub, array $tableOptions): string {
        return $this->policy->resolveStatusTypeList($sub, $tableOptions);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveOrderByColumn(array $tableOptions): string {
        return $this->policy->resolveOrderByColumn($tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions);
        $orderBy = $this->policy->resolveOrderByColumn($tableOptions);
        $order = $this->policy->resolveOrderDirection($tableOptions);

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        return "SELECT id, url, status, type,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $this->viewDoneTableName() . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }

}
