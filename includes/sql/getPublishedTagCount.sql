
select count(distinct wp_terms.term_id) as c

from {wp_terms} wp_terms

left outer join {wp_term_taxonomy} wp_term_taxonomy
on wp_terms.term_id = wp_term_taxonomy.term_id

where ( wp_term_taxonomy.taxonomy = 'post_tag' )
      and wp_term_taxonomy.count >= 1
