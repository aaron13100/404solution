
SELECT requested_url, 
       Min({wp_abj404_logsv2}.id) AS logsid, 
       max({wp_abj404_logsv2}.timestamp) as last_used,
       Count(requested_url)      AS logshits 
FROM {wp_abj404_logsv2} 

/* where requested_url = '" . $specificURL . "'"; */
{where_clause_here}

GROUP BY requested_url
order by requested_url
