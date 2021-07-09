
CREATE TABLE IF NOT EXISTS `{wp_abj404_spelling_cache}` (
 `id` bigint(20) NOT NULL AUTO_INCREMENT,
 `url` varchar(2048) NOT NULL COMMENT 'the URL the user requested',
 `matchdata` text not null COMMENT 'data that may match what the user is looking for. e.g. a list of wp_post.ids with post type and score',
PRIMARY KEY (`id`),
 UNIQUE KEY `url` (`url`(190)) USING BTREE
) COMMENT='404 Solution Plugin Spelling Cache Table'
