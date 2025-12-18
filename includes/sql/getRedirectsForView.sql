
select 

/* this is replaced with either count(*) + start comment or with nothing. */
{selecting-for-count-true-false}

        wp_abj404_redirects.id,
        wp_abj404_redirects.url,
        wp_abj404_redirects.status,
        (CASE
          when wp_abj404_redirects.status = {ABJ404_STATUS_MANUAL} then '{ABJ404_STATUS_MANUAL_text}'
          when wp_abj404_redirects.status = {ABJ404_STATUS_AUTO} then '{ABJ404_STATUS_AUTO_text}'
          when wp_abj404_redirects.status = {ABJ404_STATUS_REGEX} then '{ABJ404_STATUS_REGEX_text}'
          else 'Unknown'
         end) as status_for_view,
        wp_abj404_redirects.type,
        (CASE
          when wp_abj404_redirects.type = {ABJ404_TYPE_EXTERNAL} then '{ABJ404_TYPE_EXTERNAL_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_CAT} then '{ABJ404_TYPE_CAT_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_TAG} then '{ABJ404_TYPE_TAG_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_HOME} then '{ABJ404_TYPE_HOME_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} then 
                CONCAT(UCASE(LEFT(wp_posts.post_type, 1)), LCASE(SUBSTRING(wp_posts.post_type, 2)))
          when wp_abj404_redirects.type = {ABJ404_TYPE_404_DISPLAYED} then '{ABJ404_TYPE_SPECIAL_text}'
          else 'Unknown'
         end) as type_for_view,
        wp_abj404_redirects.final_dest,
        (case
          when wp_abj404_redirects.type = {ABJ404_TYPE_EXTERNAL} then wp_abj404_redirects.final_dest
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} then wp_posts.post_title
          when wp_abj404_redirects.type = {ABJ404_TYPE_CAT} then terms.name
          when wp_abj404_redirects.type = {ABJ404_TYPE_TAG} then terms.name
          when wp_abj404_redirects.type = {ABJ404_TYPE_HOME} then wp_options.option_value
          when wp_abj404_redirects.type = {ABJ404_TYPE_404_DISPLAYED} then '{ABJ404_TYPE_404_DISPLAYED_text}'
          else '? Dest Type'
        end) as dest_for_view,
        
        (case
          when wp_abj404_redirects.type = {ABJ404_TYPE_EXTERNAL} then 1
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} and 
          	lower(wp_posts.post_status) = 'publish' then 1
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} and 
          	lower(wp_posts.post_status) != 'publish' then 0
          when wp_abj404_redirects.type in ({ABJ404_TYPE_CAT}, {ABJ404_TYPE_TAG}) and
          	terms.term_id is not null then 1
          when wp_abj404_redirects.type in ({ABJ404_TYPE_CAT}, {ABJ404_TYPE_TAG}) and
          	terms.term_id is null then 0
          when wp_abj404_redirects.type = {ABJ404_TYPE_HOME} then 1
          when wp_abj404_redirects.type = {ABJ404_TYPE_404_DISPLAYED} then 1
          else '? Dest Type'
        end) as published_status,

        wp_abj404_redirects.code,
        wp_abj404_redirects.timestamp,
        wp_posts.id as wp_post_id,

        {logsTableColumns}

        wp_posts.post_type as wp_post_type

/* This ends a comment when only select for the count(*) */

from    {wp_abj404_redirects} wp_abj404_redirects

        LEFT OUTER JOIN {wp_posts} wp_posts
        on binary wp_abj404_redirects.final_dest = binary wp_posts.id 

        {logsTableJoin}

        left outer join {wp_terms} terms
        on binary wp_abj404_redirects.final_dest = binary terms.term_id

        inner join {wp_options} wp_options
        on binary wp_options.option_name = binary 'blogname'

where 1 and (status in ({statusTypes})) and disabled = {trashValue}

/* {searchFilterForRedirectsExists}
and replace(lower(CONVERT(CONCAT(wp_abj404_redirects.url, '////', 
        (CASE
          when wp_abj404_redirects.status = {ABJ404_STATUS_MANUAL} then '{ABJ404_STATUS_MANUAL_text}'
          when wp_abj404_redirects.status = {ABJ404_STATUS_AUTO} then '{ABJ404_STATUS_AUTO_text}'
          when wp_abj404_redirects.status = {ABJ404_STATUS_REGEX} then '{ABJ404_STATUS_REGEX_text}'
          else 'Unknown'
         end), '////',
        (CASE
          when wp_abj404_redirects.type = {ABJ404_TYPE_EXTERNAL} then '{ABJ404_TYPE_EXTERNAL_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_CAT} then '{ABJ404_TYPE_CAT_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_TAG} then '{ABJ404_TYPE_TAG_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_HOME} then '{ABJ404_TYPE_HOME_text}'
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} then 
                CONCAT(UCASE(LEFT(wp_posts.post_type, 1)), LCASE(SUBSTRING(wp_posts.post_type, 2)))
          else 'Unknown'
         end), '////',
        (case
          when wp_abj404_redirects.type = {ABJ404_TYPE_EXTERNAL} then wp_abj404_redirects.final_dest
          when wp_abj404_redirects.type = {ABJ404_TYPE_POST} then wp_posts.post_title
          when wp_abj404_redirects.type = {ABJ404_TYPE_CAT} then terms.name
          when wp_abj404_redirects.type = {ABJ404_TYPE_TAG} then terms.name
          when wp_abj404_redirects.type = {ABJ404_TYPE_HOME} then wp_options.option_value
          when wp_abj404_redirects.type = {ABJ404_TYPE_404_DISPLAYED} then '(404 page)'
          else '? Dest Type'
        end), '////',
        wp_abj404_redirects.code)
 USING utf8mb4)) COLLATE {wpdb_collate}, ' ', '')
like replace(lower(CONVERT('%{filterText}%' USING utf8mb4)) COLLATE {wpdb_collate}, ' ', '')
/* */

/* {searchFilterForCapturedExists}
and replace(lower(CONVERT(wp_abj404_redirects.url USING utf8mb4)) COLLATE {wpdb_collate}, ' ', '') like replace(lower(CONVERT('%{filterText}%' USING utf8mb4)) COLLATE {wpdb_collate}, ' ', '')
/* */

{orderByString}

limit {limitStart}, {limitEnd}
