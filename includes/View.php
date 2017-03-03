<?php

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

class ABJ_404_Solution_View {

    /** Get the text to notify the user when some URLs have been captured and need attention. 
     * @param int $captured the number of captured URLs
     * @return type html
     */
    function getDashboardNotification(int $captured) {
        $capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.', 
                'There are <a>%s captured 404 URLs</a> that need to be processed.', 
                $captured, '404-solution'), $captured);
        $capturedMessage = str_replace("<a>", 
                "<a href=\"?page=abj404_solution&subpage=abj404_captured\" \">", 
                $capturedMessage);
        $capturedMessage = str_replace("</a>", "</a>", $capturedMessage);

        return "<div class=\"updated\"><p><strong>" . esc_html(__('404 Solution', '404-solution')) . 
                ":</strong> " . $capturedMessage . "</p></div>";
    }

    /** Do an action like trash/delete/ignore/edit and display a page like stats/logs/redirects/options.
     * @global type $abj404view
     * @global type $abj404logic
     */
    static function handleMainAdminPageActionAndDisplay() {
        if (!is_admin() || !current_user_can('administrator')) { return; }
        
        global $abj404view;
        global $abj404logic;
        
        $sub = "";

        // --------------------------------------------------------------------
        // Handle Post Actions
        if (isset($_POST['action'])) {
            $action = sanitize_text_field($_POST['action']);
        } else {
            $action = "";
        }

        // this should really not pass things by reference so it can be more object oriented (encapsulation etc).
        $message = "";
        $message .= $abj404logic->handlePluginAction($action, $sub);
        $message .= $abj404logic->hanldeTrashAction();
        $message .= $abj404logic->handleDeleteAction();
        $message .= $abj404logic->handleIgnoreAction();
        $message .= $abj404logic->handleEditAction($sub);

        // --------------------------------------------------------------------
        // Output the correct page.
        $abj404view->echoChosenAdminTab($sub, $message);
    }
    
    /** Display the chosen admin page.
     * @global type $abj404view
     * @param type $sub
     * @param type $message
     */
    function echoChosenAdminTab($sub, $message) {
        global $abj404view;

        // Deal With Page Tabs
        if ($sub == "") {
            if (isset($_GET['subpage'])) {
                $sub = strtolower(sanitize_text_field($_GET['subpage']));
            } else {
                $sub = "";
            }
        }
        if ($sub == "abj404_options") {
            $sub = "options";
        } else if ($sub == "abj404_captured") {
            $sub = "captured";
        } else if ($sub == "abj404_logs") {
            $sub = "logs";
        } else if ($sub == "abj404_edit") {
            $sub = "edit";
        } else if ($sub == "abj404_stats") {
            $sub = "stats";
        } else if ($sub == "abj404_tools") {
            $sub = "tools";
        } else if ($sub == "abj404_redirects") {
            $sub = "redirects";

        } else {
            // default page when clicking the settigns submenu.
            $sub = "redirects";
        }
        
        $abj404view->outputAdminHeaderTabs($sub, $message);
        
        if ($sub == "redirects") {
            $abj404view->echoAdminRedirectsPage();
        } else if ($sub == "captured") {
            $abj404view->echoAdminCapturedURLsPage();
        } else if ($sub == "options") {
            $abj404view->echoAdminOptionsPage();
        } else if ($sub == "logs") {
            $abj404view->echoAdminLogsPage();
        } else if ($sub == "edit") {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == "stats") {
            $abj404view->outputAdminStatsPage();
        } else if ($sub == "tools") {
            $abj404view->echoAdminToolsPage();
        } else {
            echo __('Invalid Sub Page ID', '404-solution') . " (" + esc_html($sub) . ")";
            ABJ_404_Solution_Functions::debugMessage("Invalid sub page ID: " + esc_html($sub));
        }
        
        $abj404view->echoAdminFooter();
    }
    
    /** Echo the text that appears at the bottom of each admin page. */
    function echoAdminFooter() {
        echo "<div style=\"clear: both;\">";
        echo "<BR/>";
        echo "<HR/><strong>Credits:</strong><br>";
        echo "<a href=\"" . ABJ404_HOME . "\" title=\"" . __('404 Solution') . "\" target=\"_blank\">" . __('404 Solution') . "</a> ";
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
        if ($sub == "options") {
            $header = " " . __('Options', '404-solution');
        } else if ($sub == "logs") {
            $header = " " . __('Logs', '404-solution');
        } else if ($sub == "stats") {
            $header = " " . __('Stats', '404-solution');
        } else if ($sub == "edit") {
            $header = ": " . __('Edit Redirect', '404-solution');
        } else if ($sub == "redirects") {
            $header = "";
        } else {
            $header = "";
        }
        echo "<div class=\"wrap\">";
        if ($sub == "options") {
            echo "<div id=\"icon-options-general\" class=\"icon32\"></div>";
        } else {
            echo "<div id=\"icon-tools\" class=\"icon32\"></div>";
        }
        echo "<h2>" . __('404 Solution', '404-solution') . esc_html($header) . "</h2>";
        if ($message != "") {
            $allowed_tags = [
                'br' => [],
                'em' => [],
                'strong' => [],
            ];
            echo "<div class=\"message updated\"><p>" . wp_kses($message, $allowed_tags) . "</p></div>";
        }

        $class = "";
        if ($sub == "redirects") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_redirects\" title=\"" . __('Page Redirects', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Page Redirects', '404-solution') . "</a>";

        $class = "";
        if ($sub == "captured") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_captured\" title=\"" . __('Captured 404 URLs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Captured 404 URLs', '404-solution') . "</a>";

        $class = "";
        if ($sub == "logs") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_logs\" title=\"" . __('Redirect & Capture Logs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Logs', '404-solution') . "</a>";

        $class = "";
        if ($sub == "stats") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_stats\" title=\"" . __('Stats', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Stats', '404-solution') . "</a>";

        $class = "";
        if ($sub == "tools") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_tools\" title=\"" . __('Tools', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Tools', '404-solution') . "</a>";

        $class = "";
        if ($sub == "options") {
            $class = "nav-tab-active";
        }
        echo "<a href=\"?page=abj404_solution&subpage=abj404_options\" title=\"Options\" class=\"nav-tab " . $class . "\">" . __('Options', '404-solution') . "</a>";

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
        $logs = $wpdb->prefix . "abj404_logs";
        $hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_AUTO;
        $auto301 = $abj404dao->getStatsCount($query, array(ABJ404_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_AUTO;
        $auto302 = $abj404dao->getStatsCount($query, array(ABJ404_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_MANUAL;
        $manual301 = $abj404dao->getStatsCount($query, array(ABJ404_MANUAL));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_MANUAL;
        $manual302 = $abj404dao->getStatsCount($query, array(ABJ404_MANUAL));

        $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
        $trashed = $abj404dao->getStatsCount($query, array(ABJ404_AUTO, ABJ404_MANUAL));

        $total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

        echo "<div class=\"postbox-container\" style=\"float: right; width: 49%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        $content = "";
        $content .= "<p $hr>";
        $content .= "<strong>" . __('Automatic 301 Redirects', '404-solution') . ":</strong> " . esc_html($auto301) . "<br>";
        $content .= "<strong>" . __('Automatic 302 Redirects', '404-solution') . ":</strong> " . esc_html($auto302) . "<br>";
        $content .= "<strong>" . __('Manual 301 Redirects', '404-solution') . ":</strong> " . esc_html($manual301) . "<br>";
        $content .= "<strong>" . __('Manual 302 Redirects', '404-solution') . ":</strong> " . esc_html($manual302) . "<br>";
        $content .= "<strong>" . __('Trashed Redirects', '404-solution') . ":</strong> " . esc_html($trashed) . "</p>";
        $content .= "<p style=\"margin-top: 4px;\">";
        $content .= "<strong>" . __('Total Redirects', '404-solution') . ":</strong> " . esc_html($total);
        $content .= "</p>";
        $abj404view->echoPostBox("abj404-redirectStats", __('Redirects', '404-solution'), $content);

        // -------------------------------------------
        $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_CAPTURED;
        $captured = $abj404dao->getStatsCount($query, array(ABJ404_CAPTURED));

        $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_IGNORED;
        $ignored = $abj404dao->getStatsCount($query, array(ABJ404_IGNORED));

        $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
        $trashed = $abj404dao->getStatsCount($query, array(ABJ404_CAPTURED, ABJ404_IGNORED));

        $total = $captured + $ignored + $trashed;

        $content = "";
        $content .= "<p $hr>";
        $content .= "<strong>" . __('Captured URLs', '404-solution') . ":</strong> " . esc_html($captured) . "<br>";
        $content .= "<strong>" . __('Ignored 404 URLs', '404-solution') . ":</strong> " . esc_html($ignored) . "<br>";
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

        $today = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        $firstm = mktime(0, 0, 0, date('m'), 1, date('Y'));
        $firsty = mktime(0, 0, 0, 1, 1, date('Y'));

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

            $query = "select count(id) from $logs where timestamp >= $ts and action = %s";
            $disp404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action = %s";
            $distinct404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action = %s";
            $visitors404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and action = %s";
            $refer404 = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(id) from $logs where timestamp >= $ts and action != %s";
            $redirected = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action != %s";
            $distinctredirected = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action != %s";
            $distinctvisitors = $abj404dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and action != %s";
            $distinctrefer = $abj404dao->getStatsCount($query, array("404"));

            $content = "";
            $content .= "<p>";
            $content .= "<strong>" . __('Page Not Found Displayed', '404-solution') . ":</strong> " . esc_html($disp404) . "<br>";
            $content .= "<strong>" . __('Unique Page Not Found URLs', '404-solution') . ":</strong> " . esc_html($distinct404) . "<br>";
            $content .= "<strong>" . __('Unique Page Not Found Visitors', '404-solution') . ":</strong> " . esc_html($visitors404) . "<br>";
            $content .= "<strong>" . __('Unique Page Not Found Referrers', '404-solution') . ":</strong> " . esc_html($refer404) . "<br>";
            $content .= "<strong>" . __('Hits Redirected', '404-solution') . ":</strong> " . esc_html($redirected) . "<br>";
            $content .= "<strong>" . __('Unique URLs Redirected', '404-solution') . ":</strong> " . esc_html($distinctredirected) . "<br>";
            $content .= "<strong>" . __('Unique Redirected Visitors', '404-solution') . ":</strong> " . esc_html($distinctvisitors) . "<br>";
            $content .= "<strong>" . __('Unique Redirected Referrers', '404-solution') . ":</strong> " . esc_html($distinctrefer) . "<br>";
            $content .= "</p>";
            $abj404view->echoPostBox("abj404-stats" . $x, __($title), $content);
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }

    /** Display the tools page.
     * @global type $abj404view
     */
    function echoAdminToolsPage() {
        global $abj404view;
        $sub = "tools";

        $hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

        $url = "?page=abj404_solution&subpage=abj404_tools";
        $action = "abj404_purgeRedirects";

        $link = wp_nonce_url($url, $action);


        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        $content = "";
        $content .= "<form method=\"POST\" action=\"" . esc_url($link) . "\">";
        $content .= "<input type=\"hidden\" name=\"action\" value=\"purgeRedirects\">";

        $content .= "<p>";
        $content .= "<strong><label for=\"purgetype\">" . __('Purge Type', '404-solution') . ":</label></strong> <select name=\"purgetype\" id=\"purgetype\">";
        $content .= "<option value=\"logs\">" . __('Logs Only', '404-solution') . "</option>";
        $content .= "<option value=\"redirects\">" . __('Logs & Redirects', '404-solution') . "</option>";
        $content .= "</select><br><br>";

        $content .= "<strong>" . __('Redirect Types', '404-solution') . ":</strong><br>";
        $content .= "<ul style=\"margin-left: 40px;\">";
        $content .= "<li><input type=\"checkbox\" id=\"auto\" name=\"types[]\" value=\"" . ABJ404_AUTO . "\"> <label for=\"auto\">" . __('Automatic Redirects', '404-solution') . "</label></li>";
        $content .= "<li><input type=\"checkbox\" id=\"manual\" name=\"types[]\" value=\"" . ABJ404_MANUAL . "\"> <label for=\"manual\">" . __('Manual Redirects', '404-solution') . "</label></li>";
        $content .= "<li><input type=\"checkbox\" id=\"captured\" name=\"types[]\" value=\"" . ABJ404_CAPTURED . "\"> <label for=\"captured\">" . __('Captured URLs', '404-solution') . "</label></li>";
        $content .= "<li><input type=\"checkbox\" id=\"ignored\" name=\"types[]\" value=\"" . ABJ404_IGNORED . "\"> <label for=\"ignored\">" . __('Ignored URLs', '404-solution') . "</label></li>";
        $content .= "</ul>";

        $content .= "<strong>" . __('Sanity Check', '404-solution') . "</strong><br>";
        $content .= __('Using the purge options will delete logs and redirects matching the boxes selected above. This action is not reversible. Hopefully you know what you\'re doing.', '404-solution') . "<br>";
        $content .= "<br>";
        $content .= "<input type=\"checkbox\" id=\"sanity\" name=\"sanity\" value=\"1\"> " . __('I understand the above statement, I know what I am doing... blah blah blah. Just delete the records!', '404-solution') . "<br>";
        $content .= "<br>";
        $content .= "<input type=\"submit\" value=\"" . __('Purge Entries!', '404-solution') . "\" class=\"button-secondary\">";
        $content .= "</p>";

        $content .= "</form>";

        $abj404view->echoPostBox("abj404-purgeRedirects", __('Purge Options', '404-solution'), $content);

        echo "</div></div></div>";
    }

    function echoAdminOptionsPage() {
        global $abj404logic;
        global $abj404view;

        $options = $abj404logic->getOptions();

        $url = "?page=abj404_solution";

        //General Options
        $action = "abj404UpdateOptions";
        $link = wp_nonce_url($url, $action);

        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"updateOptions\">";

        $contentAutomaticRedirects = $abj404view->getAdminOptionsPageAutoRedirects($options);
        $abj404view->echoPostBox("abj404-autooptions", __('Automatic Redirects', '404-solution'), $contentAutomaticRedirects);

        $contentGeneralSettings = $abj404view->getAdminOptionsPageGeneralSettings($options);
        $abj404view->echoPostBox("abj404-generaloptions", __('General Settings', '404-solution'), $contentGeneralSettings);

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
     * @return type
     */
    function echoAdminEditRedirectPage() {
        global $abj404dao;

        // this line assures that text will appear below the page tabs at the top.
        echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" /> <BR/>";

        if (isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {
            $recnum = absint($_GET['id']);
        } else if (isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id'])) {
            $recnum = absint($_POST['id']);
        } else {
            echo __('Error: No ID found for edit request.', '404-solution');
            ABJ_404_Solution_Functions::errorMessage("No ID found in GET or POST data for edit request.");
            return;
        }

        $redirect = $abj404dao->getRedirectByID($recnum);

        if ($redirect == null) {
            echo "Error: Invalid ID Number! (id: " + $recnum . ")";
            ABJ_404_Solution_Functions::debugMessage("Error: Invalid ID Number! (id: " + $recnum . ")");
            return;
        }

        echo "<h3>" . __('Redirect Details', '404-solution') . "</h3>";

        $url = "?page=abj404_solution&subpage=abj404_edit";

        $action = "abj404editRedirect";
        $link = wp_nonce_url($url, $action);

        echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";
        echo "<input type=\"hidden\" name=\"id\" value=\"" . esc_attr($redirect['id']) . "\">";
        echo "<strong><label for=\"url\">" . __('URL', '404-solution') . 
                ":</label></strong> <input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . 
                esc_attr($redirect['url']) . "\"> (" . __('Required', '404-solution') . ")<br>";
        echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</strong> <select id=\"dest\" name=\"dest\">";
        $selected = "";
        if ($redirect['type'] == ABJ404_EXTERNAL) {
            $selected = " selected";
        }
        echo "<option value=\"" . ABJ404_EXTERNAL . "\"" . $selected . ">" . __('External Page', '404-solution') . "</options>";

        $postRows = $abj404dao->getPublishedPostIDs();
        foreach ($postRows as $row) {
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_POST;

            $selected = "";
            if ($redirect['type'] == ABJ404_POST && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Post', '404-solution') . ": " . $theTitle . "</option>";
        }

        $pagesRows = get_pages();
        foreach ($pagesRows as $row) {
            $id = $row->ID;
            $theTitle = $row->post_title;
            $thisval = $id . "|" . ABJ404_POST;

            $parent = $row->post_parent;
            while ($parent != 0) {
                $abj404dao->getPostParent($parent);
                if (!( $prow == NULL )) {
                    $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                    $parent = $prow->post_parent;
                } else {
                    break;
                }
            }

            $selected = "";
            if ($redirect['type'] == ABJ404_POST && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . $theTitle . "</option>\n";
        }

        $cats = get_categories('hierarchical=0');
        foreach ($cats as $cat) {
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_CAT;

            $selected = "";
            if ($redirect['type'] == ABJ404_CAT && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }

        $tags = get_tags('hierarchical=0');
        foreach ($tags as $tag) {
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TAG;

            $selected = "";
            if ($redirect['type'] == ABJ404_TAG && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }

        echo "</select><br>";
        $final = "";
        if ($redirect['type'] == ABJ404_EXTERNAL) {
            $final = $redirect['final_dest'];
        }
        echo "<strong><label for=\"external\">" . __('External URL', '404-solution') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . $final . "\"> (" . __('Required if Redirect to is set to External Page', '404-solution') . ")<br>";
        echo "<strong><label for=\"code\">" . __('Redirect Type', '404-solution') . ":</label></strong> <select id=\"code\" name=\"code\">";
        if ($redirect['code'] == "") {
            $codeselected = $options['default_redirect'];
        } else {
            $codeselected = $redirect['code'];
        }
        $codes = array(301, 302);
        foreach ($codes as $code) {
            $selected = "";
            if ($code == $codeselected) {
                $selected = " selected";
            }

            $title = ($code == 301) ? '301 Permanent Redirect' : '302 Temporary Redirect';
            echo "<option value=\"" . $code . "\"" . $selected . ">" . $title . "</option>";
        }
        echo "</select><br>";
        echo "<input type=\"submit\" value=\"" . __('Update Redirect', '404-solution') . "\" class=\"button-secondary\">";
        echo "</form>";
    }

    /** 
     * @global type $abj404dao
     */
    function echoAdminCapturedURLsPage() {
        global $abj404dao;
        global $abj404logic;
        $sub = "captured";

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
            $eturl = "?page=abj404_solution&subpage=abj404_captured&filter=-1";
            $trashaction = "abj404_emptyCapturedTrash";
            $eturl = wp_nonce_url($eturl, $trashaction);

            echo "<form method=\"POST\" action=\"" . esc_url($eturl) . "\">";
            echo "<input type=\"hidden\" name=\"action\" value=\"emptyCapturedTrash\">";
            echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Empty Trash', '404-solution') . "\">";
            echo "</form>";
            echo "</div>";
        } else {
            echo "<div class=\"alignleft actions\">";
            $url = "?page=abj404_solution&subpage=abj404_captured";
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
            if ($tableOptions['filter'] != ABJ404_IGNORED) {
                echo "<option value=\"bulkignore\">" . __('Mark as ignored', '404-solution') . "</option>";
            } else {
                echo "<option value=\"bulkcaptured\">" . __('Mark as captured', '404-solution') . "</option>";
            }
            echo "<option value=\"bulktrash\">" . __('Trash', '404-solution') . "</option>";
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
            $last_used = $abj404dao->getRedirectLastUsed($row['id']);
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", $last_used);
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=abj404_solution&subpage=abj404_edit&id=" . $row['id'];
            $logslink = "?page=abj404_solution&subpage=abj404_logs&id=" . $row['id'];
            $trashlink = "?page=abj404_solution&&subpage=abj404_captured&id=" . $row['id'];
            $ignorelink = "?page=abj404_solution&&subpage=abj404_captured&id=" . $row['id'];
            $deletelink = "?page=abj404_solution&subpage=abj404_captured&remove=1&id=" . $row['id'];

            if ($tableOptions['filter'] == -1) {
                $trashlink .= "&trash=0";
                $trashtitle = __('Restore', '404-solution');
            } else {
                $trashlink .= "&trash=1";
                $trashtitle = __('Trash', '404-solution');
            }

            if ($tableOptions['filter'] == ABJ404_IGNORED) {
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
            echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs', '404-solution') . "</a></span>";
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
            echo "<td>" . esc_html(date("Y/m/d h:i:s A", $row['timestamp'])) . "</td>";
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

        $sub = "redirects";

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
            $eturl = "?page=abj404_solution&filter=-1";
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
            if ($row['status'] == ABJ404_MANUAL) {
                $status = __('Manual', '404-solution');
            } else if ($row['status'] == ABJ404_AUTO) {
                $status = __('Automatic', '404-solution');
            }

            $type = "";
            $dest = "";
            $link = "";
            $title = __('Visit', '404-solution') . " ";
            if ($row['type'] == ABJ404_EXTERNAL) {
                $type = __('External', '404-solution');
                $dest = $row['final_dest'];
                $link = $row['final_dest'];
                $title .= $row['final_dest'];
            } else if ($row['type'] == ABJ404_POST) {
                $type = __('Post/Page', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|POST", 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= $permalink['title'];
            } else if ($row['type'] == ABJ404_CAT) {
                $type = __('Category', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|CAT", 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= __('Category:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TAG) {
                $type = __('Tag', '404-solution');
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|TAG", 0);
                $dest = $permalink['title'];
                $link = $permalink['link'];
                $title .= __('Tag:', '404-solution') . " " . $permalink['title'];
            }


            $hits = $row['hits'];
            $last_used = $abj404dao->getRedirectLastUsed($row['id']);
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", $last_used);
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=abj404_solution&subpage=abj404_edit&id=" . absint($row['id']);
            $logslink = "?page=abj404_solution&subpage=abj404_logs&id=" . absint($row['id']);
            $trashlink = "?page=abj404_solution&id=" . absint($row['id']);
            $deletelink = "?page=abj404_solution&remove=1&id=" . absint($row['id']);

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
            echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs') . "</a></span>";
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
            echo "<td>" . esc_html(date("Y/m/d h:i:s A", $row['timestamp'])) . "</td>";
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

            $url = "?page=abj404_solution";

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

            if (!empty($_POST['url'])) {
                $postedURL = esc_url($_POST['url']);
            } else {
                $postedURL = $urlPlaceholder;
            }
            
            echo "<strong><label for=\"url\">" . __('URL', '404-solution') . 
                    ":</label></strong> <input id=\"url\" placeholder=\"" . $urlPlaceholder . 
                    "\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . 
                    esc_attr($postedURL) . "\"> (" . __('Required', '404-solution') . ")<br>";
            echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</strong> <select id=\"dest\" name=\"dest\">";
            $selected = "";
            if (isset($_POST['dest']) && $_POST['dest'] == "EXTERNAL") {
                $selected = " selected";
            }
            echo "<option value=\"EXTERNAL\"" . $selected . ">" . __('External Page', '404-solution') . "</options>";

            $rows = $abj404dao->getPublishedPostIDs();
            foreach ($rows as $row) {
                $id = $row->id;
                $theTitle = get_the_title($id);
                $thisval = $id . "|POST";

                $selected = "";
                if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                    $selected = " selected";
                }
                echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Post', '404-solution') . ": " . esc_html($theTitle) . "</option>";
            }

            $rows = get_pages();
            foreach ($rows as $row) {
                $id = $row->ID;
                $theTitle = $row->post_title;
                $thisval = $id . "|POST";

                $parent = $row->post_parent;
                while ($parent != 0) {
                    $prow = $abj404dao->getPostParent(absint($parent));
                    if (!( $prow == NULL )) {
                        $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                        $parent = $prow->post_parent;
                    } else {
                        break;
                    }
                }

                $selected = "";
                if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                    $selected = " selected";
                }
                echo "<option value=\"" . esc_url($thisval) . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . esc_html($theTitle) . "</option>";
            }

            $cats = get_categories('hierarchical=0');
            foreach ($cats as $cat) {
                $id = $cat->term_id;
                $theTitle = $cat->name;
                $thisval = $id . "|CAT";

                $selected = "";
                if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                    $selected = " selected";
                }
                echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . esc_html($theTitle) . "</option>";
            }

            $tags = get_tags('hierarchical=0');
            foreach ($tags as $tag) {
                $id = $tag->term_id;
                $theTitle = $tag->name;
                $thisval = $id . "|TAG";

                $selected = "";
                if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                    $selected = " selected";
                }
                echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . esc_html($theTitle) . "</option>";
            }

            echo "</select><br>";
            if (isset($_POST['external'])) {
                $postedExternal = esc_url($_POST['external']);
            } else {
                $postedExternal = "";
            }
            echo "<strong><label for=\"external\">" . __('External URL', '404-solution') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . esc_attr($postedExternal) . "\"> (" . __('Required if Redirect to is set to External Page', '404-solution') . ")<br>";
            echo "<strong><label for=\"code\">" . __('Redirect Type', '404-solution') . ":</label></strong> <select id=\"code\" name=\"code\">";
            if ((!isset($_POST['code']) ) || $_POST['code'] == "") {
                $codeselected = $options['default_redirect'];
            } else {
                $codeselected = sanitize_text_field($_POST['code']);
            }
            $codes = array(301, 302);
            foreach ($codes as $code) {
                $selected = "";
                if ($code == $codeselected) {
                    $selected = " selected";
                }
                if ($code == 301) {
                    $title = '301 Permanent Redirect';
                } else {
                    $title = '302 Temporary Redirect';
                }
                echo "<option value=\"" . esc_attr($code) . "\"" . $selected . ">" . esc_html($title) . "</option>";
            }
            echo "</select><br>";
            echo "<input type=\"submit\" value=\"" . __('Add Redirect', '404-solution') . "\" class=\"button-secondary\">";
            echo "</form>";
        }
    }

    /** 
     * @global type $abj404dao
     * @global type $wpdb
     * @param type $options
     * @return string
     */
    function getAdminOptionsPageAutoRedirects($options) {
        global $abj404dao;
        $content = "";

        $selected = "";
        global $wpdb;
        $content .= "<label for=\"dest404page\">" . __('Redirect all unhandled 404s to', '404-solution') . ":</label> <select id=\"dest404page\" name=\"dest404page\">";

        $userSelected = (isset($options['dest404page']) ? $options['dest404page'] : null);
        if ($userSelected == 0) {
            $selected = "selected";
        }

        $content .= "<option value=\"0\"" . $selected . ">" . "Default 404 Page (Unchanged)" . "</option>";

        $rows = get_pages();
        foreach ($rows as $row) {
            $id = $row->ID;
            $theTitle = $row->post_title;
            $thisval = $id;

            $parent = $row->post_parent;
            while ($parent != 0) {
                $parent = absint($parent);
                $prow = $abj404dao->getPostParent($parent);
                if (!( $prow == NULL )) {
                    $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                    $parent = $prow->post_parent;
                } else {
                    break;
                }
            }
            if ($userSelected == $thisval) {
                $selected = "selected";
            } else {
                $selected = "";
            }

            $content .= "<option value=\"" . $thisval . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . esc_html($theTitle) . "</option>";
        }

        $content .= "</select><br>";

        $selectedAutoRedirects = "";
        if ($options['auto_redirects'] == '1') {
            $selectedAutoRedirects = " checked";
        }

        $content .= "<p><label for=\"auto_redirects\">" . __('Create automatic redirects', '404-solution') . ":</label> <input type=\"checkbox\" name=\"auto_redirects\" id=\"auto_redirects\" value=\"1\"" . $selectedAutoRedirects . "><br>";
        $content .= __('Automatically creates redirects based on best possible suggested page.', '404-solution') . "</p>";

        $content .= "<p><label for=\"auto_score\">" . __('Minimum match score', '404-solution') . ":</label> <input type=\"text\" name=\"auto_score\" id=\"auto_score\" value=\"" . esc_attr($options['auto_score']) . "\" style=\"width: 50px;\"><br>";
        $content .= __('Only create an automatic redirect if the suggested page has a score above the specified number', '404-solution') . "</p>";

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

        $selectedForcePermaLinks = "";
        if ($options['force_permalinks'] == '1') {
            $selectedForcePermaLinks = " checked";
        }
        $content .= "<p><label for=\"force_permalinks\">" . __('Force current permalinks', '404-solution') . ":</label> <input type=\"checkbox\" name=\"force_permalinks\" id=\"force_permalinks\" value=\"1\"" . $selectedForcePermaLinks . "><br>";
        $content .= __('Creates auto redirects for any url resolving to a post/page that doesn\'t match the current permalinks', '404-solution') . "</p>";

        $content .= "<p><label for=\"auto_deletion\">" . __('Auto redirect deletion', '404-solution') . ":</label> <input type=\"text\" name=\"auto_deletion\" id=\"auto_deletion\" value=\"" . esc_attr($options['auto_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
        $content .= __('Removes auto created redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

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
        $content = "<p><label for=\"display_suggest\">" . __('Turn on 404 suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"display_suggest\" id=\"display_suggest\" value=\"1\"" . $selectedDisplaySuggest . "><br>";
        $content .= __('Activates the 404 page suggestions function. Only works if the code is in your 404 page template.', '404-solution') . "</p>";

        $selectedSuggestCats = "";
        if ($options['suggest_cats'] == '1') {
            $selectedSuggestCats = " checked";
        }
        $content .= "<p><label for=\"suggest_cats\">" . __('Allow category suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"suggest_cats\" id=\"suggest_cats\" value=\"1\"" . $selectedSuggestCats . "><br>";

        $selectedSuggestTags = "";
        if ($options['suggest_tags'] == '1') {
            $selectedSuggestTags = " checked";
        }
        $content .= "<p><label for=\"suggest_tags\">" . __('Allow tag suggestions', '404-solution') . ":</label> <input type=\"checkbox\" name=\"suggest_tags\" id=\"suggest_tags\" value=\"1\"" . $selectedSuggestTags . "><br>";

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

    /** 
     * @param type $options
     * @return string
     */
    function getAdminOptionsPageGeneralSettings($options) {
        $content = "<p>" . __('DB Version Number', '404-solution') . ": " . esc_html($options['DB_VERSION']) . "</p>";
        $content .= "<p><label for=\"default_redirect\">" . __('Default redirect type', '404-solution') . ":</label> ";
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

        $content .= "<p><label for=\"admin_notification\">" . __('Admin notification level', '404-solution') . ":</label> <input type=\"text\" name=\"admin_notification\" id=\"admin_notification\" value=\"" . esc_attr($options['admin_notification']) . "\" style=\"width: 50px;\"> " . __('Captured URLs (0 Disables Notification)', '404-solution') . "<br>";
        $content .= __('Display WordPress admin notifications when number of captured URLs goes above specified level', '404-solution') . "</p>";

        $content .= "<p><label for=\"capture_deletion\">" . __('Collected 404 URL deletion', '404-solution') . ":</label> <input type=\"text\" name=\"capture_deletion\" id=\"capture_deletion\" value=\"" . esc_attr($options['capture_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
        $content .= __('Automatically removes 404 URLs that have been captured if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

        $content .= "<p><label for=\"manual_deletion\">" . __('Manual redirect deletion', '404-solution') . ":</label> <input type=\"text\" name=\"manual_deletion\" id=\"manual_deletion\" value=\"" . esc_attr($options['manual_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
        $content .= __('Automatically removes manually created page redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

        $selectedRemoveMatches = "";
        if ($options['remove_matches'] == '1') {
            $selectedRemoveMatches = " checked";
        }
        $content .= "<p><label for=\"remove_matches\">" . __('Remove redirect upon matching permalink', '404-solution') . ":</label> <input type=\"checkbox\" value=\"1\" name=\"remove_matches\" id=\"remove_matches\"" . $selectedRemoveMatches . "><br>";
        $content .= __('Checks each redirect for a new matching permalink before user is redirected. If a new page permalink is found matching the redirected URL then the redirect will be deleted.', '404-solution') . "</p>";

        $selectedDebugLogging = "";
        if ($options['debug_mode'] == '1') {
            $selectedDebugLogging = " checked";
        }
        $content .= "<p><label for=\"debug_mode\">" . __('Debug logging', '404-solution') . ":</label> <input type=\"checkbox\" name=\"debug_mode\" id=\"debug_mode\" value=\"1\"" . $selectedDebugLogging . "></p>";

        return $content;
    }

    /** 
     * @global type $abj404dao
     */
    function echoAdminLogsPage() {
        global $abj404dao;
        global $abj404logic;

        $sub = "logs";
        $tableOptions = $abj404logic->getTableOptions();

        // Sanitizing unchecked table options
        foreach ($tableOptions as $key => $value) {
            $key = wp_kses_post($key);
            $tableOptions[$key] = wp_kses_post($value);
        }

        $redirects = array();
        $redirectsFound = 0;
        
        $rows = $abj404dao->getRedirectsAll();
        foreach ($rows as $row) {
            $redirects[$row['id']]['id'] = absint($row['id']);
            $redirects[$row['id']]['url'] = esc_url($row['url']);
            $redirectsFound++;
        }
        ABJ_404_Solution_Functions::debugMessage($redirectsFound . " redirects found for logs page select option.");

        echo "<br>";
        echo "<form method=\"GET\" action=\"\" style=\"clear: both; display: block;\" class=\"clearbothdisplayblock\">";
        echo "<input type=\"hidden\" name=\"page\" value=\"abj404_solution\">";
        echo "<input type=\"hidden\" name=\"subpage\" value=\"abj404_logs\">";
        echo "<strong><label for=\"id\">" . __('View Logs For', '404-solution') . ":</label></strong> ";
        echo "<select name=\"id\" id=\"id\">";
        $selected = "";
        if ($tableOptions['logsid'] == 0) {
            $selected = " selected";
        }
        echo "<option value=\"0\"" . $selected . ">" . __('All Redirects', '404-solution') . "</option>";
        foreach ($redirects as $redirect) {
            $selected = "";
            if ($tableOptions['logsid'] == $redirect['id']) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($redirect['id']) . "\"" . $selected . ">" . esc_html($redirect['url']) . "</option>";
        }
        echo "</select><br>";
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
            echo "<td>" . esc_html($redirects[$row['redirect_id']]['url']) . "</td>";
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
            echo "<td>" . esc_html(date('Y/m/d h:i:s A', $row['timestamp'])) . "</td>";
            echo "<td></td>";
            echo "</tr>";
            $redirectsDisplayed++;
        }
        ABJ_404_Solution_Functions::debugMessage($redirectsDisplayed . " log records displayed on the page.");
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
        if ($sub == "captured" && $tableOptions['filter'] != '-1') {
            $cbinfo = "class=\"manage-column column-cb check-column\" style=\"vertical-align: middle; padding-bottom: 6px;\"";
        } else {
            $cbinfo = "style=\"width: 1px;\"";
        }
        echo "<th " . $cbinfo . ">";
        if ($sub == "captured" && $tableOptions['filter'] != '-1') {
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

            $url = "?page=abj404_solution";
            if ($sub == "captured") {
                $url .= "&subpage=abj404_captured";
            } else if ($sub == "logs") {
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

        $url = "?page=abj404_solution";
        if ($sub == "captured") {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == "logs") {
            $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
        }

        $url .= "&orderby=" . $tableOptions['orderby'];
        $url .= "&order=" . $tableOptions['order'];

        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
            if ($sub == "redirects") {
                $types = array(ABJ404_MANUAL, ABJ404_AUTO);
            } else {
                $types = array(ABJ404_CAPTURED, ABJ404_IGNORED);
            }
        } else {
            $types = array($tableOptions['filter']);
            $url .= "&filter=" . $tableOptions['filter'];
        }

        if ($sub == "logs") {
            $num_records = $abj404dao->getLogsCount($tableOptions['logsid']);
        } else {
            // -1 means Trash. we should create a constant for this value...
            if ($tableOptions['filter'] == -1) {
                $num_records = $abj404dao->getRecordCount($types, 1);

            } else {
                $num_records = $abj404dao->getRecordCount($types);
            }
        }
        if (ABJ_404_Solution_Functions::isDebug()) {
            ABJ_404_Solution_Functions::debugMessage(esc_html($num_records) . " total log records found. Table options: " .
                    wp_kses(json_encode($tableOptions), array()));
        }

        $total_pages = ceil($num_records / $tableOptions['perpage']);
        if ($total_pages == 0) {
            $total_pages = 1;
        }

        echo "<div class=\"tablenav-pages\">";
        $itemsText = sprintf( _n( '%s item', '%s items', $num_records, '404-solution'), $num_records);
        echo "<span class=\"displaying-num\">" . " " . $itemsText . "</span>";
        echo "<span class=\"pagination-links\">";

        $classFirstPage = "";
        if ($tableOptions['paged'] == 1) {
            $classFirstPage = " disabled";
        }
        $firsturl = $url;
        echo "<a href=\"" . esc_url($firsturl) . "\" class=\"first-page" . $classFirstPage . "\" title=\"" . __('Go to first page', '404-solution') . "\">&laquo;</a>";

        $classPreviousPage = "";
        if ($tableOptions['paged'] == 1) {
            $classPreviousPage = " disabled";
            $prevurl = $url;
        } else {
            $prev = $tableOptions['paged'] - 1;
            $prevurl = $url . "&paged=" . $prev;
        }
        echo "<a href=\"" . esc_url($prevurl) . "\" class=\"prev-page" . $classPreviousPage . "\" title=\"" . __('Go to previous page', '404-solution') . "\">&lsaquo;</a>";
        echo " ";
        echo __('Page', '404-solution') . " " . $tableOptions['paged'] . " " . __('of', '404-solution') . " " . esc_html($total_pages);
        echo " ";

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
        echo "<a href=\"" . esc_url($nexturl) . "\" class=\"next-page" . $classNextPage . "\" title=\"" . __('Go to next page', '404-solution') . "\">&rsaquo;</a>";

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
        echo "<a href=\"" . esc_url($lasturl) . "\" class=\"last-page" . $classLastPage . "\" title=\"" . __('Go to last page', '404-solution') . "\">&raquo;</a>";
        echo "</span>";
        echo "</div>";
    }    
    
    /** Output the filters for a tab.
     * @global type $abj404dao
     * @param type $sub
     * @param type $tableOptions
     */
    function echoTabFilters($sub, $tableOptions) {
        global $abj404dao;
        global $abj404logic;

        if (count($tableOptions) == 0) {
            $tableOptions = $abj404logic->getTableOptions();
        }
        echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\">";
        echo "<ul class=\"subsubsub\">";

        $url = "?page=abj404_solution";
        if ($sub == "captured") {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == "redirects") {
            $url .= "&subpage=abj404_redirects";
        } else {
            ABJ_404_Solution_Functions::errorMessage("Unexpected sub page: " + $sub);
        }

        $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
        $url .= "&order=" . sanitize_text_field($tableOptions['order']);

        if ($sub == "redirects") {
            $types = array(ABJ404_MANUAL, ABJ404_AUTO);
        } else {
            $types = array(ABJ404_CAPTURED, ABJ404_IGNORED);
        }

        $class = "";
        if ($tableOptions['filter'] == 0) {
            $class = " class=\"current\"";
        }

        if ($sub != "captured") {
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

            if ($type == ABJ404_MANUAL) {
                $title = "Manual Redirects";
            } else if ($type == ABJ404_AUTO) {
                $title = "Automatic Redirects";
            } else if ($type == ABJ404_CAPTURED) {
                $title = "Captured URL's";
            } else if ($type == ABJ404_IGNORED) {
                $title = "Ignored 404's";
            } else {
                ABJ_404_Solution_Functions::errorMessage("Unrecognized redirect type: " . esc_html($type));
            }

            echo "<li>";
            if ($sub != "captured" || $type != ABJ404_CAPTURED) {
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
