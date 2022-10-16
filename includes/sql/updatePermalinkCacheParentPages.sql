
update {wp_abj404_permalink_cache} updateme

INNER JOIN (
    select main.id as id_to_update,
           /* main.url as old_url, */
           replace(concat(parent.url, main.url), '//', '/') as new_url,
           parent.post_parent as new_post_parent

    from {wp_abj404_permalink_cache} main

    left outer join {wp_abj404_permalink_cache} parent
    on main.post_parent = parent.id

    /* only include pages that have a parent. */
    where main.post_parent != 0
    order by main.id
) newdata

ON updateme.id = newdata.id_to_update

SET updateme.url = newdata.new_url,
 updateme.post_parent = newdata.new_post_parent
 
where updateme.post_parent != 0

