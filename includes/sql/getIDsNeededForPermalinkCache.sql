
/* This is copied from getPublishedPagesAndPostsIDs.sql and modified to include the permalink cache table. */
select wp_posts.id

from {wp_posts} wp_posts

left outer join (
        /* This selects posts that have the exclude keys set in woocommerce. 
            The exclude keys are all aggregated on one line with group_concat().   */
    	select wptr.object_id,
               group_concat(wpt.name) as grouped_terms
	from {wp_term_relationships} wptr
    
        left outer join {wp_terms} wpt
        on wptr.term_taxonomy_id = wpt.term_id
        and wpt.name in ('exclude-from-search', 'exclude-from-catalog')
    
	where wpt.name is not null

    	group by wptr.object_id

	) usefulterms
    on wp_posts.ID = usefulterms.object_id

    left outer join {wp_abj404_permalink_cache} pc
    on wp_posts.ID = pc.id

where wp_posts.post_status in ('publish', 'published')
      and lcase(wp_posts.post_type) in ({recognizedPostTypes}) /* 'page', 'post', 'product' */
        

and ( usefulterms.grouped_terms is null or 
	  usefulterms.grouped_terms not like '%exclude-from-search%'
	  or usefulterms.grouped_terms not like '%exclude-from-catalog%'
    )

and pc.id is null

