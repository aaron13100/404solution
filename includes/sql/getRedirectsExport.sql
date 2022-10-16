
SELECT r.url as from_url,
	   CASE
       		when r.status = 1 then 'Manual'
       		when r.status = 2 then 'Auto'
       		when r.status = 3 then 'Captured'
       		when r.status = 4 then 'Ignored'
       		when r.status = 5 then 'Later'
       		when r.status = 6 then 'Regex'
            else 'unknown'
       end as status,
       CASE
       		when r.type = 0 then '404'
       		when r.type = 1 then 'Page/Post'
       		when r.type = 2 then 'Category'
       		when r.type = 3 then 'Tag'
       		when r.type = 4 then 'External'
       		when r.type = 5 then 'Homepage'
            else 'dunno'
       end as type,
       CASE
       		when pc.url is not null then pc.url
            when r.final_dest = '0' then null
            else r.final_dest
       end as to_url,
       wpp.post_type as type_wp
       
       /* ,
       r.final_dest,
       pc.url,
       pc.meta
       */
       
from {wp_abj404_redirects} r

left outer join {wp_abj404_permalink_cache} pc
on r.final_dest = pc.id

left outer join {wp_posts} wpp
on r.final_dest = wpp.ID


where r.url is not null and r.url != ''

