
/* S2: bulk-load every row from the live redirects table into the build
   buffer, with final_dest, code, disabled, timestamp, engine, score, and fd_int
   (CAST(final_dest AS UNSIGNED)) computed inline. dest_for_view and
   published_status start at the column defaults and are filled in
   per-type by the next stages.

   The shared build buffer holds ALL redirects (both active and trashed,
   both redirect statuses and captured statuses). Per-tab status filter,
   disabled filter, score-range filter, and search filter are all applied
   at READ time against the served view_done table.

   Resumable batching: this fragment is invoked once per batch by
   stageInsertRedirectsBatched().  {LO_BOUND} is MAX(id) of the build
   buffer at batch start (0 on first batch), and {BATCH_SIZE} caps how
   many rows the batch copies.  ORDER BY id ASC is required so MAX(id)
   advances strictly, which lets the next batch resume cleanly without
   ever inserting duplicates and without needing INSERT IGNORE. */
INSERT INTO {wp_abj404_view_build}
    (id, url, status, type, final_dest, code, disabled, timestamp, engine, score, fd_int)
SELECT
    id,
    url,
    status,
    type,
    final_dest,
    code,
    disabled,
    timestamp,
    engine,
    score,
    /* fd_int: numeric form of final_dest for the per-type UPDATE-JOINs in
       S4 (wp_posts.ID) and S5 (wp_terms.term_id). Non-numeric final_dest
       (external URLs, empty for HOME/404-displayed) becomes 0, which
       matches the legacy CAST behavior under non-strict sql_mode. The
       REGEXP guard avoids strict-mode CAST errors in MySQL 8+ defaults. */
    CAST(IF(final_dest REGEXP '^[0-9]+$', final_dest, '0') AS UNSIGNED)
FROM {wp_abj404_redirects}
WHERE id > {LO_BOUND}
ORDER BY id ASC
LIMIT {BATCH_SIZE}
