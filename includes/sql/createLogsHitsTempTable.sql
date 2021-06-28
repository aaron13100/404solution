
CREATE TABLE IF NOT EXISTS {wp_abj404_logs_hits}_temp (
 `requested_url` varchar(2048) CHARACTER SET utf8 NOT NULL,
 `logsid` bigint(40) DEFAULT NULL,
 `last_used` bigint(40),
 `logshits` bigint(21) NOT NULL DEFAULT '0',
 KEY `requested_url` (`requested_url`(128))
) DEFAULT CHARSET=utf8mb4
