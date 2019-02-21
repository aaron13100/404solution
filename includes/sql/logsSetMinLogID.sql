
/* set the min_log_id for later use in the ajax logs dropdown. */

update {wp_abj404_logsv2}
set min_log_id = true

where id in (
    select distinct logsid from (
        SELECT requested_url, 
               Min({wp_abj404_logsv2}.id) AS logsid
        FROM {wp_abj404_logsv2}
        GROUP BY requested_url
    ) bob
)
