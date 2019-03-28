
CREATE TABLE IF NOT EXISTS `{wp_abj404_spelling_cache}` (
    `id` bigint(20) NOT NULL auto_increment,
    `redirect_id` bigint(20) NOT NULL COMMENT 'corresponds to the abj404_redirects.id column',
    `matchdata` varchar(128) NOT NULL COMMENT 'that may match what the user is looking for. e.g. a list of wp_post.ids with post type and score',
PRIMARY KEY (`id`),
INDEX (`redirect_id`)
) ENGINE=MyISAM COMMENT='404 Solution Plugin Spelling Cache Table'
