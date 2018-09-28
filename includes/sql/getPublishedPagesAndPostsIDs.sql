
select wp_posts.id,
       wp_posts.post_type,
       wp_posts.post_parent,
       wp_posts.post_title,

       /* the depth is only here to initialize the value to something. later it's used for sorting. */
       '0' as depth,

       usefulterms.grouped_terms

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
    on cast(wp_posts.ID as binary) = cast(usefulterms.object_id as binary)

where cast(wp_posts.post_status as binary) = cast('publish' as binary)
      and lcase(wp_posts.post_type) in ({recognizedPostTypes})
        
/* only include this line if a slug has been specified. e.g.
      and post_name = 'specifiedSlug'
      {specifiedSlug}
/*  */

and ( usefulterms.grouped_terms is null or 
	  usefulterms.grouped_terms not like '%exclude-from-search%'
	  or usefulterms.grouped_terms not like '%exclude-from-catalog%'
    )

