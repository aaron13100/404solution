/* S9b: collapse any duplicate logs_hits rows once, outside the UPDATE JOIN. */
INSERT INTO {wp_abj404_view_build}_hits
    (requested_url, logshits, logsid, last_used)
SELECT requested_url,
       SUM(logshits) AS logshits,
       MAX(logsid) AS logsid,
       MAX(last_used) AS last_used
FROM {wp_abj404_logs_hits}
GROUP BY requested_url
