
/* we keep a separate lookup table instead of using the wordpress users table
    because a user may be deleted from that table while they still exist 
    in our log file. */

/* TODO: change this to an "insert ignore into" ... because using the 
    "where not exists" ... caused a deadlock once. */

/* only insert if the value does not exist already.
 * From https://stackoverflow.com/a/3164741 */
INSERT INTO {wp_abj404_lookup} (lkup_value)
SELECT * FROM (SELECT '{lkup_value}') AS tmp
WHERE NOT EXISTS (
    SELECT lkup_value FROM {wp_abj404_lookup} WHERE lkup_value = '{lkup_value}'
)

