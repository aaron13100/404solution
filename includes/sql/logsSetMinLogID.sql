
/* set the min_log_id for later use in the ajax logs dropdown. */

update {wp_abj404_logsv2}
set min_log_id = true

where id in (
    select logsid from (
        select grouped.logsid
        from (
            select requested_url, min(id) as logsid
            from {wp_abj404_logsv2}
            group by requested_url
        ) grouped
        inner join {wp_abj404_logsv2} current_row on current_row.id = grouped.logsid
        where current_row.min_log_id is null or current_row.min_log_id <> true
        order by grouped.logsid asc
        limit 500
    ) bounded_backfill
)
