insert into {wp_abj404_logsv2} (timestamp, user_ip, referrer, requested_url, dest_url)
SELECT distinct
    ol.timestamp,
    ol.remote_host as user_ip,
    ol.referrer,
    reds.url as requested_url,
    ol.action as dest_url
FROM {wp_abj404_logs} ol
left outer join {wp_abj404_redirects} reds
on ol.redirect_id = reds.id

/* only include rows that have not already been imported (based on the timestamp). 
This is not perfect because a URL may have been requested multiple times at the same instant,
but it's probably good enough. */ 

left outer join {wp_abj404_logsv2} lv2
on ol.timestamp = lv2.timestamp
where lv2.timestamp is null
