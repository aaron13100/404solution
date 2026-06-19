<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsSingleTableLiveResolveTest

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

    /** @var string|null Memoized blogname for HOME-typed rows (per request). */
    private $blognameCache = null;

    /** @var ABJ_404_Solution_RedirectsDenormSchemaReadiness Live introspection of
     *  the denorm columns / sort-key indexes, composed and shared so the resolver,
     *  the read coordinator, and the header UI see the same per-request memoized
     *  probes. */
    private $schemaReadiness;

    /** @var ABJ_404_Solution_RedirectsHitsRollupReader Rolls up wp_abj404_logs_hits
     *  for the visible page (S9-equivalent). */
    private $hitsRollupReader;

    /**
     * Error logging is intentionally delegated to queryAndGetResults (the
     * centralized DAO error handler), so no logger dependency is held here.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f UTF-8 sanitizer source; falls
     *   back to the container's functions service when not injected.
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $f = null) {
        $this->dbCore = $dbCore;
        $f = $f !== null ? $f : abj_service('functions');
        $this->schemaReadiness = new ABJ_404_Solution_RedirectsDenormSchemaReadiness($dbCore);
        $this->hitsRollupReader = new ABJ_404_Solution_RedirectsHitsRollupReader($dbCore, $f);
    }

    /**
     * The shared live schema/index readiness introspector for the redirects
     * table. Returned (not mirrored by per-method delegators) so the read
     * coordinator and the header UI consult the SAME per-request memoized probes
     * this resolver uses, keeping the query path and the header from drifting on
     * which sorts are index-ready.
     *
     * @return ABJ_404_Solution_RedirectsDenormSchemaReadiness
     */
    public function schemaReadiness(): ABJ_404_Solution_RedirectsDenormSchemaReadiness {
        return $this->schemaReadiness;
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
        $hitsMap = $this->hitsRollupReader->resolveHitsMap($rows);

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
        $writeDestSortKey = $this->schemaReadiness->destSortKeyColumnPresent();

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
}
