
SELECT DISTINCT requested_url
FROM (
    SELECT requested_url
    FROM {wp_abj404_logsv2} FORCE INDEX (`timestamp`)
    ORDER BY `timestamp` DESC
    LIMIT 5000
) recent_logs
LIMIT 500
