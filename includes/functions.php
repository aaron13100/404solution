<?php
/*
 * 404 Manager Global Functions
 *
*/

register_activation_hook( ABJ404_NAME, 'abj404_pluginActivation' );
register_deactivation_hook( ABJ404_NAME, 'abj404_pluginRemove' );

add_action( 'abj404_duplicateCronAction', 'abj404_removeDuplicatesCron' );
add_action( 'abj404_cleanupCronAction', 'abj404_cleaningCron' );


function abj404_updateDBVersion() {
	$options = abj404_getOptions( 1 );

	$options['DB_VERSION'] = ABJ404_VERSION;

	update_option( 'abj404_settings', $options );

	return $options;
}

function abj404_getOptions( $skip_db_check="0" ) {
	$options = get_option( 'abj404_settings' );

	if ( $options == "" ) {
		add_option( 'abj404_settings', '', '', 'no' );
	}

	// Check to make sure we aren't missing any new options.
	$defaults = abj404_getDefaultOptions();
	$missing = false;
	foreach ( $defaults as $key => $value ) {
		if ( ! isset( $options[ $key ] ) || '' === $options[ $key ] ) {
			$options[ $key ] = $value;
			$missing = true;
		}
	}

	if ( $missing ) {
		update_option( 'abj404_settings', $options );
	}

	if ( $skip_db_check == "0" ) {
		if ( $options['DB_VERSION'] != ABJ404_VERSION ) {
			if ( ABJ404_VERSION == "1.3.2" ) {
				//Unregister all crons. Some were bad.
				abj404_unregisterCrons();

				//Register the good ones
				abj404_registerCrons();
			}
			$options = abj404_updateDBVersion();
		}
	}

	return $options;
}

function abj404_getDefaultOptions() {
	$options = array(
		'default_redirect' => '301',
		'capture_404' => '1',
		'capture_deletion' => 1095,
		'manual_deletion' => '0',
		'admin_notification' => '200',
		'remove_matches' => '1',
		'display_suggest' => '1',
		'suggest_minscore' => '25',
		'suggest_max' => '5',
		'suggest_title' => '<h3>' . __( 'Suggested Alternatives', '404-solution' ) . '</h3>',
		'suggest_before' => '<ol>',
		'suggest_after' => '</ol>',
		'suggest_entrybefore' => '<li>',
		'suggest_entryafter' => '</li>',
		'suggest_noresults' => '<p>' . __( 'No Results To Display.', '404-solution' ) . '</p>',
		'suggest_cats' => '1',
		'suggest_tags' => '1',
		'auto_redirects' => '1',
		'auto_score' => '90',
		'auto_deletion' => '1095',
		'auto_cats' => '1',
		'auto_tags' => '1',
		'force_permalinks' => '1',
                'dest404page' => 'none',
	);
	return $options;
}

function abj404_pluginActivation() {
	global $wpdb;
	add_option( 'abj404_settings', '', '', 'no' );

	$charset_collate = '';
	if ( !empty( $wpdb->charset ) ) {
		$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
	}
	if ( !empty( $wpdb->collate ) ) {
		$charset_collate .= " COLLATE $wpdb->collate";
	}
	$query = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "abj404_redirects` (
	  `id` bigint(30) NOT NULL auto_increment,
	  `url` varchar(512) NOT NULL,
	  `status` bigint(20) NOT NULL,
	  `type` bigint(20) NOT NULL,
	  `final_dest` varchar(512) NOT NULL,
	  `code` bigint(20) NOT NULL,
	  `disabled` int(10) NOT NULL default '0',
	  `timestamp` bigint(30) NOT NULL,
	  PRIMARY KEY  (`id`),
	  KEY `status` (`status`),
	  KEY `type` (`type`),
	  KEY `code` (`code`),
	  KEY `timestamp` (`timestamp`),
	  KEY `disabled` (`disabled`),
	  FULLTEXT KEY `url` (`url`),
	  FULLTEXT KEY `final_dest` (`final_dest`)
	) ENGINE=MyISAM " . esc_html( $charset_collate ) . " COMMENT='404 Solution Plugin Redirects Table' AUTO_INCREMENT=1";
	$wpdb->query( $query );

	$query = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "abj404_logs` (
	  `id` bigint(40) NOT NULL auto_increment,
	  `redirect_id` bigint(40) NOT NULL,
	  `timestamp` bigint(40) NOT NULL,
	  `remote_host` varchar(512) NOT NULL,
	  `referrer` varchar(512) NOT NULL,
	  `action` varchar(512) NOT NULL,
	  PRIMARY KEY  (`id`),
	  KEY `redirect_id` (`redirect_id`),
	  KEY `timestamp` (`timestamp`)
	) ENGINE=MyISAM " . esc_html( $charset_collate ) . " COMMENT='404 Solution Plugin Logs Table' AUTO_INCREMENT=1";
	$wpdb->query( $query );

	abj404_registerCrons();

	$options = abj404_updateDBVersion();
        
        // TODO: optionally drop these tables and delete the options on the uninstall hook (not the deactivation hook).
        // see: https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
}

function abj404_registerCrons() {
	$timestamp = wp_next_scheduled( 'abj404_cleanupCronAction' );
	if ( $timestamp == False ) {
		wp_schedule_event( current_time( 'timestamp' ) - 86400, 'daily', 'abj404_cleanupCronAction' );
	}

	$timestamp = wp_next_scheduled( 'abj404_duplicateCronAction' );
	if ( $timestamp == False ) {
		wp_schedule_event( current_time( 'timestamp' ) - 3600, 'hourly', 'abj404_duplicateCronAction' );
	}
}

function abj404_unregisterCrons() {

	$crons = array( 'abj404_cleanupCronAction', 'abj404_duplicateCronAction', 'abj404_removeDuplicatesCron', 'abj404_cleaningCron' );
	for ( $i=0; $i < count( $crons ); $i++ ) {
		$cron_name = $crons[$i];
		$timestamp = wp_next_scheduled( $cron_name );
		while ( $timestamp != False ) {
			wp_unschedule_event( $timestamp, $cron_name );
			$timestamp = wp_next_scheduled( $cron_name );
		}

		$timestamp = wp_next_scheduled( $cron_name, '' );
		while ( $timestamp != False ) {
			wp_unschedule_event( $timestamp, $cron_name, '' );
			$timestamp = wp_next_scheduled( $cron_name, '' );
		}

		wp_clear_scheduled_hook( $cron_name );
	}
}

function abj404_pluginRemove() {
	//delete_option( 'abj404_settings' );
	abj404_unregisterCrons();
}

function abj404_rankPermalinks( $url, $includeCats = '1', $includeTags = '1' ) {
	global $wpdb;
	$permalinks = array();

	$query = "select id from $wpdb->posts where post_status='publish' and (post_type='page' or post_type='post')";
	$rows = $wpdb->get_results( $query );
	foreach ( $rows as $row ) {
		$id = $row->id;
		$the_permalink = get_permalink( $id );
		$urlParts = parse_url( $the_permalink );
		$scoreBasis = strlen( $urlParts['path'] );
		$levscore = levenshtein( $url, $urlParts['path'], 1, 1, 1 );
		$score = 100 - ( ( $levscore / $scoreBasis )*100 );
		$permalinks[$id . "|POST"] = number_format( $score, 4, '.', '' );
                
                // if the slug is in the URL then the user wants the post with the same slug.
                // to avoid an issue where a slug is a subset of another slug, we prefer the matching slug
                // with the longest length. e.g.
                /* url from user: www.site.com/a-post-slug
                 * post 1 slug: a-post-slug  // matches (contained in url).
                 * post 2 slug: a-post-slug-longer // does not match the url (not contained in url).
                 * 
                /* url from user: www.site.com/a-post-slug-longer
                 * post 1 slug: a-post-slug  // matches with a score + length of the slug.
                 * post 2 slug: a-post-slug-longer // matches with a score + length of the slug.
                 * 
                 * therefore the longer slug has a higher score and takes priority.
                 * this is important for when permalinks change.
                 */
                $post = get_post($id);
                $postSlug = strtolower($post->post_name);
                if (strpos(strtolower($url), $postSlug) !== false) {
        		$permalinks[$id . "|POST"] = number_format( 100 + strlen($postSlug), 4, '.', '' );
                }
	}

	if ( $includeTags == "1" ) {
		$query = "select " . esc_html( $wpdb->terms ) . ".term_id from " . esc_html( $wpdb->terms ) . " ";
		$query .= "left outer join " . esc_html( $wpdb->term_taxonomy ) . " on " . esc_html( $wpdb->terms ) . ".term_id = " . esc_html( $wpdb->term_taxonomy ) . ".term_id ";
		$query .= "where " . esc_html( $wpdb->term_taxonomy ) . ".taxonomy='post_tag' and " . esc_html( $wpdb->term_taxonomy ) . ".count >= 1";
		$rows = $wpdb->get_results( $query );
		foreach ( $rows as $row ) {
			$id = $row->term_id;
			$the_permalink = get_tag_link( $id );
			$urlParts = parse_url( $the_permalink );
			$scoreBasis = strlen( $urlParts['path'] );
			$levscore = levenshtein( $url, $urlParts['path'], 1, 1, 1 );
			$score = 100 - ( ( $levscore / $scoreBasis )*100 );
			$permalinks[$id . "|TAG"] = number_format( $score, 4, '.', '' );
		}
	}

	if ( $includeCats == "1" ) {
		$query = "select " . esc_html( $wpdb->terms ) . ".term_id from " . esc_html( $wpdb->terms ) . " ";
		$query .= "left outer join " . esc_html( $wpdb->term_taxonomy ) . " on " . esc_html( $wpdb->terms ) . ".term_id = " . esc_html( $wpdb->term_taxonomy ) . ".term_id ";
		$query .= "where " . esc_html( $wpdb->term_taxonomy ) . ".taxonomy='category' and " . esc_html( $wpdb->term_taxonomy ) . ".count >= 1";
		$rows = $wpdb->get_results( $query );
		foreach ( $rows as $row ) {
			$id = $row->term_id;
			$the_permalink = get_category_link( $id );
			$urlParts = parse_url( $the_permalink );
			$scoreBasis = strlen( $urlParts['path'] );
			$levscore = levenshtein( $url, $urlParts['path'], 1, 1, 1 );
			$score = 100 - ( ( $levscore / $scoreBasis )*100 );
			$permalinks[$id . "|CAT"] = number_format( $score, 4, '.', '' );
		}
	}

	arsort( $permalinks );
	return $permalinks;
}

/** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
 * 
 * @param type $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
 * @param type $linkScore
 * @return type an array with id, type, score, link, and title.
 */
function abj404_permalinkInfo( $idAndType, $linkScore ) {
	$permalink = array();

	$meta = explode( "|", $idAndType );

	$permalink['id'] = $meta[0];
	$permalink['type'] = $meta[1];
	$permalink['score'] = $linkScore;

	if ( $permalink['type'] == "POST" ) {
		$permalink['link'] = get_permalink( $permalink['id'] );
		$permalink['title'] = get_the_title( $permalink['id'] );
	} else if ( $permalink['type'] == "TAG" ) {
			$permalink['link'] = get_tag_link( $permalink['id'] );
			$tag = get_term( $permalink['id'], 'post_tag' );
			$permalink['title'] = $tag->name;
		} else if ( $permalink['type'] == "CAT" ) {
			$permalink['link'] = get_category_link( $permalink['id'] );
			$cat = get_term( $permalink['id'], 'category' );
			$permalink['title'] = $cat->name;
		}

	return $permalink;
}


function abj404_loadRedirectData( $url ) {
	global $wpdb;
	$redirect = array();

	$query="select * from " . esc_html( $wpdb->prefix ) . "abj404_redirects where url = '" . esc_sql( esc_url( $url ) ) . "'";

	$row = $wpdb->get_row( $query, ARRAY_A );
	if ( $row == NULL ) {
		$redirect['id']=0;
	} else {
		$redirect['id'] = $row['id'];
		$redirect['url'] = $row['url'];
		$redirect['status'] = $row['status'];
		$redirect['type'] = $row['type'];
		$redirect['final_dest'] = $row['final_dest'];
		$redirect['code'] = $row['code'];
		$redirect['disabled'] = $row['disabled'];
		$redirect['created'] = $row['timestamp'];
	}
	return $redirect;
}

/**
 * Inserts data about the redirect that was done.
 * @global type $wpdb
 * @param type $url
 * @param type $status
 * @param type $type
 * @param type $final_dest
 * @param type $code
 * @param type $disabled
 * @return type
 */
function abj404_setupRedirect( $url, $status, $type, $final_dest, $code, $disabled = 0 ) {
	global $wpdb;

	$now = time();
	$wpdb->insert( $wpdb->prefix . 'abj404_redirects',
		array(
			'url' => esc_url( $url ),
			'status' => esc_html( $status ),
			'type' => esc_html( $type ),
			'final_dest' => esc_html( $final_dest ),
			'code' => esc_html( $code ),
			'disabled' => esc_html( $disabled ),
			'timestamp' => esc_html( $now )
		),
		array(
			'%s',
			'%d',
			'%d',
			'%s',
			'%d',
			'%d',
			'%d'
		)
	);
	return $wpdb->insert_id;
}

function abj404_logRedirectHit( $id, $action ) {
	global $wpdb;
	$now = time();

	if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
		$referer = filter_input(INPUT_SERVER, "HTTP_REFERER", FILTER_SANITIZE_URL);
	} else {
		$referer = "";
	}

	$wpdb->insert( $wpdb->prefix . "abj404_logs",
		array(
			'redirect_id' => absint( $id ),
			'timestamp' => esc_html( $now ),
			'remote_host' => esc_html( filter_input(INPUT_SERVER, "REMOTE_ADDR", FILTER_SANITIZE_STRING) ),
                    
			'referrer' => esc_html( $referer ),
			'action' => esc_html( $action ),
		),
		array(
			'%d',
			'%d',
			'%s',
			'%s',
			'%s'
		)
	);
}

/** 
 *  Remove the redirect from the redirects table and from the logs.
 * @global type $wpdb
 * @param type $id
 */
function abj404_deleteRedirect($id) {
	global $wpdb;
        $cleanedID = absint(sanitize_text_field($id));
        
	if ($cleanedID != "" && $cleanedID != '0') {
		$query = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_redirects where id = %d", $cleanedID);
		$wpdb->query($query);
		$query = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_logs where redirect_id = %d", $cleanedID);
		$wpdb->query($query);
	}
}

function abj404_cleaningCron() {
	global $wpdb;
	$options = abj404_getOptions();
	$now = time();

	//Remove Captured URLs
	if ( $options['capture_deletion'] != '0' ) {
		$capture_time = $options['capture_deletion'] * 86400;
		$then = $now - $capture_time;

		//Clean up old logs
		$query = "delete from " . $wpdb->prefix . "abj404_logs where ";
		$query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_CAPTURED . " or status = " . ABJ404_IGNORED . ") ";
		$query .= "and timestamp < " . esc_sql( $then );
		$wpdb->query( $query );

		//Find unused urls
		$query = "select id from " . $wpdb->prefix . "abj404_redirects where (status = " . ABJ404_CAPTURED . " or status = " . ABJ404_IGNORED . ") and ";
		$query .= "timestamp <= " . esc_sql( $then ) . " and id not in (";
		$query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
		$query .= ")";
		$rows = $wpdb->get_results( $query, ARRAY_A );
		foreach ( $rows as $row ) {
			//Remove Them
			abj404_deleteRedirect( $row['id'] );
		}
	}

	//Remove Automatic Redirects
	if ( $options['auto_deletion'] != '0' ) {
		$auto_time = $options['auto_deletion'] * 86400;
		$then = $now - $auto_time;

		//Clean up old logs
		$query = "delete from " . $wpdb->prefix . "abj404_logs where ";
		$query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_AUTO . ") ";
		$query .= "and timestamp < " . esc_sql( $then );
		$wpdb->query( $query );

		//Find unused urls
		$query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_AUTO . " and ";
		$query .= "timestamp <= " . esc_sql( $then ) . " and id not in (";
		$query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
		$query .= ")";
		$rows = $wpdb->get_results( $query, ARRAY_A );
		foreach ( $rows as $row ) {
			//Remove Them
			abj404_deleteRedirect( $row['id'] );
		}
	}

	//Remove Manual Redirects
	if ( $options['manual_deletion'] != '0' ) {
		$manual_time = $options['manual_deletion'] * 86400;
		$then = $now - $manual_time;

		//Clean up old logs
		$query = "delete from " . $wpdb->prefix . "abj404_logs where ";
		$query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_MANUAL . ") ";
		$query .= "and timestamp < " . esc_sql( $then );
		$wpdb->query( $query );

		//Find unused urls
		$query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_MANUAL . " and ";
		$query .= "timestamp <= " . esc_sql( $then ) . " and id not in (";
		$query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
		$query .= ")";
		$rows = $wpdb->get_results( $query, ARRAY_A );
		foreach ( $rows as $row ) {
			//Remove Them
			abj404_deleteRedirect( $row['id'] );
		}
	}
}

function abj404_removeDuplicatesCron() {
	global $wpdb;

	$rtable = $wpdb->prefix . "abj404_redirects";
	$ltable = $wpdb->prefix . "abj404_logs";

	$query = "SELECT COUNT(id) as repetitions, url FROM " . esc_html( $rtable ) . " GROUP BY url HAVING repetitions > 1";
	$rows = $wpdb->get_results( $query, ARRAY_A );
	foreach ( $rows as $row ) {
		$url = $row['url'];

		$query2 = "select id from " . esc_html( $rtable ) . " where url = '" . esc_sql( esc_url( $url ) ) . "' order by id limit 0,1";
		$orig = $wpdb->get_row( $query2, ARRAY_A, 0 );
		if ( $orig['id'] != 0 ) {
			$original = $orig['id'];

			//Fix the logs table
			$query2 = "update " . $ltable . " set redirect_id = " . esc_sql( $original ) . " where redirect_id in (select id from " . esc_html( $rtable ) . " where url = '" . esc_sql( $url ) . "' and id != " . esc_sql( $original ) . ")";
			$wpdb->query( $query2 );

			$query2 = "delete from " . $rtable . " where url='" . esc_sql( esc_url( $url ) ) . "' and id != " . esc_sql( $original );
			$wpdb->query( $query2 );

		}
	}

}

function abj404_SortQuery( $urlParts ) {
	$url = "";
	if ( isset( $urlParts['query'] ) && $urlParts['query'] != "" ) {
		$queryString = array();
		$urlQuery = $urlParts['query'];
		$queryParts = preg_split( "/[;&]/", $urlQuery );
		foreach ( $queryParts as $query ) {
			if ( strpos( $query, "=" ) === false ) {
				$queryString[$query]='';
			} else {
				$stringParts = preg_split( "/=/", $query );
				$queryString[$stringParts[0]]=$stringParts[1];
			}
		}
		ksort( $queryString );
		$x=0;
		$newQS = "";
		foreach ( $queryString as $key => $value ) {
			if ( $x != 0 ) {
				$newQS .= "&";
			}
			$newQS .= $key;
			if ( $value != "" ) {
				$newQS .= "=" . $value;
			}
			$x++;
		}

		if ( $newQS != "" ) {
			$url .= "?" . $newQS;
		}
	}
	return esc_url( $url );
}

function abj404_ProcessRedirect( $redirect ) {
	//A redirect record has already been found.
	if ( ( $redirect['status'] == ABJ404_MANUAL || $redirect['status'] == ABJ404_AUTO ) && $redirect['disabled'] == 0 ) {
		//It's a redirect, not a captured or ignored URL
		if ( $redirect['type'] == ABJ404_EXTERNAL ) {
			//It's a external url setup by the user
			abj404_logRedirectHit( $redirect['id'], $redirect['final_dest'] );
			wp_redirect( esc_url( $redirect['final_dest'] ), esc_html( $redirect['code'] ) );
			exit;
		} else {
			$key="";
			if ( $redirect['type'] == ABJ404_POST ) {
				$key = $redirect['final_dest'] . "|POST";
			} else if ( $redirect['type'] == ABJ404_CAT ) {
				$key = $redirect['final_dest'] . "|CAT";
			} else if ( $redirect['type'] == ABJ404_TAG ) {
				$key = $redirect['final_dest'] . "|TAG";
			} else {
                                error_log("ABJ_404SOLUTION: Unrecognized redirect type: " . $redirect['type']);
                        }
			if ( $key != "" ) {		
				$permalink = abj404_permalinkInfo( $key, 0 );		
				abj404_logRedirectHit( $redirect['id'], $permalink['link'] );		
				wp_redirect( esc_url( $permalink['link'] ), esc_html( $redirect['code'] ) );		
				exit;		
			}
		}
	} else {
		abj404_logRedirectHit( esc_html( $redirect['id'] ), '404' );
	}
}
