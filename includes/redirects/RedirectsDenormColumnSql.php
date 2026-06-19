<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormSortKeyGatingTest and RedirectsDenormHitsRollupSourceTest

/**
 * Single source of truth for the per-redirect-type SQL that resolves the
 * dest_for_view + published_status denorm columns on wp_abj404_redirects.
 *
 * The exact same per-type display mapping (POST -> post_title / publish check,
 * CAT|TAG -> term name, HOME -> blogname, EXTERNAL -> destination URL,
 * 404-displayed -> render-time label, else broken/empty) is needed in two
 * places that run it as bulk SQL:
 *
 *   - the one-time post-upgrade backfill
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}, Step 3a), and
 *   - the real-time recompute on redirect create/edit and on post/term change
 *     ({@see ABJ_404_Solution_RedirectsDenormMaintenanceService}, Step 3c).
 *
 * Keeping the mapping here means the two paths can never drift: a new redirect
 * type or a changed publish rule is edited once and both the backfill and the
 * live maintenance pick it up. (The view's row-by-row PHP resolver,
 * {@see ABJ_404_Solution_RedirectsViewLiveResolver}, mirrors the same mapping in
 * a different execution model for the visible page only.)
 *
 * The builder emits literal-id-scoped statements (ids are cast to int before
 * interpolation, so no parameter binding is needed) and is pure: it touches no
 * database, holds no state, and resolves the WordPress core table names from
 * $wpdb the same way the backfill component did.
 */
class ABJ_404_Solution_RedirectsDenormColumnSql {

    /**
     * wp_options latch names recording that the one-time legacy-row backfill of a
     * narrow sort-key column has fully converged (no row still carries a NULL
     * key). The chunked drain
     * ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}) sets the
     * latch; the admin read path
     * ({@see ABJ_404_Solution_AdminViewReadCoordinator}) consults it before
     * ordering by the sort key. A freshly-upgraded table has the column added but
     * its legacy rows not yet drained (NULL keys), so ordering by the key would
     * bucket every legacy row as equal and fall to id order -- the wrong order.
     * Until the latch is set the read path keeps ordering by the wide source
     * column (correct order, filesort), then switches to the index-ordered key.
     * Co-located with the SQL that populates these columns so the two never drift.
     *
     * @var array<string, string>
     */
    const SORT_KEY_BACKFILL_LATCH_OPTIONS = array(
        'dest_sort_key' => 'abj404_dest_sort_key_backfilled',
        'url_sort_key'  => 'abj404_url_sort_key_backfilled',
    );

    /**
     * The wp_options latch name for a narrow sort-key column, or '' for a column
     * with no latch (so callers skip the read gate / never set a latch for it).
     *
     * @param string $targetColumn One of the SORT_KEY_BACKFILL_LATCH_OPTIONS keys.
     * @return string
     */
    public static function sortKeyBackfillLatchOption(string $targetColumn): string {
        return self::SORT_KEY_BACKFILL_LATCH_OPTIONS[$targetColumn] ?? '';
    }

    /**
     * The composite indexes that make an ORDER BY on each narrow sort-key column
     * index-ordered rather than a filesort. Both must exist for the key to be
     * safe to sort by: the (disabled, key, id) composite serves the unfiltered /
     * multi-status views, and the (status, disabled, key, id) composite serves a
     * single-status-filtered view. These names mirror createRedirectsTable.sql
     * exactly -- the DDL is the source of truth, this is the read-path's record of
     * which indexes back each key so readiness can verify they were actually
     * created (the column-add ALTER and the index-add ALTER can succeed or fail
     * independently of the drain that flips the backfill latch).
     *
     * @var array<string, array<int, string>>
     */
    const SORT_KEY_COMPOSITE_INDEXES = array(
        'dest_sort_key' => array('idx_disabled_dest_sort_id', 'idx_status_disabled_dest_sort_id'),
        'url_sort_key'  => array('idx_disabled_url_sort_id', 'idx_status_disabled_url_sort_id'),
    );

    /**
     * The composite index names that back an index-ordered sort on a narrow
     * sort-key column, or an empty array for a column with no registered
     * composites. Readiness requires every returned index to exist on the table.
     *
     * @param string $targetColumn One of the SORT_KEY_COMPOSITE_INDEXES keys.
     * @return array<int, string>
     */
    public static function sortKeyCompositeIndexNames(string $targetColumn): array {
        return self::SORT_KEY_COMPOSITE_INDEXES[$targetColumn] ?? array();
    }

    /**
     * The wp_options name recording how far the chunked legacy-row drain of a
     * narrow sort-key column has progressed: the highest redirect id drained so
     * far. The drain
     * ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}) advances
     * this as it walks the id space and resets it to 0 once the column converges
     * (it sets the latch instead). Reading cursor / MAX(id) gives the admin
     * "building the index" tooltip a cheap progress fraction WITHOUT a per-load
     * COUNT over the captured majority. Co-located with the latch option above so
     * the drain writer and the progress reader can never drift.
     *
     * @param string $targetColumn One of the SORT_KEY_BACKFILL_LATCH_OPTIONS keys.
     * @return string
     */
    public static function sortKeyBackfillCursorOption(string $targetColumn): string {
        return 'abj404_' . $targetColumn . '_backfill_cursor';
    }

    /**
     * Redirect types that one of the type-specific UPDATE statements resolves.
     * Any row whose type is outside this set is drained by the catch-all to the
     * broken/empty state, exactly as the staged pipeline's else-branch did.
     *
     * @return array<int, int>
     */
    public static function knownTypes(): array {
        return array(
            (int)ABJ404_TYPE_POST,
            (int)ABJ404_TYPE_CAT,
            (int)ABJ404_TYPE_TAG,
            (int)ABJ404_TYPE_HOME,
            (int)ABJ404_TYPE_EXTERNAL,
            (int)ABJ404_TYPE_404_DISPLAYED,
        );
    }

    /**
     * Build the ordered list of UPDATE statements that set dest_for_view +
     * published_status for the redirect rows matched by the given id clauses.
     *
     * The two id-clause forms exist because the POST and CAT/TAG statements
     * alias the redirects table as `r` (they JOIN wp_posts / wp_terms), while
     * the HOME / EXTERNAL / 404 / catch-all statements update the bare table.
     * Callers pass pre-built, already-int-sanitized fragments (or '' for "all
     * rows"); see the backfill and maintenance callers for construction.
     *
     * @param string $redirectsTable Fully-qualified redirects table name.
     * @param string $idClause     " AND r.id IN (1,2,3)" for the aliased UPDATEs, or ''.
     * @param string $idClauseBare " AND id IN (1,2,3)" for the bare UPDATEs, or ''.
     * @param bool   $recompute    How the catch-all isolates rows the type-specific
     *   statements did not set: false (backfill) guards on the dest_for_view IS NULL
     *   sentinel; true (recompute) guards on "type NOT IN (knownTypes)" so already
     *   populated rows of an unknown type are still re-drained.
     * @param bool   $includeDestSortKey Emit the dest_sort_key UPDATE only when the
     *   column exists. The dest_sort_key / url_sort_key column-add ALTER runs
     *   SEPARATELY from the dest_for_view column-add and can lag it; on such a
     *   schema-drifted table emitting an UPDATE against the missing column errors
     *   and aborts the chunk before the hits rollup, stranding the row with
     *   dest_for_view set (so the backfill's NULL sentinel skips it) but its hit
     *   counts unrolled. Callers probe column presence and pass false to omit.
     * @param bool   $includeUrlSortKey  Emit the url_sort_key UPDATE only when the
     *   column exists (same schema-drift reasoning as $includeDestSortKey).
     * @return array<int, string>
     */
    public static function buildDestPublishedStatements(
        string $redirectsTable,
        string $idClause,
        string $idClauseBare,
        bool $recompute,
        bool $includeDestSortKey = true,
        bool $includeUrlSortKey = true
    ): array {
        // Numeric final_dest as an unsigned id; non-numeric collapses to 0 (no
        // match), mirroring the staged fd_int REGEXP guard.
        $fdInt = "CAST(IF(r.final_dest REGEXP '^[0-9]+$', r.final_dest, '0') AS UNSIGNED)";

        $statements = array();

        // S4: POST-typed redirects resolve against wp_posts.
        $postsTable = self::coreTableName('posts');
        $statements[] = "UPDATE " . $redirectsTable . " r" .
            " LEFT JOIN " . $postsTable . " p ON p.ID = " . $fdInt .
            " SET r.dest_for_view = COALESCE(p.post_title, '')," .
            "     r.published_status = CASE" .
            "         WHEN p.ID IS NULL THEN 0" .
            "         WHEN LOWER(p.post_status) = 'publish' THEN 1" .
            "         ELSE 0 END" .
            " WHERE r.type = " . (int)ABJ404_TYPE_POST . $idClause;

        // S5: CAT/TAG-typed redirects resolve against wp_terms.
        $termsTable = self::coreTableName('terms');
        $statements[] = "UPDATE " . $redirectsTable . " r" .
            " LEFT JOIN " . $termsTable . " term ON term.term_id = " . $fdInt .
            " SET r.dest_for_view = COALESCE(term.name, '')," .
            "     r.published_status = CASE WHEN term.term_id IS NULL THEN 0 ELSE 1 END" .
            " WHERE r.type IN (" . (int)ABJ404_TYPE_CAT . ", " . (int)ABJ404_TYPE_TAG . ")" . $idClause;

        // S6: HOME-typed redirects show the site blogname.
        $optionsTable = self::coreTableName('options');
        $statements[] = "UPDATE " . $redirectsTable .
            " SET dest_for_view = COALESCE((SELECT option_value FROM " . $optionsTable .
            "         WHERE option_name = 'blogname' LIMIT 1), '')," .
            "     published_status = 1" .
            " WHERE type = " . (int)ABJ404_TYPE_HOME . $idClauseBare;

        // S7: EXTERNAL redirects display the destination URL itself.
        $statements[] = "UPDATE " . $redirectsTable .
            " SET dest_for_view = final_dest, published_status = 1" .
            " WHERE type = " . (int)ABJ404_TYPE_EXTERNAL . $idClauseBare;

        // S8: 404-displayed (incl. captured) rows use a render-time label.
        $statements[] = "UPDATE " . $redirectsTable .
            " SET dest_for_view = '', published_status = 1" .
            " WHERE type = " . (int)ABJ404_TYPE_404_DISPLAYED . $idClauseBare;

        // Catch-all: any row none of the statements above set lands in a valid
        // (empty, broken) state. The backfill isolates those by the NULL
        // sentinel; the recompute isolates them by "unknown type" because its
        // target rows are already non-NULL.
        $catchAllPredicate = $recompute
            ? "type NOT IN (" . implode(',', self::knownTypes()) . ")"
            : "dest_for_view IS NULL";
        $statements[] = "UPDATE " . $redirectsTable .
            " SET dest_for_view = '', published_status = 0" .
            " WHERE " . $catchAllPredicate . $idClauseBare;

        // Derive the indexable Destination sort key from the dest_for_view value
        // the statements above just set. dest_for_view is varchar(2048) (prefix-
        // indexable only, so ORDER BY on it always filesorts); dest_sort_key is a
        // narrow LEFT(...,191) copy that the (disabled, dest_sort_key, id) /
        // (status, disabled, dest_sort_key, id) composites can fully order. This
        // runs last so every row in scope already has its final dest_for_view.
        // dest_for_view IS NOT NULL skips rows the type statements did not touch.
        // Gated on column presence: the dest_sort_key column-add ALTER runs
        // separately and may lag the dest_for_view add. Emitting this against a
        // missing column would error and abort the chunk before the hits rollup.
        if ($includeDestSortKey) {
            $statements[] = "UPDATE " . $redirectsTable .
                " SET dest_sort_key = LEFT(dest_for_view, 191)" .
                " WHERE dest_for_view IS NOT NULL" . $idClauseBare;
        }

        // Derive the indexable URL sort key the same way. url is varchar(2048)
        // (prefix-indexable only, so ORDER BY url always filesorts even when every
        // value is short, because MySQL will not order by a prefix index); this
        // narrow LEFT(...,191) copy is what the (disabled, url_sort_key, id) /
        // (status, disabled, url_sort_key, id) composites order so the URL sort is
        // index-ordered on the captured tab too. url is NOT NULL, so unlike
        // dest_sort_key this populates on every row in scope (no IS NOT NULL gate
        // needed); the WHERE 1 = 1 keeps the bare id clause well-formed. Gated on
        // column presence for the same schema-drift reason as dest_sort_key.
        if ($includeUrlSortKey) {
            $statements[] = "UPDATE " . $redirectsTable .
                " SET url_sort_key = LEFT(url, 191)" .
                " WHERE 1 = 1" . $idClauseBare;
        }

        return $statements;
    }

    /**
     * Build the logshits/last_used rollup UPDATE that copies the pre-aggregated
     * wp_abj404_logs_hits rollup (keyed by canonical requested_url) onto the
     * matching redirect rows.
     *
     * The single source of truth for the rollup SQL the Step 3a backfill, the
     * Step 3d nightly reconcile (both via
     * {@see ABJ_404_Solution_RedirectsDenormChunkResolver}), and the real-time
     * Step 3c write-back ({@see ABJ_404_Solution_RedirectsDenormMaintenanceService})
     * all run.
     *
     * It reads logs_hits, NOT raw logsv2 (report.md Finding 2): logs_hits is the
     * materialized COUNT/MAX(timestamp)-by-canonical-URL rollup, so this is a
     * scoped index-friendly join with no per-chunk `GROUP BY` over the whole log
     * table -- the repeated full-table aggregate that timed out on busy sites.
     * It is also the consistent source: the write-back already overwrites
     * r.logshits from logs_hits after every rollup, so any value the old
     * logsv2-GROUP BY produced was transient anyway. h.requested_url is the
     * canonical key, so the join matches on COALESCE(canonical_url, CONCAT/TRIM)
     * to stay correct while the redirects-side canonical_url backfill is still in
     * flight. The outer LEFT JOIN resets a no-hit URL to 0/0 rather than keeping
     * a stale count.
     *
     * Pure: the caller resolves and passes the logs_hits table name and the
     * already-int-sanitized id clause; this method touches no database.
     *
     * @param string $redirectsTable Fully-qualified redirects table name.
     * @param string $logsHitsTable  Fully-qualified logs_hits rollup table name.
     * @param string $idClause       " AND r.id IN (1,2,3)" fragment, or '' for all rows.
     * @return string
     */
    public static function buildHitsRollupFromRollupTableStatement(
        string $redirectsTable,
        string $logsHitsTable,
        string $idClause
    ): string {
        $canonRedirect = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";

        return "UPDATE " . $redirectsTable . " r" .
            " LEFT JOIN " . $logsHitsTable . " h ON h.requested_url = " . $canonRedirect .
            " SET r.logshits = COALESCE(h.logshits, 0), r.last_used = COALESCE(h.last_used, 0)" .
            " WHERE 1 = 1" . $idClause;
    }

    /**
     * Resolve a WordPress core table name from $wpdb, falling back to
     * prefix + bare name when the property is absent (e.g. a minimal test wpdb
     * proxy). Avoids depending on $wpdb->tables being populated.
     *
     * @param string $bareName One of 'posts', 'terms', 'options'.
     * @return string
     */
    public static function coreTableName(string $bareName): string {
        global $wpdb;
        if (isset($wpdb->{$bareName}) && is_scalar($wpdb->{$bareName}) && (string)$wpdb->{$bareName} !== '') {
            return (string)$wpdb->{$bareName};
        }
        $prefix = (isset($wpdb->prefix) && is_scalar($wpdb->prefix)) ? (string)$wpdb->prefix : 'wp_';
        return $prefix . $bareName;
    }
}
