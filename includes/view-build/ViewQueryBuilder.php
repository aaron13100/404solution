<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin view query construction for the redirect/captured lists.
 *
 * Builds the SQL for the admin redirect/captured lists: high-impact captured
 * count, regex redirects, optimized count, and the single-table read/count
 * against wp_abj404_redirects (Denorm Step 3b). The staged view_done read path
 * that used to live here was removed when the denorm chain dropped the
 * wp_abj404_view_done table (Step 3e-D / i467); admin reads now serve straight
 * off the redirects row.
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
        // allow-unbounded-select: COUNT(*) aggregate; returns a single row
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
        $effectiveSort = $this->resolveEffectiveSort($tableOptions, $derivedPresent);
        $orderBy = $effectiveSort['orderby'];
        $order = $effectiveSort['order'];

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

        // Tie-break on the PK id in the sort direction (not on url): url is
        // varchar(2048) and only prefix-indexable, so an `ORDER BY <col>, url`
        // can never be index-ordered and always filesorts. With `id <dir>` a
        // single ascending composite index (disabled, <col>, id) serves both
        // ASC and DESC scans, so the Hits / Last Used sorts stop after one page
        // instead of sorting the whole active-redirect set. See
        // RedirectsDerivedSortExplainPlanTest. id is unique, so the ordering is
        // still fully deterministic for pagination.
        return "SELECT id, url, status, type, final_dest, code, timestamp, engine, score"
            . $derivedProjection . "\n"
            . "FROM {wp_abj404_redirects}\n"
            . $this->buildSingleTableWhere($sub, $tableOptions, $derivedPresent)
            . "ORDER BY " . $orderBy . " " . $order . ", id " . $order . "\n"
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
     * Effective (orderby column, direction) for the single-table read ORDER BY.
     *
     * On EITHER tab, a URL or Destination sort that cannot be served index-ordered
     * right now (its narrow sort key is not ready: column missing, composite index
     * missing, or the legacy-row drain not yet converged -- see
     * RedirectsDenormSchemaReadiness::sortKeyReadyForColumn) would force a filesort over
     * the wide source column (varchar(2048), prefix-only). On a large table that
     * scan can exceed a shared host's max_statement_time, the server kills the
     * query, and the tab is stuck on its loading placeholder every load. Until the
     * sort key is ready we therefore serve the always-indexed safe default
     * (timestamp DESC, "newest first") instead. This is display-only: the user's
     * saved sort preference is left untouched and resumes automatically once the
     * sort key is ready -- and the column header is rendered non-sortable with a
     * progress tooltip meanwhile (captured tab: View_CapturedURLsTable; Page
     * Redirects tab: ABJ_404_Solution_AdminTableColumnHeaders).
     *
     * Applied to BOTH tabs (the literal "no wide-column filesort" rule): the
     * Page Redirects status filter does not make the wide-url filesort safe at
     * scale, and the readiness predicate self-heals so the real sort resumes the
     * moment the key is index-ordered.
     *
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return array{orderby: string, order: string}
     */
    private function resolveEffectiveSort(array $tableOptions, bool $derivedPresent): array {
        $rawOrderBy = strtolower(is_string($tableOptions['orderby'] ?? null) ? $tableOptions['orderby'] : '');
        if ($this->wideColumnSortPendingBackfill($rawOrderBy, $tableOptions)) {
            return array('orderby' => 'timestamp', 'order' => 'DESC');
        }
        return array(
            'orderby' => $this->resolveSingleTableOrderByColumn($tableOptions, $derivedPresent),
            'order' => $this->policy->resolveOrderDirection($tableOptions),
        );
    }

    /**
     * Whether the requested sort targets a narrow sort-key-backed column
     * (url -> url_sort_key, dest -> dest_sort_key) that cannot be served
     * index-ordered yet. The coordinator sets the matching
     * _abj404_*_sort_key_present flag true only when the column exists AND its
     * composite indexes exist AND its drain latch is set
     * (RedirectsDenormSchemaReadiness::sortKeyReadyForColumn); a falsey flag means the
     * only available ordering is a wide-column filesort. Sorts on real
     * always-populated columns (logshits, last_used, score, code, type, status,
     * timestamp) are never pending and are not substituted.
     *
     * @param string $rawOrderBy Lowercased requested orderby.
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    private function wideColumnSortPendingBackfill(string $rawOrderBy, array $tableOptions): bool {
        if ($rawOrderBy === 'url') {
            return empty($tableOptions['_abj404_url_sort_key_present']);
        }
        if ($rawOrderBy === 'dest' || $rawOrderBy === 'final_dest') {
            return empty($tableOptions['_abj404_dest_sort_key_present']);
        }
        return false;
    }

    /**
     * Resolve the ORDER BY column for the single-table read.
     *
     * A sort on a derived column (logshits / last_used / dest) uses the real
     * column whenever the four denorm columns exist. The only fallback is
     * schema-drift tolerance: when the columns are absent entirely (the column-add
     * ALTER never completed) a derived sort would reference a missing column, so
     * it falls back to the always-present native url column.
     *
     * No backfill-completion flag is consulted. Each derived column degrades
     * gracefully for not-yet-backfilled rows by construction, so ordering on it is
     * always meaningful and self-heals as rows are resolved:
     *   - logshits is NOT NULL and defaults to 0, so an un-backfilled row simply
     *     sorts as 0 hits and rises into place once the rollup is written;
     *   - last_used is NULL for no-hit rows, which sort last;
     *   - the dest_for_view ordering groups NULL/empty destinations last via its
     *     CASE expression (see ViewQueryPolicy::resolveOrderByColumn).
     *
     * The previous implementation gated these sorts on the obsolete denorm
     * backfill-complete option and fell back to url order until it flipped. That
     * option was flipped only when no row had dest_for_view NULL, a condition a
     * live 404 site never reaches: every newly captured 404 is inserted with
     * dest_for_view NULL, so the option stayed false forever and the Hits / Last
     * Used / Destination columns were permanently sorted by url instead of by
     * their own values (the rendered Hits column looked random).
     *
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent Whether the four denorm columns exist on the
     *   table. False (schema drift) is the only case that forces the url fallback.
     * @return string
     */
    private function resolveSingleTableOrderByColumn(array $tableOptions, bool $derivedPresent = true): string {
        $rawOrderByValue = $tableOptions['orderby'] ?? '';
        $rawOrderBy = strtolower(is_string($rawOrderByValue) ? $rawOrderByValue : '');
        $derivedSorts = array('logshits', 'last_used', 'dest', 'final_dest');
        if (in_array($rawOrderBy, $derivedSorts, true) && !$derivedPresent) {
            return 'url';
        }
        // Destination sort: ORDER BY the narrow indexable dest_sort_key
        // (uniform direction -> (disabled, dest_sort_key, id) /
        // (status, disabled, dest_sort_key, id) serve it without a filesort) when
        // the column exists. Blank/NULL destinations then sort naturally (first
        // ascending, last descending) rather than the old always-last CASE, which
        // was the very thing that forced a filesort (computed expression over a
        // varchar(2048) prefix-only column). When the column is absent (an install
        // mid-upgrade), fall back to the CASE-on-dest_for_view filesort so the
        // sort still works -- just unindexed -- until the column-add completes.
        if (($rawOrderBy === 'dest' || $rawOrderBy === 'final_dest')
                && !empty($tableOptions['_abj404_dest_sort_key_present'])) {
            return 'dest_sort_key';
        }
        // URL sort (incl. the Page Redirects default): ORDER BY the narrow
        // indexable url_sort_key so (disabled, url_sort_key, id) /
        // (status, disabled, url_sort_key, id) serve it without a filesort. url is
        // varchar(2048), prefix-only, so ORDER BY url itself always filesorts even
        // when every value is short (MySQL will not order by a prefix index). When
        // the column is absent (an install mid-upgrade) fall back to raw url so the
        // sort still works -- unindexed -- until the column-add completes. URLs
        // longer than 191 chars sharing a 191-char prefix tie-break by id.
        if ($rawOrderBy === 'url' && !empty($tableOptions['_abj404_url_sort_key_present'])) {
            return 'url_sort_key';
        }
        return $this->policy->resolveOrderByColumn($tableOptions);
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

}
