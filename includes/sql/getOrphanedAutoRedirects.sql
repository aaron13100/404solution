
SELECT r.id, r.url, r.final_dest
FROM {wp_abj404_redirects} r
LEFT JOIN {wp_posts} p ON r.final_dest = p.ID
WHERE r.status = {ABJ404_STATUS_AUTO}
  AND r.disabled = 0
  AND r.type = {ABJ404_TYPE_POST}
  AND (p.ID IS NULL OR p.post_status NOT IN ('publish', 'inherit'))
