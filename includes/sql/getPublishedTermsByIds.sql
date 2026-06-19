
select {wp_terms}.term_id,
       {wp_terms}.name,
       {wp_terms}.slug,
       wp_term_taxonomy.taxonomy

from {wp_terms}

left outer join {wp_term_taxonomy} wp_term_taxonomy
on {wp_terms}.term_id = wp_term_taxonomy.term_id

where wp_term_taxonomy.taxonomy in ({taxonomyFilter})
      and wp_term_taxonomy.count >= 1
      and {wp_terms}.term_id in ({termIds})

order by {wp_terms}.name
