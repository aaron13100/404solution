
select count(id) from {wp_abj404_logsv2} where 1

/* {SPECIFIC_ID}
and requested_url = (select requested_url from {wp_abj404_logsv2} where id = {logID})

/*  */
