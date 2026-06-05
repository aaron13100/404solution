
SELECT wpp.id, wpp.post_content
FROM {wp_posts} wpp
INNER JOIN {wp_abj404_permalink_cache} plc ON wpp.id = plc.id
WHERE plc.content_keywords IS NULL
AND wpp.post_status IN ('publish', 'published')
LIMIT {limit-results}
