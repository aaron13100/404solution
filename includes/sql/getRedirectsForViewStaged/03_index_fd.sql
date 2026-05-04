
/* S3: indexes used by the per-type UPDATE-JOINs in S4 (POST), S5 (CAT/TAG),
   S6 (HOME), S7 (EXTERNAL), S8 (404 displayed). Composite is added because
   MySQL/MariaDB usually pick one index per table reference per join step
   (no index merge by default), and every per-type UPDATE combines a
   `WHERE type = X` predicate with a `fd_int = ...` join key. */
ALTER TABLE {wp_abj404_view_build}
    ADD INDEX `idx_fd_int`      (`fd_int`),
    ADD INDEX `idx_type`        (`type`),
    ADD INDEX `idx_type_fd_int` (`type`, `fd_int`)
