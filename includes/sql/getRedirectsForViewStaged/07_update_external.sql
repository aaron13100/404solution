
/* S7: EXTERNAL redirects display the destination URL itself. */
UPDATE {wp_abj404_view_build}
SET
    dest_for_view = final_dest,
    published_status = 1
WHERE type = {ABJ404_TYPE_EXTERNAL}
