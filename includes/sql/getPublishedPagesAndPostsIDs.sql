
select wp_posts.id,
       wp_posts.post_type,
       wp_posts.post_parent,
       wp_posts.post_title,

       /* the depth is only here to initialize the value to something. later it's used for sorting. */
       '0' as depth,

       usefulterms.grouped_terms,
       
       plc.url

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

left outer join {wp_abj404_permalink_cache} plc
on wp_posts.ID = plc.id

where wp_posts.post_status in ('publish', 'published')
      and lcase(wp_posts.post_type) in ({recognizedPostTypes}) /* 'page', 'post', 'product' */
        
/* only include this line if a slug has been specified. e.g.
      and post_name = 'specifiedSlug'
{specifiedSlug}
/*  */

/* only include this line if a search term has been specified. e.g.
      and lower(post_name) like 'searchTerm'
{searchTerm}
/*  */

/* only include this line if it's been specified. e.g.
      and abs(plc.url_length - 100) <= 6
{extraWhereClause}
/*  */

and ( usefulterms.grouped_terms is null or 
	  usefulterms.grouped_terms not like '%exclude-from-search%'
	  or usefulterms.grouped_terms not like '%exclude-from-catalog%'
    )

/* order results. e.g order by abs(plc.url_length - 100), wp_posts.ID
{order-results}
/*  */

/* limit results. e.g limit 250
{limit-results}
/*  */
