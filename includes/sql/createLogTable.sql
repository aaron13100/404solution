/* The "username" and "location" fields reference a lookup table.

    This log table has an advantages over the old one: 
    1) Storing a redirect is not necessary to have a line in the log.
        This allows us to log ignored requests without creating a redirect for them. 
        This is useful when requests are ignored based on the user agent.  
*/

CREATE TABLE IF NOT EXISTS {wp_abj404_logsv2} (
    `id` bigint(40) NOT NULL auto_increment,
    `timestamp` bigint(40) NOT NULL,
    `user_ip` varchar(512) NOT NULL,
    `referrer` varchar(512) DEFAULT NULL,
    `requested_url` varchar(2048) NOT NULL,
    `requested_url_detail` varchar(2048) DEFAULT NULL,
    `username` bigint(20) DEFAULT NULL,
    `dest_url` varchar(512) NOT NULL,
    `min_log_id` tinyint(1) DEFAULT NULL,
    `engine` varchar(64) DEFAULT NULL,
    `pipeline_trace` blob DEFAULT NULL,
    `canonical_url` varchar(2048) DEFAULT NULL COMMENT 'Cached CONCAT(/, TRIM(BOTH / FROM requested_url)) so the logs_hits rebuild JOIN against redirects.canonical_url is index-friendly. Plain (not VIRTUAL) for MySQL 5.6 / 5.7.0-5.7.5 compatibility. Populated at insert time by sanitizeLogEntry; legacy NULL rows backfilled by backfillLogsv2CanonicalUrl().',
    PRIMARY KEY  (`id`),
    KEY `timestamp` (`timestamp`),
    KEY `requested_url` (`requested_url`(190)) USING BTREE,
    KEY `username` (`username`) USING BTREE,
    KEY `min_log_id` (`min_log_id`),
    KEY `idx_requested_url_timestamp` (`requested_url`(190), `timestamp`),
    KEY `idx_canonical_url` (`canonical_url`(190)) USING BTREE
) COMMENT='404 Solution Plugin Logs Table.' AUTO_INCREMENT=1

