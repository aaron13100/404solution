<?php
/*
 * 404 Manager Front End Functions
 *
*/

function wbz404_suggestions() {
	if (is_404()) {
		$options = wbz404_getOptions();
		if ($options['display_suggest'] == '1') {
			echo "<div class=\"404suggest\">";
				$requestedURL = $_SERVER['REQUEST_URI'];
				$urlParts = parse_url($requestedURL);
				$permalinks = wbz404_rankPermalinks($urlParts['path'], $options['suggest_cats'], $options['suggest_tags']);

				echo $options['suggest_title'];
				$displayed = 0;

				foreach($permalinks as $k=>$v) {
					$permalink = wbz404_permalinkInfo($k,$v);

					if ($permalink['score'] >= $options['suggest_minscore']) {
						if ($displayed == 0) {
							echo $options['suggest_before'];
						}

						echo $options['suggest_entrybefore'];
						echo "<a href=\"" . $permalink['link'] . "\" title=\"" . $permalink['title'] . "\">" . $permalink['title'] . "</a>";
						if (is_user_logged_in() && current_user_can('manage_options')) {
							echo " (" . $permalink['score'] . ")";
						}
						echo $options['suggest_entryafter'];
						$displayed++;
						if ($displayed >= $options['suggest_max']) {
							break;
						}
					} else {
						break;
					}
				}
				if ($displayed >= 1) {
					echo $options['suggest_after'];
				} else {
					echo $options['suggest_noresults'];
				}
	
				//Promote Plugin
				if ($options['404_promote'] == "1") {
					echo wbz404_trans('Generated using the') . " <a href=\"" . WBZ404_HOME . "\" title=\"" . wbz404_trans('Wordpress 404 Manager Plugin') . "\" target=\"_blank\">";
					echo wbz404_trans('404 Redirected') . "</a> " . wbz404_trans('plugin written by') . " <a href=\"http://www.weberz.com/\" title=\"Weberz Hosting\" target=\"_blank\">Weberz Hosting</a>.";
				}
			echo "</div>";
		}
	}
}

function wbz404_process404() {
	$options = wbz404_getOptions();

	$urlRequest = $_SERVER['REQUEST_URI'];
	$urlParts = parse_url($urlRequest);
	$requestedURL = $urlParts['path'];
	$requestedURL .= wbz404_SortQuery($urlParts);

	//Get URL data if it's already in our database
	$redirect = wbz404_loadRedirectData($requestedURL);

	if (is_404() && $requestedURL != "") {
		if ($redirect['id'] != '0') {
			//A redirect record exists.
			wbz404_ProcessRedirect($redirect);
		} else {
			//No redirect record.
			$found = 0;
			if ($options['auto_redirects'] == '1') {
				//Site owner wants automatic redirects
				$permalinks = wbz404_rankPermalinks($requestedURL, $options['auto_cats'], $options['auto_tags']);
				$minScore = $options['auto_score'];

				foreach($permalinks as $k=>$v) {
					$permalink = wbz404_permalinkInfo($k, $v);

					if ($permalink['score'] >= $minScore) {
						$found = 1;
						break;		
					} else {
						//Score not high enough
						break;
					}
				}

				if ($found == 1) {
					//We found a permalink that will work!
					$type = 0;
					if ($permalink['type'] == "POST") {
						$type = WBZ404_POST;
					} else  if ($permalink['type'] == "CAT") {
						$type = WBZ404_CAT;
					} else if ($permalink['type'] == "TAG") {
						$type = WBZ404_TAG;
					}
					if ($type != 0) {
						$redirect_id = wbz404_setupRedirect($requestedURL, WBZ404_AUTO, $type, $permalink['id'], $options['default_redirect'], 0);
					}
				}
			}
			if ($found == 1) {
				//Perform actual redirect
				wbz404_logRedirectHit($redirect_id, $permalink['link']);
				wp_redirect($permalink['link'], $options['default_redirect']);
				exit;
			} else {
				//Check for incoming 404 settings
				if ($options['capture_404'] == '1') {
					$redirect_id = wbz404_setupRedirect($requestedURL, WBZ404_CAPTURED, 0,0, $options['default_redirect'], 0);
					wbz404_logRedirectHit($redirect_id, '404');
				}
			}
		}
	} else {
		if (is_single() || is_page()) {	
			if (!is_feed() && !is_trackback() && !is_preview()) {
				$theID = get_the_ID();
				$permalink = wbz404_permalinkInfo($theID . "|POST", 0);

				$urlParts = parse_url($permalink['link']);
				$perma_link = $urlParts['path'];

				$paged = get_query_var('page') ? get_query_var('page') : FALSE;

				if (! $paged === FALSE) {
					if ($urlParts[query] == "") {
						if (substr($perma_link,-1) == "/") {
							$perma_link .= $paged . "/";
						} else {
							$perma_link .= "/" . $paged;
						}
					} else {
						$urlParts['query'] .= "&page=" . $paged;
					}
				}

				$perma_link .= wbz404_SortQuery($urlParts);

				//Check for forced permalinks
				if ($options['force_permalinks'] == '1' && $options['auto_redirects'] == '1') {
					if ($requestedURL != $perma_link) {
						if ($redirect['id'] != '0') {
							wbz404_ProcessRedirect($redirect);
						} else {
							$redirect_id = wbz404_setupRedirect($requestedURL, WBZ404_AUTO, WBZ404_POST, $permalink['id'], $options['default_redirect'], 0);
							wbz404_logRedirectHit($redirect_id, $permalink['link']);
							wp_redirect($permalink['link'], $options['default_redirect']);
							exit;
						}
					}
				}

				if ($requestedURL == $perma_link) {
					//Not a 404 Link. Check for matches
					if ($options['remove_matches'] == '1') {
						if ($redirect['id'] != '0') {
							wbz404_cleanRedirect($redirect['id']);
						}
					}
				}
			}
		}
	}
}

function wbz404_redirectCanonical($redirect, $request) {
	if (is_single() || is_page()) {
		if (!is_feed() && !is_trackback() && !is_preview()) {
			$options = wbz404_getOptions();

			$urlRequest = $_SERVER['REQUEST_URI'];
			$urlParts = parse_url($urlRequest);
			
			$requestedURL = $urlParts['path'];
			$requestedURL .= wbz404_SortQuery($urlParts);

		        #//Get URL data if it's already in our database
		        $data = wbz404_loadRedirectData($requestedURL);	

			if ($data['id'] != '0') {
				wbz404_ProcessRedirect($data);
			} else {
				if ($options['auto_redirects'] == '1' && $options['force_permalinks'] == '1') {
					$theID = get_the_ID();
					$permalink = wbz404_permalinkInfo($theID . "|POST", 0);
					$urlParts = parse_url($permalink['link']);

					$perma_link = $urlParts['path'];
					$paged = get_query_var('page') ? get_query_var('page') : FALSE;

					if (! $paged === FALSE) {
						if ($urlParts[query] == "") {
							if (substr($perma_link,-1) == "/") {
								$perma_link .= $paged . "/";
							} else {
								$perma_link .= "/" . $paged;
							}
						} else {
							$urlParts['query'] .= "&page=" . $paged;
						}
					}

					$perma_link .= wbz404_SortQuery($urlParts);

					if ($requestedURL != $perma_link) {
						$redirect_id = wbz404_setupRedirect($requestedURL, WBZ404_AUTO, WBZ404_POST, $theID, $options['default_redirect'], 0);
						wbz404_logRedirectHit($redirect_id, $perma_link);
						wp_redirect($perma_link, $options['default_redirect']);
						exit;
					}
				}
			}
		}
	}

	if (is_404()) { return false; }
	return $redirect;
}

add_action('template_redirect', 'wbz404_process404');
add_filter('redirect_canonical', 'wbz404_redirectCanonical', 10, 2);
