<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

    /** Setup. */
    static function init() {
        add_filter("plugin_action_links_" . ABJ404_NAME, 'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
        add_action('template_redirect', 'ABJ_404_Solution_WordPress_Connector::process404', 9999);
        add_filter('redirect_canonical', 'ABJ_404_Solution_WordPress_Connector::redirectCanonical', 10, 2);

        add_action('abj404_duplicateCronAction', 'ABJ_404_Solution_DataAccess::removeDuplicatesCron');
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
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("addSettingsLinkToPluginPage");

            return $links;
        }

        $settings_link = '<a href="options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options">' . 
                __('Settings') . '</a>';
        array_unshift($links, $settings_link);
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
                $permalinks = $abj404spellChecker->findMatchingPosts($urlParts['path'], $options['suggest_cats'], $options['suggest_tags']);

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

    /**
     * Process the 404s
     */
    static function process404() {
        global $abj404dao;
        global $abj404logic;
        global $abj404connector;
        global $abj404spellChecker;
        global $abj404logging;

        $urlRequest = esc_url(preg_replace('/\?.*/', '', esc_url($_SERVER['REQUEST_URI'])));

        if ($abj404logic->shouldIgnoreRequest($urlRequest)) {
            return;
        }

        // remove the home directory from the URL parts because it should not be considered for spell checking.
        $urlSlugOnly = $abj404logic->removeHomeDirectory($urlRequest);

        $urlParts = parse_url($urlRequest);
        $requestedURL = $urlParts['path'];
        $requestedURL .= $abj404connector->sortQueryParts($urlParts);

        // Get URL data if it's already in our database
        $redirect = $abj404dao->getActiveRedirectForURL($requestedURL);

        $options = $abj404logic->getOptions();

        if ($abj404logging->isDebug()) {
            $abj404logging->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
                    wp_kses_post(json_encode($redirect)) . " | is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
                    " | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
                    is_preview() . " | options: " . wp_kses_post(json_encode($options)));
        }

        if ($requestedURL != "") {
            // if we already know where to go then go there.
            if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                // A redirect record exists.
                $abj404connector->processRedirect($redirect);

                // we only reach this line if an error happens.
                exit;
            }

            // --------------------------------------------------------------
            // try a permalink change.
            $slugPermalink = $abj404spellChecker->getPermalinkUsingSlug($urlSlugOnly);
            if (!empty($slugPermalink)) {
                $redirectType = $slugPermalink['type'];
                $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $slugPermalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($redirect_id, $slugPermalink['link']);
                $abj404logic->forceRedirect(esc_url($slugPermalink['link']), esc_html($options['default_redirect']));
                exit;
            }

            // --------------------------------------------------------------
            // try spell checking.
            $permalink = $abj404spellChecker->getPermalinkUsingSpelling($urlSlugOnly);
            if (!empty($permalink)) {
                $redirectType = $permalink['type'];
                $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $permalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
                $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                exit;
            }
        } else {

            $abj404logic->tryNormalPostQuery($options);

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
                    if (@$options['force_permalinks'] == '1' && @$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $abj404connector->processRedirect($redirect);
                            } else {
                                $redirect_id = $abj404dao->setupRedirect(esc_url($requestedURL), ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, $permalink['id'], $options['default_redirect'], 0);
                                $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
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

        // ---------------------------------------
        // if there's a default 404 page specified then use that.
        $dest404page = (isset($options['dest404page']) ? $options['dest404page'] : 
            ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);
        if (($dest404page != ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED) && 
                ($dest404page != ABJ404_TYPE_404_DISPLAYED)) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0);
            $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_CAPTURED, $permalink['type'], $permalink['id'], $options['default_redirect'], 0);
            $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
            $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
            exit;
        }

        // ---------------------------------------
        // give up. log the 404.
        if (@$options['capture_404'] == '1') {
            // get the existing redirect before adding a new one.
            $redirect = $abj404dao->getExistingRedirectForURL($requestedURL);
            if ($redirect['id'] != 0) {
                $redirect_id = $redirect['id'];
            } else {
                $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_CAPTURED, ABJ404_TYPE_404_DISPLAYED, ABJ404_TYPE_404_DISPLAYED, $options['default_redirect'], 0);
            }
            $abj404dao->logRedirectHit($redirect_id, '404');
        } else {
            if ($abj404logging->isDebug()) {
                $abj404logging->debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL .
                        " | Redirect: " . wp_kses_post(json_encode($redirect)) . " | is_single(): " . is_single() . " | " .
                        "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " .
                        is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses_post(json_encode($options)));
            }
        }
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
        if (@$urlParts['query'] == "") {
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

    /**
     * Redirect canonicals
     */
    static function redirectCanonical($redirect, $request) {
        global $abj404dao;
        global $abj404connector;
        global $abj404logic;

        if (is_single() || is_page()) {
            if (!is_feed() && !is_trackback() && !is_preview()) {
                $options = $abj404logic->getOptions();

                // Sanitizing options.
                foreach ($options as $key => $value) {
                    $key = wp_kses_post($key);
                    $options[$key] = wp_kses_post($value);
                }

                $urlRequest = esc_url($_SERVER['REQUEST_URI']);
                $urlParts = parse_url($urlRequest);

                $requestedURL = $urlParts['path'];
                $requestedURL .= $abj404connector->sortQueryParts($urlParts);

                // Get URL data if it's already in our database.
                $data = $abj404dao->getActiveRedirectForURL($requestedURL);

                if ($data['id'] != '0' && $data['final_dest'] != 0) {
                    $abj404connector->processRedirect($data);
                } else {
                    if ($options['auto_redirects'] == '1' && $options['force_permalinks'] == '1') {
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

                        if ($requestedURL != $perma_link) {
                            $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, $theID, $options['default_redirect'], 0);
                            $abj404dao->logRedirectHit($redirect_id, $perma_link);
                            $abj404logic->forceRedirect(esc_url($perma_link), esc_html($options['default_redirect']));
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

    /** Redirect to the page specified. 
     * @global type $abj404dao
     * @global type $abj404logging
     * @global type $abj404logic
     * @param type $redirect
     * @return type
     */
    function processRedirect($redirect) {
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
            $abj404dao->logRedirectHit($redirect['id'], $redirect['final_dest']);
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
            $abj404dao->logRedirectHit($redirect['id'], $permalink['link']);
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
            if (( @$_GET['page'] == ABJ404_PP ) || ( $pagenow == 'index.php' && (!isset($_GET['page']) ) )) {
                $options = $abj404logic->getOptions();
                if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
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
        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
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
