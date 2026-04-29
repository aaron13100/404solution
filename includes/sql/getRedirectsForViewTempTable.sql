
/* Pre-aggregate logsv2 hits by canonical requested_url so URL variants
   (e.g. '/foo', 'foo', '/foo/') collapse into a single rollup row. The
   canonical form is CONCAT('/', TRIM(BOTH '/' FROM url)) — same
   normalization the legacy slash-tolerant joins used. Read-side queries
   apply the identical canonicalization to redirects.url so a single
   indexed BINARY equality lookup against logs_hits.requested_url matches
   every recorded variant.

   The inner join with redirects also uses canonical equality so that only
   logged URLs that match a known redirect become rollup rows. */
SELECT  CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url)) AS requested_url,
        MIN({wp_abj404_logsv2}.id) AS logsid,
        MAX({wp_abj404_logsv2}.timestamp) AS last_used,
        COUNT(*) AS logshits

FROM    {wp_abj404_logsv2}

        INNER JOIN {wp_abj404_redirects}
        ON CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url)) =
           CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_redirects}.url))

GROUP BY CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_logsv2}.requested_url))
