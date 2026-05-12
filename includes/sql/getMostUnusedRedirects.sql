
select inner_table.*,
       FROM_UNIXTIME(inner_table.most_recent) as last_used_formatted,
       COALESCE(dest_url, permalink) as best_guess_dest
from (
    -- F6 audit: use the pre-aggregated logs_hits rollup instead of scanning
    -- raw logsv2. h.last_used is MAX(logsv2.timestamp) per canonical
    -- requested_url, refreshed by createRedirectsForViewHitsTable. The join
    -- key uses redirects.canonical_url (which logs_hits.requested_url is
    -- stored in the same canonical form) with a fallback to r.url so the
    -- pre-backfill window behaves no worse than the legacy logsv2 join.
    -- The l-side LEFT JOIN by h.logsid is a single-row PK probe that
    -- recovers the "any dest_url" value the old GROUP BY r.url query
    -- produced; only consumed by the debug log message in
    -- deleteOldRedirectsByType().
    SELECT r.id,
           r.url as from_url,
           l.dest_url,
           UNIX_TIMESTAMP(NOW()) as now,
           r.timestamp as created_date,
           h.last_used as last_used,
           greatest(
                COALESCE(r.timestamp, h.last_used),
                COALESCE(h.last_used, r.timestamp)
           ) as most_recent,

           Replace(
              Replace(
                 Replace(
                    Replace(wpo.option_value, '%year%', Date_format(wpp.post_date, '%Y')),
                    '%monthnum%', Date_format(wpp.post_date, '%m')),
                 '%day%', Date_format(wpp.post_date, '%d')),
              '%postname%', wpp.post_name)
           AS permalink

    FROM {wp_abj404_redirects} r
         left outer join {wp_abj404_logs_hits} h
            on h.requested_url = COALESCE(r.canonical_url, r.url)

         left outer join {wp_abj404_logsv2} l
            on l.id = h.logsid

         left outer join {wp_posts} wpp
         on r.final_dest = wpp.ID

         left outer JOIN {wp_options} wpo
         ON wpo.option_name = 'permalink_structure'

    where r.status in ({status_list})
      and r.disabled = 0
) inner_table

where most_recent <= {timelimit}
