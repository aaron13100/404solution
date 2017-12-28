
select {wp_abj404_logsv2}.timestamp, 
       {wp_abj404_logsv2}.user_ip as remote_host, 
       {wp_abj404_logsv2}.referrer,
       {wp_abj404_logsv2}.dest_url as action,
       {wp_abj404_logsv2}.requested_url as url,
       {wp_abj404_logsv2}.requested_url_detail as url_detail,
       {wp_abj404_logsv2}.username as username
from {wp_abj404_logsv2}
where 1

/* {logsid_included}
    and {wp_abj404_logsv2}.requested_url = (select innerid.requested_url from {wp_abj404_logsv2} innerid 
                                            where innerid.id = {logsid} )
/* */

order by {orderby} {order}, 
         {wp_abj404_logsv2}.timestamp desc
limit {start}, {perpage}
