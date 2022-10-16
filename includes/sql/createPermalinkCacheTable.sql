
CREATE TABLE IF NOT EXISTS `{wp_abj404_permalink_cache}` (
    `id` bigint(20) NOT NULL COMMENT 'corresponds to the wp_posts.id column',
    `url` varchar(2048) NOT NULL COMMENT 'a sometimes updated column that holds URLs for pages',
    `meta` tinytext NOT NULL COMMENT 'e.g. {t:"post",s:"published"}',
    `url_length` smallint(6) default null,
    `post_parent` bigint(20) NOT NULL COMMENT 'wp_posts.post_parent. helps us add parent page URLs to the URL',
	PRIMARY KEY (`id`),
	key `url_length` (`url_length`)
) COMMENT='404 Solution Plugin Permalinks Cache Table'

