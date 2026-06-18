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
     * The previous implementation gated these sorts on
     * abj404_redirects_denorm_backfill_complete and fell back to url order until
     * it flipped. That flag is flipped only when no row has dest_for_view NULL, a
     * condition a live 404 site never reaches: every newly captured 404 is
     * inserted with dest_for_view NULL, so the flag stayed false forever and the
     * Hits / Last Used / Destination columns were permanently sorted by url
     * instead of by their own values (the rendered Hits column looked random).
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
