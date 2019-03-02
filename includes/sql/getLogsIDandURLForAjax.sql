
SELECT requested_url, 
       {wp_abj404_logsv2}.id AS logsid
FROM {wp_abj404_logsv2} 

/* e.g. where requested_url like '%a%' */
{where_clause_here}

order by requested_url

/* limit results. e.g limit 250 */
{limit-results}
/*  */
