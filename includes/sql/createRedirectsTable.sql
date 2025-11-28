
CREATE TABLE IF NOT EXISTS {wp_abj404_redirects} (
    `id` bigint(30) NOT NULL auto_increment,
    `url` varchar(2048) NOT NULL,
    `status` bigint(20) NOT NULL,
    `type` bigint(20) NOT NULL,
    `final_dest` varchar(2048) NOT NULL,
    `code` bigint(20) NOT NULL,
    `disabled` int(10) NOT NULL default 0,
    `timestamp` bigint(30) NOT NULL,
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

