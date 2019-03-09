
RENAME TABLE {wp_abj404_woocommerce_cache} TO {wp_abj404_woocommerce_cache_temp}; -- WP SPLIT HERE

RENAME TABLE {wp_abj404_woocommerce_cache_2} TO {wp_abj404_woocommerce_cache}; -- WP SPLIT HERE

drop table {wp_abj404_woocommerce_cache_temp}; -- WP SPLIT HERE

