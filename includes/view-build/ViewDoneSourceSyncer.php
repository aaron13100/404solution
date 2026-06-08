<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write-through view_done synchronizer.
 *
 * Owns the `syncViewDoneWithSource()` operation called by every admin write
 * handler (AddRedirectHandler, EditRedirectHandler, the trash handlers, REST
 * mutations, WP-CLI redirect commands) immediately after the source-table
 * mutation lands. The staged-rebuild pipeline (S1..S11 in
 * {@see ABJ_404_Solution_ViewBuildStagePipeline}) is async by design and yields
 * on time budget; without write-through synchronization, view_done lags the
 * source table on every write and the admin tables show stale rows until the
 * next S11 swap.
 *
 * This collaborator is a peer of {@see ABJ_404_Solution_ViewBuildRebuildReconcile}
 * but a distinct responsibility: rebuild-reconcile handles crash recovery and
 * orphan-table cleanup for the staged pipeline, while this class handles
 * routine row-set synchronization on every admin write. Split out 2026-06-08
 * to keep ViewBuildRebuildReconcile.php under the design-audit file-size cap
 * (the docblock at the top of that file always listed three responsibilities
 * for crash/orphan recovery and never mentioned this one).
 *
 * Cross-collaborator access flows through the collaboration context's data
 * bundle, the same way every other ViewBuildCollaborator subclass reaches the
 * wpdb-equivalent layer.
 */
class ABJ_404_Solution_ViewDoneSourceSyncer extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Reconcile view_done with the live wp_abj404_redirects table on the row
     * set, without waiting for the staged-rebuild pipeline (S1..S11) to
     * complete.
     *
     * The staged pipeline is async by design: stages yield on time budget
     * and a single request may run only one or two stages before scheduling
     * the next cron tick. Between the source-table mutation and S11 RENAME
     * swap, view_done is stale: newly INSERTed redirects are invisible,
     * DELETEd rows linger, UPDATEd rows show old values. Production users
     * see this as "I added a redirect but it does not appear in the list
     * until I refresh in a few minutes"; the e2e suite sees it as "created
     * redirect must appear in table" flakes under concurrent load.
     *
     * This method closes the visibility gap surgically on the affected row
     * set, using three operations that mirror the three source-table
     * mutation classes:
     *
     *   - INSERT IGNORE: any source row missing from view_done is added
     *     with the source columns (id, url, status, type, final_dest,
     *     code, disabled, timestamp, engine, score). Derived columns
     *     (status_for_view, type_for_view, dest_for_view,
     *     published_status, fd_int, wp_post_id, wp_post_type, logshits,
     *     logsid, last_used) take the DDL defaults and will be
     *     re-derived by the next S11 swap.
     *
     *   - DELETE LEFT JOIN: any view_done row whose source-table row no
     *     longer exists is removed (orphan cleanup; previously inline in
     *     EmptyCapturedTrashHandler / EmptyRedirectTrashHandler).
     *
     *   - UPDATE INNER JOIN: any view_done row whose source-column
     *     values differ from the live source row is refreshed in place.
     *
     * Each operation is idempotent: running it twice has the same
     * effect as running it once. Safe to call when view_done does not
     * exist (the DAO swallows the missing-table error via log_errors
     * false).
     *
     * The next S11 RENAME swap atomically replaces view_done with a
     * freshly-derived buffer; the rows surgically written here are
     * overwritten with the fully-derived versions then. Until then,
     * the admin sees the correct row set with default-shaped derived
     * columns rather than serving a stale snapshot.
     *
     * @return void
     */
    public function syncViewDoneWithSource(): void {
        // INSERT IGNORE any source rows missing from view_done.
        $this->host->dataBoundary()->queryAndGetResults(
            "INSERT IGNORE INTO {wp_abj404_view_done}"
            . " (id, url, status, type, final_dest, code, disabled, timestamp, engine, score)"
            . " SELECT r.id, r.url, r.status, r.type, r.final_dest, r.code, r.disabled, r.timestamp, r.engine, r.score"
            . " FROM {wp_abj404_redirects} r"
            . " LEFT JOIN {wp_abj404_view_done} vd ON vd.id = r.id"
            . " WHERE vd.id IS NULL",
            array('log_errors' => false)
        );
        // DELETE orphan view_done rows whose source has been removed.
        $this->host->dataBoundary()->queryAndGetResults(
            "DELETE vd FROM {wp_abj404_view_done} vd"
            . " LEFT JOIN {wp_abj404_redirects} r ON vd.id = r.id"
            . " WHERE r.id IS NULL",
            array('log_errors' => false)
        );
        // UPDATE base-column values for rows whose source differs.
        $this->host->dataBoundary()->queryAndGetResults(
            "UPDATE {wp_abj404_view_done} vd"
            . " INNER JOIN {wp_abj404_redirects} r ON vd.id = r.id"
            . " SET vd.url = r.url, vd.status = r.status, vd.type = r.type,"
            . " vd.final_dest = r.final_dest, vd.code = r.code,"
            . " vd.disabled = r.disabled, vd.timestamp = r.timestamp"
            . " WHERE vd.url <> r.url OR vd.status <> r.status OR vd.type <> r.type"
            . " OR vd.final_dest <> r.final_dest OR vd.code <> r.code"
            . " OR vd.disabled <> r.disabled OR vd.timestamp <> r.timestamp",
            array('log_errors' => false)
        );
    }
}
