
/* Build buffer for the staged getRedirectsForView pipeline. The same DDL
   is used for both `{wp_abj404_view_build}` (populated stage by stage on
   each rebuild) and `{wp_abj404_view_done}` (the served read target after
   the atomic RENAME swap). Per-tab status/disabled/scoreRange/filterText
   filtering is applied at read time against this shared, precomputed
   shape.

   No ENGINE clause: the system default applies. The orchestrator catches
   any storage-engine rejection on CREATE and retries with explicit
   ENGINE=MyISAM, then ENGINE=InnoDB, in that order. */
CREATE TABLE IF NOT EXISTS {wp_abj404_view_build} (
    `id` bigint(30) NOT NULL,
    `url` varchar(2048) NOT NULL,
    `status` bigint(20) NOT NULL,
    `status_for_view` varchar(64) NOT NULL DEFAULT '',
    `type` bigint(20) NOT NULL,
    `type_for_view` varchar(64) NOT NULL DEFAULT '',
    `final_dest` varchar(2048) NOT NULL,
    `dest_for_view` varchar(2048) NOT NULL DEFAULT '',
    `published_status` tinyint(4) NOT NULL DEFAULT 0,
    `code` bigint(20) NOT NULL,
    `disabled` int(10) NOT NULL DEFAULT 0,
    `timestamp` bigint(30) NOT NULL,
    `engine` varchar(64) DEFAULT NULL,
    `score` decimal(5,2) DEFAULT NULL,
    `fd_int` bigint(20) NOT NULL DEFAULT 0,
    `wp_post_id` bigint(20) DEFAULT NULL,
    `wp_post_type` varchar(20) DEFAULT NULL,
    `logshits` bigint(20) DEFAULT NULL,
    `logsid` bigint(20) DEFAULT NULL,
    `last_used` bigint(20) DEFAULT NULL,
    PRIMARY KEY  (`id`)
) COMMENT='404 Solution staged view build buffer'
