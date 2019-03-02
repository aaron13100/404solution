
select wpp.id
from {wp_posts} wpp

left outer join {wp_abj404_permalink_cache} pc
on wpp.ID = pc.id

where pc.id is null
