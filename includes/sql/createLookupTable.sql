
/* The "vaule" field is 60 because the WordPress username field is 60 characters. 
    The longest location name I found was 44 characters (Armed Forces Europe, Middle East, & Canada).
 */
CREATE TABLE IF NOT EXISTS {wp_abj404_lookup} (
    `id` bigint(20) NOT NULL auto_increment,
    `lkup_value` varchar(60) NOT NULL,
    PRIMARY KEY  (`id`),
    UNIQUE KEY `lkup_value` (`lkup_value`) USING BTREE
) COMMENT='404 Solution Plugin Lookup Table' AUTO_INCREMENT=1

