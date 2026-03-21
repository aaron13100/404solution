CREATE TABLE IF NOT EXISTS `{wp_abj404_engine_profiles}` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `url_pattern` varchar(500) NOT NULL DEFAULT '',
  `is_regex` tinyint(1) NOT NULL DEFAULT 0,
  `enabled_engines` text NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `priority_status` (`priority`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
