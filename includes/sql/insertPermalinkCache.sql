
INSERT INTO {wp_abj404_permalink_cache} (id, url, `structure`) VALUES 
	('{id}', '{url}', '{structure}')
  ON DUPLICATE KEY UPDATE 
    id = {id},
    url = '{url}',
    `structure` = '{structure}'
