
select {wp_abj404_logsv2}.timestamp, 
       {wp_abj404_logsv2}.user_ip as remote_host, 
       {wp_abj404_logsv2}.referrer,
       {wp_abj404_logsv2}.dest_url as action,
       {wp_abj404_logsv2}.requested_url as url,
       COALESCE({wp_abj404_logsv2}.requested_url_detail, '') AS url_detail,
       usernameLookup.lkup_value as username

from {wp_abj404_logsv2}

     left outer join {wp_abj404_lookup} usernameLookup
     on {wp_abj404_logsv2}.username = usernameLookup.id

where 1

/* {logsid_included}
    and {wp_abj404_logsv2}.requested_url = (select innerid.requested_url from {wp_abj404_logsv2} innerid 
                                            where innerid.id = {logsid} )
/* */

order by {orderby} {order}, 
         {wp_abj404_logsv2}.timestamp desc
limit {start}, {perpage}
