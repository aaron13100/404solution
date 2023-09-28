
select {wp_terms}.term_id,
       {wp_terms}.name,
       {wp_terms}.slug,
       wp_term_taxonomy.taxonomy,
       wp_term_taxonomy.count,
       'in code' as url

from {wp_terms}

left outer join {wp_term_taxonomy} wp_term_taxonomy
on {wp_terms}.term_id = wp_term_taxonomy.term_id

/* the recognizedCategories variable holds user defined taxonomies */
where ( wp_term_taxonomy.taxonomy = 'category' or lower({wp_terms}.name) in ({recognizedCategories}) 
        or lower(wp_term_taxonomy.taxonomy) in ({recognizedCategories}) )
      and wp_term_taxonomy.count >= 1

/* only include this line if an ID has been specified. e.g.
      and wp_terms.term_id = 74
      {term_id}
/*  */

/* only include this line if a slug has been specified. e.g.
      and wp_terms.slug = 'about'
      {slug}
/*  */

order by {wp_terms}.name
