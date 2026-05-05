/* S9a: explicitly materialized, indexed hit-count aggregate.

   MySQL can materialize a GROUP BY derived table from logs_hits without an
   index, then scan that derived table once per view_build row. Creating our
   own temporary table with an index keeps the defensive duplicate collapse
   while preserving indexed equality lookups for the UPDATE that follows. */
CREATE TEMPORARY TABLE {wp_abj404_view_build}_hits (
    `requested_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `logshits` bigint(21) DEFAULT NULL,
    `logsid` bigint(40) DEFAULT NULL,
    `last_used` bigint(40) DEFAULT NULL,
    KEY `requested_url` (`requested_url`(128))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
