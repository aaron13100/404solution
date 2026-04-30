
/* Pre-aggregate logsv2 hits by canonical requested_url so URL variants
   (e.g. '/foo', 'foo', '/foo/') collapse into a single rollup row. The
   canonical form is CONCAT('/', TRIM(BOTH '/' FROM url)) — same
   normalization the legacy slash-tolerant joins used. Read-side queries
   apply the identical canonicalization to redirects.url so a single
   indexed BINARY equality lookup against logs_hits.requested_url matches
   every recorded variant.

   The inner join with redirects matches the canonical logsv2 expression
   against the persisted redirects.canonical_url column (added 4.1.10) so
   the redirects side becomes an indexed equality probe instead of
   recomputing CONCAT/TRIM per row. The COALESCE fallback covers rows
   where the chunked backfill hasn't reached yet.

   failed_hits is the count of 404-only hits per canonical URL — i.e.
   logsv2 rows where dest_url is empty/NULL. Used by
   flagDeadDestinationRedirects() to find redirects whose final_dest
   itself is 404'ing, without scanning raw logsv2 in cron. */
SELECT  CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url)) AS requested_url,
        MIN({wp_abj404_logsv2}.id) AS logsid,
        MAX({wp_abj404_logsv2}.timestamp) AS last_used,
        COUNT(*) AS logshits,
        SUM(CASE WHEN {wp_abj404_logsv2}.dest_url = '' OR {wp_abj404_logsv2}.dest_url IS NULL THEN 1 ELSE 0 END) AS failed_hits

FROM    {wp_abj404_logsv2}

        INNER JOIN {wp_abj404_redirects}
        ON CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url)) =
           COALESCE({wp_abj404_redirects}.canonical_url,
                    CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_redirects}.url)))

GROUP BY CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url))
