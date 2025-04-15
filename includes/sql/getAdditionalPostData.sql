
SELECT
    wp_posts.ID                                        AS post_id,
    null                                               AS term_id,
    null                                               AS term_name,
    wp_posts.post_type                                 AS post_type,
    wp_posts.post_author                               AS author_id,
    wp_users.display_name                              AS author_name,
    DATE_FORMAT(wp_posts.post_date, '%Y-%m-%d')        AS formatted_post_date,
    wp_posts.post_name                                 AS slug,
    null                                               AS taxonomy

FROM {wp_posts} wp_posts
    
LEFT OUTER JOIN {wp_users} wp_users  /* to get author display name */
ON wp_posts.post_author = wp_users.ID

WHERE
    wp_posts.ID in ({IDS_TO_INCLUDE})


union

SELECT
    null                                               AS post_id,
    wp_terms.term_id                                   as term_id,
    wp_terms.name                                      as term_name,
    null                                               AS post_type,
    null                                               AS author_id,
    null                                               AS author_name,
    null                                               AS formatted_post_date,
    wp_terms.slug                                      AS slug,
    wp_term_taxonomy.taxonomy                          AS taxonomy

FROM {wp_terms} wp_terms
    
LEFT OUTER JOIN {wp_term_taxonomy} wp_term_taxonomy /* to get the taxonomy name */
ON wp_terms.term_id = wp_term_taxonomy.term_id 

WHERE
    wp_terms.term_id in ({IDS_TO_INCLUDE})

