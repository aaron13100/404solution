
select wp_posts.id,
       wp_posts.post_type,
       wp_posts.post_parent,
       wp_posts.post_title,
       wp_posts.post_status as origPostStatus,
       wp_posts_parents.post_status as parentPostStatus,

       usefulterms.grouped_terms,

       wp_postmeta.meta_id

from {wp_posts} wp_posts

/* we join again here to get the published/not_published status of the parent post. */
inner join {wp_posts} wp_posts_parents
on wp_posts.post_parent = wp_posts_parents.ID

inner join {wp_postmeta} wp_postmeta
on wp_postmeta.meta_key = '_wp_attached_file'
and wp_posts.id = wp_postmeta.post_id

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

where wp_posts_parents.post_status in ('publish', 'published')
        
and ( usefulterms.grouped_terms is null or 
	  usefulterms.grouped_terms not like '%exclude-from-search%'
	  or usefulterms.grouped_terms not like '%exclude-from-catalog%'
    )

