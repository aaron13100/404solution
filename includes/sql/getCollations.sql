
SELECT  t.table_schema,
        t.table_name,
        t.table_collation,
        ccsa.character_set_name
FROM information_schema.tables t

inner join information_schema.collation_character_set_applicability ccsa
on t.table_collation = ccsa.collation_name

/* where table_name in ('wp_abj404_redirects', 'wp_posts') */
where t.table_name in ({table_names})
/* and TABLE_SCHEMA = '404-solution' */
and table_schema = '{TABLE_SCHEMA}'
