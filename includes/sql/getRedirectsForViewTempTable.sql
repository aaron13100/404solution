
/* Pre-aggregate logsv2 hits by canonical requested_url so URL variants
   (e.g. '/foo', 'foo', '/foo/') collapse into a single rollup row. The
   canonical form is CONCAT('/', TRIM(BOTH '/' FROM url)) — same
   normalization the legacy slash-tolerant joins used. Read-side queries
   apply the identical canonicalization to redirects.url so a single
   indexed BINARY equality lookup against logs_hits.requested_url matches
   every recorded variant.

   The JOIN reads the persisted canonical_url column on BOTH sides:
   logsv2.canonical_url (added 4.1.x) and redirects.canonical_url
   (added 4.1.10). The rebuild caller refuses to run until both backfills
   are complete, so this SQL keeps the hot join as a clean indexed equality
   with no COALESCE/CONCAT fallback on either side.

   failed_hits is the count of 404-only hits per canonical URL — i.e.
   logsv2 rows where dest_url is empty/NULL. Used by
   flagDeadDestinationRedirects() to find redirects whose final_dest
   itself is 404'ing, without scanning raw logsv2 in cron. */
SELECT  {wp_abj404_logsv2}.canonical_url AS requested_url,
        MIN({wp_abj404_logsv2}.id) AS logsid,
        MAX({wp_abj404_logsv2}.timestamp) AS last_used,
        COUNT(*) AS logshits,
        SUM(CASE WHEN {wp_abj404_logsv2}.dest_url = '' OR {wp_abj404_logsv2}.dest_url IS NULL THEN 1 ELSE 0 END) AS failed_hits

FROM    {wp_abj404_logsv2}

        INNER JOIN {wp_abj404_redirects}
        ON {wp_abj404_logsv2}.canonical_url = {wp_abj404_redirects}.canonical_url

GROUP BY {wp_abj404_logsv2}.canonical_url
