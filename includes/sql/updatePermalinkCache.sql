
insert ignore into {wp_abj404_permalink_cache} (id, url, meta, post_parent, url_length)

/* This selects the permalink for a page ID. */
select 	subTable.*,
		length(subTable.url) 

from (
select  wpp.id as id, 

        case
          when wpp.post_type = 'post' then

          concat(/* wpo_su.option_value, */
            replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(
              BINARY wpo_pls.option_value, 
                BINARY '%year%', date_format(wpp.post_date, '%Y')), 
                BINARY '%monthnum%', date_format(wpp.post_date, '%m')), 
                BINARY '%day%', date_format(wpp.post_date, '%d')), 
                BINARY '%hour%', date_format(wpp.post_date, '%HH')),
                BINARY '%minute%', date_format(wpp.post_date, '%i')),
                BINARY '%second%', date_format(wpp.post_date, '%ss')),
                BINARY '%postname%', wpp.post_name), 
                BINARY '%pagename%', wpp.post_name), 
                BINARY '%post_id%', wpp.id),
                BINARY '%category%', coalesce(category_table.category, '')),
                BINARY '%author%', coalesce(author_table.user_nicename, ''))
          )

          /* pages don't use the permalink structure. */
          else concat(concat('/', BINARY wpp.post_name), '/')
        
        end as url,

        concat(concat(concat(concat('s:', BINARY wpp.post_status), ',t:'), BINARY wpp.post_type), ',') as meta,

        wpp.post_parent as post_parent

from 
  {wp_posts} wpp 

  inner join {wp_options} wpo_pls 
  on wpo_pls.option_name = 'permalink_structure' 

  inner join {wp_options} wpo_su
  on wpo_su.option_name = 'siteurl' 

    /* select the author of a post. */
  left outer join (
    select wpusers.id,
          wpusers.user_nicename
      
    from {wp_users} wpusers
      
    inner join {wp_options} wpo 
    on wpo.option_name = 'permalink_structure' 

    /* Only include this subselect if the author is necessary. */
    where instr(wpo.option_value, '%author%') > 0
  ) author_table on wpp.post_author = author_table.ID

    /* select the category of a post. */
  left outer join (
    select  wtr.object_id ID, 
        min(wpt.slug) category
    from {wp_term_relationships} wtr
      
    inner join {wp_options} wpo 
    on wpo.option_name = 'permalink_structure' 

    inner join {wp_term_taxonomy} wtt
    on wtt.term_taxonomy_id = wtr.term_taxonomy_id
    and wtt.taxonomy in ('category', 'product_cat')

    inner join {wp_terms} wpt 
    on wpt.term_id = wtt.term_id

    /* Only include this subselect if the category is necessary. This speeds up
     the query from .2 to .001 when the category is not used in the permalink structure. */
    where instr(wpo.option_value, '%category%') > 0
    group by wtr.object_id
  ) category_table on wpp.ID = category_table.ID

where
  /* only published posts. */
  wpp.post_status in ('publish', 'published')
) subTable
