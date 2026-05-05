
/* S5: resolve CAT/TAG-typed redirects against wp_terms. Same shape as
   S4: indexed fd_int drives the join, eq_ref on wp_terms.term_id.

   Resumable batching: invoked once per id-range batch by
   stageUpdateTermsBatched().  Bounds are inclusive on the high side so
   the caller can stride forward by id range without missing rows on
   batch boundaries. */
UPDATE {wp_abj404_view_build} t
LEFT JOIN {wp_terms} term ON term.term_id = t.fd_int
SET
    t.dest_for_view    = COALESCE(term.name, ''),
    t.published_status = CASE WHEN term.term_id IS NULL THEN 0 ELSE 1 END
WHERE t.type IN ({ABJ404_TYPE_CAT}, {ABJ404_TYPE_TAG})
  AND t.id > {LO_BOUND}
  AND t.id <= {HI_BOUND}
