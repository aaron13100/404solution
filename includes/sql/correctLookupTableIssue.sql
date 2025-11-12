
delete from {wp_abj404_lookup}
where lkup_value in (
    select lkup_value from (
	    SELECT DISTINCT lkup_value
	    FROM {wp_abj404_lookup}
	    group by lkup_value
	    having count(lkup_value) > 1
    ) a
)
