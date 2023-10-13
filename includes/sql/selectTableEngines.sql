
select  distinct
		table_name,
		engine

from    information_schema.tables AS tb

where   lower(table_name) like '{wp_prefix}abj404%'
