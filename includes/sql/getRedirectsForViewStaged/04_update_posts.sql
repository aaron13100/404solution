
/* S4: resolve POST-typed redirects against wp_posts. Drives the JOIN via
   the indexed fd_int column (a real BIGINT, no per-row CAST needed),
   eq_ref lookup on wp_posts.ID. dest_for_view, wp_post_id, wp_post_type,
   type_for_view (capitalized post_type), and published_status are all
   populated in this single UPDATE so wp_posts is touched once per
   POST-typed redirect, not three times.

   When the joined post does not exist (final_dest points at a deleted
   ID), wp_posts.ID is NULL and:
     - dest_for_view stays the column default (empty string)
     - wp_post_id, wp_post_type stay NULL
     - published_status becomes 0 (matches legacy behavior, drives the
       red "broken destination" badge in the admin UI).

   Resumable batching: invoked once per id-range batch by
   stageUpdatePostsBatched().  Bounds are inclusive on the high side so
   the caller can stride forward by id range without missing rows on
   batch boundaries. */
UPDATE {wp_abj404_view_build} t
LEFT JOIN {wp_posts} p ON p.ID = t.fd_int
SET
    t.dest_for_view = COALESCE(p.post_title, ''),
    t.wp_post_id    = p.ID,
    t.wp_post_type  = p.post_type,
    t.type_for_view = COALESCE(
        CONCAT(UCASE(LEFT(p.post_type, 1)), LCASE(SUBSTRING(p.post_type, 2))),
        ''),
    t.published_status = CASE
        WHEN p.ID IS NULL                     THEN 0
        WHEN LOWER(p.post_status) = 'publish' THEN 1
        ELSE 0
    END
WHERE t.type = {ABJ404_TYPE_POST}
  AND t.id > {LO_BOUND}
  AND t.id <= {HI_BOUND}
