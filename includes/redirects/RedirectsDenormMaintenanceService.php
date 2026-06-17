<?php

if (!defined('ABSPATH')) {
    exit;
}

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

    /** @var bool|null Memoized "are the four denorm columns present on redirects". */
    private $denormColumnsPresentCache = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logging = null) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
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
        $statements = ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements(
            $redirectsTable,
            " AND r.id IN (" . $idList . ")",
            " AND id IN (" . $idList . ")",
            true
        );
        foreach ($statements as $statement) {
            // queryAndGetResults is the centralized error handler: a write
            // failure on a read-only / disk-full host is logged there as a
            // warning and never surfaced.
            $this->dbCore->queryAndGetResults($statement);
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
        if ($typeList === '') {
            return array();
        }
        $result = $this->dbCore->queryAndGetResults(
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
        $canonRedirect = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";

        // LEFT JOIN so a redirect URL with no hits resets to 0 / 0 rather than
        // keeping a stale count. Mirrors the rollup table's own semantics
        // (requested_url is the canonical key, logshits / last_used the rolled
        // values).
        $query = "UPDATE " . $redirectsTable . " r" .
            " LEFT JOIN " . $logsHitsTable . " h ON h.requested_url = " . $canonRedirect .
            " SET r.logshits = COALESCE(h.logshits, 0), r.last_used = COALESCE(h.last_used, 0)";
        $this->dbCore->queryAndGetResults($query);
    }

    /**
     * Whether the four Step 3a denorm columns exist on wp_abj404_redirects.
     * Memoized per instance so the SHOW COLUMNS probe runs at most once.
     *
     * @return bool
     */
    private function denormColumnsPresent(): bool {
        if ($this->denormColumnsPresentCache !== null) {
            return $this->denormColumnsPresentCache;
        }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM " . $table,
            array('log_errors' => false)
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $present = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'field' && is_scalar($value)
                        && strtolower((string)$value) === 'dest_for_view') {
                    $present = true;
                    break 2;
                }
            }
        }
        $this->denormColumnsPresentCache = $present;
        return $present;
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
        // DAO-bypass-approved: schema existence probe; routing a SHOW TABLES
        // through queryAndGetResults would log a benign "table missing" error on
        // a stripped install.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsTable) . "'");
        return $found === $logsTable;
    }
}
