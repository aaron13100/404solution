
/* S6: HOME-typed redirects show the site's blogname as their destination.
   wp_options.option_name is a UNIQUE index in WP core, so this scalar
   subquery reads exactly one row regardless of multisite shape. Missing
   blogname yields '' for dest_for_view (acceptable degraded path). */
UPDATE {wp_abj404_view_build}
SET
    dest_for_view = COALESCE((
        SELECT option_value FROM {wp_options}
        WHERE option_name = 'blogname' LIMIT 1), ''),
    published_status = 1
WHERE type = {ABJ404_TYPE_HOME}
