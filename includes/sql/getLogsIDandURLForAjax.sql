
SELECT requested_url, 
       {wp_abj404_logsv2}.id AS logsid
FROM {wp_abj404_logsv2} 

{where_clause_here}

order by requested_url
