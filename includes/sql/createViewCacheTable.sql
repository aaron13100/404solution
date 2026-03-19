
CREATE TABLE IF NOT EXISTS {wp_abj404_view_cache} (
    `id` bigint(20) NOT NULL auto_increment,
    `cache_key` varchar(64) NOT NULL,
    `subpage` varchar(64) NOT NULL default '',
    `payload` longtext NOT NULL,
    `payload_bytes` int(10) unsigned NOT NULL default 0,
    `refreshed_at` bigint(20) NOT NULL default 0,
    `expires_at` bigint(20) NOT NULL default 0,
    `updated_at` bigint(20) NOT NULL default 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `cache_key` (`cache_key`),
    KEY `expires_at` (`expires_at`),
    KEY `refreshed_at` (`refreshed_at`)
) COMMENT='404 Solution View Snapshot Cache Table'
