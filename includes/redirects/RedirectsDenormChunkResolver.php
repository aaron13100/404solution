<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormHitsRollupSourceTest

/**
 * Resolves the four denormalized derived columns (logshits, last_used,
 * dest_for_view, published_status) on wp_abj404_redirects for an explicit chunk
 * of redirect ids.
 *
 * Shared collaborator for the two bulk-write paths that populate those columns:
 *
 *   - the one-time post-upgrade backfill
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}, Step 3a), and
 *   - the nightly full reconcile / Tier-3 backstop
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormReconcile}, Step 3d).
 *
 * Both feed it a chunk of ids and differ only in the $recompute flag they pass
 * through to {@see ABJ_404_Solution_RedirectsDenormColumnSql}: the backfill
 * isolates not-yet-resolved rows by the dest_for_view IS NULL sentinel
 * ($recompute = false); the reconcile recomputes every row regardless of its
 * current value ($recompute = true) so it catches even raw-SQL writers that
 * bypassed the Step 3c real-time hooks. Centralizing the per-chunk write here
 * means a new redirect type, a changed publish rule, or a tweaked rollup join is
 * edited once and both paths pick it up; they can never drift.
 *
 * Stateless static helper, exactly like its sibling
 * {@see ABJ_404_Solution_RedirectsDenormColumnSql}: callers pass the
 * DatabaseCore + logger per call. Every write routes through queryAndGetResults
 * (the centralized DAO error handler), so a write failure on a read-only /
 * disk-full host is logged there as a warning and the chunk simply stops
 * (returns false) rather than throwing or emailing.
 */
class ABJ_404_Solution_RedirectsDenormChunkResolver {

    /**
     * Populate the four derived columns for an explicit list of redirect ids.
     *
     * dest_for_view + published_status are resolved per redirect type, mirroring
     * staged-build stages S4-S8, with a catch-all that drains any unmatched row.
     * logshits + last_used are rolled up from the wp_abj404_logs_hits rollup by
     * canonical URL (NOT raw logsv2: report.md Finding 2) when that rollup table
     * exists; when it is absent the columns keep their 0 / NULL defaults so a
     * degraded site still resolves dest_for_view.
     *
     * Idempotent: re-running on the same ids recomputes the same values from the
     * same sources.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore         The DAO used for every write.
     * @param ABJ_404_Solution_Logging      $logger         Warns on a stopping write error.
     * @param string                        $redirectsTable Fully-qualified redirects table name.
     * @param array<int, int>               $ids            Redirect ids in this chunk.
     * @param bool                          $recompute      false: catch-all guards on the
     *   dest_for_view IS NULL sentinel (backfill); true: catch-all guards on
     *   "type NOT IN (knownTypes)" so already-populated unknown-type rows are
     *   still re-drained (full reconcile).
     * @return bool True if the chunk resolved cleanly, false if a write errored.
     */
    public static function resolveChunk(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger,
        string $redirectsTable,
        array $ids,
        bool $recompute
    ): bool {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return true;
        }
        $idClause = " AND r.id IN (" . $idList . ")";
        $idClauseBare = " AND id IN (" . $idList . ")";

        // Probe the narrow sort-key columns once per chunk (same shape as the
        // logs_hits SHOW TABLES probe below): their column-add ALTER runs
        // separately from the dest_for_view add and may lag it, so emitting an
        // UPDATE against a still-missing column would error and abort the chunk
        // before the hits rollup -- stranding the row with dest_for_view set but
        // its hit counts unrolled.
        $columns = self::redirectsColumnSet($dbCore, $redirectsTable);
        $statements = ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements(
            $redirectsTable,
            $idClause,
            $idClauseBare,
            $recompute,
            isset($columns['dest_sort_key']),
            isset($columns['url_sort_key'])
        );
        foreach ($statements as $statement) {
            if (!self::runChunkWrite($dbCore, $logger, $statement)) {
                return false;
            }
        }

        $hitsStatement = self::buildHitsRollupStatement($dbCore, $redirectsTable, $idClause);
        if ($hitsStatement !== null && !self::runChunkWrite($dbCore, $logger, $hitsStatement)) {
            return false;
        }

        return true;
    }

    /**
     * Build the logshits/last_used rollup UPDATE for a chunk, or null when the
     * logs_hits rollup table is absent (a stripped install, or one whose first
     * rollup rebuild has not run yet, rolls nothing up and keeps the 0 defaults).
     *
     * Reads the pre-aggregated logs_hits rollup, NOT raw logsv2 (report.md
     * Finding 2): the old logsv2 `GROUP BY` ran in full once per chunk and timed
     * out on busy sites. logs_hits is keyed by canonical requested_url and is the
     * same source the real-time write-back uses, so the chunk values stay
     * consistent with what the write-back would set.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param string $redirectsTable
     * @param string $idClause Pre-built " AND r.id IN (...)" fragment.
     * @return string|null
     */
    private static function buildHitsRollupStatement(
        ABJ_404_Solution_DatabaseCore $dbCore,
        string $redirectsTable,
        string $idClause
    ): ?string {
        global $wpdb;
        $logsHitsTable = $dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        if (!isset($wpdb)) {
            return null;
        }
        // Schema existence probe; routing a SHOW TABLES through
        // queryAndGetResults would log a benign "table missing" error on a
        // stripped install before its create-tables flow has run.
        // @utf8-audit: opt-out - $logsHitsTable is an internally resolved plugin table name (doTableNameReplacements); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: SHOW TABLES schema existence probe.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsHitsTable) . "'");
        if ($found !== $logsHitsTable) {
            return null;
        }
        $canonicalRedirectUrl = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $comparableRedirectUrl = $dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $canonicalRedirectUrl,
            array('table' => $logsHitsTable, 'column' => 'requested_url')
        );
        return ABJ_404_Solution_RedirectsDenormColumnSql::buildHitsRollupFromRollupTableStatement(
            array(
                'redirects_table' => $redirectsTable,
                'logs_hits_table' => $logsHitsTable,
                'id_clause' => $idClause,
                'comparable_redirect_url' => $comparableRedirectUrl,
            )
        );
    }

    /**
     * Lowercased column-name set of the redirects table via one SHOW COLUMNS,
     * used to gate the optional narrow sort-key UPDATEs. A failed/empty probe
     * yields an empty set so every sort-key gate degrades to "omit" (the safe
     * fallback: the read path still orders by the wide source column).
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param string $redirectsTable
     * @return array<string, bool>
     */
    private static function redirectsColumnSet(ABJ_404_Solution_DatabaseCore $dbCore, string $redirectsTable): array {
        $result = $dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $redirectsTable,
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
        return $set;
    }

    /**
     * Execute one chunk write, warning and signalling stop on error.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging      $logger
     * @param string                        $statement
     * @return bool
     */
    private static function runChunkWrite(ABJ_404_Solution_DatabaseCore $dbCore, $logger, string $statement): bool {
        $result = $dbCore->queryAndGetResults($statement);
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $logger->warn("RedirectsDenormChunkResolver: stopping after write error: " . $lastError);
            return false;
        }
        return true;
    }
}
