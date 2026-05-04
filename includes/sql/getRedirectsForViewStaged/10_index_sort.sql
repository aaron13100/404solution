
/* S10: indexes used by the read query against the served view_done table.
   Read filters: status IN (...), disabled = ?, optional score-range,
   optional filterText LIKE composite. Read sorts: published_status ASC
   primary, then user-chosen orderby column (url/status/type/code/score/
   timestamp/logshits/last_used/final_dest), then url ASC, then id.

   The composite indexes lead with published_status because that is the
   first ORDER BY key. Trailing column makes the (sort + LIMIT 0,25) shape
   plan-friendly: the planner reads directly off the index in order
   without filesort. status_disabled covers the WHERE filter. */
ALTER TABLE {wp_abj404_view_build}
    ADD INDEX `idx_status_disabled` (`status`, `disabled`),
    ADD INDEX `idx_pub_url`         (`published_status`, `url`(190)),
    ADD INDEX `idx_pub_status`      (`published_status`, `status`),
    ADD INDEX `idx_pub_type`        (`published_status`, `type`),
    ADD INDEX `idx_pub_code`        (`published_status`, `code`),
    ADD INDEX `idx_pub_score`       (`published_status`, `score`),
    ADD INDEX `idx_pub_timestamp`   (`published_status`, `timestamp`),
    ADD INDEX `idx_pub_logshits`    (`published_status`, `logshits`),
    ADD INDEX `idx_pub_last_used`   (`published_status`, `last_used`),
    ADD INDEX `idx_pub_dest`        (`published_status`, `final_dest`(190))
