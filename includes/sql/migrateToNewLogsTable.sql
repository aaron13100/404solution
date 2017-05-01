insert into {wp_abj404_logsv2} (timestamp, user_ip, referrer, requested_url, dest_url)
SELECT ol.timestamp,
	ol.remote_host as user_ip,
    ol.referrer,
    reds.url as requested_url,
    ol.action as dest_url
FROM {wp_abj404_logs} ol
left outer join wp_abj404_redirects reds
on ol.redirect_id = reds.id
