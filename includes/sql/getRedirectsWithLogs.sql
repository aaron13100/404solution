
select distinct r.id, r.url 
from {wp_abj404_redirects} r
inner join {wp_abj404_logsv2} lv2 on cast(r.url as binary) = cast(lv2.requested_url as binary)
order by url
