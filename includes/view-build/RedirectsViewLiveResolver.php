<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Live resolver for the denormalized derived columns on the visible page of the
 * admin redirects/captured table (Denorm Step 3b, i460).
 *
 * The single-table read serves rows straight off wp_abj404_redirects, where
 * dest_for_view / published_status / logshits / last_used are real columns that
 * may be stale or empty (right after upgrade, or after a destination rename / new
 * 404 hit). This class resolves the derived/display values LIVE for the ~50 rows
 * on the current page on every read, renders from those live values, and writes
 * the four persisted columns back so subsequent filter/sort reads see fresh data.
 * That is the mechanism behind both the always-fresh display and the
 * instant+complete first load (correct even when the columns are still NULL).
 *
 * Resolution mirrors the staged view_done pipeline exactly (stages S4-S9) so the
 * output is byte-identical to the pre-refactor staged path: dest_for_view /
 * published_status / wp_post_id / wp_post_type per redirect type (POST, CAT/TAG,
 * HOME, EXTERNAL, 404-displayed, else empty/broken); logshits / logsid /
 * last_used rolled up from wp_abj404_logs_hits by the canonical URL key.
 *
 * wp_post_id / wp_post_type / logsid are display-only (not columns on the table).
 * The four persisted columns are written back idempotently (only changed rows),
 * and the persist DEGRADES GRACEFULLY on a read-only / disk-full host: values are
 * still resolved and rendered, the write is skipped, nothing throws (defensive
 * philosophy #2/#8). queryAndGetResults() is the centralized error handler.
 */
class ABJ_404_Solution_RedirectsViewLiveResolver {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions Used to strip invalid UTF-8 from
     *  capture-derived URLs before they reach esc_sql() (Pattern 10). */
    private $f;

    /** @var string|null Memoized blogname for HOME-typed rows (per request). */
    private $blognameCache = null;

    /** @var array<string,bool>|null Memoized lowercased column-name set of the
     *  redirects table (one SHOW COLUMNS per request), consulted by both
     *  derivedColumnsPresent() and destSortKeyColumnPresent(). */
    private $redirectsColumnSetCache = null;

    /** @var array<string,bool>|null Memoized lowercased index-name (Key_name) set
     *  of the redirects table (one SHOW INDEX per request), consulted by
     *  sortKeyReadyForColumn() to confirm the composite indexes backing a narrow
     *  sort key were actually created before the read orders by it. */
    private $redirectsIndexSetCache = null;

    /**
     * Error logging is intentionally delegated to queryAndGetResults (the
     * centralized DAO error handler), so no logger dependency is held here.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f UTF-8 sanitizer source; falls
     *   back to the Functions singleton when not injected (e.g. older callers).
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $f = null) {
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : ABJ_404_Solution_Functions::getInstance();
    }

    /**
     * Safe string read of a query-result field; absent/non-scalar becomes ''.
     * @param array<array-key, mixed> $row @param string $key @return string
     */
    private function strField(array $row, string $key): string {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string)$row[$key] : '';
    }

    /**
     * Safe int read; NULL/absent/non-numeric stays null, numeric becomes int.
     * @param array<array-key, mixed> $row @param string $key @return int|null
     */
    private function intFieldOrNull(array $row, string $key): ?int {
        return isset($row[$key]) && is_numeric($row[$key]) ? (int)$row[$key] : null;
    }

    /**
     * Safe string read that preserves the NULL/absent distinction (stays null),
     * which the write-back change detection needs (stored NULL != resolved '').
     * @param array<array-key, mixed> $row @param string $key @return string|null
     */
    private function strFieldOrNull(array $row, string $key): ?string {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string)$row[$key] : null;
    }

    /**
     * Whether the four Step 3a denorm columns exist on wp_abj404_redirects.
     *
     * Schema-drift tolerance (defensive philosophy #1/#7): a site whose
     * column-add ALTER never completed serves off the base columns alone (the
     * write-back is then skipped, with nowhere to write). Memoized per instance
     * so the SHOW COLUMNS probe runs at most once per request.
     *
     * @return bool
     */
    public function derivedColumnsPresent(): bool {
        return isset($this->redirectsColumnSet()['dest_for_view']);
    }

    /**
     * Whether the indexable Destination sort key column (dest_sort_key, added
     * after the Step 3a four) exists on wp_abj404_redirects. The admin read uses
     * it for an index-ordered Destination sort; when it is absent (an install
     * mid-upgrade, before the column-add ALTER ran) the read falls back to the
     * CASE-on-dest_for_view filesort. Memoized via the shared column-set probe.
     *
     * @return bool
     */
    public function destSortKeyColumnPresent(): bool {
        return isset($this->redirectsColumnSet()['dest_sort_key']);
    }

    /**
     * Whether the indexable URL sort key column (url_sort_key) exists on
     * wp_abj404_redirects. The admin read uses it for an index-ordered URL sort;
     * when it is absent (an install mid-upgrade, before the column-add ALTER ran)
     * the read falls back to the raw-url filesort. Memoized via the shared
     * column-set probe.
     *
     * @return bool
     */
    public function urlSortKeyColumnPresent(): bool {
        return isset($this->redirectsColumnSet()['url_sort_key']);
    }

    /**
     * The single authority for "may the admin read ORDER BY this narrow sort-key
     * column right now, index-ordered?" -- consulted by BOTH the query path
     * (AdminViewReadCoordinator, which sets the _abj404_*_sort_key_present table
     * options the ViewQueryBuilder reads) AND the header UI (ViewReadService::
     * isSortReadyForOrderby, which disables the sort link with a progress tooltip).
     * Centralised here so those two paths cannot drift: a sort the query refuses
     * to order by must also be the one the header disables, and vice versa.
     *
     * Ready requires ALL THREE, because a filesort on the captured majority can
     * exceed a shared host's max_statement_time:
     *   1. the column exists (the column-add ALTER ran);
     *   2. EVERY composite index backing it exists (the index-add ALTER ran -- it
     *      can fail or lag independently of the column-add and the drain, e.g.
     *      disk full or online-DDL refused, leaving an ORDER BY on the key as an
     *      unindexed filesort); and
     *   3. the one-time legacy-row drain has converged (the backfill latch is set,
     *      so no row still carries a NULL key that would bucket to id order).
     * Any one missing means the only correct serve is the wide-column filesort, so
     * the read falls back to the safe default instead of ordering by the key.
     *
     * @param string $column url_sort_key | dest_sort_key.
     * @return bool
     */
    public function sortKeyReadyForColumn(string $column): bool {
        if (!isset($this->redirectsColumnSet()[$column])) {
            return false;
        }
        if (!$this->sortKeyCompositeIndexesPresent($column)) {
            return false;
        }
        $latch = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($column);
        return $latch !== '' && function_exists('get_option') && get_option($latch) === '1';
    }

    /**
     * Whether every composite index registered for a narrow sort-key column
     * exists on the redirects table. A column with no registered composites is
     * treated as never index-ready (the safe default).
     *
     * @param string $column url_sort_key | dest_sort_key.
     * @return bool
     */
    private function sortKeyCompositeIndexesPresent(string $column): bool {
        $required = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyCompositeIndexNames($column);
        if (empty($required)) {
            return false;
        }
        $present = $this->redirectsIndexSet();
        foreach ($required as $indexName) {
            if (!isset($present[strtolower($indexName)])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The lowercased index-name (Key_name) set of wp_abj404_redirects, fetched
     * once per request via a single SHOW INDEX. Same per-request memoization and
     * schema-drift tolerance as redirectsColumnSet(): an empty/failed probe yields
     * an empty set, so every index-presence check degrades to false (the safe
     * fallback). Runs only when the admin redirects view is rendered -- not on the
     * frontend 404 hot path.
     *
     * @return array<string,bool>
     */
    private function redirectsIndexSet(): array {
        if ($this->redirectsIndexSetCache !== null) {
            return $this->redirectsIndexSetCache;
        }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->dbCore->queryAndGetResults("SHOW INDEX FROM " . $table,
            array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $set = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'key_name' && is_scalar($value)) {
                    $set[strtolower((string)$value)] = true;
                    break;
                }
            }
        }
        $this->redirectsIndexSetCache = $set;
        return $set;
    }

    /**
     * The lowercased column-name set of wp_abj404_redirects, fetched once per
     * request via a single SHOW COLUMNS. Schema-drift tolerance (defensive
     * philosophy #1/#7): a site whose column-add ALTER never completed is served
     * off whatever columns it does have. An empty/failed probe yields an empty
     * set, so every presence check degrades to false (the safe fallback).
     *
     * @return array<string,bool>
     */
    private function redirectsColumnSet(): array {
        if ($this->redirectsColumnSetCache !== null) {
            return $this->redirectsColumnSetCache;
        }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $table,
            array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $set = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'field' && is_scalar($value)) {
                    $set[strtolower((string)$value)] = true;
                    break;
                }
            }
        }
        $this->redirectsColumnSetCache = $set;
        return $set;
    }

    /**
     * Resolve the derived/display columns for the visible rows LIVE, overwrite
     * the rendered values on each row, and persist the four denorm columns back.
     *
     * @param array<int, array<string, mixed>> $rows Rows read off wp_abj404_redirects.
     * @param bool $persist Whether the four denorm columns exist and may be
     *   written back. False on a schema-drifted table that lacks the columns:
     *   the values are still resolved for display, just not persisted.
     * @return array<int, array<string, mixed>> The same rows with fresh derived values.
     */
    public function resolveAndPersistVisibleRows(array $rows, bool $persist = true): array {
        if (empty($rows)) {
            return $rows;
        }

        $postsMap = $this->resolvePostsMap($rows);
        $termsMap = $this->resolveTermsMap($rows);
        $hitsMap = $this->resolveHitsMap($rows);

        $resolved = array();
        $writeBacks = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $resolved[] = $row;
                continue;
            }
            $out = $this->applyResolution($row, $postsMap, $termsMap, $hitsMap);
            $resolved[] = $out;
            if (!$persist) {
                continue;
            }
            $persistValues = $this->persistValuesIfChanged($row, $out);
            if ($persistValues !== null) {
                $writeBacks[] = $persistValues;
            }
        }

        if (!empty($writeBacks)) {
            $this->persistResolvedColumns($writeBacks);
        }

        return $resolved;
    }

    /**
     * Look up wp_posts for every POST-typed row's numeric final_dest (S4).
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>> postId => {ID, post_title, post_status, post_type}
     */
    private function resolvePostsMap(array $rows): array {
        $ids = $this->collectFinalDestIds($rows, ABJ404_TYPE_POST);
        if (empty($ids)) {
            return array();
        }
        $query = "SELECT ID, post_title, post_status, post_type FROM {wp_posts} WHERE ID IN ("
            . implode(',', $ids) . ")";
        return $this->indexRowsBy($this->dbCore->queryAndGetResults($query), 'ID');
    }

    /**
     * Look up wp_terms for every CAT/TAG-typed row's numeric final_dest (S5).
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>> termId => {term_id, name}
     */
    private function resolveTermsMap(array $rows): array {
        $ids = array_merge(
            $this->collectFinalDestIds($rows, ABJ404_TYPE_CAT),
            $this->collectFinalDestIds($rows, ABJ404_TYPE_TAG)
        );
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return array();
        }
        $query = "SELECT term_id, name FROM {wp_terms} WHERE term_id IN (" . implode(',', $ids) . ")";
        return $this->indexRowsBy($this->dbCore->queryAndGetResults($query), 'term_id');
    }

    /**
     * Roll up wp_abj404_logs_hits for every visible row's canonical URL in one
     * grouped query. Mirrors S9: SUM(logshits), MAX(logsid), MAX(last_used) by
     * requested_url. Returns an empty map (degraded path) when the logs_hits
     * table is absent, so a read on a stripped-down install still renders.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{logshits:int, logsid:int|null, last_used:int|null}>
     */
    private function resolveHitsMap(array $rows): array {
        if (!$this->logsHitsTableExists()) {
            return array();
        }
        $canonicals = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonicals[$this->canonicalUrl($this->strField($row, 'url'))] = true;
        }
        if (empty($canonicals)) {
            return array();
        }
        $quoted = array();
        foreach (array_keys($canonicals) as $canonical) {
            // Capture-derived URLs can carry invalid UTF-8 bytes; strip them
            // before esc_sql() so the IN() prefilter cannot break the query
            // (Pattern 10). The exact match below still uses the stored value.
            $quoted[] = "'" . esc_sql($this->f->sanitizeInvalidUTF8($canonical)) . "'";
        }
        $query = "SELECT requested_url, SUM(logshits) AS logshits, MAX(logsid) AS logsid,"
            . " MAX(last_used) AS last_used FROM {wp_abj404_logs_hits}"
            . " WHERE requested_url IN (" . implode(',', $quoted) . ") GROUP BY requested_url";
        $result = $this->dbCore->queryAndGetResults($query);
        $rowsOut = is_array($result['rows'] ?? null) ? $result['rows'] : array();

        $map = array();
        foreach ($rowsOut as $hitRow) {
            if (!is_array($hitRow) || !isset($hitRow['requested_url'])) {
                continue;
            }
            // Exact-string match in PHP keeps the binary-collation semantics the
            // staged S9 JOIN used; the IN() above is only a coarse prefilter.
            $map[$this->strField($hitRow, 'requested_url')] = array(
                'logshits' => $this->intFieldOrNull($hitRow, 'logshits') ?? 0,
                'logsid' => $this->intFieldOrNull($hitRow, 'logsid'),
                'last_used' => $this->intFieldOrNull($hitRow, 'last_used'),
            );
        }
        return $map;
    }

    /**
     * Overwrite the derived/display fields on a single row from the resolved
     * maps. Per-type destination resolution mirrors staged stages S4-S8 plus the
     * catch-all; hits come from the S9-equivalent rollup.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $postsMap
     * @param array<int, array<string, mixed>> $termsMap
     * @param array<string, array{logshits:int, logsid:int|null, last_used:int|null}> $hitsMap
     * @return array<string, mixed>
     */
    private function applyResolution(array $row, array $postsMap, array $termsMap, array $hitsMap): array {
        $out = $row;

        $destination = $this->resolveDestinationFields($row, $postsMap, $termsMap);
        $out['dest_for_view'] = $destination['dest_for_view'];
        $out['published_status'] = $destination['published_status'];
        $out['wp_post_id'] = $destination['wp_post_id'];
        $out['wp_post_type'] = $destination['wp_post_type'];

        $hit = $hitsMap[$this->canonicalUrl($this->strField($row, 'url'))] ?? null;
        $out['logshits'] = $hit !== null ? $hit['logshits'] : null;
        $out['logsid'] = $hit !== null ? $hit['logsid'] : null;
        $out['last_used'] = $hit !== null ? $hit['last_used'] : null;

        return $out;
    }

    /**
     * Resolve dest_for_view / published_status / wp_post_id / wp_post_type for a
     * single row per redirect type. Mirrors staged stages S4-S8 plus the
     * catch-all (any other type renders empty/broken).
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $postsMap
     * @param array<int, array<string, mixed>> $termsMap
     * @return array{dest_for_view:string, published_status:int, wp_post_id:string|null, wp_post_type:string|null}
     */
    private function resolveDestinationFields(array $row, array $postsMap, array $termsMap): array {
        $type = $this->intFieldOrNull($row, 'type');
        $finalDest = $this->strField($row, 'final_dest');

        if ($type === ABJ404_TYPE_POST) {
            $post = $this->lookupById($postsMap, $finalDest);
            if ($post === null) {
                return $this->destinationFields('', 0);
            }
            return $this->destinationFields(
                $this->strField($post, 'post_title'),
                strtolower($this->strField($post, 'post_status')) === 'publish' ? 1 : 0,
                isset($post['ID']) ? $this->strField($post, 'ID') : null,
                isset($post['post_type']) ? $this->strField($post, 'post_type') : null
            );
        }
        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            $term = $this->lookupById($termsMap, $finalDest);
            return $term === null
                ? $this->destinationFields('', 0)
                : $this->destinationFields($this->strField($term, 'name'), 1);
        }
        if ($type === ABJ404_TYPE_HOME) {
            return $this->destinationFields($this->blogname(), 1);
        }
        if ($type === ABJ404_TYPE_EXTERNAL) {
            return $this->destinationFields($finalDest, 1);
        }
        if ($type === ABJ404_TYPE_404_DISPLAYED) {
            return $this->destinationFields('', 1);
        }
        return $this->destinationFields('', 0);
    }

    /**
     * @return array{dest_for_view:string, published_status:int, wp_post_id:string|null, wp_post_type:string|null}
     */
    private function destinationFields(string $dest, int $published, ?string $wpPostId = null, ?string $wpPostType = null): array {
        return array(
            'dest_for_view' => $dest,
            'published_status' => $published,
            'wp_post_id' => $wpPostId,
            'wp_post_type' => $wpPostType,
        );
    }

    /**
     * Compute the to-be-persisted values for the four denorm columns, returning
     * them only when they differ from what the row already stored (idempotent
     * write-back; converged rows produce no write). logshits is NOT NULL on the
     * table, so an unresolved (no-hits) row persists 0; comparing the persist
     * value (not the rendered NULL) against the stored 0 avoids rewriting forever.
     *
     * @param array<string, mixed> $original The row as read off the table.
     * @param array<string, mixed> $resolved The row after live resolution.
     * @return array{id:int, dest_for_view:string, dest_sort_key:string, published_status:int, logshits:int, last_used:int|null}|null
     */
    private function persistValuesIfChanged(array $original, array $resolved): ?array {
        $id = $this->intFieldOrNull($resolved, 'id');
        if ($id === null) {
            return null;
        }
        $dest = $this->strField($resolved, 'dest_for_view');
        $published = $this->intFieldOrNull($resolved, 'published_status') ?? 0;
        $logshits = $this->intFieldOrNull($resolved, 'logshits') ?? 0;
        $lastUsed = $this->intFieldOrNull($resolved, 'last_used');

        $storedDest = $this->strFieldOrNull($original, 'dest_for_view');
        $storedPublished = $this->intFieldOrNull($original, 'published_status');
        $storedLogshits = $this->intFieldOrNull($original, 'logshits');
        $storedLastUsed = $this->intFieldOrNull($original, 'last_used');

        $unchanged = $storedDest === $dest
            && $storedPublished === $published
            && $storedLogshits === $logshits
            && $storedLastUsed === $lastUsed;
        if ($unchanged) {
            return null;
        }

        // dest_sort_key is a pure function of dest_for_view (the indexable narrow
        // copy LEFT(dest_for_view, 191)); it rides along on the dest_for_view
        // change rather than being its own change trigger. The bulk backfill is
        // the populator for the pre-backfill NULL window. mb_substr counts
        // characters, matching the SQL LEFT(...,191).
        $destSortKey = function_exists('mb_substr')
            ? (string) mb_substr($dest, 0, 191)
            : (string) substr($dest, 0, 191);

        return array(
            'id' => $id,
            'dest_for_view' => $dest,
            'dest_sort_key' => $destSortKey,
            'published_status' => $published,
            'logshits' => $logshits,
            'last_used' => $lastUsed,
        );
    }

    /**
     * Persist the four denorm columns for the changed rows in one batched UPDATE.
     * Skipped entirely when a write block (read-only replica / disk full) is
     * active so a degraded host still renders without an errored write.
     *
     * @param array<int, array{id:int, dest_for_view:string, dest_sort_key:string, published_status:int, logshits:int, last_used:int|null}> $writeBacks
     * @return void
     */
    private function persistResolvedColumns(array $writeBacks): void {
        if ($this->dbCore->noticeState()->isWriteBlockActive()) {
            return;
        }

        // dest_sort_key is written only when the column exists (added after the
        // Step 3a four); on an install still missing it, skip that one assignment
        // so the write-back of the other columns still succeeds (schema drift).
        $writeDestSortKey = $this->destSortKeyColumnPresent();

        $ids = array();
        $destCases = '';
        $destSortCases = '';
        $publishedCases = '';
        $logshitsCases = '';
        $lastUsedCases = '';
        foreach ($writeBacks as $wb) {
            $id = (int)$wb['id'];
            $ids[] = $id;
            $destCases .= ' WHEN ' . $id . " THEN '" . esc_sql($wb['dest_for_view']) . "'";
            $destSortCases .= ' WHEN ' . $id . " THEN '" . esc_sql($wb['dest_sort_key']) . "'";
            $publishedCases .= ' WHEN ' . $id . ' THEN ' . (int)$wb['published_status'];
            $logshitsCases .= ' WHEN ' . $id . ' THEN ' . (int)$wb['logshits'];
            $lastUsedCases .= ' WHEN ' . $id . ' THEN '
                . ($wb['last_used'] === null ? 'NULL' : (int)$wb['last_used']);
        }

        $idList = implode(',', $ids);
        $query = "UPDATE {wp_abj404_redirects} SET"
            . " dest_for_view = CASE id" . $destCases . " END,"
            . ($writeDestSortKey ? " dest_sort_key = CASE id" . $destSortCases . " END," : "")
            . " published_status = CASE id" . $publishedCases . " END,"
            . " logshits = CASE id" . $logshitsCases . " END,"
            . " last_used = CASE id" . $lastUsedCases . " END"
            . " WHERE id IN (" . $idList . ")";
        // queryAndGetResults is the centralized error handler: a write failure on
        // a read-only/disk-full host is logged there as a warning and never
        // surfaced. The resolved values were already rendered, so a skipped
        // persist only costs a re-resolve on the next read.
        $this->dbCore->queryAndGetResults($query);
    }

    /**
     * Collect numeric final_dest values for rows of one type; non-numeric
     * final_dest is dropped (matches the staged fd_int REGEXP guard).
     * @param array<int, array<string, mixed>> $rows @param int $type
     * @return array<int, int>
     */
    private function collectFinalDestIds(array $rows, int $type): array {
        $ids = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowType = $this->intFieldOrNull($row, 'type');
            if ($rowType !== $type) {
                continue;
            }
            $finalDest = $this->strField($row, 'final_dest');
            if (preg_match('/^\d+$/', $finalDest)) {
                $ids[(int)$finalDest] = (int)$finalDest;
            }
        }
        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $result queryAndGetResults() return shape.
     * @return array<int, array<string, mixed>>
     */
    private function indexRowsBy(array $result, string $keyColumn): array {
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $map = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row[$keyColumn]) && is_numeric($row[$keyColumn])) {
                $map[(int)$row[$keyColumn]] = $row;
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $map @param string $finalDest
     * @return array<string, mixed>|null
     */
    private function lookupById(array $map, string $finalDest): ?array {
        if (!preg_match('/^\d+$/', $finalDest)) {
            return null;
        }
        return $map[(int)$finalDest] ?? null;
    }

    /** @param string $url @return string */
    private function canonicalUrl(string $url): string {
        return '/' . trim($url, '/');
    }

    /** @return string */
    private function blogname(): string {
        if ($this->blognameCache !== null) {
            return $this->blognameCache;
        }
        $value = '';
        if (function_exists('get_option')) {
            $raw = get_option('blogname', '');
            $value = is_scalar($raw) ? (string)$raw : '';
        }
        if ($value === '') {
            $result = $this->dbCore->queryAndGetResults(
                "SELECT option_value FROM {wp_options} WHERE option_name = 'blogname' LIMIT 1"
            );
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (isset($rows[0]) && is_array($rows[0])) {
                $value = $this->strField($rows[0], 'option_value');
            }
        }
        $this->blognameCache = $value;
        return $value;
    }

    /** @return bool */
    private function logsHitsTableExists(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        // Schema existence probe; routing a SHOW TABLES through
        // queryAndGetResults would log a benign "table missing" error on a
        // stripped install.
        // DAO-bypass-approved: SHOW TABLES schema existence probe.
        // @utf8-audit: opt-out - $logsTable is an internally resolved plugin table name (doTableNameReplacements); system-controlled, cannot contain invalid UTF-8.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsTable) . "'");
        return $found === $logsTable;
    }
}
