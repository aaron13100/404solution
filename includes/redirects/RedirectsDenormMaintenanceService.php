<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormMaintenanceIntegrationTest

/**
 * Real-time maintenance of the four denormalized derived columns on
 * wp_abj404_redirects (Denorm Step 3c, i461).
 *
 * Step 3a backfilled the columns once; Step 3b reads off them and live-resolves
 * the visible page for DISPLAY freshness. This service keeps the STORED columns
 * (which order the FULL, off-page result set) current in real time so a sort or
 * filter that reaches rows beyond the visible page still sees fresh values:
 *
 *   - dest_for_view + published_status are recomputed when a redirect is
 *     created or edited (the add/edit write path), and when the post or term a
 *     redirect targets changes (save_post / transition_post_status /
 *     deleted_post / edited_term, dispatched by
 *     {@see ABJ_404_Solution_RedirectsDenormContentHooks} via a final_dest
 *     reverse lookup).
 *   - logshits + last_used are written back onto the redirect rows when the
 *     logs_hits rollup rebuilds ({@see ABJ_404_Solution_LogsHitsRollupService}).
 *
 * Per-type dest/published resolution is delegated to
 * {@see ABJ_404_Solution_RedirectsDenormColumnSql} so it never drifts from the
 * one-time backfill. Every write DEGRADES GRACEFULLY (defensive philosophy
 * #2/#7/#8): a schema-drifted table missing the columns, or a read-only replica
 * / disk-full host with the write block active, skips silently; a query error
 * is logged as a warning by queryAndGetResults (the centralized DAO error
 * handler) and never raised to the admin or emailed.
 *
 * This is a data-access service: it issues SQL and holds no business or
 * presentation logic. WordPress-hook argument extraction lives in the content
 * hooks class; cache invalidation lives in the write services that call it.
 */
class ABJ_404_Solution_RedirectsDenormMaintenanceService {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_TableReadinessGate */
    private $readiness;

    /** @var array<string,bool>|null Memoized lowercased column-name set of the
     *  redirects table (one SHOW COLUMNS per instance), the source for both the
     *  dest_for_view presence check and the narrow sort-key presence gates. */
    private $redirectsColumnSetCache = null;

    /**
     * Redirect-id batch size for the chunked logs_hits write-back. A full-table
     * UPDATE JOIN over every redirect row can lock / heavily load the redirects
     * table on slow shared hosting exactly while the admin is reading it
     * (report.md Finding 5); chunking by id bounds each statement's row count and
     * lock duration. Matches the backfill/reconcile chunk size.
     */
    const WRITE_BACK_CHUNK_SIZE = 1000;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logging = null) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->readiness = new ABJ_404_Solution_TableReadinessGate(
            $dbCore,
            $dbCore->tableNameResolver()
        );
    }

    /**
     * Recompute dest_for_view + published_status for an explicit list of
     * redirect ids. Used by the add/edit write path (the just-written id) and by
     * the post/term reverse-lookup handlers.
     *
     * Idempotent: re-running on the same ids recomputes the same values from the
     * same sources. Touches only dest_for_view + published_status; logshits /
     * last_used are owned by the rollup write-back and keep their values.
     *
     * @param array<int, int|string> $ids
     * @return void
     */
    public function recomputeByRedirectIds(array $ids): void {
        $cleanIds = array();
        foreach ($ids as $id) {
            $intId = is_scalar($id) ? (int)$id : 0;
            if ($intId > 0) {
                $cleanIds[$intId] = $intId;
            }
        }
        if (empty($cleanIds)) {
            return;
        }
        if (!$this->denormColumnsPresent()) {
            // Schema drift: the column-add ALTER never completed. Nothing to
            // maintain; the live resolver still renders the visible page.
            $this->logger->debugMessage(__FUNCTION__ . " skipped: denorm columns absent (schema drift).");
            return;
        }
        if ($this->dbCore->noticeState()->isWriteBlockActive()) {
            // Read-only replica / disk full: skip the write, never error.
            $this->logger->debugMessage(__FUNCTION__ . " skipped: DB write block active (read-only / disk full).");
            return;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $idList = implode(',', array_values($cleanIds));
        // Gate the narrow sort-key UPDATEs on column presence: those columns are
        // added by a separate ALTER that can lag the dest_for_view add, and an
        // UPDATE against a missing column would error (logged as a warning by
        // queryAndGetResults) and skip the rest of the recompute for this id.
        $columns = $this->redirectsColumnSet();
        $statements = ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements(
            $redirectsTable,
            " AND r.id IN (" . $idList . ")",
            " AND id IN (" . $idList . ")",
            true,
            isset($columns['dest_sort_key']),
            isset($columns['url_sort_key'])
        );
        foreach ($statements as $statement) {
            // queryAndGetResults is the centralized error handler: a write
            // failure on a read-only / disk-full host is logged there as a
            // warning and never surfaced. Stop after the first failing
            // statement (same stop-on-first-error shape as the sibling chunk
            // writer, RedirectsDenormChunkResolver::runChunkWrite(), and this
            // class's own writeBackLogsHitsColumns()) rather than continuing
            // to hammer a table that is already erroring; the next edit or
            // the nightly Step 3d reconcile retries the recompute from a
            // clean state. This is a best-effort DISPLAY/sort cache, not
            // authoritative row data, so a transaction is unnecessary here --
            // unlike RedirectConditionsRepository/RedirectWriteService, a
            // partial write just means a stale sort key until the next
            // recompute, not lost or hybrid user data.
            $result = $this->dbCore->queryAndGetResults($statement);
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
            if ($lastError !== '') {
                $this->logger->debugMessage(__FUNCTION__ . ' stopped after a write error: ' . $lastError);
                break;
            }
        }
    }

    /**
     * Recompute the denorm columns for every redirect whose final_dest targets
     * the given post id (POST-typed redirects only). Invoked when a post is
     * saved, transitions status, or is deleted.
     *
     * @param int $postId
     * @return void
     */
    public function recomputeForChangedPost(int $postId): void {
        if ($postId <= 0) {
            return;
        }
        $ids = $this->reverseLookupRedirectIds(array((int)ABJ404_TYPE_POST), $postId);
        $this->recomputeByRedirectIds($ids);
    }

    /**
     * Recompute the denorm columns for every redirect whose final_dest targets
     * the given term id (CAT/TAG-typed redirects only). Invoked when a term is
     * edited.
     *
     * @param int $termId
     * @return void
     */
    public function recomputeForChangedTerm(int $termId): void {
        if ($termId <= 0) {
            return;
        }
        $ids = $this->reverseLookupRedirectIds(
            array((int)ABJ404_TYPE_CAT, (int)ABJ404_TYPE_TAG),
            $termId
        );
        $this->recomputeByRedirectIds($ids);
    }

    /**
     * Find redirect ids of the given types whose final_dest equals the changed
     * object id. The final_dest index serves this reverse lookup. The type
     * filter keeps the post and term id namespaces separate: a post id and a
     * term id can collide numerically, so a post change must never recompute a
     * term-typed redirect (and vice versa).
     *
     * @param array<int, int> $types
     * @param int $finalDestId
     * @return array<int, int>
     */
    private function reverseLookupRedirectIds(array $types, int $finalDestId): array {
        $typeList = implode(',', array_map('intval', $types));
        if ($typeList === '' || $this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return array();
        }
        $result = $this->dbCore->queryAndGetResults(
            // allow-unbounded-select: bounded by single final_dest equality (only the few redirects sharing one destination); a LIMIT here would corrupt denorm consistency by dropping siblings
            "SELECT id FROM {wp_abj404_redirects} WHERE type IN (" . $typeList . ") AND final_dest = %s",
            array('query_params' => array((string)$finalDestId))
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Write the rolled-up hit count + last-used timestamp from the freshly
     * rebuilt wp_abj404_logs_hits table back onto every redirect row, keyed on
     * canonical URL. Called by the rollup after a successful rebuild so the
     * STORED logshits / last_used columns (used to order the full set) stay
     * current without waiting for the nightly reconcile.
     *
     * Degrades the same way as the recompute path: no columns, no logs_hits
     * table, or an active write block -> skip, never throw.
     *
     * @return void
     */
    public function writeBackLogsHitsColumns(): void {
        if (!$this->denormColumnsPresent()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped: denorm columns absent (schema drift).");
            return;
        }
        if ($this->dbCore->noticeState()->isWriteBlockActive()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped: DB write block active (read-only / disk full).");
            return;
        }
        if (!$this->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped: logs_hits rollup table absent.");
            return;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $logsHitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $canonicalRedirectUrl = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $comparableRedirectUrl = $this->dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $canonicalRedirectUrl,
            array('table' => $logsHitsTable, 'column' => 'requested_url')
        );

        // Chunk by redirect id (report.md Finding 5): a single full-table UPDATE
        // JOIN over every redirect row can lock / heavily load the redirects table
        // on slow shared hosting while the admin is reading it. Walk the ids with
        // a cursor (so sparse ids after heavy deletion never produce empty range
        // passes) and roll each batch through the same single-source-of-truth
        // builder the backfill / reconcile chunk resolver uses (LEFT JOIN
        // logs_hits, so a no-hit URL resets to 0/0 rather than keeping a stale
        // count). A write error stops the walk; the next rollup / nightly
        // reconcile retries from the start (queryAndGetResults logs it).
        $cursor = 0;
        while (true) {
            $ids = $this->nextRedirectIdChunk($redirectsTable, $cursor, self::WRITE_BACK_CHUNK_SIZE);
            if (empty($ids)) {
                break;
            }
            $cursor = (int)max($ids);
            $idClause = ' AND r.id IN (' . implode(',', $ids) . ')';
            $query = ABJ_404_Solution_RedirectsDenormColumnSql::buildHitsRollupFromRollupTableStatement(
                array(
                    'redirects_table' => $redirectsTable,
                    'logs_hits_table' => $logsHitsTable,
                    'id_clause' => $idClause,
                    'comparable_redirect_url' => $comparableRedirectUrl,
                )
            );
            $result = $this->dbCore->queryAndGetResults($query);
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
            if ($lastError !== '') {
                $this->logger->debugMessage(__FUNCTION__ . ' stopped after a write error past id ' . $cursor
                    . '; the next rollup / reconcile will retry.');
                break;
            }
            if (count($ids) < self::WRITE_BACK_CHUNK_SIZE) {
                break;
            }
        }
    }

    /**
     * Fetch the next batch of redirect ids strictly greater than $afterId, in id
     * order, capped at $limit. The cursor walk over the PRIMARY key visits only
     * rows that exist, so a table with sparse ids (heavy deletion) never wastes a
     * pass on an empty id range.
     *
     * @param string $redirectsTable
     * @param int $afterId
     * @param int $limit
     * @return array<int, int>
     */
    private function nextRedirectIdChunk(string $redirectsTable, int $afterId, int $limit): array {
        $result = $this->dbCore->queryAndGetResults(
            'SELECT id FROM ' . $redirectsTable . ' WHERE id > ' . (int)$afterId
            . ' ORDER BY id ASC LIMIT ' . (int)$limit
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Whether the four Step 3a denorm columns exist on wp_abj404_redirects
     * (probed via the dest_for_view sentinel column). Derived from the memoized
     * column-set probe.
     *
     * @return bool
     */
    private function denormColumnsPresent(): bool {
        return isset($this->redirectsColumnSet()['dest_for_view']);
    }

    /**
     * Lowercased column-name set of wp_abj404_redirects via one SHOW COLUMNS,
     * memoized per instance after a successful non-empty probe. A failed/empty
     * probe yields an uncached empty set, so an in-request schema repair can be
     * observed by the next call. Every
     * presence check (dest_for_view, dest_sort_key, url_sort_key) degrades to
     * false -- the safe schema-drift fallback (skip the write / omit the UPDATE).
     *
     * @return array<string, bool>
     */
    private function redirectsColumnSet(): array {
        if ($this->redirectsColumnSetCache !== null) {
            return $this->redirectsColumnSetCache;
        }
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return array();
        }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM " . $table,
            array('log_errors' => false)
        );
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
        if (!empty($set)) {
            $this->redirectsColumnSetCache = $set;
        }
        return $set;
    }

    /**
     * Existence probe for the logs_hits rollup table. A stripped-down install
     * that never built the rollup table skips the write-back rather than
     * erroring on a missing table.
     *
     * @return bool
     */
    private function logsHitsTableExists(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        // Schema existence probe; routing a SHOW TABLES through
        // queryAndGetResults would log a benign "table missing" error on a
        // stripped install.
        // @utf8-audit: opt-out - $logsTable is an internally resolved plugin table name (doTableNameReplacements); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: SHOW TABLES schema existence probe.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsTable) . "'");
        return $found === $logsTable;
    }
}
