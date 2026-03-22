
select r.* 
from {wp_abj404_redirects} r

left outer join {wp_posts} p
on r.final_dest = p.id

left outer join {wp_terms} t
on r.final_dest = t.term_id

where   r.url in (BINARY '{url1}', BINARY '{url2}')
        /* a disabled value of '1' means in the trash. */
        and r.disabled = 0 
        and r.status in ({ABJ404_STATUS_MANUAL}, {ABJ404_STATUS_AUTO})
        and r.type not in ({ABJ404_TYPE_404_DISPLAYED})

        /* only include the redirect if the page exists, the destination is external,
           the type is homepage (final_dest is always 0 for TYPE_HOME),
           or the redirect code needs no destination (410 Gone, 451 Unavailable). */
        and (p.id is not null or t.term_id is not null or r.type = {ABJ404_TYPE_EXTERNAL}
             or r.type = {ABJ404_TYPE_HOME}
             or r.code in (410, 451))
        and (p.post_status in ('publish', 'published') or r.type != 1)

        /* scheduled redirect: only match within active date range */
        and (r.start_ts IS NULL OR r.start_ts <= UNIX_TIMESTAMP())
        and (r.end_ts IS NULL OR r.end_ts > UNIX_TIMESTAMP())

-- make sure the first url appears first.
order by (CASE
			when r.url = BINARY '{url1}' then 1
			when r.url = BINARY '{url2}' then 2
			else 'Unknown'
         end),
         r.timestamp desc
