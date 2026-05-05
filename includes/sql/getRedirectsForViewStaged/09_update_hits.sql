
/* S9c: LEFT JOIN the indexed S9 temporary aggregate onto the build buffer
   so the rendered table can sort/show logshits and last_used. Skipped
   entirely (orchestrator never reaches S9) when wp_abj404_logs_hits does
   not exist.

   The preceding temporary aggregate is defensive: the rebuild pipeline already
   produces one row per canonical_url via GROUP BY, but the table schema
   does not enforce UNIQUE on requested_url, so a corrupted/partial
   rebuild could leave duplicates. SUM/MAX on every group keeps the JOIN
   deterministic regardless. */
UPDATE {wp_abj404_view_build} t
LEFT JOIN {wp_abj404_view_build}_hits h
    ON h.requested_url =
       (CONVERT(CONCAT('/', TRIM(BOTH '/' FROM t.url)) USING utf8mb4) COLLATE utf8mb4_bin)
SET
    t.logshits  = h.logshits,
    t.logsid    = h.logsid,
    t.last_used = h.last_used
