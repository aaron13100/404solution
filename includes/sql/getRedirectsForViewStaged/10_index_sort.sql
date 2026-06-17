
/* S10: indexes used by the read query against the served view_done table.
   Read filters: status IN (...), disabled = ?, optional score-range,
   optional filterText LIKE composite. Read sorts: user-chosen orderby
   column (url/status/type/code/score/timestamp/logshits/last_used/
   final_dest), then url ASC, then id.

   NOTE: these composite indexes lead with published_status, which used to
   be the forced primary ORDER BY key. published_status is no longer a sort
   key (it is not user-orderable; it only drives the dead-destination
   warning at display time), so a leading-published_status index can no
   longer serve the ORDER BY in index order -- the planner falls back to a
   filesort over at most N redirect rows. These index definitions are left
   as-is pending the larger view-build rework that retires this pipeline;
   re-leading them on the actual sort columns would be churn on DDL that is
   slated for removal. status_disabled covers the WHERE filter. */
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
