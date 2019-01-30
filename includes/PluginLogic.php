<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* the glue that holds it together / everything else. */

class ABJ_404_Solution_PluginLogic {
    
    /** Track whether we're already in the method that updates the database that may be called recursively.
     * @var type */
    private $currentlyUpdatingDatabaseVersion = false;

    /** If a page's URL is /blogName/pageName then this returns /pageName.
     * @param type $urlRequest
     * @return type
     */
    function removeHomeDirectory($urlRequest) {
        $urlHomeDirectory = rtrim(parse_url(get_home_url(), PHP_URL_PATH), '/');
        if (substr($urlRequest, 0, mb_strlen($urlHomeDirectory)) == $urlHomeDirectory) {
            $urlRequest = mb_substr($urlRequest, mb_strlen($urlHomeDirectory . "/"));
        }
        
        $urlRequest = rtrim($urlRequest, "/");
        
        return $urlRequest;
    }
    /** Forward to a real page for queries like ?p=10
     * @global type $wp_query
     * @param type $options
     */
    function tryNormalPostQuery($options) {
        global $wp_query;
        global $abj404dao;

        // this is for requests like website.com/?p=123
        $query = $wp_query->query;
        // if it's not set then don't use it.
        if (!array_key_exists('p', $query) || !isset($query['p'])) {
            return;
        }
        $pageid = $query['p'];
        if (!empty($pageid)) {
            $permalink = urldecode(get_permalink($pageid));
            $status = get_post_status($pageid);
            if (($permalink != false) && ($status == 'publish')) {
                $urlHomeDirectory = rtrim(parse_url(get_home_url(), PHP_URL_PATH), '/');
                $fromURL = $urlHomeDirectory . '/?p=' . $pageid;
                $redirect = $abj404dao->getExistingRedirectForURL($fromURL);
                if (!isset($redirect['id']) || $redirect['id'] == 0) {
                    $abj404dao->setupRedirect($fromURL, ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, 
                            $pageid, $options['default_redirect'], 0);
                }
                $abj404dao->logRedirectHit($fromURL, $permalink, 'page ID');
                $this->forceRedirect($permalink, esc_html($options['default_redirect']));
                exit;
            }
        }
    }
    
    /** 
     * @global type $abj404logging
     * @global type $abj404logic
     * @param type $urlRequest the requested URL. e.g. /404killer/aboutt
     * @param type $urlSlugOnly only the slug. e.g. /aboutt
     */
    function initializeIgnoreValues($urlRequest, $urlSlugOnly) {
        global $abj404logging;
        global $abj404logic;
        
        $options = $abj404logic->getOptions();
        $ignoreReasonDoNotProcess = null;
        $ignoreReasonDoProcess = null;
        
        // Note: is_admin() does not mean the user is an admin - it returns true when the user is on an admin screen.
        // ignore requests that are supposed to be for an admin.
        $adminURL = parse_url(admin_url(), PHP_URL_PATH);
        if (is_admin() || mb_substr($urlRequest, 0, mb_strlen($adminURL)) == $adminURL) {
            $abj404logging->debugMessage("Ignoring admin URL: " . $urlRequest);
            $ignoreReasonDoNotProcess = 'Admin URL';
        }
        
        // The user agent Zemanta Aggregator http://www.zemanta.com causes a lot of false positives on 
        // posts that are still drafts and not actually published yet. It's from the plugin "WordPress Related Posts"
        // by https://www.sovrn.com/. 
        $userAgents = preg_split("@\n@", mb_strtolower($options['ignore_dontprocess']), NULL, PREG_SPLIT_NO_EMPTY);
        $httpUserAgent = mb_strtolower(@$_SERVER['HTTP_USER_AGENT']);
        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $abj404logging->debugMessage("Ignoring user agent (do not redirect): " . 
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoNotProcess = 'User agent (do not redirect): ' . $_SERVER['HTTP_USER_AGENT'];
            }
        }
        
        // ----- ignore based on regex file path
        $patternsToIgnore = $options['folders_files_ignore_usable'];
        if (!empty($patternsToIgnore)) {
            foreach ($patternsToIgnore as $patternToIgnore) {
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying regex pattern to ignore\"' . 
                        $patternToIgnore . '" to URL slug: ' . $urlSlugOnly;
                if (preg_match("/" . $patternToIgnore . "/", $urlSlugOnly, $matches)) {
                    $abj404logging->debugMessage("Ignoring file/folder (do not redirect) for URL: " . 
                            esc_html($urlSlugOnly) . ", pattern used: " . $patternToIgnore);
                    $ignoreReasonDoNotProcess = 'Files and folders (do not redirect) pattern: ' . esc_html($patternToIgnore);
                }
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex pattern to ignore.';
            }
        }
        $_REQUEST[ABJ404_PP]['ignore_donotprocess'] = $ignoreReasonDoNotProcess;
        
        // -----
        // ignore and process
        $userAgents = preg_split("@\n@", mb_strtolower($options['ignore_doprocess']), NULL, PREG_SPLIT_NO_EMPTY);
        $httpUserAgent = mb_strtolower(@$_SERVER['HTTP_USER_AGENT']);
        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $abj404logging->debugMessage("Ignoring user agent (process ok): " . 
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoProcess = 'User agent (process ok): ' . $agentToIgnore;
            }
        }
        $_REQUEST[ABJ404_PP]['ignore_doprocess'] = $ignoreReasonDoProcess;
    }
    
    /** The passed in reason will be appended to the automatically generated reason.
     * @param type $reason
     */
    function sendTo404Page($requestedURL, $reason = '') {
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;

        // this may be used later when displaying suggestions.
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        try {
            setcookie($cookieName, urldecode(esc_url($_SERVER['REQUEST_URI'])), time() + (60 * 4), "/");
            
        } catch (Exception $e) {
            $abj404logging->debugMessage("There was an issue setting a cookie: " . $e->getMessage());
            // This javascript redirect will only appear if the header redirect did not work for some reason.
            // document.cookie = "username=John Doe; expires=Thu, 18 Dec 2013 12:00:00 UTC";
            $expireTime = date("D, d M Y H:i:s T", time() + (60 * 4));
            $c = "\n" . '<script>document.cookie = "' . $cookieName . '=' . 
                    urldecode(esc_url($_SERVER['REQUEST_URI'])) . 
                    '; expires=' . $expireTime . '";</script>' . "\n";
            echo $c;
        }
        
        $_REQUEST[ABJ404_PP][$cookieName] = urldecode(esc_url($_SERVER['REQUEST_URI']));
        
        $options = $abj404logic->getOptions();
        
        // ---------------------------------------
        // if there's a default 404 page specified then use that.
        $dest404page = (array_key_exists('dest404page', $options) && isset($options['dest404page']) ? 
                $options['dest404page'] : 
            ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);
        if (($dest404page != ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED) && 
                ($dest404page != ABJ404_TYPE_404_DISPLAYED)) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0);
            
            // get the existing redirect before adding a new one.
            $redirect = $abj404dao->getExistingRedirectForURL($requestedURL);
            if (!isset($redirect['id']) || $redirect['id'] == 0) {
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_CAPTURED, $permalink['type'], $permalink['id'], $options['default_redirect'], 0);
            }
            
            $abj404dao->logRedirectHit($requestedURL, $permalink['link'], 'user specified 404 page. ' . $reason);
            $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
            exit;
        }

        // ---------------------------------------
        // give up. log the 404.
        if (@$options['capture_404'] == '1') {
            // get the existing redirect before adding a new one.
            $redirect = $abj404dao->getExistingRedirectForURL($requestedURL);
            if (!isset($redirect['id']) || $redirect['id'] == 0) {
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_CAPTURED, ABJ404_TYPE_404_DISPLAYED, ABJ404_TYPE_404_DISPLAYED, $options['default_redirect'], 0);
            }
            $abj404dao->logRedirectHit($requestedURL, '404', 'gave up. ' . $reason);
        } else {
            if ($abj404logging->isDebug()) {
                $abj404logging->debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL .
                        " | Redirect: " . wp_kses_post(json_encode($redirect)) . " | is_single(): " . is_single() . " | " .
                        "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " .
                        is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses_post(json_encode($options)));
            }
        }
    }
    
    /** 
     * @param type $skip_db_check
     * @return array
     */
    function getOptions($skip_db_check = false) {
        $options = get_option('abj404_settings');

        if (!is_array($options)) {
            add_option('abj404_settings', '', '', 'no');
            $options = array();
        }

        // Check to make sure we aren't missing any new options.
        $defaults = $this->getDefaultOptions();
        $missing = false;
        foreach ($defaults as $key => $value) {
            if (!isset($options) || $options == '' ||
                    !array_key_exists($key, $options) || !isset($options[$key]) || '' == $options[$key]) {
                $options[$key] = $value;
                $missing = true;
            }
        }

        if ($missing) {
            update_option('abj404_settings', $options);
        }

        if ($skip_db_check == false) {
            if (!array_key_exists('DB_VERSION', $options) || $options['DB_VERSION'] != ABJ404_VERSION) {
                $options = $this->updateToNewVersion($options);
            }
        }

        return $options;
    }

    /** Do any maintenance when upgrading to a new version.
     * @global type $abj404logging
     * @param type $options
     * @return type
     */
    function updateToNewVersion($options) {
        global $abj404logging;
        
        if ($this->currentlyUpdatingDatabaseVersion) {
            $abj404logging->errorMessage("Avoiding infinite loop on database update.");
            return $options;
        }

        try {
            $this->currentlyUpdatingDatabaseVersion = true;
            $returnValue = $this->updateToNewVersionAction($options);
            
        } catch (Exception $e) {
            $this->errorMessage("Error updating to new version. ", $e);
        }
        $this->currentlyUpdatingDatabaseVersion = false;
        
        return $returnValue;
    }
    
    /** Do any maintenance when upgrading to a new version.
     * @global type $abj404logic
     * @global type $abj404logging
     * @global type $wpdb
     * @param type $options
     * @return type
     */
    function updateToNewVersionAction($options) {
        global $abj404logic;
        global $abj404logging;
        global $wpdb;
        
        $currentDBVersion = "(unknown)";
        if (array_key_exists('DB_VERSION', $options)) {
            $currentDBVersion = $options['DB_VERSION'];
        }
        $abj404logging->infoMessage("Updating database version from " . $currentDBVersion . 
                " to " . ABJ404_VERSION . " (begin).");

        // wp_abj404_logsv2 exists since 1.7.
        ABJ_404_Solution_DataAccess::createDatabaseTables();

        // abj404_duplicateCronAction is no longer needed as of 1.7.
        wp_clear_scheduled_hook('abj404_duplicateCronAction');

        ABJ_404_Solution_PluginLogic::doUnregisterCrons();
        // added in 1.8.2
        ABJ_404_Solution_PluginLogic::doRegisterCrons();

        // since 1.9.0. ignore_doprocess add SeznamBot, Pinterestbot, UptimeRobot and "Slurp" -> "Yahoo! Slurp"
        if (version_compare($currentDBVersion, '1.9.0') < 0) {
            $userAgents = preg_split("@\n@", $options['ignore_doprocess'], NULL, PREG_SPLIT_NO_EMPTY);
            $uasForSearch = preg_split("@\n@", mb_strtolower($options['ignore_doprocess']), NULL, PREG_SPLIT_NO_EMPTY);

            foreach ($userAgents as &$str) {
                if (mb_strtolower(trim($str)) == "slurp") {
                    $str = "Yahoo! Slurp";
                    $abj404logging->infoMessage('Changed user agent "Slurp" to "Yahoo! Slurp" in the do not log list.');
                }
            }

            if (!in_array("seznambot", $uasForSearch)) {
                $userAgents[] = 'SeznamBot';
                $abj404logging->infoMessage('Added user agent "SeznamBot" to do not log list."');
            }
            if (!in_array("pinterestbot", $uasForSearch)) {
                $userAgents[] = 'Pinterestbot';
                $abj404logging->infoMessage('Added user agent "Pinterestbot" to do not log list."');
            }
            if (!in_array("uptimerobot", $uasForSearch)) {
                $userAgents[] = 'UptimeRobot';
                $abj404logging->infoMessage('Added user agent "UptimeRobot" to do not log list."');
            }

            $options['ignore_doprocess'] = implode("\n",$userAgents);
            update_option('abj404_settings', $options);
        }

        // move to the new log table
        if (version_compare($currentDBVersion, '1.8.0') < 0) {
            $query = "SHOW TABLES LIKE '" . $wpdb->prefix . 'abj404_logs' . "'";
            $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $rows = $result['rows'];
            
            // make sure empty() only sees a variable and not a function for older PHP versions, due to
            // https://stackoverflow.com/a/2173318 and 
            // https://wordpress.org/support/topic/fatal-error-will-latest-release/
            $filteredRows = array_filter($rows);
            if (!empty($filteredRows)) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/migrateToNewLogsTable.sql");
                $query = str_replace('{wp_abj404_logsv2}', $wpdb->prefix . 'abj404_logsv2', $query);
                $query = str_replace('{wp_abj404_logs}', $wpdb->prefix . 'abj404_logs', $query);
                $query = str_replace('{wp_abj404_redirects}', $wpdb->prefix . 'abj404_redirects', $query);
                $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);

                // if anything was successfully imported then delete the old table.
                if ($result['rows_affected'] > 0) {
                    $abj404logging->infoMessage($result['rows_affected'] . 
                            ' log rows were migrated to the new table structre.');
                    // log the rows inserted/migrated.
                    $wpdb->query('drop table ' . $wpdb->prefix . 'abj404_logs');
                }
            }
        }

        // add the second part of the default destination page.
        $dest404page = $options['dest404page'];
        if (strpos($dest404page, '|') === false) {
            // not found
            if ($dest404page == '0') {
                $dest404page .= "|" . ABJ404_TYPE_404_DISPLAYED;
            } else {
                $dest404page .= '|' . ABJ404_TYPE_POST;
            }
            $options['dest404page'] = $dest404page;
            update_option('abj404_settings', $options);
        }

        $options = $abj404logic->doUpdateDBVersionOption();
        $abj404logging->infoMessage("Updating database version to " . ABJ404_VERSION . " (end).");
        
        return $options;
    }

    /** 
     * @return array
     */
    function getDefaultOptions() {
        $options = array(
            'default_redirect' => '301',
            'send_error_logs' => '0',
            'capture_404' => '1',
            'capture_deletion' => 1095,
            'manual_deletion' => '0',
            'log_deletion' => '365',
            'admin_notification' => '200',
            'remove_matches' => '1',
            'suggest_minscore' => '25',
            'suggest_max' => '5',
            'suggest_title' => '<h3>' . __('Here are some other great pages', '404-solution') . '</h3>',
            'suggest_before' => '<ol>',
            'suggest_after' => '</ol>',
            'suggest_entrybefore' => '<li>',
            'suggest_entryafter' => '</li>',
            'suggest_noresults' => '<p>' . __('No suggestions. :/ ', '404-solution') . '</p>',
            'suggest_cats' => '1',
            'suggest_tags' => '1',
            'auto_redirects' => '1',
            'auto_score' => '90',
            'auto_deletion' => '1095',
            'auto_cats' => '1',
            'auto_tags' => '1',
            'dest404page' => '0|' . ABJ404_TYPE_404_DISPLAYED,
            'maximum_log_disk_usage' => '100',
            'ignore_dontprocess' => 'zemanta aggregator',
            'ignore_doprocess' => "Googlebot\nMediapartners-Google\nAdsBot-Google\ndevelopers.google.com\n"
            . "Bingbot\nYahoo! Slurp\nDuckDuckBot\nBaiduspider\nYandexBot\nwww.sogou.com\nSogou-Test-Spider\n"
            . "Exabot\nfacebot\nfacebookexternalhit\nia_archiver\nSeznamBot\nPinterestbot\nUptimeRobot\nMJ12bot",
            'recognized_post_types' => "page\npost\nproduct",
            'recognized_categories' => "",
            'folders_files_ignore' => "",
            'folders_files_ignore_usable' => "",
            'debug_mode' => 0,
            'DB_VERSION' => '0.0.0',
            'menuLocation' => 'underSettings',
            'admin_notification_email' => '',
            'geo2ip' => '1',
            'page_redirects_order_by' => 'url',
            'page_redirects_order' => 'ASC',
            'captured_order_by' => 'logshits',
            'captured_order' => 'DESC',
        );
        
        return $options;
    }

    function doUpdateDBVersionOption() {
        global $abj404logic;

        $options = $abj404logic->getOptions(true);

        $options['DB_VERSION'] = ABJ404_VERSION;

        update_option('abj404_settings', $options);

        return $options;
    }

    /** Remove cron jobs. */
    static function doUnregisterCrons() {
        $crons = array('abj404_cleanupCronAction', 'abj404_duplicateCronAction', 'removeDuplicatesCron', 'deleteOldRedirectsCron');
        for ($i = 0; $i < count($crons); $i++) {
            $cron_name = $crons[$i];
            $timestamp1 = wp_next_scheduled($cron_name);
            while ($timestamp1 != False) {
                wp_unschedule_event($timestamp1, $cron_name);
                $timestamp1 = wp_next_scheduled($cron_name);
            }

            $timestamp2 = wp_next_scheduled($cron_name, '');
            while ($timestamp2 != False) {
                wp_unschedule_event($timestamp2, $cron_name, '');
                $timestamp2 = wp_next_scheduled($cron_name, '');
            }

            wp_clear_scheduled_hook($cron_name);
        }
    }

    /** Create database tables. Register crons. etc.
     * @global type $abj404logic
     * @global type $abj404dao
     */
    static function runOnPluginActivation() {
        global $abj404logic;
        global $abj404dao;
        global $abj404logging;
        add_option('abj404_settings', '', '', 'no');
        
        if (!isset($abj404logging)) {
            $abj404logging = new ABJ_404_Solution_Logging();
        }
        if (!isset($abj404dao)) {
            $abj404dao = new ABJ_404_Solution_DataAccess();
        }
        if (!isset($abj404logic)) {
            $abj404logic = new ABJ_404_Solution_PluginLogic();
        }
        
        ABJ_404_Solution_DataAccess::createDatabaseTables();

        ABJ_404_Solution_PluginLogic::doRegisterCrons();

        $abj404logic->doUpdateDBVersionOption();
    }

    static function doRegisterCrons() {
        if (!wp_next_scheduled('abj404_cleanupCronAction')) {
            // we randomize this so that when the geo2ip file is downloaded, there aren't a whole
            // lot of users that request the file at the same time.
            $timeForEvent = '0' . rand(0, 5) . ':' . rand(10, 59) . ':' . rand(10, 59);
            wp_schedule_event(strtotime($timeForEvent), 'daily', 'abj404_cleanupCronAction');
        }
    }
    
    function getDebugLogFileLink() {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

    /** Do the passed in action and return the associated message. 
     * @global type $abj404logic
     * @param type $action
     * @param string $sub
     * @return type
     */
    function handlePluginAction($action, &$sub) {
        global $abj404logic;
        global $abj404dao;
        global $abj404logging;
        
        $message = "";
        
        if ($action == "updateOptions") {
            if (check_admin_referer('abj404UpdateOptions') && is_admin()) {
                // delete the debug file and lose all changes, or
                $shouldDeleteDebugFile = @$_POST['deleteDebugFile'];
                if ($shouldDeleteDebugFile) {
                    $filepath = $abj404logging->getDebugFilePath();
                    if (!file_exists($filepath)) {
                        $message = sprintf(__("Debug file not found. (%s)", '404-solution'), $filepath);
                    } else if ($abj404logging->deleteDebugFile()) {
                        $message = sprintf(__("Debug file deleted. (%s)", '404-solution'), $filepath);
                    } else {
                        $message = sprintf(__("Issue deleting debug file. (%s)", '404-solution'), $filepath);
                    }
                    return $message;
                }
                
                // save all changes. saveOptions, saveSettings
                $sub = "abj404_options";
                $message = $this->updateOptionsFromPOST();
                if ($message == "") {
                    $message = __('Options Saved Successfully!', '404-solution');
                } else {
                    $message .= __('Some options were not saved successfully.', '404-solution');
                }
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if ($action == "addRedirect") {
            if (check_admin_referer('abj404addRedirect') && is_admin()) {
                $message = $this->addAdminRedirect();
                if ($message == "") {
                    $message = __('New Redirect Added Successfully!', '404-solution');
                } else {
                    $message .= __('Error: unable to add new redirect.', '404-solution');
                }
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if ($action == "emptyRedirectTrash") {
            if (check_admin_referer('abj404_emptyRedirectTrash') && is_admin()) {
                $abj404logic->doEmptyTrash('abj404_redirects');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if ($action == "emptyCapturedTrash") {
            if (check_admin_referer('abj404_emptyCapturedTrash') && is_admin()) {
                $abj404logic->doEmptyTrash('abj404_captured');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if ($action == "purgeRedirects") {
            if (check_admin_referer('abj404_purgeRedirects') && is_admin()) {
                $message = $abj404dao->deleteSpecifiedRedirects();
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if ($action == "runMaintenance") {
            if (check_admin_referer('abj404_runMaintenance') && is_admin()) {
                $message = $abj404dao->deleteOldRedirectsCron();
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        } else if (substr($action . '', 0, 4) == "bulk") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                if (!array_key_exists('idnum', $_POST) || !isset($_POST['idnum'])) {
                    $abj404logging->debugMessage("No ID(s) specified for bulk action: " . esc_html($action));
                    echo sprintf(__("Error: No ID(s) specified for bulk action. (%s)", '404-solution'), esc_html($action),
                            false);
                    return;
                }
                $message = $abj404logic->doBulkAction($action, array_map('absint', $_POST['idnum']));
            } else {
                $abj404logging->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . esc_html($action) . ", Sub: " . esc_html($sub));
            }
        }
                
        return $message;
    }

    /** Move redirects to trash. 
     * @return type
     */
    function hanldeTrashAction() {
        global $abj404dao;
        global $abj404logging;
        
        $message = "";
        // Handle Trash Functionality
        if (array_key_exists('trash', $_GET) && isset($_GET['trash'])) {
            if (check_admin_referer('abj404_trashRedirect') && is_admin()) {
                $trash = "";
                if ($_GET['trash'] == 0) {
                    $trash = 0;
                } else if ($_GET['trash'] == 1) {
                    $trash = 1;
                } else {
                    $abj404logging->errorMessage("Unexpected trash operation: " . 
                            esc_html($_GET['trash']));
                    $message = __('Error: Bad trash operation specified.', '404-solution');
                    return $message;
                }
                
                $message = $abj404dao->moveRedirectsToTrash(absint($_GET['id']), $trash);
                if ($message == "") {
                    if ($trash == 1) {
                        $message = __('Redirect moved to trash successfully!', '404-solution');
                    } else {
                        $message = __('Redirect restored from trash successfully!', '404-solution');
                    }
                } else {
                    if ($trash == 1) {
                        $message = __('Error: Unable to move redirect to trash.', '404-solution');
                    } else {
                        $message = __('Error: Unable to move redirect from trash.', '404-solution');
                    }
                }
                
            }
        }
        
        return $message;
    }
    
    function handleActionChangeItemsPerRow() {
        global $abj404dao;
        
        if ($abj404dao->getPostOrGetSanitize('action') == 'changeItemsPerRow') {
            $showRows = max(absint($abj404dao->getPostOrGetSanitize('perpage')), ABJ404_OPTION_MIN_PERPAGE);

            $options = $this->getOptions();
            $options['perpage'] = $showRows;
            update_option('abj404_settings', $options);
        }
    }
    
    /** 
     * 
     * @global type $abj404dao
     * @global type $abj404logging
     * @return string
     */
    function handleActionImportRedirects() {
        global $abj404dao;
        global $abj404logging;
        $message = "";
        
        
        if ($abj404dao->getPostOrGetSanitize('action') == 'importRedirects') {
            if ($abj404dao->getPostOrGetSanitize('sanity_404redirected') != '1') {
                $message = __("Error: You didn't check the I understand checkbox. No importing for you!", '404-solution');
                return $message;
            }

            check_admin_referer('abj404_importRedirects');
            
            try {
                $result = $abj404dao->importDataFromPluginRedirectioner();
                if ($result['last_error'] != '') {
                    $message = sprintf(__("Error: No records were imported. SQL result: %s", '404-solution'), 
                            wp_kses_post(json_encode($result['last_error'])));
                } else {
                    $message = sprintf(__("Records imported: %s", '404-solution'), esc_html($result['rows_affected']));
                }
                
            } catch (Exception $e) {
                $message = "Error: Importing failed. Message: " . $e->getMessage();
                $abj404logging->errorMessage('Error importing redirects.', $e);
            }
        }
        
        return $message;
    }
    
    function handleActionDeleteLog() {
        $message = "";

        //Handle Delete Functionality
        if (array_key_exists('deleteDebugFile', $_GET) && @$_GET['deleteDebugFile'] == '1') {
            if (check_admin_referer('abj404_deleteDebugFile') && is_admin()) {
                $abj404dao->deleteRedirect(absint($_GET['id']));
                $message = __('Redirect Removed Successfully!', '404-solution');
            }
        }

        return $message;
    }
    
    /** Delete redirects.
     * @global type $abj404dao
     * @return type
     */
    function handleDeleteAction() {
        global $abj404dao;
        $message = "";
        
        //Handle Delete Functionality
        if (array_key_exists('remove', $_GET) && @$_GET['remove'] == 1) {
            if (check_admin_referer('abj404_removeRedirect') && is_admin()) {
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    $abj404dao->deleteRedirect(absint($_GET['id']));
                    $message = __('Redirect Removed Successfully!', '404-solution');
                }
            }
        }
        
        return $message;
    }
    
    /** Set a redirect as ignored.
     * @return type
     */
    function handleIgnoreAction() {
        global $abj404dao;
        global $abj404logging;
        $message = "";
        
        //Handle Ignore Functionality
        if (array_key_exists('ignore', $_GET) && isset($_GET['ignore'])) {
            if (check_admin_referer('abj404_ignore404') && is_admin()) {
                if ($_GET['ignore'] != 0 && $_GET['ignore'] != 1) {
                    $abj404logging->debugMessage("Unexpected ignore operation: " . 
                            esc_html($_GET['ignore']));
                    $message = __('Error: Bad ignore operation specified.', '404-solution');
                    return $message;                    
                }
                
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    if ($_GET['ignore'] == 1) {
                        $newstatus = ABJ404_STATUS_IGNORED;
                    } else {
                        $newstatus = ABJ404_STATUS_CAPTURED;
                    }
                    
                    $message = $abj404dao->updateRedirectTypeStatus(absint($_GET['id']), $newstatus);
                    if ($message == "") {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = __('Removed 404 URL from ignored list successfully!', '404-solution');
                        } else {
                            $message = __('404 URL marked as ignored successfully!', '404-solution');
                        }
                    } else {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = __('Error: unable to remove URL from ignored list', '404-solution');
                        } else {
                            $message = __('Error: unable to mark URL as ignored', '404-solution');
                        }
                    }
                }
            }
        }

        return $message;
    }
    
    /** Set a redirect as "organize later".
     * @return type
     */
    function handleLaterAction() {
        global $abj404dao;
        global $abj404logging;
        $message = "";
        
        //Handle Ignore Functionality
        if (array_key_exists('later', $_GET) && isset($_GET['later'])) {
            if (check_admin_referer('abj404_organizeLater') && is_admin()) {
                if ($_GET['later'] != 0 && $_GET['later'] != 1) {
                    $abj404logging->debugMessage("Unexpected organize later operation: " . 
                            esc_html($_GET['later']));
                    $message = __('Error: Bad organize later operation specified.', '404-solution');
                    return $message;                    
                }
                
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    if ($_GET['later'] == 1) {
                        $newstatus = ABJ404_STATUS_LATER;
                    } else {
                        $newstatus = ABJ404_STATUS_CAPTURED;
                    }
                    
                    $message = $abj404dao->updateRedirectTypeStatus(absint($_GET['id']), $newstatus);
                    if ($message == "") {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = __('Removed 404 URL from organize later list successfully!', '404-solution');
                        } else {
                            $message = __('404 URL marked as organize later successfully!', '404-solution');
                        }
                    } else {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = __('Error: unable to remove URL from organize later list', '404-solution');
                        } else {
                            $message = __('Error: unable to mark URL as organize later', '404-solution');
                        }
                    }
                }
            }
        }

        return $message;
    }

    /** Edit redirect data.
     * @return type
     */
    function handleActionEdit(&$sub) {
        global $abj404dao;
        $message = "";
        
        //Handle edit posts
        if (array_key_exists('action', $_POST) && @$_POST['action'] == "editRedirect") {
            $id = $abj404dao->getPostOrGetSanitize('id');
            $ids = $abj404dao->getPostOrGetSanitize('ids_multiple');
            if (!($id === null && $ids === null) && (preg_match('/[0-9]+/', '' . $id) || preg_match('/[0-9]+/', '' . $ids))) {
                if (check_admin_referer('abj404editRedirect') && is_admin()) {
                    $message = $this->updateRedirectData();
                    if ($message == "") {
                        $message .= __('Redirect Information Updated Successfully!', '404-solution');
                        $sub = 'abj404_redirects';
                    } else {
                        $message .= __('Error: Unable to update redirect data.', '404-solution');
                    }
                }
            }
        }

        return $message;
    }
    
    /**
     * @global type $abj404dao
     * @param type $action
     * @param type $ids
     * @return string
     */
    function doBulkAction($action, $ids) {
        global $abj404dao;
        global $abj404logging;
        $message = "";

        // nonce already verified.
        
        $abj404logging->debugMessage("In doBulkAction. Action: " . 
                esc_html($action == '' ? '(none)' : $action)) . ", ids: " . wp_kses_post(json_encode($ids));

        if ($action == "bulkignore" || $action == "bulkcaptured" || $action == "bulklater") {
            if ($action == "bulkignore") {
                $status = ABJ404_STATUS_IGNORED;
            } else if ($action == "bulkcaptured") {
                $status = ABJ404_STATUS_CAPTURED;
            } else if ($action == "bulklater") {
                $status = ABJ404_STATUS_LATER;
                
            } else {
                $abj404logging->errorMessage("Unrecognized bulk action: " . esc_html($action));
                echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
                return;
            }
            $count = 0;
            foreach ($ids as $id) {
                $s = $abj404dao->updateRedirectTypeStatus($id, $status);
                if ($s == "") {
                    $count++;
                }
            }
            if ($action == "bulkignore") {
                $message = $count . " " . __('URLs marked as ignored.', '404-solution');
            } else if ($action == "bulkcaptured") {
                $message = $count . " " . __('URLs marked as captured.', '404-solution');
            } else if ($action == "bulklater") {
                $message = $count . " " . __('URLs marked as Later.', '404-solution');
            } else {
                $abj404logging->errorMessage("Unrecognized bulk action: " . esc_html($action));
                echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
            }

        } else if ($action == "bulktrash") {
            $count = 0;
            foreach ($ids as $id) {
                $s = $abj404dao->moveRedirectsToTrash($id, 1);
                if ($s == "") {
                    $count ++;
                }
            }
            $message = $count . " " . __('URLs moved to trash', '404-solution');

        } else {
            $abj404logging->errorMessage("Unrecognized bulk action: " . esc_html($action));
            echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
        }
        return $message;
    }

    /** 
     * This is for both empty trash buttons (page redirects and captured 404 URLs).
     * @param type $sub
     */
    function doEmptyTrash($sub) {
        global $abj404logging;
        global $wpdb;
        global $abj404_redirect_types;
        global $abj404_captured_types;
        
        $redirectsTable = $wpdb->prefix . "abj404_redirects";
        $query = "";
        if ($sub == "abj404_captured") {
            $query = "delete FROM " . $redirectsTable . " \n" .
                    "where disabled = 1 \n" .
                    "      and status in (" . implode(", ", $abj404_captured_types) . ")";
            
        } else if ($sub == "abj404_redirects") {
            $query = "delete FROM " . $redirectsTable . " \n" .
                    "where disabled = 1 \n" .
                    "      and status in (" . implode(", ", $abj404_redirect_types) . ")";
            
        } else {
            $abj404logging->errorMessage("Unrecognized type in doEmptyTrash(" . $sub . ")");
        }

        $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        $abj404logging->debugMessage("doEmptyTrash deleted " . $result['rows_affected'] . " rows total. (" . $sub . ")");
        
        ABJ_404_Solution_DataAccess::queryAndGetResults("optimize table " . $redirectsTable);
    }
    
    /** 
     * @global type $abj404dao
     * @return string
     */
    function updateRedirectData() {
        global $abj404dao;
        global $abj404logging;
        $message = "";
        $fromURL = "";
        $ids_multiple = "";
        
        if (@$_POST['url'] == "" && @$_POST['ids_multiple'] != "") {
            $ids_multiple = array_map('absint', explode(',', $_POST['ids_multiple']));
        } else if (@$_POST['url'] != "" && @$_POST['ids_multiple'] == "") {
            $fromURL = $_POST['url'];
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
        }

        if ($fromURL != "" && mb_substr($_POST['url'], 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
        }

        $typeAndDest = $this->getRedirectTypeAndDest();

        if ($typeAndDest['message'] != "") {
            return $typeAndDest['message'];
        }

        if ($typeAndDest['type'] != "" && $typeAndDest['dest'] !== "") {
            $statusType = ABJ404_STATUS_MANUAL;
            if (array_key_exists('is_regex_url', $_POST) && isset($_POST['is_regex_url']) && 
                $_POST['is_regex_url'] != '0') {
                
                $statusType = ABJ404_STATUS_REGEX;
            }
            
            // decide whether we're updating one or multiple redirects.
            if ($fromURL != "") {
                $abj404dao->updateRedirect($typeAndDest['type'], $typeAndDest['dest'], $fromURL, $_POST['id'], 
                        $_POST['code'], $statusType);

            } else if ($ids_multiple != "") {
                // get the redirect data for each ID.
                $redirects_multiple = $abj404dao->getRedirectsByIDs($ids_multiple);
                foreach ($redirects_multiple as $redirect) {
                    $abj404dao->updateRedirect($typeAndDest['type'], $typeAndDest['dest'], 
                            $redirect['url'], $redirect['id'], $_POST['code'], $statusType);
                }

            } else {
                $abj404logging->errorMessage("Issue determining which redirect(s) to update. " . 
                    "fromURL: " . $fromURL . ", ids_multiple: " . implode(',', $ids_multiple));
            }

            $_POST['url'] = "";
            $_POST['code'] = "";
            $_POST['external'] = "";
            $_POST['dest'] = "";
        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $abj404logging->errorMessage("Update redirect data issue. Type: " . esc_html($typeAndDest['type']) . ", dest: " .
                    esc_html($typeAndDest['dest']));
        }

        return $message;
    }
    
    function getRedirectTypeAndDest() {
        global $abj404logging;
        
        $response = array();
        $response['type'] = "";
        $response['dest'] = "";
        $response['message'] = "";
        
        if ($_POST['dest'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            if ($_POST['external'] == "") {
                $response['message'] = __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>";
            } else {
                if (substr($_POST['external'], 0, 7) != "http://" && mb_substr($_POST['external'], 0, 8) != "https://" && mb_substr($_POST['external'], 0, 6) != "ftp://") {
                    $response['message'] = __('Error: External URLs must start with http://, https://, or ftp://', '404-solution') . "<BR/>";
                }
            }
        }

        if ($response['message'] != "") {
            return $response;
        }
        $info = explode("|", sanitize_text_field($_POST['dest']));

        if ($_POST['dest'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $response['type'] = ABJ404_TYPE_EXTERNAL;
            $response['dest'] = esc_url($_POST['external']);
        } else {
            if (count($info) == 2) {
                $response['dest'] = absint($info[0]);
                $response['type'] = $info[1];
            } else {
                $abj404logging->errorMessage("Unexpected info while updating redirect: " . 
                        wp_kses_post(json_encode($info)));
            }
        }
        
        return $response;
    }
    
    /**
     * @global type $abj404dao
     * @return string
     */
    function addAdminRedirect() {
        global $abj404dao;
        global $abj404logging;
        $message = "";
        
        if ($_POST['manual_redirect_url'] == "") {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
            return $message;
        }
            
        if (substr($_POST['manual_redirect_url'], 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
            return $message;
        }

        $typeAndDest = $this->getRedirectTypeAndDest();

        if ($typeAndDest['message'] != "") {
            return $typeAndDest['message'];
        }

        if ($typeAndDest['type'] != "" && $typeAndDest['dest'] !== "") {
            // url match type. regex or normal exact match.
            $statusType = ABJ404_STATUS_MANUAL;
            if (array_key_exists('is_regex_url', $_POST) && isset($_POST['is_regex_url']) && 
                $_POST['is_regex_url'] != '0') {
                
                $statusType = ABJ404_STATUS_REGEX;
            }
            
            $abj404dao->setupRedirect(esc_url($_POST['manual_redirect_url']), $statusType, 
                    $typeAndDest['type'], $typeAndDest['dest'], sanitize_text_field($_POST['code']), 0);
            $_POST['manual_redirect_url'] = "";
            $_POST['code'] = "";
            $_POST['external'] = "";
            $_POST['dest'] = "";
        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $abj404logging->errorMessage("Add redirect data issue. Type: " . esc_html($typeAndDest['type']) . ", dest: " .
                    esc_html($typeAndDest['dest']));
        }
        

        return $message;
    }

    /** 
     * @global type $abj404dao
     * @return 
     */
    function getTableOptions($pageBeingViewed = null) {
        global $abj404dao;
        $tableOptions = array();
        $options = $this->getOptions(true);

        $tableOptions['filter'] = $abj404dao->getPostOrGetSanitize("filter", "");
        if ($tableOptions['filter'] == "") {
            if (array_key_exists('subpage', $_GET) && @$_GET['subpage'] == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_STATUS_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        }

        if (array_key_exists('orderby', $_GET) && isset($_GET['orderby'])) {
            $tableOptions['orderby'] = esc_sql($_GET['orderby']);

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order_by'] = $tableOptions['orderby'];
                update_option('abj404_settings', $options);
                
            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order_by'] = $tableOptions['orderby'];
                update_option('abj404_settings', $options);
            }
            
        } else if (array_key_exists('subpage', $_GET) && @$_GET['subpage'] == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
            
        } else {
            
            if ($pageBeingViewed == 'abj404_redirects') {
                $tableOptions['orderby'] = $options['page_redirects_order_by'];
                
            } else if ($pageBeingViewed == 'abj404_captured') {
                $tableOptions['orderby'] = $options['captured_order_by'];
                
            } else {
                $tableOptions['orderby'] = "url";
            }
        }

        if (array_key_exists('order', $_GET) && isset($_GET['order'])) {
            $tableOptions['order'] = esc_sql($_GET['order']);

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order'] = $tableOptions['order'];
                update_option('abj404_settings', $options);
                
            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order'] = $tableOptions['order'];
                update_option('abj404_settings', $options);
            }
            
        } else if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";
            
        } else {
            
            if ($pageBeingViewed == 'abj404_redirects') {
                $tableOptions['order'] = $options['page_redirects_order'];
                
            } else if ($pageBeingViewed == 'abj404_captured') {
                $tableOptions['order'] = $options['captured_order'];
                
            } else {
                $tableOptions['order'] = "ASC";
            }
        }

        $tableOptions['paged'] = $abj404dao->getPostOrGetSanitize("paged", 1);

        $perPageOption = ABJ404_OPTION_DEFAULT_PERPAGE;
        if (array_key_exists('perpage', $options) && isset($options['perpage'])) {
            $perPageOption = max(absint($options['perpage']), ABJ404_OPTION_MIN_PERPAGE);
        }
        $tableOptions['perpage'] = $abj404dao->getPostOrGetSanitize("perpage", $perPageOption);

        if (array_key_exists('subpage', $_GET) && @$_GET['subpage'] == "abj404_logs") {
            if (array_key_exists('id', $_GET) && isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {                
                $tableOptions['logsid'] = absint($_GET['id']);
            } else {
                $tableOptions['logsid'] = 0;
            }
        }

        // sanitize all values.
        foreach ($tableOptions as &$value) {
            $value = esc_sql(sanitize_text_field($value));
        }
        unset($value);

        return $tableOptions;
    }
    
    /** 
     * @global type $abj404logic
     * @return string
     */
    function updateOptionsFromPOST() {
        $message = "";
        $options = $this->getOptions();
        
        // options with custom messages.
        if (array_key_exists('default_redirect', $_POST) && isset($_POST['default_redirect'])) {
            if ($_POST['default_redirect'] == "301" || $_POST['default_redirect'] == "302") {
                $options['default_redirect'] = intval($_POST['default_redirect']);
            } else {
                $message .= __('Error: Invalid value specified for default redirect type', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('admin_notification', $_POST) && isset($_POST['admin_notification'])) {
            if (preg_match('/^[0-9]+$/', $_POST['admin_notification']) == 1) {
                $options['admin_notification'] = absint($_POST['admin_notification']);
            }
        }
        
        if (array_key_exists('capture_deletion', $_POST) && isset($_POST['capture_deletion'])) {
            if (preg_match('/^[0-9]+$/', $_POST['capture_deletion']) == 1 && $_POST['capture_deletion'] >= 0) {
                $options['capture_deletion'] = absint($_POST['capture_deletion']);
            } else {
                $message .= __('Error: Collected URL deletion value must be a number greater than or equal to zero', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('manual_deletion', $_POST) && isset($_POST['manual_deletion'])) {
            if (preg_match('/^[0-9]+$/', $_POST['manual_deletion']) == 1 && $_POST['manual_deletion'] >= 0) {
                $options['manual_deletion'] = absint($_POST['manual_deletion']);
            } else {
                $message .= __('Error: Manual redirect deletion value must be a number greater than or equal to zero', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('log_deletion', $_POST) && isset($_POST['log_deletion'])) {
            if (preg_match('/^[0-9]+$/', $_POST['log_deletion']) == 1 && $_POST['log_deletion'] >= 0) {
                $options['log_deletion'] = absint($_POST['log_deletion']);
            } else {
                $message .= __('Error: Log deletion value must be a number greater than or equal to zero', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('suggest_minscore', $_POST) && isset($_POST['suggest_minscore'])) {
            if (preg_match('/^[0-9]+$/', $_POST['suggest_minscore']) == 1 && $_POST['suggest_minscore'] >= 0 && $_POST['suggest_minscore'] <= 99) {
                $options['suggest_minscore'] = min(max(absint($_POST['suggest_minscore']), 10), 90);
            } else {
                $message .= __('Error: Suggestion minimum score value must be a number between 1 and 99', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('suggest_max', $_POST) && isset($_POST['suggest_max'])) {
            if (preg_match('/^[0-9]+$/', $_POST['suggest_max']) == 1 && $_POST['suggest_max'] >= 1) {
                $options['suggest_max'] = absint($_POST['suggest_max']);
            } else {
                $message .= __('Error: Maximum number of suggest value must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
            }
        }
        
        if (array_key_exists('auto_score', $_POST) && isset($_POST['auto_score'])) {
            if (preg_match('/^[0-9]+$/', $_POST['auto_score']) == 1 && $_POST['auto_score'] >= 0 && $_POST['auto_score'] <= 99) {
                $options['auto_score'] = absint($_POST['auto_score']);
            } else {
                $message .= __('Error: Auto match score value must be a number between 0 and 99', '404-solution') . ".<BR/>";
            }
        }
        
        if (array_key_exists('auto_deletion', $_POST) && isset($_POST['auto_deletion'])) {
            if (preg_match('/^[0-9]+$/', $_POST['auto_deletion']) == 1 && $_POST['auto_deletion'] >= 0) {
                $options['auto_deletion'] = absint($_POST['auto_deletion']);
            } else {
                $message .= __('Error: Auto redirect deletion value must be a number greater than or equal to zero', '404-solution') . ".<BR/>";
            }
        }

        if (array_key_exists('maximum_log_disk_usage', $_POST) && isset($_POST['maximum_log_disk_usage'])) {
            if (preg_match('/^[0-9]+$/', $_POST['maximum_log_disk_usage']) == 1 && $_POST['maximum_log_disk_usage'] > 0) {
                $options['maximum_log_disk_usage'] = absint($_POST['maximum_log_disk_usage']);
            } else {
                $message .= __('Error: Maximum log disk usage must be a number greater than zero', '404-solution') . ".<BR/>";
            }
        }

        // these options all default to 0 if they're not specifically set to 1.
        $optionsList = array('remove_matches', 'debug_mode', 'suggest_cats', 'suggest_tags', 
            'auto_redirects', 'auto_cats', 'auto_tags', 'capture_404', 'send_error_logs', 'log_raw_ips');
        foreach ($optionsList as $optionName) {
            $options[$optionName] = (array_key_exists($optionName, $_POST) && $_POST[$optionName] == "1") ? 1 : 0;
        }

        // the suggest_.* options have html in them.
        $optionsListSuggest = array('suggest_title', 'suggest_before', 'suggest_after', 'suggest_entrybefore', 
            'suggest_entryafter', 'suggest_noresults');
        foreach ($optionsListSuggest as $optionName) {
            $options[$optionName] = wp_kses_post($_POST[$optionName]);
        }

        if (array_key_exists('dest404page', $_POST) && isset($_POST['dest404page'])) {
            $options['dest404page'] = sanitize_text_field(@$_POST['dest404page']);
        }
        if (array_key_exists('ignore_dontprocess', $_POST) && isset($_POST['ignore_dontprocess'])) {
            $options['ignore_dontprocess'] = wp_kses_post(@$_POST['ignore_dontprocess']);
        }
        if (array_key_exists('ignore_doprocess', $_POST) && isset($_POST['ignore_doprocess'])) {
            $options['ignore_doprocess'] = wp_kses_post(@$_POST['ignore_doprocess']);
        }
        if (array_key_exists('recognized_post_types', $_POST) && isset($_POST['recognized_post_types'])) {
            $options['recognized_post_types'] = wp_kses_post(@$_POST['recognized_post_types']);
        }
        if (array_key_exists('recognized_categories', $_POST) && isset($_POST['recognized_categories'])) {
            $options['recognized_categories'] = wp_kses_post(@$_POST['recognized_categories']);
        }
        if (array_key_exists('menuLocation', $_POST) && isset($_POST['menuLocation'])) {
            $options['menuLocation'] = wp_kses_post(@$_POST['menuLocation']);
        }
        if (array_key_exists('geo2ip', $_POST) && isset($_POST['geo2ip'])) {
            $options['geo2ip'] = wp_kses_post(@$_POST['geo2ip']);
        }
        if (array_key_exists('admin_notification_email', $_POST) && isset($_POST['admin_notification_email'])) {
            $options['admin_notification_email'] = trim(wp_kses_post(@$_POST['admin_notification_email']));
        }
        
        if (array_key_exists('folders_files_ignore', $_POST) && isset($_POST['folders_files_ignore'])) {
            $options['folders_files_ignore'] = wp_unslash(wp_kses_post(@$_POST['folders_files_ignore']));
            
            // make the regular expressions usable.
            $patternsToIgnore = preg_split("@\n@", $options['folders_files_ignore'], NULL, PREG_SPLIT_NO_EMPTY);
            $usableFilePatterns = array();
            foreach ($patternsToIgnore as $patternToIgnore) {
                $newPattern = '^' . preg_quote(trim($patternToIgnore), '/') . '$';
                $newPattern = str_replace("\*",".*", $newPattern);
                $usableFilePatterns[] = $newPattern;
            }
            $options['folders_files_ignore_usable'] = $usableFilePatterns;
        }

        /** Sanitize all data. */
        foreach ($options as $key => $option) {
            $new_key = wp_kses_post($key);
            $new_option = wp_kses_post($option);
            $new_options[$new_key] = $new_option;
        }

        update_option('abj404_settings', $new_options);
        
        global $abj404logging;
        $abj404logging->infoMessage("POST data: " . json_encode($_POST));

        return $message;
    }
    
    /** First try a wp_redirect. Then try a redirect with JavaScript. The wp_redirect usually works, but doesn't 
     * if some other plugin has already output any kind of data. 
     * @param type $location
     * @param type $status
     * @param type $queryParts The query part of a URL. e.g. "?s=queryPart"
     */
    function forceRedirect($location, $status = 302, $queryParts = '') {
        // try a normal redirect using a header.
        wp_redirect($location . $queryParts, $status);
        
        // TODO add an ajax request here that fires after 5 seconds. 
        // upon getting the request the server will log the error. the plugin could then notify an admin.
        
        // This javascript redirect will only appear if the header redirect did not work for some reason.
        $c = '<script>' . 'function doRedirect() {' . "\n" .
                '   window.location.replace("' . $location . '");' . "\n" .
                '}' . "\n" .
                'setTimeout(doRedirect, 1);' . "\n" .
                '</script>' . "\n" .
                'Page moved: <a href="' . $location . $queryParts . '">' . $location . '</a>';
        echo $c;
        exit;
    }

    /** Order pages and set the page depth for child pages.
     * Move the children to be underneath the parents.
     * @param type $pages
     */    
    function orderPageResults($pages, $includeMissingParentPages = false) {
        global $abj404logging;
        global $abj404dao;
        
        // sort by type then title.
        usort($pages, array($this, "sortByTypeThenTitle"));
        // run this to see if there are any child pages left.
        $orderedPages = $this->setDepthAndAddChildren($pages);
        
        // The pages are now sorted. We now apply the depth AND we make sure the child pages
        // always immediately follow the parent pages.

        // -------------
        if ($includeMissingParentPages && (count($orderedPages) != count($pages))) {
            $iterations = 0;
            
            do {
                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);
                $idCountBefore = count($idsOfMissingParentPages);
                $iterations = $iterations + 1;
                
                // get the parents of the unused pages.
                foreach ($idsOfMissingParentPages as $pageID) {
                    $postParent = get_post($pageID);
                    if ($postParent == null) {
                        continue;
                    }
                    $slug = $postParent->post_name;
                    $parentPage = $abj404dao->getPublishedPagesAndPostsIDs($slug);
                    if (count($parentPage != 0)) {
                        $pages[] = $parentPage[0];
                    }
                }
                
                if ($iterations > 10) {
                    break;
                }
                
                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);
                
                // loop until we can't find any more parents. This may happen if a sub-page is published
                // and the parent page is not published.
            } while ($idCountBefore != count($idsOfMissingParentPages));
            
            // sort everything again
            usort($pages, array($this, "sortByTypeThenTitle"));
            $orderedPages = $this->setDepthAndAddChildren($pages);
        }
        
        // if there are child pages left over then there's an issue. it means there's a child page that was
        // returned but the parent for that child was not returned. so we don't have any place to display
        // the child page. this could be because the parent page is not "published"
        if ($abj404logging->isDebug()) {
            if (count($orderedPages) != count($pages)) {
                $unusedPages = array_udiff($pages, $orderedPages, array($this, 'compareByID'));
                $abj404logging->debugMessage("There was an issue finding the parent pages for some child pages. " .
                        "These pages' parents may not have a 'published' status. Pages: " . 
                        wp_kses_post(json_encode($unusedPages)));
            }
        }
        
        return $orderedPages;
    }
    
    /** Returns a list of parent IDs that can't be found in the passed in pages.
     * @param type $pages
     */
    function getMissingParentPageIDs($pages) {
        $listOfIDs = array();
        $missingParentPageIDs = array();
        
        foreach ($pages as $page) {
            $listOfIDs[] = $page->id;
        }
        
        foreach ($pages as $page) {
            if ($page->post_parent == 0) {
                continue;
            }
            if (in_array($page->post_parent, $listOfIDs)) {
                continue;
            }
            
            $missingParentPageIDs[] = $page->post_parent;
        }

        $missingParentPageIDs = array_unique($missingParentPageIDs);
        return $missingParentPageIDs;
    }

    /** Compare pages based on their ID.
     * @param type $a
     * @param type $b
     * @return int
     */
    function compareByID($a, $b) {
        if ($a->id < $b->id) {
            return -1;
        }
        if ($b->id < $a->id) {
            return 1;
        }
        return 0;
    }
    
    /** Set the depth of each page and add pages under their parents by rebuilding the list
     * every time we iterate through it and adding the child pages at the right moment every time
     * the list is built.
     * @param type $pages
     * @return type
     */
    function setDepthAndAddChildren($pages) {
        // find all child pages (pages that have parents).
        $childPages = $this->findChildPages($pages);
        
        // find all pages with no parents.
        $mainPages = $this->findAllMainPages($pages);
        
        $oldChildPageCount = -1;
        
        // this do{} loop is here because some child pages have children.
        do {
            // add every page to a new list, while looking for parents.
            $orderedPages = array();
            foreach ($mainPages as $page) {
                // always add the main page.
                $orderedPages[] = $page;
                
                // if this page is the parent of any children then add the children.
                $removeThese = array();
                foreach ($childPages as $child) {
                    if ($child->post_parent == $page->id) {
                        // set the page depth based on the parent's page depth.
                        $child->depth = $page->depth + 1;

                        $removeThese[] = $child;
                        $orderedPages[] = $child;
                    }
                }
                
                // remove any child pages that have been placed already
                $childPages = $this->removeUsedChildPages($childPages, $removeThese);
            }
            
            // the new list becomes the list that we will iterate over next time. 
            // this prepares us for the next iteration and for child pages with a depth greater than 1.
            // (for child pages that have children).
            $mainPages = $orderedPages;
            
            // if the count has not changed then there's no point in looping again.
            if (count($childPages) == $oldChildPageCount) {
                break;
            }
            $oldChildPageCount = count($childPages);
            // stop the loop once there are no more children to add.
        } while (count($childPages) > 0);
        
        return $orderedPages;
    }
    
    /** 
     * @param type $pages
     * @return type
     */
    function findAllMainPages($pages) {
        $mainPages = array();
        foreach ($pages as $page) {
            // if there's no parent then just add the page.
            if ($page->post_parent == 0) {
                $mainPages[] = $page;
            }
        }
        
        return $mainPages;
    }
    
    /** 
     * @param type $childPages
     * @param type $removeThese
     * @return type
     */
    function removeUsedChildPages($childPages, $removeThese) {
        // if any children were added then remove them from the list.
        foreach ($removeThese as $removeThis) {
            $key = array_search($removeThis, $childPages);
            if ($key !== false) {
               unset($childPages[$key]);
            }
        }
        
        return $childPages;
    }
    
    /** Return pages that have a non-0 parent.
     * @param type $pages
     * @return type
     */
    function findChildPages($pages) {
        $childPages = array();
        foreach ($pages as $page) {
            if ($page->post_parent != 0) {
                $childPages[] = $page;
            }
        }
        return $childPages;
    }

    /** 
     * @param type $a
     * @param type $b
     * @return type
     */
    function sortByTypeThenTitle($a, $b) {
        // first sort by type
        $result = strcmp($a->post_type, $b->post_type);
        if ($result != 0) {
            return $result;
        }
        
        // then by title.
        return strcmp($a->post_title, $b->post_title);
    }

    /** Send an email if a notification should be displayed. Return true if an email is sent, or false otherwise.
     * @global type $abj404dao
     * @global type $abj404logging
     * @return type
     */
    function emailCaptured404Notification() {
        global $abj404dao;
        global $abj404logging;
        
        $options = $this->getOptions(true);
        
        $captured404Count = $abj404dao->getCapturedCountForNotification();
        if (!$this->shouldNotifyAboutCaptured404s($captured404Count)) {
            return "Not enough 404s found to send an admin notification email (" . $captured404Count . ").";
        }
        
        $captured404URLSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_captured';
        $generalSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';
        $to = $options['admin_notification_email'];
        $subject = '404 Solution: Captured 404 Notification';
        $body = "There are currently " . $captured404Count . " captured 404s to look at. <BR/><BR/>\n\n";
        $body .= 'Visit <a href="' . $captured404URLSettings . '">' . $captured404URLSettings . 
                '</a> to see them.<BR/><BR/>' . "\n";
        $body .= 'To stop getting these emails, update the settings at <a href="' . $generalSettings . '">' . 
                $generalSettings . '</a>, or contact the site administrator.' . "<BR/>\n";
        $body .= "<BR/><BR/>\n\nSent " . date('Y/m/d h:i:s T') . "<BR/>\n" . "PHP version: " . PHP_VERSION . 
                ", <BR/>\nPlugin version: " . ABJ404_VERSION;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = 'From: ' . get_option('admin_email') . '<' . get_option('admin_email') . '>';
        
        // send the email
        $abj404logging->debugMessage("Sending captured 404 notification email to: " . $options['admin_notification_email']);
        wp_mail($to, $subject, $body, $headers);
        $abj404logging->debugMessage("Captured 404 notification email sent.");
        return "Captured 404 notification email sent to: " . trim($options['admin_notification_email']);
    }
    
    /** Return true if a notification should be displayed, or false otherwise.
     * @global type $abj404dao
     * @param type $captured404Count the number of captured 404s
     * @return boolean
     */
    function shouldNotifyAboutCaptured404s($captured404Count) {
        $options = $this->getOptions(true);
        
        if (array_key_exists('admin_notification', $options) && isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            if ($captured404Count >= $options['admin_notification']) {
                return true;
            }
        }
        
        return false;
    }
}
