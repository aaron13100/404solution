<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist) && is_admin()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

    /** Setup. */
    static function init() {
        add_filter("plugin_action_links_" . ABJ404_NAME, 'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
        add_action('template_redirect', 'ABJ_404_Solution_WordPress_Connector::process404', 9999);

        add_action('abj404_cleanupCronAction', 'ABJ_404_Solution_DataAccess::deleteOldRedirectsCron');

        register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::doUnregisterCrons');
        register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginActivation');

        add_action('admin_notices', 'ABJ_404_Solution_WordPress_Connector::echoDashboardNotification');
        add_action('admin_menu', 'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
    }

    /** Add the "Settings" link to the WordPress plugins page (next to activate/deactivate and edit).
     * @param type $links
     * @return type
     */
    static function addSettingsLinkToPluginPage($links) {
        global $abj404logging;
        global $abj404logic;
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("addSettingsLinkToPluginPage");

            return $links;
        }

        $settings_link = '<a href="options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options">' . 
                __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        
        $debugExplanation = __('Debug Log', '404-solution');
        $debugLogLink = $abj404logic->getDebugLogFileLink();
        $debugExplanation = '<a href="options-general.php' . $debugLogLink . '" target="_blank" >' . $debugExplanation .
                '</a>';
        array_push($links, $debugExplanation);
        
        return $links;
    }

    /**
     * @global type $abj404spellChecker
     */
    function suggestions() {
        global $abj404logic;
        global $abj404spellChecker;

        if (is_404()) {
            $options = $abj404logic->getOptions();
            if (@$options['display_suggest'] == '1') {
                echo "<div class=\"suggest-404s\">";
                $requestedURL = esc_url($_SERVER['REQUEST_URI']);

                $urlParts = parse_url($requestedURL);
                $permalinks = $abj404spellChecker->findMatchingPosts($urlParts['path'], @$options['suggest_cats'], @$options['suggest_tags']);

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
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($k, $v);

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
                        echo wp_kses(@$options['suggest_entryafter'], array(
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

    /**
     * Process the 404s
     */
    static function process404() {
        global $abj404dao;
        global $abj404logic;
        global $abj404connector;
        global $abj404spellChecker;
        global $abj404logging;

        if (!is_404()) {
            return;
        }

        $urlRequest = esc_url(preg_replace('/\?.*/', '', esc_url($_SERVER['REQUEST_URI'])));
        
        // setup ignore variables on $_REQUEST['abj404solution']
        $abj404logic->initializeIgnoreValues($urlRequest);
        
        if ($_REQUEST[ABJ404_PP]['ignore_donotprocess']) {
            $abj404dao->logRedirectHit($urlRequest, '404', 'ignored');
            return;
        }
        
        // remove the home directory from the URL parts because it should not be considered for spell checking.
        $urlSlugOnly = $abj404logic->removeHomeDirectory($urlRequest);

        $urlParts = parse_url(esc_url($_SERVER['REQUEST_URI']));
        $requestedURL = $urlParts['path'];
        $requestedURL .= $abj404connector->sortQueryParts($urlParts);

        // Get URL data if it's already in our database
        $redirect = $abj404dao->getActiveRedirectForURL($requestedURL);

        $options = $abj404logic->getOptions();

        if ($abj404logging->isDebug()) {
            $debugOptionsMsg = esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' . 
                    $options['auto_score'] . ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
                    $options['auto_tags'] . ', dest404page: ' . $options['dest404page']);
            $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $_SERVER['HTTP_USER_AGENT'] . ', REMOTE_ADDR: ' . 
                    $_SERVER['REMOTE_ADDR'] . ', REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
            $abj404logging->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
                    wp_kses_post(json_encode($redirect)) . " | is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
                    " | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
                    is_preview() . " | options: " . $debugOptionsMsg . ', ' . $debugServerMsg);
        }

        if ($requestedURL != "") {
            // if we already know where to go then go there.
            if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                // A redirect record exists.
                $abj404connector->processRedirect($requestedURL, $redirect, 'existing');

                // we only reach this line if an error happens.
                exit;
            }

            // --------------------------------------------------------------
            // try a permalink change.
            $slugPermalink = $abj404spellChecker->getPermalinkUsingSlug($urlSlugOnly);
            if (!empty($slugPermalink)) {
                $redirectType = $slugPermalink['type'];
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $slugPermalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($requestedURL, $slugPermalink['link'], 'exact slug');
                $abj404logic->forceRedirect(esc_url($slugPermalink['link']), esc_html($options['default_redirect']));
                exit;
            }

            // --------------------------------------------------------------
            // try spell checking.
            $permalink = $abj404spellChecker->getPermalinkUsingSpelling($urlSlugOnly);
            if (!empty($permalink)) {
                $redirectType = $permalink['type'];
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $permalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($requestedURL, $permalink['link'], 'spell check');
                $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                exit;
            }

        } else {

            // this is for a permalink structure that has changed?
            if (is_single() || is_page()) {
                if (!is_feed() && !is_trackback() && !is_preview()) {
                    $theID = get_the_ID();
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|" . ABJ404_TYPE_POST, 0);

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

                    $perma_link .= $abj404connector->sortQueryParts($urlParts);

                    // Check for forced permalinks.
                    if (@$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $abj404connector->processRedirect($requestedURL, $redirect, 'single page 3');
                            } else {
                                $abj404dao->setupRedirect(esc_url($requestedURL), ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, $permalink['id'], $options['default_redirect'], 0);
                                $abj404dao->logRedirectHit($requestedURL, $permalink['link'], 'single page');
                                $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        // Not a 404 Link. Check for matches.
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $abj404dao->deleteRedirect($redirect['id']);
                            }
                        }
                    }
                }
            }
        }

        // this is for requests like website.com/?p=123            
        $abj404logic->tryNormalPostQuery($options);
        
        $abj404logic->sendTo404Page($requestedURL, '');
    }

    /** Sort the QUERY parts of the requested URL. 
     * This is in place because these are stored as part of the URL in the database and used for forwarding to another page.
     * This is done because sometimes different query parts result in a completely different page. Therefore we have to 
     * take into account the query part of the URL (?query=part) when looking for a page to redirect to. 
     * 
     * Here we sort the query parts so that the same request will always look the same.
     * @param type $urlParts
     * @return string
     */
    function sortQueryParts($urlParts) {
        if (!array_key_exists('query', $urlParts) || @$urlParts['query'] == "") {
            return "";
        }
        $url = "";

        $queryString = array();
        $urlQuery = $urlParts['query'];
        $queryParts = preg_split("/[;&]/", $urlQuery);
        foreach ($queryParts as $query) {
            if (strpos($query, "=") === false) {
                $queryString[$query] = '';
            } else {
                $stringParts = preg_split("/=/", $query);
                $queryString[$stringParts[0]] = $stringParts[1];
            }
        }
        ksort($queryString);
        $x = 0;
        $newQS = "";
        foreach ($queryString as $key => $value) {
            if ($x != 0) {
                $newQS .= "&";
            }
            $newQS .= $key;
            if ($value != "") {
                $newQS .= "=" . $value;
            }
            $x++;
        }

        if ($newQS != "") {
            $url .= "?" . $newQS;
        }

        return esc_url($url);
    }

    /** Redirect to the page specified. 
     * @global type $abj404dao
     * @global type $abj404logging
     * @global type $abj404logic
     * #param type $requestedURL
     * @param type $redirect
     * #param type $matchReason
     * @return type
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {
        global $abj404dao;
        global $abj404logging;
        global $abj404logic;

        if (( $redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO ) || $redirect['disabled'] != 0) {
            // It's a redirect that has been deleted, ignored, or captured.
            $abj404logging->errorMessage("processRedirect() was called with bad redirect data. Data: " .
                    wp_kses_post(json_encode($redirect)));
            return;
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $abj404dao->logRedirectHit($requestedURL, $redirect['final_dest'], 'external');
            $abj404logic->forceRedirect(esc_url($redirect['final_dest']), esc_html($redirect['code']));
            exit;
        }

        $key = "";
        if ($redirect['type'] == ABJ404_TYPE_POST) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_POST;
        } else if ($redirect['type'] == ABJ404_TYPE_CAT) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_CAT;
        } else if ($redirect['type'] == ABJ404_TYPE_TAG) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_TAG;
        } else if ($redirect['type'] == ABJ404_TYPE_HOME) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_HOME;
        } else {
            $abj404logging->errorMessage("Unrecognized redirect type in Connector: " .
                    wp_kses_post(json_encode($redirect)));
        }

        if ($key != "") {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);
            $abj404dao->logRedirectHit($requestedURL, $permalink['link'], $matchReason);
            $abj404logic->forceRedirect($permalink['link'], esc_html($redirect['code']));
            exit;
        }
    }

    /** Display an admin dashboard notification.
     * e.g. There are 29 captured 404 URLs that need to be processed.
     * @global type $pagenow
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404view
     */
    static function echoDashboardNotification() {
        global $abj404logging;
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("echoDashboardNotification");
            return;
        }

        global $pagenow;
        global $abj404dao;
        global $abj404logic;
        global $abj404view;

        if (current_user_can('manage_options')) {
            if ( (array_key_exists('page', $_GET) && $_GET['page'] == ABJ404_PP) ||
                 ($pagenow == 'index.php' && !isset($_GET['page'])) ) {
                $options = $abj404logic->getOptions();
                if (array_key_exists('admin_notification', $options) && isset($options['admin_notification']) && $options['admin_notification'] != '0') {
                    $captured = $abj404dao->getCapturedCountForNotification();
                    if ($captured >= $options['admin_notification']) {
                        $msg = $abj404view->getDashboardNotificationCaptured($captured);
                        echo $msg;
                    }
                }
            }
        }
    }

    /** Adds a link under the "Settings" link to the plugin page.
     * @global string $menu
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404logging
     * @return type
     */
    static function addMainSettingsPageLink() {
        global $menu;
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("addMainSettingsPageLink");
            return;
        }

        $options = $abj404logic->getOptions();
        $pageName = "404 Solution";

        // Admin notice
        if (array_key_exists('admin_notification', $options) && isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            $captured = $abj404dao->getCapturedCountForNotification();
            if (isset($options['admin_notification']) && $captured >= $options['admin_notification']) {
                $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html($captured) . "</span></span>";
                $pos = strpos($menu[80][0], 'update-plugins');
                if ($pos === false) {
                    $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
                }
            }
        }

        // this adds the settings link at Settings->404 Solution.
        add_submenu_page('options-general.php', '404 Solution', $pageName, 'manage_options', ABJ404_PP, 
                'ABJ_404_Solution_View::handleMainAdminPageActionAndDisplay');
    }

}

ABJ_404_Solution_WordPress_Connector::init();
