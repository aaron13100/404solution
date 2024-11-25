
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

        /* only include the redirect if the page exists or the destination is external. */
        and (p.id is not null or t.term_id is not null or r.type = {ABJ404_TYPE_EXTERNAL})
        and (p.post_status in ('publish', 'published') or r.type != 1)

-- make sure the first url appears first.
order by (CASE
			when r.url = BINARY '{url1}' then 1
			when r.url = BINARY '{url2}' then 2
			else 'Unknown'
         end),
         r.timestamp desc
