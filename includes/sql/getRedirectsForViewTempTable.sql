
SELECT  requested_url,
        MIN({wp_abj404_logsv2}.id) AS logsid,
        max({wp_abj404_logsv2}.timestamp) as last_used,
        count(requested_url) as logshits

FROM    {wp_abj404_logsv2}

        inner join {wp_abj404_redirects}
        on concat('/', trim(both '/' from {wp_abj404_logsv2}.requested_url)) =
           concat('/', trim(both '/' from {wp_abj404_redirects}.url))

group by requested_url 
