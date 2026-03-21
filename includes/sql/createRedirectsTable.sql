
CREATE TABLE IF NOT EXISTS {wp_abj404_redirects} (
    `id` bigint(30) NOT NULL auto_increment,
    `url` varchar(2048) NOT NULL,
    `status` bigint(20) NOT NULL,
    `type` bigint(20) NOT NULL,
    `final_dest` varchar(2048) NOT NULL,
    `code` bigint(20) NOT NULL,
    `disabled` int(10) NOT NULL default 0,
    `timestamp` bigint(30) NOT NULL,
    `engine` varchar(64) DEFAULT NULL,
    `score` decimal(5,2) DEFAULT NULL COMMENT 'Match confidence score (0-100), NULL for manual redirects',
    `start_ts` bigint(20) DEFAULT NULL COMMENT 'Unix timestamp when redirect becomes active (NULL = always)',
    `end_ts` bigint(20) DEFAULT NULL COMMENT 'Unix timestamp when redirect expires (NULL = never)',
    PRIMARY KEY  (`id`),
    KEY `status` (`status`),
    KEY `type` (`type`),
    KEY `code` (`code`),
    KEY `timestamp` (`timestamp`),
    KEY `disabled` (`disabled`),
    KEY `url` (`url`(190)) USING BTREE,
    KEY `final_dest` (`final_dest`(190)) USING BTREE,
    KEY `idx_url_disabled_status` (`url`(190), `disabled`, `status`),
    KEY `idx_status_disabled` (`status`, `disabled`)
) COMMENT='404 Solution Plugin Redirects Table' AUTO_INCREMENT=1

