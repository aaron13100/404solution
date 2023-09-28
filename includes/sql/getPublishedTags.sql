
select wp_terms.term_id,
       wp_terms.name,
       wp_terms.slug,
       wp_term_taxonomy.taxonomy,
       wp_term_taxonomy.count,
       'in code' as url

from {wp_terms} wp_terms

left outer join {wp_term_taxonomy} wp_term_taxonomy
on wp_terms.term_id = wp_term_taxonomy.term_id

where ( wp_term_taxonomy.taxonomy = 'post_tag' )
      and wp_term_taxonomy.count >= 1

/* only include this line if a slug has been specified. e.g.
      and wp_terms.slug = 'about'
      {slug}
/*  */

order by wp_terms.name
