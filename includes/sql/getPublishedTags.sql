
select wp_terms.term_id,
       wp_terms.name,
       wp_term_taxonomy.taxonomy,
       wp_term_taxonomy.count

from {wp_terms} wp_terms

left outer join {wp_term_taxonomy} wp_term_taxonomy
on wp_terms.term_id = wp_term_taxonomy.term_id

where ( wp_term_taxonomy.taxonomy = 'post_tag' )
      and wp_term_taxonomy.count >= 1

order by wp_terms.name
