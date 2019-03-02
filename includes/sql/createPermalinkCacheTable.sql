
CREATE TABLE `wp_abj404_permalink_cache` (
    `id` bigint(20) NOT NULL COMMENT 'corresponds to the wp_posts.id column',
    `rowType` varchar(32) NOT NULL,
    `url` varchar(2048) NOT NULL COMMENT 'a sometimes updated column that holds URLs for pages',
PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='404 Solution Plugin Permalinks Cache Table'
