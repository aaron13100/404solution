<?php

/*
 * 404 Manager Front End Functions
 *
 */

/** 
 * Add the Settings link to the WordPress plugins page.
 * @param type $links
 * @return type
 */
function plugin_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=abj404_solution&subpage=abj404_options">' . __( 'Settings' ) . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter( "plugin_action_links_" . ABJ404_PLUGIN_BASENAME, 'plugin_add_settings_link' );

/**
 * Suggesting 404 content based on defaults and settings
 */
function abj404_suggestions() {
    if (is_404()) {
        $options = abj404_getOptions();
        if (isset($options['display_suggest']) && $options['display_suggest'] == '1') {
            echo "<div class=\"suggest-404s\">";
            $requestedURL = esc_url(filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL));

            $urlParts = parse_url($requestedURL);
            $permalinks = abj404_rankPermalinks($urlParts['path'], $options['suggest_cats'], $options['suggest_tags']);

            // Allowing some HTML.
            echo wp_kses($options['suggest_title'], array(
                'h1' => array(),
                'h2' => array(),
                'h3' => array(),
                'h4' => array(),
                'h5' => array(),
                'h6' => array(),
                'i' => array(),
                'em' => array(),
                'strong' => array(),
                    )
            );
            $displayed = 0;

            foreach ($permalinks as $k => $v) {
                $permalink = abj404_permalinkInfo($k, $v);

                if ($permalink['score'] >= $options['suggest_minscore']) {
                    if ($displayed == 0) {
                        // No need to escape since we're expecting HTML
                        echo wp_kses($options['suggest_before'], array(
                            'ul' => array(),
                            'ol' => array(),
                            'li' => array(),
                                )
                        );
                    }

                    echo wp_kses($options['suggest_entrybefore'], array(
                        'ul' => array(),
                        'ol' => array(),
                        'li' => array(),
                            )
                    );
                    echo "<a href=\"" . esc_url($permalink['link']) . "\" title=\"" . esc_attr($permalink['title']) . "\">" . esc_attr($permalink['title']) . "</a>";
                    if (is_user_logged_in() && current_user_can('manage_options')) {
                        echo " (" . esc_html($permalink['score']) . ")";
                    }
                    echo wp_kses($options['suggest_entryafter'], array(
                        'ul' => array(),
                        'ol' => array(),
                        'li' => array(),
                            )
                    );
                    $displayed++;
                    if ($displayed >= $options['suggest_max']) {
                        break;
                    }
                } else {
                    break;
                }
            }
            if ($displayed >= 1) {
                echo wp_kses($options['suggest_after'], array(
                    'ul' => array(),
                    'ol' => array(),
                    'li' => array(),
                        )
                );
            } else {
                echo wp_kses($options['suggest_noresults'], $allowedtags);
            }

            echo "</div>";
        }
    }
}

add_action('template_redirect', 'abj404_process404', 9999);

/**
 * Process the 404s
 */
function abj404_process404() {
    // Bail out if not on 404 error page.
    if (!is_404()) {
        return;
    }

    $options = abj404_getOptions();

    $urlRequest = esc_url(preg_replace('/\?.*/', '', filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL)));
    $urlParts = parse_url($urlRequest);
    $requestedURL = $urlParts['path'];
    $requestedURL .= abj404_SortQuery($urlParts);

    //Get URL data if it's already in our database
    $redirect = abj404_loadRedirectData($requestedURL);

    if (is_404() && $requestedURL != "") {
        if ($redirect['id'] != '0') {
            // A redirect record exists.
            abj404_ProcessRedirect($redirect);
        } else {
            // No redirect record.
            $found = 0;
            if (isset($options['auto_redirects']) && $options['auto_redirects'] == '1') {
                // Site owner wants automatic redirects.
                $permalinks = abj404_rankPermalinks($requestedURL, $options['auto_cats'], $options['auto_tags']);
                $minScore = $options['auto_score'];

                foreach ($permalinks as $key => $value) {
                    $permalink = abj404_permalinkInfo($key, $value);

                    if ($permalink['score'] >= $minScore) {
                        $found = 1;
                        // TODO: this should use the highest score, not the first found?
                        break;
                    } else {
                        // Score not high enough.
                        // TODO: verify: why is this in a loop if both cases break??
                        break;
                    }
                }

                if ($found == 1) {
                    // We found a permalink that will work!
                    $type = 0;
                    if ($permalink['type'] == "POST") {
                        $type = ABJ404_POST;
                    } else if ($permalink['type'] == "CAT") {
                        $type = ABJ404_CAT;
                    } else if ($permalink['type'] == "TAG") {
                        $type = ABJ404_TAG;
                    }
                    if ($type != 0) {
                        $redirect_id = abj404_setupRedirect($requestedURL, ABJ404_AUTO, $type, $permalink['id'], $options['default_redirect'], 0);
                    } else {
                        error_log("ABJ_404_SOLUTION: Unhandled permalink type: " . esc_html($permalink['type']));
                    }
                }
            }
            if ($found == 1) {
                // Perform actual redirect.
                abj404_logRedirectHit($redirect_id, $permalink['link']);
                wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                exit;
            } else {
                // Check for incoming 404 settings.
                if (isset($options['capture_404']) && $options['capture_404'] == '1') {
                    $redirect_id = abj404_setupRedirect($requestedURL, ABJ404_CAPTURED, 0, 0, $options['default_redirect'], 0);
                    abj404_logRedirectHit($redirect_id, '404');
                }
            }
        }
    } else {
        if (is_single() || is_page()) {
            if (!is_feed() && !is_trackback() && !is_preview()) {
                $theID = get_the_ID();
                $permalink = abj404_permalinkInfo($theID . "|POST", 0);

                $urlParts = parse_url($permalink['link']);
                $perma_link = $urlParts['path'];

                $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                if (!$paged === FALSE) {
                    if ($urlParts[query] == "") {
                        if (substr($perma_link, -1) == "/") {
                            $perma_link .= $paged . "/";
                        } else {
                            $perma_link .= "/" . $paged;
                        }
                    } else {
                        $urlParts['query'] .= "&page=" . $paged;
                    }
                }

                $perma_link .= abj404_SortQuery($urlParts);

                // Check for forced permalinks.
                if (isset($options['force_permalinks']) && isset($options['auto_redirects']) && $options['force_permalinks'] == '1' && $options['auto_redirects'] == '1') {
                    if ($requestedURL != $perma_link) {
                        if ($redirect['id'] != '0') {
                            abj404_ProcessRedirect($redirect);
                        } else {
                            $redirect_id = abj404_setupRedirect(esc_url($requestedURL), ABJ404_AUTO, ABJ404_POST, $permalink['id'], $options['default_redirect'], 0);
                            abj404_logRedirectHit($redirect_id, $permalink['link']);
                            wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                            exit;
                        }
                    }
                }

                if ($requestedURL == $perma_link) {
                    // Not a 404 Link. Check for matches.
                    if ($options['remove_matches'] == '1') {
                        if ($redirect['id'] != '0') {
                            abj404_deleteRedirect($redirect['id']);
                        }
                    }
                }
            }
        }
    }

    // if there's a default 404 page specified then use that.
    $userSelected = (isset($options['dest404page']) ? $options['dest404page'] : 'none');
    if ($userSelected != "none") {
        $permalink = abj404_permalinkInfo($userSelected . "|POST", 0);
        $redirect_id = abj404_setupRedirect($requestedURL, ABJ404_AUTO, ABJ404_POST, $permalink['id'], $options['default_redirect'], 0);
        // Perform actual redirect.
        abj404_logRedirectHit($redirect_id, $permalink['link']);
        wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
        exit;
    }
}
add_filter('redirect_canonical', 'abj404_redirectCanonical', 10, 2);

/**
 * Redirect canonicals
 */
function abj404_redirectCanonical($redirect, $request) {
    if (is_single() || is_page()) {
        if (!is_feed() && !is_trackback() && !is_preview()) {
            $options = abj404_getOptions();


            // Sanitizing options.
            foreach ($options as $key => $value) {
                $key = wp_kses_post($key);
                $options[$key] = wp_kses_post($value);
            }

            $urlRequest = esc_url(filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL));
            $urlParts = parse_url($urlRequest);

            $requestedURL = $urlParts['path'];
            $requestedURL .= abj404_SortQuery($urlParts);

            // Get URL data if it's already in our database.
            $data = abj404_loadRedirectData($requestedURL);

            if ($data['id'] != '0') {
                abj404_ProcessRedirect($data);
            } else {
                if ($options['auto_redirects'] == '1' && $options['force_permalinks'] == '1') {
                    $theID = get_the_ID();
                    $permalink = abj404_permalinkInfo($theID . "|POST", 0);
                    $urlParts = parse_url($permalink['link']);

                    $perma_link = $urlParts['path'];
                    $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                    if (!$paged === FALSE) {
                        if ($urlParts[query] == "") {
                            if (substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        } else {
                            $urlParts['query'] .= "&page=" . $paged;
                        }
                    }

                    $perma_link .= abj404_SortQuery($urlParts);

                    if ($requestedURL != $perma_link) {
                        $redirect_id = abj404_setupRedirect($requestedURL, ABJ404_AUTO, ABJ404_POST, $theID, $options['default_redirect'], 0);
                        abj404_logRedirectHit($redirect_id, $perma_link);
                        wp_redirect(esc_url($perma_link), esc_html($options['default_redirect']));
                        exit;
                    }
                }
            }
        }
    }

    if (is_404()) {
        return false;
    }

    return $redirect;
}
