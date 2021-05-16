
insert ignore into {wp_abj404_permalink_cache} (id, url, structure)

/* This selects the permalink for a page ID. */
select  wpp.id as id, 

        concat(wpo_su.option_value,
          replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(
            wpo_pls.option_value, 
              '%year%', date_format(wpp.post_date, '%Y')), 
              '%monthnum%', date_format(wpp.post_date, '%m')), 
              '%day%', date_format(wpp.post_date, '%d')), 
              '%hour%', date_format(wpp.post_date, '%HH')),
              '%minute%', date_format(wpp.post_date, '%i')),
              '%second%', date_format(wpp.post_date, '%ss')),
              '%postname%', wpp.post_name), 
              '%pagename%', wpp.post_name), 
              '%post_id%', wpp.id),
              '%category%', coalesce(category_table.category, '')),
              '%author%', wpusers.user_nicename)
        ) as url,

        wpo_pls.option_value as structure
from 
  {wp_posts} wpp 

  inner join {wp_options} wpo_pls 
  on wpo_pls.option_name = 'permalink_structure' 

  inner join {wp_options} wpo_su
  on wpo_su.option_name = 'siteurl' 

  left outer join {wp_users} wpusers
  on wpp.post_author = wpusers.id

    /* select the category of a post. */
  left outer join (
    select  wtr.object_id ID, 
        min(wpt.slug) category
    from {wp_term_relationships} wtr
      
    inner join {wp_options} wpo 
    on wpo.option_name = 'permalink_structure' 

    inner join {wp_term_taxonomy} wtt
    on wtt.term_taxonomy_id = wtr.term_taxonomy_id
    and wtt.taxonomy = 'category' 

    inner join {wp_terms} wpt 
    on wpt.term_id = wtt.term_id

    /* Only include this subselect if the category is necessary. This speeds up
     the query from .2 to .001 when the category is not used in the permalink structure. */
    where instr(wpo.option_value, '%category%') > 0
    group by wtr.object_id
  ) category_table on wpp.ID = category_table.ID

where
  /* only published posts. */
  wpp.post_status = 'publish'

