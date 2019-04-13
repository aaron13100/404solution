
select 
        wp_abj404_redirects.id,
        wp_abj404_redirects.url,
        wp_abj404_redirects.status,
        wp_abj404_redirects.type,
        wp_abj404_redirects.final_dest,
        wp_abj404_redirects.code,
        wp_abj404_redirects.timestamp,
        wp_posts.id as wp_post_id,

        {logsTableColumns}

        wp_posts.post_type as wp_post_type

from    {wp_abj404_redirects} wp_abj404_redirects

        LEFT OUTER JOIN {wp_posts} wp_posts
        on wp_abj404_redirects.final_dest = wp_posts.id 

        {logsTableJoin}


where 1 and (status in ({statusTypes})) and disabled = {trashValue}

{orderByString}

limit {limitStart}, {limitEnd}
