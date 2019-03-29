
INSERT INTO {wp_abj404_spelling_cache} (url, matchdata) VALUES 
	('{url}','{matchdata}')
  ON DUPLICATE KEY UPDATE matchdata = '{matchdata}'
