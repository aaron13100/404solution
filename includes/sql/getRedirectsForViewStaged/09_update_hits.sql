
/* S9: optional. Pre-aggregate logs_hits by canonical requested_url, then
   LEFT JOIN onto the build buffer so the rendered table can sort/show
   logshits and last_used. Skipped entirely (orchestrator never reads
   this file) when wp_abj404_logs_hits does not exist.

   The grouped subquery is defensive: the rebuild pipeline already
   produces one row per canonical_url via GROUP BY, but the table schema
   does not enforce UNIQUE on requested_url, so a corrupted/partial
   rebuild could leave duplicates. SUM/MAX on every group keeps the JOIN
   deterministic regardless. logshits/logsid/last_used are aggregation
   targets so no per-column index on the source table is needed; the
   existing requested_url(128) prefix index handles the GROUP BY. */
UPDATE {wp_abj404_view_build} t
LEFT JOIN (
    SELECT requested_url,
           SUM(logshits) AS logshits,
           MAX(logsid)   AS logsid,
           MAX(last_used) AS last_used
    FROM {wp_abj404_logs_hits}
    GROUP BY requested_url
) h
    ON BINARY h.requested_url =
       BINARY CONCAT('/', TRIM(BOTH '/' FROM t.url))
SET
    t.logshits  = h.logshits,
    t.logsid    = h.logsid,
    t.last_used = h.last_used
