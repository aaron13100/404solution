
SELECT DISTINCT requested_url
FROM (
    SELECT requested_url
    FROM {wp_abj404_logsv2} FORCE INDEX (`timestamp`)
    ORDER BY `timestamp` DESC
    LIMIT {recent_window}
) recent_logs
LIMIT {distinct_cap}
