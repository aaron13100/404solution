
select count(id) from {wp_abj404_logsv2} where 1

/* {SPECIFIC_ID}
and requested_url in (select url from {wp_abj404_redirects} where id = {redirect_id})

/*  */
