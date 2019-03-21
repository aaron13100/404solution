
CREATE TABLE IF NOT EXISTS `{wp_abj404_permalink_cache}` (
    `id` bigint(20) NOT NULL COMMENT 'corresponds to the wp_posts.id column',
    `url` varchar(2048) NOT NULL COMMENT 'a sometimes updated column that holds URLs for pages',
    `structure` varchar(256) NOT NULL COMMENT 'e.g. /%postname%/ or /%year%/%monthnum%/%postname%/',
PRIMARY KEY (`id`),
INDEX (`structure`)
) ENGINE=MyISAM COMMENT='404 Solution Plugin Permalinks Cache Table'
