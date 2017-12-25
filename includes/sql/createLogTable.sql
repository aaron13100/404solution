/* This log table has an advantages over the old one: 
    1) Storing a redirect is not necessary to have a line in the log.
        This allows us to log ignored requests without creating a redirect for them. 
        This is useful when requests are ignored based on the user agent.  
*/

CREATE TABLE IF NOT EXISTS {wp_abj404_logsv2} (
    `id` bigint(40) NOT NULL auto_increment,
    `timestamp` bigint(40) NOT NULL,
    `user_ip` varchar(512) NOT NULL,
    `referrer` varchar(512) NOT NULL,
    `requested_url` varchar(512) NOT NULL,
    `requested_url_detail` varchar(512) DEFAULT NULL,
    `dest_url` varchar(512) NOT NULL,
    PRIMARY KEY  (`id`),
    KEY `timestamp` (`timestamp`),
    KEY `requested_url` (`requested_url`) USING BTREE
) ENGINE=MyISAM character set utf8 COMMENT='404 Solution Plugin Logs Table' AUTO_INCREMENT=1

