
select r.* 
from {wp_abj404_redirects} r

left outer join {wp_posts} p
on r.final_dest = p.id

where   r.url = '{url}'
        /* a disabled value of '1' means in the trash. */
        and r.disabled = 0 
        and r.status in ({ABJ404_STATUS_MANUAL}, {ABJ404_STATUS_AUTO})
        and r.type not in ({ABJ404_TYPE_404_DISPLAYED})

        /* only include the redirect if the page exists or the destination is external. */
        and (p.id is not null or r.type = {ABJ404_TYPE_EXTERNAL})

order by r.timestamp DESC
