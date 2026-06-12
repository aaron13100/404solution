
/* S8: 404-displayed redirects use a render-time translated destination label.
   Captured 404 rows also fall in this branch (status = captured/ignored/later,
   type = 404_displayed). */
UPDATE {wp_abj404_view_build}
SET
    dest_for_view = '',
    published_status = 1
WHERE type = {ABJ404_TYPE_404_DISPLAYED}
