
/* only insert if the value does not exist already.
 * From https://stackoverflow.com/a/3164741 */
INSERT INTO {wp_abj404_lookup} (lkup_value)
SELECT * FROM (SELECT '{lkup_value}') AS tmp
WHERE NOT EXISTS (
    SELECT lkup_value FROM {wp_abj404_lookup} WHERE lkup_value = '{lkup_value}'
)

