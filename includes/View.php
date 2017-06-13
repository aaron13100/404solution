<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist) && is_admin()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

class ABJ_404_Solution_View {

    /** Get the text to notify the user when some URLs have been captured and need attention. 
     * @param int $captured the number of captured URLs
     * @return type html
     */
    function getDashboardNotificationCaptured($captured) {
        $capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.', 
                'There are <a>%s captured 404 URLs</a> that need to be processed.', 
                $captured, '404-solution'), $captured);
        $capturedMessage = str_replace("<a>", 
                "<a href=\"options-general.php?page=" . ABJ404_PP . "&subpage=abj404_captured\" >", 
                $capturedMessage);
        $capturedMessage = str_replace("</a>", "</a>", $capturedMessage);

        return '<div class="notice notice-info"><p><strong>' . esc_html(__('404 Solution', '404-solution')) . 
                ":</strong> " . $capturedMessage . "</p></div>";
    }

    /** Do an action like trash/delete/ignore/edit and display a page like stats/logs/redirects/options.
     * @global type $abj404view
     * @global type $abj404logic
     */
    static function handleMainAdminPageActionAndDisplay() {
        global $abj404view;
        global $abj404logic;
        global $abj404logging;
        global $abj404dao;
        
        try {
            $action = $abj404dao->getPostOrGetSanitize('action');
            
            if (!is_admin() || !current_user_can('administrator')) { 
                $abj404logging->logUserCapabilities("handleMainAdminPageActionAndDisplay (" . 
                        esc_html($action == '' ? '(none)' : $action) . ")");
                return; 
            }

            $sub = "";

            // --------------------------------------------------------------------
            // Handle Post Actions
            $abj404logging->debugMessage("Processing request for action: " . 
                    esc_html($action == '' ? '(none)' : $action));

            // this should really not pass things by reference so it can be more object oriented (encapsulation etc).
            $message = "";
            $message .= $abj404logic->handlePluginAction($action, $sub);
            $message .= $abj404logic->hanldeTrashAction();
            $message .= $abj404logic->handleDeleteAction();
            $message .= $abj404logic->handleIgnoreAction();
            $message .= $abj404logic->handleActionEdit($sub);
            $message .= $abj404logic->handleActionDeleteLog();
            $message .= $abj404logic->handleActionImportRedirects();
            $message .= $abj404logic->handleActionChangeItemsPerRow();

            // --------------------------------------------------------------------
            // Output the correct page.
            $abj404view->echoChosenAdminTab($action, $sub, $message);
            
        } catch (Exception $e) {
            $abj404logging->errorMessage("Caught exception: " . stripcslashes(wp_kses_post(json_encode($e))));
            throw $e;
        }
    }
    
    /** Display the chosen admin page.
     * @global type $abj404view
     * @param type $sub
     * @param type $message
     */
    function echoChosenAdminTab($action, $sub, $message) {
        global $abj404view;
        global $abj404logging;
        global $abj404dao;

        // Deal With Page Tabs
        if ($sub == "") {
            $sub = strtolower($abj404dao->getPostOrGetSanitize('subpage'));
        }
        if ($sub == "") {
            $sub = 'abj404_redirects';
            $abj404logging->debugMessage('No tab selected. Displaying the "redirects" tab.');
        }
        
        $abj404logging->debugMessage("Displaying sub page: " . esc_html($sub == '' ? '(none)' : $sub));
        
        $abj404view->outputAdminHeaderTabs($sub, $message);
        
        if ($sub == 'abj404_redirects') {
            $abj404view->echoAdminRedirectsPage();
        } else if (($action == 'editRedirect') || ($sub == 'abj404_edit')) {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == 'abj404_captured') {
            $abj404view->echoAdminCapturedURLsPage();
        } else if ($sub == "abj404_options") {
            $abj404view->echoAdminOptionsPage();
        } else if ($sub == 'abj404_logs') {
            $abj404view->echoAdminLogsPage();
        } else if ($sub == 'abj404_stats') {
            $abj404view->outputAdminStatsPage();
        } else if ($sub == 'abj404_tools') {
            $abj404view->echoAdminToolsPage();
        } else if ($sub == 'abj404_debugfile') {
            $abj404view->echoAdminDebugFile();
        } else {
            $abj404logging->debugMessage('No tab selected. Displaying the "redirects" tab.');
            $abj404view->echoAdminRedirectsPage();
        }
        
        $abj404view->echoAdminFooter();
    }
    
    /** Echo the text that appears at the bottom of each admin page. */
    function echoAdminFooter() {
        echo "<div style=\"clear: both;\">";
        echo "<BR/>";
        echo "<HR/><strong>Credits:</strong><BR/>";
        echo "<a href=\"" . ABJ404_HOME_URL . "\" title=\"" . __('404 Solution') . "\" target=\"_blank\">" . __('404 Solution') . "</a> ";
        echo __('is maintained by', '404-solution');
        echo " ";
        echo "<a href=\"http://www.wealth-psychology.com/404-solution/\" title=\"Aaron J\" target=\"_blank\">Aaron J</a>. | ";

        echo __('Version', '404-solution') . ": " . ABJ404_VERSION;

        echo "</div>";
        echo "</div>";
    }

    /** Output the tabs at the top of the plugin page.
     * @param type $sub
     * @param type $message
     */
    function outputAdminHeaderTabs($sub = 'list', $message = '') {
        if ($sub == "abj404_options") {
            $header = " " . __('Options', '404-solution');
        } else if ($sub == 'abj404_logs') {
            $header = " " . __('Logs', '404-solution');
        } else if ($sub == 'abj404_stats') {
            $header = " " . __('Stats', '404-solution');
        } else if ($sub == 'abj404_edit') {
            $header = ": " . __('Edit Redirect', '404-solution');
        } else if ($sub == 'abj404_redirects') {
            $header = "";
        } else {
            $header = "";
        }
        echo "<div class=\"wrap\">";
        if ($sub == "abj404_options") {
            echo "<div id=\"icon-options-general\" class=\"icon32\"></div>";
        } else {
            echo "<div id=\"icon-tools\" class=\"icon32\"></div>";
        }
        echo "<h2>" . __('404 Solution', '404-solution') . esc_html($header) . "</h2>";
        if ($message != "") {
            $allowed_tags = array(
                'br' => array(),
                'em' => array(),
                'strong' => array(),
            );
            
            if ((strlen($message) >= 6) && (substr(strtolower($message), 0, 6) == 'error:')) {
                $cssClasses = 'notice notice-error';
            } else {
                $cssClasses = 'notice notice-success';
            }
            
            echo '<div class="' . $cssClasses . '"><p>' . wp_kses($message, $allowed_tags) . "</p></div>";
        }

        $class = "";
        if ($sub == 'abj404_redirects') {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_redirects\" title=\"" . __('Page Redirects', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Page Redirects', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_captured') {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_captured\" title=\"" . __('Captured 404 URLs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Captured 404 URLs', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_logs') {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_logs\" title=\"" . __('Redirect & Capture Logs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Logs', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_stats') {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_stats\" title=\"" . __('Stats', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Stats', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_tools') {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_tools\" title=\"" . __('Tools', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Tools', '404-solution') . "</a>";

        $class = "";
        if ($sub == "abj404_options") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_options\" title=\"Options\" class=\"nav-tab " . $class . "\">" . __('Options', '404-solution') . "</a>";

        echo "<hr style=\"border: 0px; border-bottom: 1px solid #DFDFDF; margin-top: 0px; margin-bottom: 0px; \">";
    }
    
    /** This outputs a box with a title and some content in it. 
     * It's used on the Stats, Options and Tools page (for example).
     * @param type $id
     * @param type $title
     * @param type $content
     */
    function echoPostBox($id, $title, $content) {
        echo "<div id=\"" . esc_attr($id) . "\" class=\"postbox\">";
        echo "<h3 class=\"hndle\" style=\"cursor: default;\"><span>" . esc_html($title) . "</span></h3>";
        echo "<div class=\"inside\">" . $content /* Can't escape here, as contains forms */ . "</div>";
        echo "</div>";
    }

    /** Output the stats page.
     * @global type $wpdb
     * @global type $abj404dao
     */
    function outputAdminStatsPage() {
        global $wpdb;
        global $abj404dao;
        global $abj404view;

        $redirects = $wpdb->prefix . "abj404_redirects";
        $logs = $wpdb->prefix . "abj404_logsv2";
        $hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_STATUS_AUTO;
        $auto301 = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_STATUS_AUTO;
        $auto302 = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_STATUS_MANUAL;
        $manual301 = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_MANUAL));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_STATUS_MANUAL;
        $manual302 = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_MANUAL));

        $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
        $trashed = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_AUTO, ABJ404_STATUS_MANUAL));

        $total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

        echo "<div class=\"postbox-container\" style=\"float: right; width: 49%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        $content = "";
        $content .= "<p $hr>";
        $content .= "<strong>" . __('Automatic 301 Redirects', '404-solution') . ":</strong> " . esc_html($auto301) . "<BR/>";
        $content .= "<strong>" . __('Automatic 302 Redirects', '404-solution') . ":</strong> " . esc_html($auto302) . "<BR/>";
        $content .= "<strong>" . __('Manual 301 Redirects', '404-solution') . ":</strong> " . esc_html($manual301) . "<BR/>";
        $content .= "<strong>" . __('Manual 302 Redirects', '404-solution') . ":</strong> " . esc_html($manual302) . "<BR/>";
        $content .= "<strong>" . __('Trashed Redirects', '404-solution') . ":</strong> " . esc_html($trashed) . "</p>";
        $content .= "<p style=\"margin-top: 4px;\">";
        $content .= "<strong>" . __('Total Redirects', '404-solution') . ":</strong> " . esc_html($total);
        $content .= "</p>";
        $abj404view->echoPostBox("abj404-redirectStats", __('Redirects', '404-solution'), $content);

        // -------------------------------------------
        $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_STATUS_CAPTURED;
        $captured = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_CAPTURED));

        $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_STATUS_IGNORED;
        $ignored = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_IGNORED));

        $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
        $trashed = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED));

        $total = $captured + $ignored + $trashed;

        $content = "";
        $content .= "<p $hr>";
        $content .= "<strong>" . __('Captured URLs', '404-solution') . ":</strong> " . esc_html($captured) . "<BR/>";
        $content .= "<strong>" . __('Ignored 404 URLs', '404-solution') . ":</strong> " . esc_html($ignored) . "<BR/>";
        $content .= "<strong>" . __('Trashed URLs', '404-solution') . ":</strong> " . esc_html($trashed) . "</p>";
        $content .= "<p style=\"margin-top: 4px;\">";
        $content .= "<strong>" . __('Total URLs', '404-solution') . ":</strong> " . esc_html($total);
        $content .= "</p>";
        $abj404view->echoPostBox("abj404-capturedStats", __('Captured URLs', '404-solution'), $content);
        echo "</div>";
        echo "</div>";
        echo "</div>";

        // -------------------------------------------

        echo "<div class=\"postbox-container\" style=\"width: 49%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        $today = mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y'))));
        $firstm = mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y'))));
        $firsty = mktime(0, 0, 0, 1, 1, abs(intval(date('Y'))));

        for ($x = 0; $x <= 3; $x++) {
            if ($x == 0) {
                $title = "Today's Stats";
                $ts = $today;
            } else if ($x == 1) {
                $title = "This Month";
                $ts = $firstm;
            } else if ($x == 2) {
                $title = "This Year";
                $ts = $firsty;
            } else if ($x == 3) {
                $title = "All Stats";
                $ts = 0;
            }

            $query = "select count(id) from $logs where timestamp >= $ts and dest_url = %s";
            $disp404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct requested_url) from $logs where timestamp >= $ts and dest_url = %s";
            $distinct404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct user_ip) from $logs where timestamp >= $ts and dest_url = %s";
            $visitors404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and dest_url = %s";
            $refer404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(id) from $logs where timestamp >= $ts and dest_url != %s";
            $redirected = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct requested_url) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctredirected = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct user_ip) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctvisitors = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctrefer = $abj404dao->getStatsCount($query, array("404"));

            $content = "";
            $content .= "<p>";
            $content .= "<strong>" . __('Page Not Found Displayed', '404-solution') . ":</strong> " . esc_html($disp404) . "<BR/>";
            $content .= "<strong>" . __('Unique Page Not Found URLs', '404-solution') . ":</strong> " . esc_html($distinct404) . "<BR/>";
            $content .= "<strong>" . __('Unique Page Not Found Visitors', '404-solution') . ":</strong> " . esc_html($visitors404) . "<BR/>";
            $content .= "<strong>" . __('Unique Page Not Found Referrers', '404-solution') . ":</strong> " . esc_html($refer404) . "<BR/>";
            $content .= "<strong>" . __('Hits Redirected', '404-solution') . ":</strong> " . esc_html($redirected) . "<BR/>";
            $content .= "<strong>" . __('Unique URLs Redirected', '404-solution') . ":</strong> " . esc_html($distinctredirected) . "<BR/>";
            $content .= "<strong>" . __('Unique Redirected Visitors', '404-solution') . ":</strong> " . esc_html($distinctvisitors) . "<BR/>";
            $content .= "<strong>" . __('Unique Redirected Referrers', '404-solution') . ":</strong> " . esc_html($distinctrefer) . "<BR/>";
            $content .= "</p>";
            $abj404view->echoPostBox("abj404-stats" . $x, __($title), $content);
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    
    function echoAdminDebugFile() {
        global $abj404logging;
        if (current_user_can('administrator')) {
            // read the file and replace new lines with <BR/>.
            if (file_exists($abj404logging->getDebugFilePath())) {
                $filecontents = esc_html(file_get_contents($abj404logging->getDebugFilePath()));
            } else {
                $filecontents = __('(The file does not exist.)', '404-solution');
            }
            
            echo "<div style=\"clear: both;\">";
            echo nl2br($filecontents);
            
        } else {
            echo "Non-admin request to view debug file.";
            $abj404logging->errorMessage("Non-admin request to view debug file.");
        }
    }

    /** Display the tools page.
     * @global type $abj404view
     */
    function echoAdminToolsPage() {
        global $abj404view;
        global $abj404dao;
        global $abj404logging;

        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        
        // read the html content.
        $html = $abj404dao->readFileContents(__DIR__ . "/html/toolsPurgeForm.html");
        // do special replacements
        $html = str_replace('{toolsPurgeFormActionLink}', $link, $html);
        // constants and translations.
        $html = $this->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-purgeRedirects", __('Purge Options', '404-solution'), $html);
        echo "</div></div></div>";
        
        // ------------------------------------
        
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_importRedirects");
        
        // read the html content.
        $html = $abj404dao->readFileContents(__DIR__ . "/html/toolsImportForm.html");
        // do special replacements
        $html = str_replace('{toolsImportFormActionLink}', $link, $html);
        // constants and translations.
        $html = $this->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-purgeRedirects", __('Import Options', '404-solution'), $html);
        echo "</div></div></div>";
        
        // ------------------------------------
        
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        
        // read the html content.
        $html = $abj404dao->readFileContents(__DIR__ . "/html/toolsEtcForm.html");
        // do special replacements
        $html = str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        // constants and translations.
        $html = $this->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-purgeRedirects", __('Etcetera', '404-solution'), $html);
        echo "</div></div></div>";
    }
    
    /** Replace constants and translations.
     * @param type $text
     * @return type
     */
    function doNormalReplacements($text) {
        // known strings that do not exist in the translation file.
        $knownReplacements = array(
            '{ABJ404_STATUS_AUTO}' => ABJ404_STATUS_AUTO,
            '{ABJ404_STATUS_MANUAL}' => ABJ404_STATUS_MANUAL,
            '{ABJ404_STATUS_CAPTURED}' => ABJ404_STATUS_CAPTURED,
            '{ABJ404_STATUS_IGNORED}' => ABJ404_STATUS_IGNORED,
            '{ABJ404_TYPE_404_DISPLAYED}' => ABJ404_TYPE_404_DISPLAYED,
            '{ABJ404_TYPE_POST}' => ABJ404_TYPE_POST,
            '{ABJ404_TYPE_CAT}' => ABJ404_TYPE_CAT,
            '{ABJ404_TYPE_TAG}' => ABJ404_TYPE_TAG,
            '{ABJ404_TYPE_EXTERNAL}' => ABJ404_TYPE_EXTERNAL,
            '{ABJ404_TYPE_HOME}' => ABJ404_TYPE_HOME,
            );

        // replace known strings that do not exist in the translation file.
        $text = str_replace(array_keys($knownReplacements), array_values($knownReplacements), $text);
        
        // Find the strings to replace in the content.
        $re = '/\{(.+?)\}/x';
        preg_match_all($re, $text, $stringsToReplace, PREG_PATTERN_ORDER);

        // Iterate through each string to replace.
        foreach ($stringsToReplace[1] as $stringToReplace) {
            $text = str_replace('{' . $stringToReplace . '}', 
                    __($stringToReplace, '404-solution'), $text);
        }
        
        return $text;
    }

    function echoAdminOptionsPage() {
        global $abj404logic;
        global $abj404view;

        $options = $abj404logic->getOptions();

        //General Options
        $link = wp_nonce_url("?page=" . ABJ404_PP . '&subpage=abj404_options', "abj404UpdateOptions");

        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"updateOptions\">";

        $contentAutomaticRedirects = $abj404view->getAdminOptionsPageAutoRedirects($options);
        $abj404view->echoPostBox("abj404-autooptions", __('Automatic Redirects', '404-solution'), $contentAutomaticRedirects);

        $contentGeneralSettings = $abj404view->getAdminOptionsPageGeneralSettings($options);
        $abj404view->echoPostBox("abj404-generaloptions", __('General Settings', '404-solution'), $contentGeneralSettings);

        $contentAdvancedSettings = $abj404view->getAdminOptionsPageAdvancedSettings($options);
        $abj404view->echoPostBox("abj404-advancedoptions", __('Advanced Settings (Etc)', '404-solution'), $contentAdvancedSettings);

        $content404PageSuggestions = $abj404view->getAdminOptionsPage404Suggestions($options);
        $abj404view->echoPostBox("abj404-suggestoptions", __('404 Page Suggestions', '404-solution'), $content404PageSuggestions);

        echo "<input type=\"submit\" id=\"abj404-optionssub\" value=\"Save Settings\" class=\"button-primary\">";
        echo "</form>";

        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    
    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     * @return type
     */
    function echoAdminEditRedirectPage() {
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;
        
        $options = $abj404logic->getOptions();
        
        // this line assures that text will appear below the page tabs at the top.
        echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span> <BR/>";
        
        echo "<h3>" . __('Redirect Details', '404-solution') . "</h3>";

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_edit", "abj404editRedirect");

        echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";

        $recnum = null;
        if (array_key_exists('id', $_GET) && isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {
            $abj404logging->debugMessage("Edit redirect page. GET ID: " . 
                    wp_kses_post(json_encode($_GET['id'])));
            $recnum = absint($_GET['id']);
        } else if (array_key_exists('id', $_POST) && isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id'])) {
            $abj404logging->debugMessage("Edit redirect page. POST ID: " . 
                    wp_kses_post(json_encode($_POST['id'])));
            $recnum = absint($_POST['id']);
        } else if ($abj404dao->getPostOrGetSanitize('idnum') !== null) {
            $recnums_multiple = array_map('absint', $abj404dao->getPostOrGetSanitize('idnum'));
            $abj404logging->debugMessage("Edit redirect page. ids_multiple: " . 
                    wp_kses_post(json_encode($recnums_multiple)));

        } else {
            echo __('Error: No ID(s) found for edit request.', '404-solution');
            $abj404logging->errorMessage("No ID(s) found in GET or POST data for edit request.");
            return;
        }
        
        // Decide whether we're editing one or multiple redirects.
        // If we're editing only one then set the ID to that one value.
        if ($recnum != null) {
            $redirect = $abj404dao->getRedirectByID($recnum);
            if ($redirect == null) {
                echo "Error: Invalid ID Number! (id: " . esc_html($recnum) . ")";
                $abj404logging->errorMessage("Error: Invalid ID Number! (id: " . esc_html($recnum) . ")");
                return;
            }

            echo "<input type=\"hidden\" name=\"id\" value=\"" . esc_attr($redirect['id']) . "\">";
            echo "<strong><label for=\"url\">" . __('URL', '404-solution') . 
                    ":</label></strong> ";
            echo "<input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . 
                    esc_attr($redirect['url']) . "\"> (" . __('Required', '404-solution') . ")<BR/>";
            
        } else if ($recnums_multiple != null) {
            $redirects_multiple = $abj404dao->getRedirectsByIDs($recnums_multiple);
            if ($redirects_multiple == null) {
                echo "Error: Invalid ID Numbers! (ids: " . esc_html(implode(',', $recnums_multiple)) . ")";
                $abj404logging->debugMessage("Error: Invalid ID Numbers! (ids: " . 
                        esc_html(implode(',', $recnums_multiple)) . ")");
                return;
            }

            echo "\n" . '<input type="hidden" name="ids_multiple" value="' . esc_html(implode(',', $recnums_multiple)) . '">';
            echo "\n" . '<table><tr><td style="vertical-align: top; padding-right: 5px; padding-top: 5px;"><strong>';
            echo "\n" . '<label>' . __('URLs', '404-solution') . ':</label></strong></td> ';
            
            echo "\n" . '<td style="vertical-align: top; padding: 5px;">' . "\n" . '<ul style="margin: 0px;">';
            foreach ($redirects_multiple as $redirect) {
                echo "\n<li>" . $redirect['url'] . "</li>\n";
            }
            echo "\n" . '</ul>';
            echo "\n</td></tr></table>\n";
            
            // here we set the variable to the first value returned because it's used to set default values
            // in the form data.
            $redirect = reset($redirects_multiple);
            
        } else {
            echo "Error: Invalid ID Number(s) specified! (id: " . $recnum . ", ids: " . $recnums_multiple . ")";
            $abj404logging->debugMessage("Error: Invalid ID Number(s) specified! (id: " . $recnum . 
                    ", ids: " . $recnums_multiple . ")");
            return;
        }
        
        echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</label></strong> \n";
        echo '<select style="max-width: 75%;" id="dest" name="dest">';
        echo $this->echoRedirectDestinationOptionsDefaults($redirect['type']);
        echo $this->echoRedirectDestinationOptionsPosts($redirect['final_dest'] . '|' . $redirect['type']);
        echo $this->echoRedirectDestinationOptionsPages($redirect['final_dest'] . '|' . $redirect['type']);

        echo "</select><BR/>";
        $final = "";
        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $final = $redirect['final_dest'];
        }
        
        if ($redirect['code'] == "") {
            $codeSelected = $options['default_redirect'];
        } else {
            $codeSelected = $redirect['code'];
        }
        $this->echoEditRedirect($final, $codeSelected, __('Update Redirect', '404-solution'));
        
        echo "</form>";
    }
    
    /** This is a supporting method for the echoAdminEditRedirectPage() method.
     */
    function echoRedirectDestinationOptionsPages($dest) {
        echo $this->echoRedirectDestinationOptionsPagesOnly($dest);

        $cats = get_categories('hierarchical=0');
        foreach ($cats as $cat) {
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_TYPE_CAT;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            echo "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }

        $tags = get_tags('hierarchical=0');
        foreach ($tags as $tag) {
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TYPE_TAG;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }        
    }
    
    function echoRedirectDestinationOptionsPagesOnly($dest) {
        $content = "";
        $pagesRows = get_pages();
        foreach ($pagesRows as $prow) {
            $id = $prow->ID;
            $theTitle = $prow->post_title;
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . 
                    $theTitle . "</option>\n";
        }
        
        return $content;
    }

    /** 
     * @global type $abj404dao
     */
    function echoAdminCapturedURLsPage() {
        global $abj404dao;
        global $abj404logic;
        $sub = 'abj404_captured';

        $tableOptions = $abj404logic->getTableOptions();

        $this->echoTabFilters($sub, $tableOptions);

        $columns['url']['title'] = "URL";
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "50%";
        $columns['hits']['title'] = "Hits";
        $columns['hits']['orderby'] = "hits";
        $columns['hits']['width'] = "10%";
        $columns['timestamp']['title'] = "Created";
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "20%";
        $columns['last_used']['title'] = "Last Used";
        $columns['last_used']['orderby'] = "";
        $columns['last_used']['width'] = "20%";

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);


        echo "<div class=\"tablenav\">";
        $this->echoPaginationLinks($sub, $tableOptions);

        if ($tableOptions['filter'] == '-1') {
            echo "<div class=\"alignleft actions\">";
            $eturl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=-1&subpage=abj404_captured";
            $trashaction = "abj404_emptyCapturedTrash";
            $eturl = wp_nonce_url($eturl, $trashaction);

            echo "<form method=\"POST\" action=\"" . esc_url($eturl) . "\">";
            echo "<input type=\"hidden\" name=\"action\" value=\"emptyCapturedTrash\">";
            echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Empty Trash', '404-solution') . "\">";
            echo "</form>";
            echo "</div>";
        } else {
            echo "<div class=\"alignleft actions\">";
            $url = "?page=" . ABJ404_PP . "&subpage=abj404_captured";
            if ($tableOptions['filter'] != 0) {
                $url .= "&filter=" . $tableOptions['filter'];
            }
            if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
                $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            }

            $bulkaction = "abj404_bulkProcess";
            // is there a way to use the <select> below and use the selected action (bulkignore, bulkcaptured, bulktrash)
            // when creating the nonce (instead of using one nonce for all actions)?
            $url = wp_nonce_url($url, $bulkaction); 

            echo "<form method=\"POST\" action=\"" . $url . "\">";
            echo "<select name=\"action\">";
            if ($tableOptions['filter'] != ABJ404_STATUS_IGNORED) {
                echo "<option value=\"bulkignore\">" . __('Mark as ignored', '404-solution') . "</option>";
            } else {
                echo "<option value=\"bulkcaptured\">" . __('Mark as captured', '404-solution') . "</option>";
            }
            echo "<option value=\"bulktrash\">" . __('Trash', '404-solution') . "</option>";
            echo "<option value=\"editRedirect\">" . __('Create a redirect', '404-solution') . "</option>";
            echo "</select>";
            echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Apply', '404-solution') . "\">";
            echo "</div>";
        }
        echo "</div>";

        echo "<table class=\"wp-list-table widefat fixed\">";
        echo "<thead>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</thead>";
        echo "<tfoot>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</tfoot>";
        echo "<tbody id=\"the-list\">";
        $rows = $abj404dao->getRedirects($sub, $tableOptions);
        $displayed = 0;
        $y = 1;
        foreach ($rows as $row) {
            $displayed++;

            $hits = $row['hits'];
            $last_used = $abj404dao->getRedirectLastUsed($row['logsid']);
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . $row['id'];
            $logslink = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . $row['logsid'];
            $trashlink = "?page=" . ABJ404_PP . "&&subpage=abj404_captured&id=" . $row['id'];
            $ignorelink = "?page=" . ABJ404_PP . "&&subpage=abj404_captured&id=" . $row['id'];
            $deletelink = "?page=" . ABJ404_PP . "&subpage=abj404_captured&remove=1&id=" . $row['id'];

            if ($tableOptions['filter'] == -1) {
                $trashlink .= "&trash=0";
                $trashtitle = __('Restore', '404-solution');
            } else {
                $trashlink .= "&trash=1";
                $trashtitle = __('Trash', '404-solution');
            }

            if ($tableOptions['filter'] == ABJ404_STATUS_IGNORED) {
                $ignorelink .= "&ignore=0";
                $ignoretitle = __('Remove Ignore Status', '404-solution');
            } else {
                $ignorelink .= "&ignore=1";
                $ignoretitle = __('Ignore 404 Error', '404-solution');
            }

            if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
                $trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
                $ignorelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
                $deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            }
            if ($tableOptions['filter'] != 0) {
                $trashlink .= "&filter=" . $tableOptions['filter'];
                $ignorelink .= "&filter=" . $tableOptions['filter'];
                $deletelink .= "&filter=" . $tableOptions['filter'];
            }

            $trashaction = "abj404_trashRedirect";
            $trashlink = wp_nonce_url($trashlink, $trashaction);

            if ($tableOptions['filter'] == -1) {
                $deleteaction = "abj404_removeRedirect";
                $deletelink = wp_nonce_url($deletelink, $deleteaction);
            }

            $ignoreaction = "abj404_ignore404";
            $ignorelink = wp_nonce_url($ignorelink, $ignoreaction);

            $class = "";
            if ($y == 0) {
                $class = " class=\"alternate\"";
                $y++;
            } else {
                $y = 0;
            }

            echo "<tr id=\"post-" . esc_attr($row['id']) . "\"" . $class . ">";
            echo "<th class=\"check-column\">";
            if ($tableOptions['filter'] != '-1') {
                echo "<input type=\"checkbox\" name=\"idnum[]\" value=\"" . esc_attr($row['id']) . "\">";
            }
            echo "</th>";
            echo "<td>";
            echo "<strong><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . esc_html($row['url']) . "</a></strong>";
            echo "<div class=\"row-actions\">";
            if ($tableOptions['filter'] != -1) {
                echo "<span class=\"edit\"><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . __('Edit', '404-solution') . "</a></span>";
                echo " | ";
            }
            echo "<span class=\"trash\"><a href=\"" . esc_url($trashlink) . "\" title=\"" . __('Trash Redirected URL', '404-solution') . "\">" . esc_html($trashtitle) . "</a></span>";
            
            echo " | ";
            if ($row['logsid'] > 0) {
                echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs', '404-solution') . "</a></span>";
            } else {
                echo "<span class=\"view\">" . __('(No logs)') . "</a></span>";
            }
            if ($tableOptions['filter'] != -1) {
                echo " | ";
                echo "<span class=\"ignore\"><a href=\"" . esc_url($ignorelink) . "\" title=\"" . $ignoretitle . "\">" . esc_html($ignoretitle) . "</a></span>";
            } else {
                echo " | ";
                echo "<span class=\"delete\"><a href=\"" . esc_url($deletelink) . "\" title=\"" . __('Delete Redirect Permanently', '404-solution') . "\">" . __('Delete Permanently', '404-solution') . "</a></span>";
            }
            echo "</div>";
            echo "</td>";
            echo "<td>" . esc_html($hits) . "</td>";
            echo "<td>" . esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))) . "</td>";
            echo "<td>" . esc_html($last) . "</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        if ($displayed == 0) {
            echo "<tr>";
            echo "<td></td>";
            echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . __('No Records To Display', '404-solution') . "</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";

        echo "<div class=\"tablenav\">";
        if ($tableOptions['filter'] != '-1') {
            echo "</form>";
        }
        $this->echoPaginationLinks($sub, $tableOptions);
        echo "</div>";
    }

    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function echoAdminRedirectsPage() {
        global $abj404dao;
        global $abj404logic;

        $sub = 'abj404_redirects';

        $options = $abj404logic->getOptions();
        $tableOptions = $abj404logic->getTableOptions();

        // Sanitizing unchecked table options
        foreach ($tableOptions as $key => $value) {
            $key = wp_kses_post($key);
            $tableOptions[$key] = wp_kses_post($value);
        }

        $this->echoTabFilters($sub, $tableOptions);

        $columns['url']['title'] = "URL";
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "25%";
        $columns['status']['title'] = "Status";
        $columns['status']['orderby'] = "status";
        $columns['status']['width'] = "5%";
        $columns['type']['title'] = "Type";
        $columns['type']['orderby'] = "type";
        $columns['type']['width'] = "10%";
        $columns['dest']['title'] = "Destination";
        $columns['dest']['orderby'] = "final_dest";
        $columns['dest']['width'] = "25%";
        $columns['code']['title'] = "Redirect";
        $columns['code']['orderby'] = "code";
        $columns['code']['width'] = "5%";
        $columns['hits']['title'] = "Hits";
        $columns['hits']['orderby'] = "hits";
        $columns['hits']['width'] = "10%";
        $columns['timestamp']['title'] = "Created";
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "10%";
        $columns['last_used']['title'] = "Last Used";
        $columns['last_used']['orderby'] = "";
        $columns['last_used']['width'] = "10%";

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        echo "<div class=\"tablenav\">";
        $this->echoPaginationLinks($sub, $tableOptions);

        if ($tableOptions['filter'] == '-1') {
            echo "<div class=\"alignleft actions\">";
            $eturl = "?page=" . ABJ404_PP . "&filter=-1&subpage=abj404_redirects";
            $trashaction = "abj404_emptyRedirectTrash";
            $eturl = wp_nonce_url($eturl, $trashaction);

            echo "<form method=\"POST\" action=\"" . esc_url($eturl) . "\">";
            echo "<input type=\"hidden\" name=\"action\" value=\"emptyRedirectTrash\">";
            echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Empty Trash', '404-solution') . "\">";
            echo "</form>";
            echo "</div>";
        }
        echo "</div>";

        echo "<table class=\"wp-list-table widefat fixed\">";
        echo "<thead>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</thead>";
        echo "<tfoot>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</tfoot>";
        echo "<tbody id=\"the-list\">";
        $rows = $abj404dao->getRedirects($sub, $tableOptions);
        $displayed = 0;
        $y = 1;
        foreach ($rows as $row) {
            $displayed++;
            $status = "";
            if ($row['status'] == ABJ404_STATUS_MANUAL) {
                $status = __('Manual', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_AUTO) {
                $status = __('Automatic', '404-solution');
            }

            $type = "";
            $dest = "";
            $link = "";
            $title = __('Visit', '404-solution') . " ";
            if ($row['type'] == ABJ404_TYPE_EXTERNAL) {
                $type = __('External', '404-solution');
                $dest = $row['final_dest'];
                $link = $row['final_dest'];
                $title .= $row['final_dest'];
            } else if ($row['type'] == ABJ404_TYPE_POST) {
                $type = __('Post/Page', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_POST, 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_CAT) {
                $type = __('Category', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_CAT, 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= __('Category:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_TAG) {
                $type = __('Tag', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_TAG, 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= __('Tag:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_HOME) {
                $type = __('Home Page', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_HOME, 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= __('Home Page:', '404-solution') . " " . $permalink['title'];
            }


            $hits = $row['hits'];
            $last_used = $abj404dao->getRedirectLastUsed($row['logsid']);
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . absint($row['id']);
            $logslink = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . absint($row['logsid']);
            $trashlink = "?page=" . ABJ404_PP . "&id=" . absint($row['id']);
            $deletelink = "?page=" . ABJ404_PP . "&remove=1&id=" . absint($row['id']);

            if ($tableOptions['filter'] == -1) {
                $trashlink .= "&trash=0";
                $trashtitle = __('Restore', '404-solution');
            } else {
                $trashlink .= "&trash=1";
                $trashtitle = __('Trash', '404-solution');
            }

            if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
                $trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
                $deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            }
            if ($tableOptions['filter'] != 0) {
                $trashlink .= "&filter=" . $tableOptions['filter'];
                $deletelink .= "&filter=" . $tableOptions['filter'];
            }

            $trashaction = "abj404_trashRedirect";
            $trashlink = wp_nonce_url($trashlink, $trashaction);

            if ($tableOptions['filter'] == -1) {
                $deleteaction = "abj404_removeRedirect";
                $deletelink = wp_nonce_url($deletelink, $deleteaction);
            }

            $class = "";
            if ($y == 0) {
                $class = " class=\"alternate\"";
                $y++;
            } else {
                $y = 0;
            }

            echo "<tr id=\"post-" . esc_attr($row['id']) . "\"" . $class . ">";
            echo "<td></td>";
            echo "<td>";
            echo "<strong><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . esc_html($row['url']) . "</a></strong>";
            echo "<div class=\"row-actions\">";
            if ($tableOptions['filter'] != -1) {
                echo "<span class=\"edit\"><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . __('Edit') . "</a></span>";
                echo " | ";
            }
            echo "<span class=\"trash\"><a href=\"" . esc_url($trashlink) . "\" title=\"" . __('Trash Redirected URL', '404-solution') . "\">" . esc_html($trashtitle) . "</a></span>";
            echo " | ";
            if ($row['logsid'] > 0) {
                echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs') . "</a></span>";
            } else {
                echo "<span class=\"view\">" . __('(No logs)') . "</a></span>";
            }
            if ($tableOptions['filter'] == -1) {
                echo " | ";
                echo "<span class=\"delete\"><a href=\"" . esc_url($deletelink) . "\" title=\"" . __('Delete Redirect Permanently', '404-solution') . "\">" . __('Delete Permanently', '404-solution') . "</a></span>";
            }
            echo "</div>";
            echo "</td>";
            echo "<td>" . esc_html($status) . "</td>";
            echo "<td>" . esc_html($type) . "</td>";
            echo "<td><a href=\"" . esc_url($link) . "\" title=\"" . $title . "\" target=\"_blank\">" . esc_html($dest) . "</a></td>";
            echo "<td>" . esc_html($row['code']) . "</td>";
            echo "<td>" . esc_html($hits) . "</td>";
            echo "<td>" . esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))) . "</td>";
            echo "<td>" . esc_html($last) . "</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        if ($displayed == 0) {
            echo "<tr>";
            echo "<td></td>";
            echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . __('No Records To Display', '404-solution') . "</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";

        echo "<div class=\"tablenav\">";
        $this->echoPaginationLinks($sub, $tableOptions);
        echo "</div>";

        if ($tableOptions['filter'] != -1) {
            echo "<h3>" . __('Add Manual Redirect', '404-solution') . "</h3>";

            $url = "?page=" . ABJ404_PP;

            if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
                $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            }
            if ($tableOptions['filter'] != 0) {
                $url .= "&filter=" . $tableOptions['filter'];
            }

            $action = "abj404addRedirect";
            $link = wp_nonce_url($url, $action);

            echo "<form method=\"POST\" action=\"" . $link . "\">";
            echo "<input type=\"hidden\" name=\"action\" value=\"addRedirect\">";

            $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";

            if (array_key_exists('url', $_POST) && isset($_POST['url']) && $_POST['url'] != '') {
                $postedURL = esc_url($_POST['url']);
            } else {
                $postedURL = $urlPlaceholder;
            }
            
            echo "<strong><label for=\"url\">" . __('URL', '404-solution') . 
                    ":</label></strong> <input id=\"url\" placeholder=\"" . $urlPlaceholder . 
                    "\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . 
                    esc_attr($postedURL) . "\"> (" . __('Required', '404-solution') . ")<BR/>";
            
            echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</label></strong> \n";
            echo '<select style="max-width: 75%;" id="dest" name="dest">';
            
            $defaultDestination = '';
            $defaultType = '';
            if (!array_key_exists('dest', $_POST)) {
                $defaultDestination = $_POST['dest'];
            }
            if (!array_key_exists('type', $_POST)) {
                $defaultType = $_POST['type'];
            }
        
            echo $this->echoRedirectDestinationOptionsDefaults($defaultDestination);
            echo $this->echoRedirectDestinationOptionsPosts($defaultDestination . '|' . $defaultType);
            echo $this->echoRedirectDestinationOptionsPages($defaultDestination . '|' . $defaultType);

            echo "</select><BR/>";
            
            $externalDestination = esc_url(@$_POST['external']);
            if (@$_POST['code'] == "") {
                $codeselected = $options['default_redirect'];
            } else {
                $codeselected = sanitize_text_field($_POST['code']);
            }
            $this->echoEditRedirect($externalDestination, $codeselected, __('Add Redirect', '404-solution'));
            
            echo "</form>";
        }
    }
    
    /** This is used both to add and to edit a redirect.
     * @param type $destination
     * @param type $codeselected
     * @param type $label
     */
    function echoEditRedirect($destination, $codeselected, $label) {
        echo "<strong><label for=\"external\">" . __('External URL', '404-solution') . 
                ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . 
                esc_attr($destination) . "\"> (" . __('Required if Redirect to is set to External Page', '404-solution') . 
                ")<BR/>";
        echo "<strong><label for=\"code\">" . __('Redirect Type', '404-solution') . 
                ":</label></strong> <select id=\"code\" name=\"code\">";
        
        $codes = array(301, 302);
        foreach ($codes as $code) {
            $selected = "";
            if ($code == $codeselected) {
                $selected = " selected";
            }

            $title = ($code == 301) ? '301 Permanent Redirect' : '302 Temporary Redirect';
            echo "<option value=\"" . esc_attr($code) . "\"" . $selected . ">" . esc_html($title) . "</option>";
        }
        echo "</select><BR/>";
        echo "<input type=\"submit\" value=\"" . $label . "\" class=\"button-secondary\">";
    }
    
    function echoRedirectDestinationOptionsDefaults($currentlySelected) {
        $content = "";
        $selected = "";
        if ($currentlySelected == ABJ404_TYPE_EXTERNAL) {
            $selected = " selected";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL . "\"" . $selected . ">" . 
                __('External Page', '404-solution') . "</option>";

        if ($currentlySelected == ABJ404_TYPE_HOME) {
            $selected = " selected";
        } else {
            $selected = "";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_HOME . "|" . ABJ404_TYPE_HOME . "\"" . $selected . ">" . 
                __('Home Page', '404-solution') . "</option>";
        
        return $content;
    }
    
    function echoRedirectDestinationOptionsPosts($dest) {
        global $abj404dao;
        $content = "";
        
        $postRows = $abj404dao->getPublishedPostIDs();
        foreach ($postRows as $row) {
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Post', '404-solution') . ": " . $theTitle . "</option>";
        }
        
        return $content;
    }

    /** 
     * @global type $abj404dao
     * @global type $wpdb
     * @param type $options
     * @return string
     */
    function getAdminOptionsPageAutoRedirects($options) {
        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = "";

        $selected = "";
        $content .= "<label for=\"dest404page\">" . __('Redirect all unhandled 404s to', '404-solution') . ":</label> <select id=\"dest404page\" name=\"dest404page\">";

        $userSelected = (array_key_exists('dest404page', $options) && isset($options['dest404page']) ?
                $options['dest404page'] : null);
        $dest404page = ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED;
        $selected = $userSelected == $dest404page ? "selected" : "";
        $content .= '<option value="' . ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED . '"' . $selected . ">" . 
                __('(Default 404 Page)', '404-solution') . "</option>";

        $destHomepage = ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME;
        $selected = $userSelected == $destHomepage ? "selected" : "";
        $content .= '<option value="' . ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME . '"' . 
                $selected . ">" . __('(Home Page)', '404-solution') . "</option>";

        $content .= $this->echoRedirectDestinationOptionsPagesOnly($userSelected);

        $content .= "</select><BR/>";

        $selectedAutoRedirects = "";
        if ($options['auto_redirects'] == '1') {
            $selectedAutoRedirects = " checked";
        }

        $content .= "<p><label for=\"auto_redirects\">" . __('Create automatic redirects', '404-solution') . ":</label> <input type=\"checkbox\" name=\"auto_redirects\" id=\"auto_redirects\" value=\"1\"" . $selectedAutoRedirects . "><BR/>";
        $content .= $spaces . __('Automatically creates redirects based on best possible suggested page.', '404-solution') . "</p>";

        $content .= "<p><label for=\"auto_score\">" . __('Minimum match score', '404-solution') . ":</label> <input type=\"text\" name=\"auto_score\" id=\"auto_score\" value=\"" . esc_attr($options['auto_score']) . "\" style=\"width: 50px;\"><BR/>";
        $content .= $spaces . __('Only create an automatic redirect if the suggested page has a score above the specified number', '404-solution') . "</p>";

        $selectedAutoCats = "";
        if ($options['auto_cats'] == '1') {
            $selectedAutoCats = " checked";
        }
        $content .= "<p><label for=\"auto_cats\">" . __('Create automatic redirects for categories', '404-solution') . ":</label> <input type=\"checkbox\" name=\"auto_cats\" id=\"auto_cats\" value=\"1\"" . $selectedAutoCats . "></p>";

        $selectedAutoTags = "";
        if ($options['auto_tags'] == '1') {
            $selectedAutoTags = " checked";
        }
        $content .= "<p><label for=\"auto_tags\">" . __('Create automatic redirects for tags', '404-solution') . ":</label> <input type=\"checkbox\" name=\"auto_tags\" id=\"auto_tags\" value=\"1\"" . $selectedAutoTags . "></p>";

        $content .= "<p><label for=\"auto_deletion\">" . __('Auto redirect deletion', '404-solution') . ":</label> <input type=\"text\" name=\"auto_deletion\" id=\"auto_deletion\" value=\"" . esc_attr($options['auto_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<BR/>";
        $content .= $spaces . __('Removes auto created redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

        return $content;
    }

    /** 
     * @param type $options
     * @return string
     */
    function getAdminOptionsPage404Suggestions($options) {
        // Suggested Alternatives Options
        $selectedDisplaySuggest = "";
        if ($options['display_suggest'] == '1') {
            $selectedDisplaySuggest = " checked";
        }
        
        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        
        $content = "<p><label for=\"display_suggest\">" . __('Turn on 404 suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"display_suggest\" id=\"display_suggest\" value=\"1\"" . $selectedDisplaySuggest . "><BR/>";
        $content .= $spaces . __('Activates the 404 page suggestions function. Only works if the code is in your 404 page template.', '404-solution');
        $content .= "<BR/>" . $spaces . "Code: " . 
                esc_html("<?php if (!empty(\$abj404connector)) {\$abj404connector->suggestions(); } ?>") . "</p>";

        $selectedSuggestCats = "";
        if ($options['suggest_cats'] == '1') {
            $selectedSuggestCats = " checked";
        }
        $content .= "<p><label for=\"suggest_cats\">" . __('Allow category suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"suggest_cats\" id=\"suggest_cats\" value=\"1\"" . $selectedSuggestCats . "><BR/>";

        $selectedSuggestTags = "";
        if ($options['suggest_tags'] == '1') {
            $selectedSuggestTags = " checked";
        }
        $content .= "<p><label for=\"suggest_tags\">" . __('Allow tag suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"suggest_tags\" id=\"suggest_tags\" value=\"1\"" . $selectedSuggestTags . "><BR/>";

        $content .= "<p><label for=\"suggest_minscore\">" . __('Minimum score of suggestions to display', '404-solution') . ":</label> <input type=\"text\" name=\"suggest_minscore\" id=\"suggest_minscore\" value=\"" . esc_attr($options['suggest_minscore']) . "\" style=\"width: 50px;\"></p>"
        ;
        $content .= "<p><label for=\"suggest_max\">" . __('Maximum number of suggestions to display', '404-solution') . ":</label> <input type=\"text\" name=\"suggest_max\" id=\"suggest_max\" value=\"" . esc_attr($options['suggest_max']) . "\" style=\"width: 50px;\"></p>";

        $content .= "<p><label for=\"suggest_title\">" . __('Page suggestions title', '404-solution') . ":</label> <input type=\"text\" name=\"suggest_title\" id=\"suggest_title\" value=\"" . esc_attr($options['suggest_title']) . "\" style=\"width: 200px;\"></p>";

        $content .= "<p>" . __('Display Before/After page suggestions', '404-solution') . ": ";
        $content .= "<input type=\"text\" name=\"suggest_before\" value=\"" . esc_attr($options['suggest_before']) . "\" style=\"width: 100px;\"> / ";
        $content .= "<input type=\"text\" name=\"suggest_after\" value=\"" . esc_attr($options['suggest_after']) . "\" style=\"width: 100px;\">";

        $content .= "<p>" . __('Display Before/After each suggested entry', '404-solution') . ": ";
        $content .= "<input type=\"text\" name=\"suggest_entrybefore\" value=\"" . esc_attr($options['suggest_entrybefore']) . "\" style=\"width: 100px;\"> / ";
        $content .= "<input type=\"text\" name=\"suggest_entryafter\" value=\"" . esc_attr($options['suggest_entryafter']) . "\" style=\"width: 100px;\">";

        $content .= "<p><label for=\"suggest_noresults\">" . __('Display if no suggestion results', '404-solution') . ":</label> ";
        $content .= "<input type=\"text\" name=\"suggest_noresults\" id=\"suggest_noresults\" value=\"" . esc_attr($options['suggest_noresults']) . "\" style=\"width: 200px;\">";

        return $content;
    }
    
    function getAdminOptionsPageAdvancedSettings($options) {
        global $abj404logging;
        global $abj404dao;
        global $abj404logic;

        $selectedDebugLogging = "";
        if ($options['debug_mode'] == '1') {
            $selectedDebugLogging = " checked";
        }
        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = $abj404logic->getDebugLogFileLink();
        $debugExplanation = str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        // TODO make the delete link use a POST request instead of a GET request.
        $debugDelete = __('<a>Delete</a> the debug file.', '404-solution');
        $deleteLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_options&action=deleteDebugFile", 
                "abj404_deleteDebugFile");
        $debugDelete = str_replace('<a>', '<a href="' . $deleteLink . '" >', $debugDelete);
        
        $kbFileSize = round($abj404logging->getDebugFileSize() / 1024);
        $debugFileSize = sprintf(__("Debug file size: %s KB.", '404-solution'), $kbFileSize);
        
        
        // ----
        // read the html content.
        $html = $abj404dao->readFileContents(__DIR__ . "/html/settingsAdvanced.html");
        $html = str_replace('{DATABASE_VERSION}', esc_html($options['DB_VERSION']), $html);
        $html = str_replace('checked=""', $selectedDebugLogging, $html);
        $html = str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = str_replace('{<a>Delete</a> the debug file.}', $debugDelete, $html);
        $html = str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);
        $html = str_replace('{ignore_dontprocess}', wp_kses_post($options['ignore_dontprocess']), $html);
        $html = str_replace('{ignore_doprocess}', wp_kses_post($options['ignore_doprocess']), $html);
        // constants and translations.
        $html = $this->doNormalReplacements($html);
        
        // ------------------
         
        return $html;
    }

    /** 
     * @param type $options
     * @return string
     */
    function getAdminOptionsPageGeneralSettings($options) {
        global $abj404logging;
        global $abj404dao;
        
        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");

        $content = "<p><label for=\"default_redirect\">" . __('Default redirect type', '404-solution') . ":</label> ";
        $content .= "<select name=\"default_redirect\" id=\"default_redirect\">";
        $selectedDefaultRedirect301 = "";
        if ($options['default_redirect'] == '301') {
            $selectedDefaultRedirect301 = " selected";
        }
        $content .= "<option value=\"301\"" . $selectedDefaultRedirect301 . ">" . __('Permanent 301', '404-solution') . "</option>";
        $selectedDefaultRedirect302 = "";
        if ($options['default_redirect'] == '302') {
            $selectedDefaultRedirect302 = " selected";
        }
        $content .= "<option value=\"302\"" . $selectedDefaultRedirect302 . ">" . __('Temporary 302', '404-solution') . "</option>";
        $content .= "</select></p>";

        $selectedCapture404 = "";
        if ($options['capture_404'] == '1') {
            $selectedCapture404 = " checked";
        }
        $content .= "<p><label for=\"capture_404\">" . __('Collect incoming 404 URLs', '404-solution') . ":</label> <input type=\"checkbox\" name=\"capture_404\" id=\"capture_404\" value=\"1\"" . $selectedCapture404 . "></p>";

        $content .= "<p><label for=\"admin_notification\">" . __('Admin notification level', '404-solution') . ":</label> <input type=\"text\" name=\"admin_notification\" id=\"admin_notification\" value=\"" . esc_attr($options['admin_notification']) . "\" style=\"width: 50px;\"> " . __('Captured URLs (0 Disables Notification)', '404-solution') . "<BR/>";
        $content .= $spaces . __('Display WordPress admin notifications when number of captured URLs goes above specified level', '404-solution') . "</p>";

        $content .= "<p><label for=\"capture_deletion\">" . __('Collected 404 URL deletion', '404-solution') . ":</label> <input type=\"text\" name=\"capture_deletion\" id=\"capture_deletion\" value=\"" . esc_attr($options['capture_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<BR/>";
        $content .= $spaces . __('Automatically removes 404 URLs that have been captured if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

        $content .= "<p><label for=\"manual_deletion\">" . __('Manual redirect deletion', '404-solution') . ":</label> <input type=\"text\" name=\"manual_deletion\" id=\"manual_deletion\" value=\"" . esc_attr($options['manual_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<BR/>";
        $content .= $spaces . __('Automatically removes manually created page redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

        $content .= "<p><label for=\"maximum_log_disk_usage\">" . __('Maximum log disk usage (MB)', '404-solution') . ":</label> <input type=\"text\" name=\"maximum_log_disk_usage\" id=\"maximum_log_disk_usage\" value=\"" . esc_attr($options['maximum_log_disk_usage']) . "\" style=\"width: 50px;\"> " . "<BR/>";
        $content .= $spaces . __('Keeps the most recent (and deletes the oldest) log records when the disk usage reaches this limit.', '404-solution');
        $logSizeBytes = $abj404dao->getLogDiskUsage();
        $logSizeMB = round($logSizeBytes / (1024 * 1000), 2);
        $totalLogLines = $abj404dao->getLogsCount(0);
        $logSize = sprintf(__("Current approximate log disk usage: %s MB (%s rows).", '404-solution'), 
                $logSizeMB, $totalLogLines);

        $timeToDisplay = $abj404dao->getEarliestLogTimestamp();
        $earliestLogDate = date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
                    date('A', $timeToDisplay);
        $logSize .= ' ' . sprintf(__("Earliest log date: %s.", '404-solution'), $earliestLogDate) . ' ';
        
        $content .= "<BR/>" . $spaces . $logSize . '  ' . __('Cleanup is done daily.', '404-solution') . "</p>";

        $selectedRemoveMatches = "";
        if ($options['remove_matches'] == '1') {
            $selectedRemoveMatches = " checked";
        }
        

        $content .= "<p><label for=\"remove_matches\">" . __('Remove redirect upon matching permalink', '404-solution') . ":</label> <input type=\"checkbox\" value=\"1\" name=\"remove_matches\" id=\"remove_matches\"" . $selectedRemoveMatches . "><BR/>";
        $content .= $spaces . __('Checks each redirect for a new matching permalink before user is redirected. If a new page permalink is found matching the redirected URL then the redirect will be deleted.', '404-solution') . "</p>";

        return $content;
    }

    /** 
     * @global type $abj404dao
     */
    function echoAdminLogsPage() {
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;

        $sub = 'abj404_logs';
        $tableOptions = $abj404logic->getTableOptions();

        // Sanitizing unchecked table options
        foreach ($tableOptions as $key => $value) {
            $key = wp_kses_post($key);
            $tableOptions[$key] = wp_kses_post($value);
        }

        $logRows = array();
        $logRowsFound = 0;
        
        $rows = $abj404dao->getLogsIDandURL();
        foreach ($rows as $row) {
            $logRows[$row['id']]['id'] = absint($row['id']);
            $logRows[$row['id']]['url'] = esc_url($row['url']);
            $logRowsFound++;
        }
        $abj404logging->debugMessage($logRowsFound . " log rows found for logs page select option.");

        echo "<BR/>";
        echo "<form method=\"GET\" action=\"\" style=\"clear: both; display: block;\" class=\"clearbothdisplayblock\">";
        echo '<input type="hidden" name="page" value="' . ABJ404_PP . '">';
        echo "<input type=\"hidden\" name=\"subpage\" value=\"abj404_logs\">";
        echo "<strong><label for=\"id\">" . __('View Logs For', '404-solution') . ":</label></strong> ";
        echo "<select name=\"id\" id=\"id\">";
        
        $selected = "";
        if ($tableOptions['logsid'] == 0) {
            $selected = " selected";
        }
        echo "<option value=\"0\"" . $selected . ">" . __('All Redirects', '404-solution') . "</option>";
        foreach ($logRows as $logRow) {
            $selected = "";
            if ($tableOptions['logsid'] == $logRow['id']) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($logRow['id']) . "\"" . $selected . ">" . esc_html($logRow['url']) . "</option>";
        }
        echo "</select><BR/>";
        echo "<input type=\"submit\" value=\"View Logs\" class=\"button-secondary\">";
        echo "</form>";

        $columns['url']['title'] = "URL";
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "25%";
        $columns['host']['title'] = "IP Address";
        $columns['host']['orderby'] = "remote_host";
        $columns['host']['width'] = "10%";
        $columns['refer']['title'] = "Referrer";
        $columns['refer']['orderby'] = "referrer";
        $columns['refer']['width'] = "25%";
        $columns['dest']['title'] = "Action Taken";
        $columns['dest']['orderby'] = "action";
        $columns['dest']['width'] = "25%";
        $columns['timestamp']['title'] = "Date";
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "15%";

        echo "<div class=\"tablenav\">";
        $this->echoPaginationLinks($sub, $tableOptions);
        echo "</div>";

        echo "<table class=\"wp-list-table widefat fixed\">";
        echo "<thead>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</thead>";
        echo "<tfoot>";
        $this->echoTableColumns($sub, $tableOptions, $columns);
        echo "</tfoot>";
        echo "<tbody>";

        $rows = $abj404dao->getLogRecords($tableOptions);
        $redirectsDisplayed = 0;
        $y = 1;

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);
        foreach ($rows as $row) {
            $class = "";
            if ($y == 0) {
                $class = " class=\"alternate\"";
                $y++;
            } else {
                $y = 0;
            }
            echo "<tr" . $class . ">";
            echo "<td></td>";
            echo "<td>" . esc_html($row['url']) . "</td>";
            echo "<td>" . esc_html($row['remote_host']) . "</td>";
            echo "<td>";
            if ($row['referrer'] != "") {
                echo "<a href=\"" . esc_url($row['referrer']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['referrer']) . "\" target=\"_blank\">" . esc_html($row['referrer']) . "</a>";
            } else {
                echo "&nbsp;";
            }
            echo "</td>";
            echo "<td>";
            if ($row['action'] == "404") {
                echo __('Displayed 404 Page', '404-solution');
            } else {
                echo __('Redirect to', '404-solution') . " ";
                echo "<a href=\"" . esc_url($row['action']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['action']) . "\" target=\"_blank\">" . esc_html($row['action']) . "</a>";
            }
            echo "</td>";
            $timeToDisplay = abs(intval($row['timestamp']));
            echo "<td>" . date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
                    date('A', $timeToDisplay) . "</td>";
            echo "<td></td>";
            echo "</tr>";
            $redirectsDisplayed++;
        }
        $abj404logging->debugMessage($redirectsDisplayed . " log records displayed on the page.");
        if ($redirectsDisplayed == 0) {
            echo "<tr>";
            echo "<td></td>";
            echo "<td colspan=\"5\" style=\"text-align: center; font-weight: bold;\">" . __('No Results To Display', '404-solution') . "</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";

        echo "<div class=\"tablenav\">";
        $this->echoPaginationLinks($sub, $tableOptions);
        echo "</div>";
    }

    /** 
     * @param type $sub
     * @param type $tableOptions
     * @param type $columns
     */
    function echoTableColumns($sub, $tableOptions, $columns) {
        echo "<tr>";
        if ($sub == 'abj404_captured' && $tableOptions['filter'] != '-1') {
            $cbinfo = "class=\"manage-column column-cb check-column\" style=\"vertical-align: middle; padding-bottom: 6px;\"";
        } else {
            $cbinfo = "style=\"width: 1px;\"";
        }
        echo "<th " . $cbinfo . ">";
        if ($sub == 'abj404_captured' && $tableOptions['filter'] != '-1') {
            echo "<input type=\"checkbox\">";
        }
        echo "</th>";
        foreach ($columns as $column) {
            $style = "";
            if ($column['width'] != "") {
                $style = " style=\"width: " . esc_attr($column['width']) . ";\" ";
            }
            $nolink = 0;
            $sortorder = "";
            if ($tableOptions['orderby'] == $column['orderby']) {
                $class = " sorted";
                if ($tableOptions['order'] == "ASC") {
                    $class .= " asc";
                    $sortorder = "DESC";
                } else {
                    $class .= " desc";
                    $sortorder = "ASC";
                }
            } else {
                if ($column['orderby'] != "") {
                    $class = " sortable";
                    if ($column['orderby'] == "timestamp" || $column['orderby'] == "lastused") {
                        $class .= " asc";
                        $sortorder = "DESC";
                    } else {
                        $class .= " desc";
                        $sortorder = "ASC";
                    }
                } else {
                    $class = "";
                    $nolink = 1;
                }
            }

            $url = "?page=" . ABJ404_PP;
            if ($sub == 'abj404_captured') {
                $url .= "&subpage=abj404_captured";
            } else if ($sub == 'abj404_logs') {
                $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
            }
            if ($tableOptions['filter'] != 0) {
                $url .= "&filter=" . $tableOptions['filter'];
            }
            $url .= "&orderby=" . $column['orderby'] . "&order=" . $sortorder;

            echo "<th" . $style . "class=\"manage-column column-title" . $class . "\">";
            if ($nolink == 1) {
                echo $column['title'];
            } else {
                echo "<a href=\"" . esc_url($url) . "\">";
                echo "<span>" . esc_html($column['title']) . "</span>";
                echo "<span class=\"sorting-indicator\"></span>";
                echo "</a>";
            }
            echo "</th>";
        }
        echo "<th style=\"width: 1px;\"></th>";
        echo "</tr>";
    }

    /** 
     * @global type $abj404dao
     * @param type $sub
     * @param type $tableOptions
     */
    function echoPaginationLinks($sub, $tableOptions) {
        global $abj404dao;

        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_logs') {
            $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
        }

        $url .= "&orderby=" . $tableOptions['orderby'];
        $url .= "&order=" . $tableOptions['order'];

        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
            if ($sub == 'abj404_redirects') {
                $types = array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO);
            } else {
                $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED);
            }
        } else {
            $types = array($tableOptions['filter']);
        }
        $url .= "&filter=" . $tableOptions['filter'];

        if ($sub == 'abj404_logs') {
            $num_records = $abj404dao->getLogsCount($tableOptions['logsid']);
        } else {
            // -1 means Trash. we should create a constant for this value...
            if ($tableOptions['filter'] == -1) {
                $num_records = $abj404dao->getRecordCount($types, 1);

            } else {
                $num_records = $abj404dao->getRecordCount($types);
            }
        }

        $total_pages = ceil($num_records / $tableOptions['perpage']);
        if ($total_pages == 0) {
            $total_pages = 1;
        }

        $itemsText = sprintf( _n( '%s item', '%s items', $num_records, '404-solution'), $num_records);

        $classFirstPage = "";
        if ($tableOptions['paged'] == 1) {
            $classFirstPage = " disabled";
        }
        $firsturl = $url;

        $classPreviousPage = "";
        if ($tableOptions['paged'] == 1) {
            $classPreviousPage = " disabled";
            $prevurl = $url;
        } else {
            $prev = $tableOptions['paged'] - 1;
            $prevurl = $url . "&paged=" . $prev;
        }

        $classNextPage = "";
        if ($tableOptions['paged'] + 1 > $total_pages) {
            $classNextPage = " disabled";
            if ($tableOptions['paged'] == 1) {
                $nexturl = $url;
            } else {
                $nexturl = $url . "&paged=" . $tableOptions['paged'];
            }
        } else {
            $next = $tableOptions['paged'] + 1;
            $nexturl = $url . "&paged=" . $next;
        }

        $classLastPage = "";
        if ($tableOptions['paged'] + 1 > $total_pages) {
            $classLastPage = " disabled";
            if ($tableOptions['paged'] == 1) {
                $lasturl = $url;
            } else {
                $lasturl = $url . "&paged=" . $tableOptions['paged'];
            }
        } else {
            $lasturl = $url . "&paged=" . $total_pages;
        }
        
        // ------------
        $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage'])) + 1;
        $end = min($start + absint(sanitize_text_field($tableOptions['perpage'])) - 1, $num_records);
        $currentlyShowingText = sprintf(__('%s - %s of %s', '404-solution'), $start, $end, $num_records);
        $currentPageText = __('Page', '404-solution') . " " . $tableOptions['paged'] . " " . __('of', '404-solution') . " " . esc_html($total_pages);
        $showRowsText = __('Rows per page:', '404-solution');
        $showRowsLink = wp_nonce_url($url . '&action=changeItemsPerRow', "abj404_importRedirects");
        
        // read the html content.
        $html = $abj404dao->readFileContents(__DIR__ . "/html/paginationLinks.html");
        // do special replacements
        $html = str_replace(' value="' . $tableOptions['perpage'] . '"', 
                ' value="' . $tableOptions['perpage'] . '" selected', 
                $html);
        $html = str_replace('{changeItemsPerPage}', $showRowsLink, $html);
        $html = str_replace('{TEXT_BEFORE_LINKS}', $currentlyShowingText, $html);
        $html = str_replace('{TEXT_SHOW_ROWS}', $showRowsText, $html);
        $html = str_replace('{LINK_FIRST_PAGE}', esc_url($firsturl), $html);
        $html = str_replace('{LINK_PREVIOUS_PAGE}', esc_url($prevurl), $html);
        $html = str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = str_replace('{LINK_NEXT_PAGE}', esc_url($nexturl), $html);
        $html = str_replace('{LINK_LAST_PAGE}', esc_url($lasturl), $html);
        // constants and translations.
        $html = $this->doNormalReplacements($html);
        
        echo $html;
    }    
    
    /** Output the filters for a tab.
     * @global type $abj404dao
     * @param type $sub
     * @param type $tableOptions
     */
    function echoTabFilters($sub, $tableOptions) {
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;

        if (count($tableOptions) == 0) {
            $tableOptions = $abj404logic->getTableOptions();
        }
        echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span>";
        echo "<ul class=\"subsubsub\">";

        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_redirects') {
            $url .= "&subpage=abj404_redirects";
        } else {
            $abj404logging->errorMessage("Unexpected sub page: " . $sub);
        }

        $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
        $url .= "&order=" . sanitize_text_field($tableOptions['order']);

        if ($sub == 'abj404_redirects') {
            $types = array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO);
        } else {
            $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED);
        }

        $class = "";
        if ($tableOptions['filter'] == 0) {
            $class = " class=\"current\"";
        }

        if ($sub != 'abj404_captured') {
            echo "<li>";
            echo "<a href=\"" . esc_url($url) . "\"" . $class . ">" . __('All', '404-solution');
            echo " <span class=\"count\">(" . esc_html($abj404dao->getRecordCount($types)) . ")</span>";
            echo "</a>";
            echo "</li>";
        }

        foreach ($types as $type) {
            $thisurl = $url . "&filter=" . $type;

            $class = "";
            if ($tableOptions['filter'] == $type) {
                $class = " class=\"current\"";
            }

            if ($type == ABJ404_STATUS_MANUAL) {
                $title = "Manual Redirects";
            } else if ($type == ABJ404_STATUS_AUTO) {
                $title = "Automatic Redirects";
            } else if ($type == ABJ404_STATUS_CAPTURED) {
                $title = "Captured URLs";
            } else if ($type == ABJ404_STATUS_IGNORED) {
                $title = "Ignored 404s";
            } else {
                $abj404logging->errorMessage("Unrecognized redirect type in View: " . esc_html($type));
            }

            echo "<li>";
            if ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) {
                echo " | ";
            }
            echo "<a href=\"" . esc_url($thisurl) . "\"" . $class . ">" . ( $title );
            echo " <span class=\"count\">(" . esc_html($abj404dao->getRecordCount(array($type))) . ")</span>";
            echo "</a>";
            echo "</li>";
        }


        $trashurl = $url . "&filter=-1";
        $class = "";
        if ($tableOptions['filter'] == -1) {
            $class = " class=\"current\"";
        }
        echo "<li> | ";
        echo "<a href=\"" . esc_url($trashurl) . "\"" . $class . ">" . __('Trash', '404-solution');
        echo " <span class=\"count\">(" . esc_html($abj404dao->getRecordCount($types, 1)) . ")</span>";
        echo "</a>";
        echo "</li>";

        echo "</ul>";
        echo "</span>";
    }
}
