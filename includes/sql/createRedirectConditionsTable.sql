
CREATE TABLE IF NOT EXISTS {wp_abj404_redirect_conditions} (
    `id` bigint(30) NOT NULL auto_increment,
    `redirect_id` bigint(30) NOT NULL,
    `logic` varchar(3) NOT NULL DEFAULT 'AND' COMMENT 'AND or OR — how this condition combines with others',
    `condition_type` varchar(32) NOT NULL COMMENT 'login_status|user_role|referrer|user_agent|ip_range|http_header',
    `operator` varchar(16) NOT NULL DEFAULT 'equals' COMMENT 'equals|contains|regex|not_equals|not_contains|cidr',
    `value` varchar(1024) NOT NULL COMMENT 'The value to match against',
    `sort_order` int(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `redirect_id` (`redirect_id`),
    KEY `condition_type` (`condition_type`)
) COMMENT='404 Solution Redirect Conditions Table' AUTO_INCREMENT=1

