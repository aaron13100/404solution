
select inner_table.*,
       FROM_UNIXTIME(inner_table.most_recent) as last_used_formatted,
       COALESCE(dest_url, permalink) as best_guess_dest
from (
    -- 
    SELECT r.id,
           r.url as from_url,
           l.dest_url,
           UNIX_TIMESTAMP(NOW()) as now,
           r.timestamp as created_date,
           max(l.timestamp) as last_used,
           greatest(
                COALESCE(r.timestamp, max(l.timestamp)), 
                COALESCE(max(l.timestamp), r.timestamp)
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
         left outer join {wp_abj404_logsv2} l
         on r.url = l.requested_url

         left outer join {wp_posts} wpp
         on r.final_dest = wpp.ID

         left outer JOIN {wp_options} wpo
         ON wpo.option_name = 'permalink_structure'

    where r.status in ({status_list})
    group by r.url
) inner_table

where most_recent <= {timelimit}
