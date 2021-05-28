
select  table_schema, 
		table_name

from    information_schema.tables AS tb

where   lower(table_name) like '{wp_prefix}abj404%'
and     lower(`engine`) = 'myisam'
and     table_name not like '{wp_abj404_logsv2}'
