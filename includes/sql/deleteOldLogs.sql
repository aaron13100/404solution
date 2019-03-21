
DELETE FROM {wp_abj404_logsv2}
WHERE timestamp IS NOT NULL 
ORDER BY timestamp asc 
LIMIT {lines_to_delete};
