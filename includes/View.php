<?php

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

class ABJ_404_Solution_View {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_View();
		}
		
		return self::$instance;
	}
	
	/** Get the text to notify the user when some URLs have been captured and need attention. 
     * @param int $captured the number of captured URLs
     * @return string html
     */
    function getDashboardNotificationCaptured($captured) {
    	$f = ABJ_404_Solution_Functions::getInstance();
    	$capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.', 
                'There are <a>%s captured 404 URLs</a> to be processed.', 
                $captured, '404-solution'), $captured);
        $capturedMessage = $f->str_replace("<a>", 
                "<a href=\"options-general.php?page=" . ABJ404_PP . "&subpage=abj404_captured\" >", 
                $capturedMessage);
        $capturedMessage = $f->str_replace("</a>", "</a>", $capturedMessage);

        return '<div class="notice notice-info"><p><strong>' . PLUGIN_NAME . 
                ":</strong> " . $capturedMessage . "</p></div>";
    }

    /** Do an action like trash/delete/ignore/edit and display a page like stats/logs/redirects/options.
     * @global type $abj404view
     * @global type $abj404logic
     */
    static function handleMainAdminPageActionAndDisplay() {
        global $abj404view;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        try {
            $action = $abj404dao->getPostOrGetSanitize('action');
            
            if (!is_admin() || !$abj404logic->userIsPluginAdmin()) { 
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
            $message .= $abj404logic->handleLaterAction();
            $message .= $abj404logic->handleActionEdit($sub, $action);
            $message .= $abj404logic->handleActionImportRedirects();
            $message .= $abj404logic->handleActionChangeItemsPerRow();
            $message .= $abj404logic->handleActionImportFile();
            
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
     * @param string $sub
     * @param string $message
     */
    function echoChosenAdminTab($action, $sub, $message) {
        global $abj404view;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        // Deal With Page Tabs
        if ($sub == "") {
            $sub = $f->strtolower($abj404dao->getPostOrGetSanitize('subpage'));
        }
        if ($sub == "") {
            $sub = 'abj404_redirects';
            $abj404logging->debugMessage('No tab selected. Displaying the "redirects" tab.');
        }
        
        $abj404logging->debugMessage("Displaying sub page: " . esc_html($sub == '' ? '(none)' : $sub));
        
        $abj404view->outputAdminHeaderTabs($sub, $message);
        
        if (($action == 'editRedirect') || ($sub == 'abj404_edit')) {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == 'abj404_redirects') {
            $abj404view->echoAdminRedirectsPage();
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
        $f = ABJ_404_Solution_Functions::getInstance();
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminFooter.html");
        $html = $f->str_replace('{JAPANESE_FLASHCARDS_URL}', ABJ404_FC_URL, $html);
        
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        echo $html;
    }

    /** Output the tabs at the top of the plugin page.
     * @param string $sub
     * @param string $message
     */
    function outputAdminHeaderTabs($sub = 'list', $message = '') {
        $f = ABJ_404_Solution_Functions::getInstance();
        ABJ_404_Solution_WPNotices::echoAdminNotices();
        
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
        echo "<div class=\"wrap\" style='z-index: 1;position: relative;'>";
        if ($sub == "abj404_options") {
            echo "\n<div id=\"icon-options-general\" class=\"icon32\"></div>";
        } else {
            echo "\n<div id=\"icon-tools\" class=\"icon32\"></div>";
        }
        echo "\n<h2>" . PLUGIN_NAME . esc_html($header) . "</h2>";
        if ($message != "") {
            $allowed_tags = array(
                'br' => array(),
                'em' => array(),
                'strong' => array(),
            );
            
            if (($f->strlen($message) >= 6) && ($f->substr($f->strtolower($message), 0, 6) == 'error:')) {
                $cssClasses = 'notice notice-error';
            } else {
                $cssClasses = 'notice notice-success';
            }
            
            echo '<div class="' . $cssClasses . '"><p>' . wp_kses($message, $allowed_tags) . "</p></div>\n";
        }

        $class = "";
        if ($sub == 'abj404_redirects') {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_redirects\" title=\"" . __('Page Redirects', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Page Redirects', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_captured') {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_captured\" title=\"" . __('Captured 404 URLs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Captured 404 URLs', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_logs') {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_logs\" title=\"" . __('Redirect & Capture Logs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Logs', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_stats') {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_stats\" title=\"" . __('Stats', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Stats', '404-solution') . "</a>";

        $class = "";
        if ($sub == 'abj404_tools') {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_tools\" title=\"" . __('Tools', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Tools', '404-solution') . "</a>";

        $class = "";
        if ($sub == "abj404_options") {
            $class = "nav-tab-active";
        }
        echo "\n<a href=\"?page=" . ABJ404_PP . "&subpage=abj404_options\" title=\"Options\" class=\"nav-tab " . $class . "\">" . __('Options', '404-solution') . "</a>";

        echo "<hr style=\"border: 0px; border-bottom: 1px solid #DFDFDF; margin-top: 0px; margin-bottom: 0px; \">";
    }
    
    /** This outputs a box with a title and some content in it. 
     * It's used on the Stats, Options and Tools page (for example).
     * @param int $id
     * @param string $title
     * @param string $content
     */
    function echoPostBox($id, $title, $content) {
        echo "<div id=\"" . esc_attr($id) . "\" class=\"postbox\">";
        echo "<h3 class=\"\" ><span>" . esc_html($title) . "</span></h3>";
        echo "<div class=\"inside\">" . $content /* Can't escape here, as contains forms */ . "</div>";
        echo "</div>";
    }

    /** Output the stats page.
     * @global type $wpdb
     * @global type $abj404dao
     */
    function outputAdminStatsPage() {
        global $wpdb;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
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

        $query = "select count(id) from $redirects where disabled = 0 and status in (%d, %d)"; // . ABJ404_STATUS_IGNORED;
        $ignored = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        $query = "select count(id) from $redirects where disabled = 1 and (status in (%d, %d, %d) )";
        $trashed = $abj404dao->getStatsCount($query, array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

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
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        if ($abj404logic->userIsPluginAdmin()) {
        	$filesToEcho = array($abj404logging->getDebugFilePath(), 
        			$abj404logging->getDebugFilePathOld());
        	for ($i = 0; $i < count($filesToEcho); $i++) {
        		$currentFile = $filesToEcho[$i];
        		echo "<div style=\"clear: both;\">";
        		echo "<BR/>Contents of: " . $currentFile . ": <BR/><BR/>";
        		// read the file and replace new lines with <BR/>.
        		$this->echoFileContents($currentFile);
        		echo "</div>";
        	}
            
        } else {
        	echo "Non-admin request to view debug file.";
        	$current_user = wp_get_current_user();
        	$userInfo = "Login: " . $current_user->user_login . ", display name: " . 
         		$current_user->display_name . ", Email: " . $current_user->user_email . 
         		", UserID: " . $current_user->ID;
            $abj404logging->infoMessage("Non-admin request to view debug file. User info: " .
            	$userInfo);
        }
    }
    
    function echoFileContents($fileName) {
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	
    	if (file_exists($fileName)) {
    		$linesRead = 0;
    		$handle = null;
    		try {
    			if ($handle = fopen($abj404logging->getDebugFilePath(), "r")) {
    				// read the file one line at a time.
    				while (($line = fgets($handle)) !== false) {
    					$linesRead++;
    					echo nl2br(esc_html($line));
    					
    					if ($linesRead > 1000000) {
    						echo "<BR/><BR/>Read " . $linesRead . " lines. Download debug file to see more.";
    						break;
    					}
    				}
    			} else {
    				$this->errorMessage("Error opening debug file.");
    			}
    			
    		} catch (Exception $e) {
    			$this->errorMessage("Error while reading debug file.", $e);
    		}
    		
    		if ($handle != null) {
    			fclose($handle);
    		}
    		
    	} else {
    		echo nl2br(__('(The log file does not exist.)', '404-solution'));
    	}
    }

    /** Display the tools page.
     * @global type $abj404view
     */
    function echoAdminToolsPage() {
        global $abj404view;
        $f = ABJ_404_Solution_Functions::getInstance();

        // ------------------------------------
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_exportRedirects");
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsExportForm.html");
        // do special replacements
        $html = $f->str_replace('{toolsExportRedirectsLink}', $link, $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-exportRedirects", __('Export', '404-solution'), $html);
        // ------------------------------------
        
        // ------------------------------------
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", 
            "abj404_importRedirectsFile");
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsImportForm.html");
        // do special replacements
        $html = $f->str_replace('{toolsImportRedirectsLink}', $link, $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-importRedirects", __('Import', '404-solution'), $html);
        // ------------------------------------
        
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsPurgeForm.html");
        // do special replacements
        $html = $f->str_replace('{toolsPurgeFormActionLink}', $link, $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-purgeRedirects", __('Purge Options', '404-solution'), $html);
        echo "</div></div></div>";
        
        // ------------------------------------
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        $link .= '&manually_fired=true';
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsEtcForm.html");
        // do special replacements
        $html = $f->str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";
        $abj404view->echoPostBox("abj404-purgeRedirects", __('Etcetera', '404-solution'), $html);
        echo "</div></div></div>";
    }
    
    function echoAdminOptionsPage() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        global $abj404view;
        global $abj404viewSuggestions;
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $options = $abj404logic->getOptions();

        // if the current URL does not match the chosen menuLocation then redirect to the correct URL
        $urlParts = parse_url(urldecode($_SERVER['REQUEST_URI']));
        $currentURL = $urlParts['path'];
        if (array_key_exists('menuLocation', $options) && isset($options['menuLocation']) && 
                $options['menuLocation'] == 'settingsLevel') {
            if ($f->strpos($currentURL, 'options-general.php') != false) {
                // the option changed and we're at the wrong URL now, so we redirect to the correct one.
                $abj404logic->forceRedirect(admin_url() . "admin.php?page=" . 
                        ABJ404_PP . '&subpage=abj404_options');
            }
        } else if ($f->strpos($currentURL, 'admin.php') != false) {
            // if the current URL has admin.php then the URLs don't match and we need to reload.
            $abj404logic->forceRedirect(admin_url() . "options-general.php?page=" . 
                    ABJ404_PP . '&subpage=abj404_options');
        }

        //General Options
        echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
        echo "<div class=\"metabox-holder\">";
        echo " <div class=\"meta-box-sortables\">";

        $formBeginning = '<form method="POST" id="admin-options-page" ' . 
        	'name="admin-options-page" action="#" data-url="{data-url}">' . "\n";
        $formBeginning .= '<input type="hidden" name="action" id="action" value="updateOptions">' . "\n";
        $formBeginning .= '<input type="hidden" name="nonce" id="nonce" value="' . 
        	wp_create_nonce('abj404UpdateOptions') . '">' . "\n";
        $formBeginning = $f->str_replace('{data-url}', 
        	"admin-ajax.php?action=updateOptions", $formBeginning);
        echo $formBeginning;

        $contentAutomaticRedirects = $abj404view->getAdminOptionsPageAutoRedirects($options);
        $abj404view->echoPostBox("abj404-autooptions", __('Automatic Redirects', '404-solution'), $contentAutomaticRedirects);

        $contentGeneralSettings = $abj404view->getAdminOptionsPageGeneralSettings($options);
        $abj404view->echoPostBox("abj404-generaloptions", __('General Settings', '404-solution'), $contentGeneralSettings);

        $contentAdvancedSettings = $abj404view->getAdminOptionsPageAdvancedSettings($options);
        $abj404view->echoPostBox("abj404-advancedoptions", __('Advanced Settings (Etc)', '404-solution'), $contentAdvancedSettings);

        $content404PageSuggestions = $abj404viewSuggestions->getAdminOptionsPage404Suggestions($options);
        $abj404view->echoPostBox("abj404-suggestoptions", __('404 Page Suggestions', '404-solution'), $content404PageSuggestions);

        echo "<input type=\"submit\" name=\"abj404-optionssub\" id=\"abj404-optionssub\" " .
            "value=\"Save Settings\" class=\"button-primary\">";
        echo "</form><!-- end in admin-options-page -->";

        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    
    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function echoAdminEditRedirectPage() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $options = $abj404logic->getOptions();
        
        // this line assures that text will appear below the page tabs at the top.
        echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span> <BR/>";
        
        echo "<h3>" . __('Redirect Details', '404-solution') . "</h3>";

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_edit", "abj404editRedirect");

        echo '<form method="POST" name="admin-edit-redirect" action="' . esc_attr($link) . '">';
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";

        $recnum = null;
        if (array_key_exists('id', $_GET) && isset($_GET['id']) && $f->regexMatch('[0-9]+', $_GET['id'])) {
            $abj404logging->debugMessage("Edit redirect page. GET ID: " . 
                    wp_kses_post(json_encode($_GET['id'])));
            $recnum = absint($_GET['id']);
            
        } else if (array_key_exists('id', $_POST) && isset($_POST['id']) && $f->regexMatch('[0-9]+', $_POST['id'])) {
            $abj404logging->debugMessage("Edit redirect page. POST ID: " . 
                    wp_kses_post(json_encode($_POST['id'])));
            $recnum = absint($_POST['id']);
            
        } else if ($abj404dao->getPostOrGetSanitize('idnum') !== null) {
            $recnums_multiple = array_map('absint', $abj404dao->getPostOrGetSanitize('idnum'));
            $abj404logging->debugMessage("Edit redirect page. ids_multiple: " . 
                    wp_kses_post(json_encode($recnums_multiple)));

        } else {
            echo __('Error: No ID(s) found for edit request.', '404-solution');
            $abj404logging->debugMessage("No ID(s) found in GET or POST data for edit request.");
            return;
        }
        
        // Decide whether we're editing one or multiple redirects.
        // If we're editing only one then set the ID to that one value.
        if ($recnum != null) {
            $recnumAsArray = array();
            $recnumAsArray[] = $recnum;
            $redirects_multiple = $abj404dao->getRedirectsByIDs($recnumAsArray);
            
            if (empty($redirects_multiple)) {
                echo "Error: Invalid ID Number! (id: " . esc_html($recnum) . ")";
                $abj404logging->errorMessage("Error: Invalid ID Number! (id: " . esc_html($recnum) . ")");
                return;
            }
            
            $redirect = reset($redirects_multiple);
            $isRegexChecked = '';
            if ($redirect['status'] == ABJ404_STATUS_REGEX) {
                $isRegexChecked = ' checked ';
            }

            echo "<input type=\"hidden\" name=\"id\" value=\"" . esc_attr($redirect['id']) . "\">";
            echo "<strong><label for=\"url\">" . __('URL', '404-solution') . 
                    ":</label></strong> ";
            echo "<input id=\"url\" style=\"width: 45%;\" type=\"text\" name=\"url\" value=\"" . 
                    esc_attr($redirect['url']) . "\" required> (" . __('Required', '404-solution') . ")<BR/>\n\n";
            echo "\n\n" . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" name="is_regex_url" ';
            echo 'id="is_regex_url" value="1" ' . $isRegexChecked . '>' . "\n";
            $html = '<label for="is_regex_url">{Treat this URL as a regular expression}</label> ' . "\n";
            $html .= '<a id="showInfoLink" onclick="showHideRegexExplanation()" ';
            
            $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/showHideRegexExplanation.html");
            $html = $f->doNormalReplacements($html);
            echo $html;

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
                echo "\n<li>" . esc_html($redirect['url']) . "</li>\n";
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
        
        $final = "";
        $pageIDAndType = "";
        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $final = $redirect['final_dest'];
            $pageIDAndType = ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL;
            
        } else if ($redirect['final_dest'] != 0) {
            // if a destination has been specified then let's fill it in.
            $pageIDAndType = $redirect['final_dest'] . "|" . $redirect['type'];
            
        } else if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
        	$pageIDAndType = ABJ404_TYPE_404_DISPLAYED . "|" . ABJ404_TYPE_404_DISPLAYED;
        }
        
        if ($redirect['code'] == "") {
            $codeSelected = $options['default_redirect'];
        } else {
            $codeSelected = $redirect['code'];
        }
        
        $pageTitle = $abj404logic->getPageTitleFromIDAndType($pageIDAndType, $redirect['final_dest']);        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
                "/html/addManualRedirectPageSearchDropdown.html");
        $html = $f->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}', 
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $f->str_replace('{pageIDAndType}', $pageIDAndType, $html);
        $html = $f->str_replace('{data-url}', 
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true", $html);
        $html = $f->doNormalReplacements($html);
        echo $html;
        
        $this->echoEditRedirect($final, $codeSelected, __('Update Redirect', '404-solution'));
        
        echo "</form><!-- end admin-edit-redirect -->";
    }
    
    function echoRedirectDestinationOptionsOthers($dest, $rows) {
        $content = array();
        
        $rowCounter = 0;
        $currentPostType = '';
        
        foreach ($rows as $row) {
            $rowCounter++;
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'Before row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
            
            if ($row->post_type != $currentPostType) {
                if ($currentPostType != '') {
                    $content[] = "\n" . '</optgroup>' . "\n";
                }
                
                $content[] = "\n" . '<optgroup label="' . __(ucwords($row->post_type), '404-solution') . '">' . "\n";
                $currentPostType = $row->post_type;
            }

            // this is split in this ridiculous way to help me figure out how to resolve a memory issue.
            // (https://wordpress.org/support/topic/options-tab-is-not-loading/)
            $content[] = "\n <option value=\"";
            $content[] = esc_attr($thisval);
            $content[] = "\"";
            $content[] = $selected;
            $content[] = ">";
            
            // insert some spaces for child pages.
            for ($i = 0; $i < $row->depth; $i++) {
                $content[] = "&nbsp;&nbsp;&nbsp;";
            }
            
            $content[] = __(ucwords($row->post_type), '404-solution');
            $content[] = ": ";
            $content[] = esc_html($theTitle);
            $content[] = "</option>";
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'After row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
        }
        
        $content[] = "\n" . '</optgroup>' . "\n";
        

        $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after building redirect destination page list.';
        
        return implode('', $content);
    }

    function echoRedirectDestinationOptionsCatsTags($dest) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $content = "";
        $content .= "\n" . '<optgroup label="Categories">' . "\n";
        
        $customTagsEtc = array();

        // categories ---------------------------------------------
        $cats = $abj404dao->getPublishedCategories();
        foreach ($cats as $cat) {
            $taxonomy = $cat->taxonomy;
            if ($taxonomy != 'category') {
                continue;
            }
            
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_TYPE_CAT;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        $customTagsEtc = $abj404logic->getMapOfCustomCategories($cats);

        // tags ---------------------------------------------
        $content .= "\n" . '<optgroup label="Tags">' . "\n";
        $tags = $abj404dao->getPublishedTags();
        foreach ($tags as $tag) {
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TYPE_TAG;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        
        // custom ---------------------------------------------
        foreach ($customTagsEtc as $taxonomy => $catRow) {
            $content .= "\n" . '<optgroup label="' . esc_html($taxonomy) . '">' . "\n";
            
            foreach ($catRow as $cat) {
                $id = $cat->term_id;
                $theTitle = $cat->name;
                $thisval = $id . "|" . ABJ404_TYPE_CAT;

                $selected = "";
                if ($thisval == $dest) {
                    $selected = " selected";
                }
                $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Custom', '404-solution') . ": " . $theTitle . "</option>";
            }
            
            $content .= "\n" . '</optgroup>' . "\n";
        }
        
        return $content;
    }
    
    /** 
     * @global type $abj404dao
     */
    function echoAdminCapturedURLsPage() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $sub = 'abj404_captured';
        $f = ABJ_404_Solution_Functions::getInstance();

        $tableOptions = $abj404logic->getTableOptions($sub);

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);
        
        echo $this->getTabFilters($sub, $tableOptions);

        echo "<div class=\"tablenav admin-captured-urls-page-top\">";
        echo $this->getPaginationLinks($sub);

        // bulk operations dropdown -------------
        $bulkOptions = array();
        if ($tableOptions['filter'] != ABJ404_STATUS_CAPTURED) {
            $bulkOptions[] = '<option value="bulkcaptured">{Mark as Captured}</option>';
        }
        if ($tableOptions['filter'] != ABJ404_STATUS_IGNORED) {
            $bulkOptions[] = '<option value="bulkignore">{Mark as Ignored}</option>';
        }
        if ($tableOptions['filter'] != ABJ404_STATUS_LATER) {
            $bulkOptions[] = '<option value="bulklater">{Organize Later}</option>';
        }
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            $bulkOptions[] = '<option value="bulktrash">{Move to Trash}</option>';
        }
        $bulkOptions[] = '<option value="editRedirect">{Create a Redirect}</option>';
        $allBulkOptions = implode("\n", $bulkOptions);
        
        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);
        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/bulkOperationsDropdown.html");
        $html = $f->str_replace('{action_url}', $url, $html);
        $html = $f->str_replace('{bulkOptions}', $allBulkOptions, $html);
        $html = $f->doNormalReplacements($html);
        echo $html;

        // empty trash button -------------
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $eturl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ABJ404_TRASH_FILTER . 
                    "&subpage=abj404_captured";
            $eturl = wp_nonce_url($eturl, 'abj404_bulkProcess');

            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/emptyTrashButton.html");
            $html = $f->str_replace('{action_url}', $eturl, $html);
            $html = $f->str_replace('{action_value}', 'emptyCapturedTrash', $html);
            $html = $f->doNormalReplacements($html);
            echo $html;
        }
        // ----------

        echo "</div>";


        echo $this->getCapturedURLSPageTable($sub);

        echo "<div class=\"tablenav admin-captured-urls-page-bottom\">";
        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/bulkOperationsDropdown2.html");
        $html = $f->str_replace('{action_url}', $url, $html);
        $html = $f->str_replace('{bulkOptions}', $allBulkOptions, $html);
        $html = $f->doNormalReplacements($html);
        echo $html;
        
        echo $this->getPaginationLinks($sub, false);
        
        echo "</div></form><!-- page-form big outer form could end here -->";
    }
    
    function getCapturedURLSPageTable($sub) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $tableOptions = $abj404logic->getTableOptions($sub);

        // ----------------------------------------------
        // these are used for a GET request so they're not translated.
        $columns = array();
        $columns['url']['title'] = __('URL', '404-solution');
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "48%";
        $columns['hits']['title'] = __('Hits', '404-solution');
        $columns['hits']['orderby'] = "logshits";
        $columns['hits']['width'] = "7%";
        $columns['hits']['title_attr'] = __('Changes may not be updated immediately when ordering by this column', '404-solution');
        $columns['timestamp']['title'] = __('Created', '404-solution');
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "20%";
        $columns['last_used']['title'] = __('Last Used', '404-solution');
        $columns['last_used']['orderby'] = "last_used";
        $columns['last_used']['width'] = "20%";
        $columns['last_used']['title_attr'] = __('Changes may not be updated immediately when ordering by this column', '404-solution');

        $html = "<table class=\"wp-list-table widefat fixed\">";
        $html .= "<thead>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</thead>";
        $html .= "<tfoot>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</tfoot>";
        $html .= "<tbody id=\"the-list\">";
        
        $rows = $abj404dao->getRedirectsForView($sub, $tableOptions);
        $displayed = 0;
        $y = 1;
        foreach ($rows as $row) {
            $displayed++;

            $hits = $row['logshits'];
            
            $last_used = $row['last_used'];
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . $row['id'];
            $logslink = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . $row['logsid'];
            $trashlink = "?page=" . ABJ404_PP . "&&subpage=abj404_captured&id=" . $row['id'] .
            	"&subpage=" . $sub;
            $ajaxTrashLink = "admin-ajax.php?action=trashLink" . "&id=" . absint($row['id']) . 
            	"&subpage=" . $sub;
            $ignorelink = "?page=" . ABJ404_PP . "&&subpage=abj404_captured&id=" . $row['id'] .
            	"&subpage=" . $sub;
            $laterlink = "?page=" . ABJ404_PP . "&&subpage=abj404_captured&id=" . $row['id'] .
            	"&subpage=" . $sub;
            $deletelink = "?page=" . ABJ404_PP . "&subpage=abj404_captured&remove=1&id=" . $row['id'] .
            	"&subpage=" . $sub;

            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $trashlink .= "&trash=0";
                $ajaxTrashLink .= "&trash=0";
                $trashtitle = __('Restore', '404-solution');
            } else {
                $trashlink .= "&trash=1";
                $ajaxTrashLink .= "&trash=1";
                $trashtitle = __('Trash', '404-solution');
            }

            $ignoretitle = "";
            if ($tableOptions['filter'] == ABJ404_STATUS_IGNORED) {
                $ignorelink .= "&ignore=0";
                $ignoretitle = __('Remove Ignore Status', '404-solution');
            } else {
                $ignorelink .= "&ignore=1";
                $ignoretitle = __('Ignore 404 Error', '404-solution');
            } 

            $latertitle = '?Organize Later?';
            if ($tableOptions['filter'] == ABJ404_STATUS_LATER) {
                $laterlink .= "&later=0";
                $latertitle = __('Remove Later Status', '404-solution');
            } else {
                $laterlink .= "&later=1";
                $latertitle = __('Organize Later', '404-solution');
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
                $laterlink .= "&filter=" . $tableOptions['filter'];
            }

            $trashlink = wp_nonce_url($trashlink, "abj404_trashRedirect");
            $ajaxTrashLink = wp_nonce_url($ajaxTrashLink, "abj404_ajaxTrash");

            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $deletelink = wp_nonce_url($deletelink, "abj404_removeRedirect");
            }

            $ignorelink = wp_nonce_url($ignorelink, "abj404_ignore404");
            $laterlink = wp_nonce_url($laterlink, "abj404_organizeLater");

            $class = "";
            if ($y == 0) {
                $class = "alternate";
                $y++;
            } else {
                $y = 0;
                $class = "normal-non-alternate";
            }
            
            // ------------------------
            $rowActions = array();
            if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
                $rowActions[] = '<span class="edit"><a href="{editLink}" title="{Edit Redirect Details}">{Edit}</a></span>';
            }
            $rowActions[] = '<span class="trash"><a href="#" class="ajax-trash-link" data-url="{ajaxTrashLink}" title="trashtitle}">{trashtitle}</a></span>';
            if ($row['logsid'] > 0) {
                $rowActions[] = '<span class="view"><a href="{logsLink}" title="{View Redirect Logs}">{View Logs}</a></span>';
            } else {
                $rowActions[] = '<span class="view">{(No logs)}</a></span>';
            }
            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $rowActions[] = '<span class="delete"><a href="{deleteLink}" title="{Delete Redirect Permanently}">{Delete Permanently}</a></span>';
            } else {
                $rowActions[] = '<span class="ignore"><a href="{ignoreLink}" title="{ignoreTitle}">{ignoreTitle}</a></span>';
                $rowActions[] = '<span class="ignore"><a href="{laterLink}" title="{laterTitle}">{laterTitle}</a></span>';
            }
            $allRowActions = implode("\n | ", $rowActions);
            
            $tempHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowCapturedURLs.html");
            $tempHtml = $f->str_replace('{rowActions}', $allRowActions, $tempHtml);
            $tempHtml = $f->str_replace('{rowid}', $row['id'], $tempHtml);
            $tempHtml = $f->str_replace('{rowClass}', $class, $tempHtml);
            $tempHtml = $f->str_replace('{editLink}', $editlink, $tempHtml);
            $tempHtml = $f->str_replace('{logsLink}', $logslink, $tempHtml);
            $tempHtml = $f->str_replace('{trashLink}', $trashlink, $tempHtml);
            $tempHtml = $f->str_replace('{ajaxTrashLink}', $ajaxTrashLink, $tempHtml);
            $tempHtml = $f->str_replace('{trashtitle}', $trashtitle, $tempHtml);
            $tempHtml = $f->str_replace('{ignoreLink}', $ignorelink, $tempHtml);
            $tempHtml = $f->str_replace('{ignoreTitle}', $ignoretitle, $tempHtml);
            $tempHtml = $f->str_replace('{laterLink}', $laterlink, $tempHtml);
            $tempHtml = $f->str_replace('{laterTitle}', $latertitle, $tempHtml);
            $tempHtml = $f->str_replace('{deleteLink}', $deletelink, $tempHtml);
            $tempHtml = $f->str_replace('{url}', esc_html($row['url']), $tempHtml);
            $tempHtml = $f->str_replace('{hits}', esc_html($hits), $tempHtml);
            $tempHtml = $f->str_replace('{created_date}', 
                    esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))), $tempHtml);
            $tempHtml = $f->str_replace('{last_used_date}', esc_html($last), $tempHtml);
            
            $tempHtml = $f->doNormalReplacements($tempHtml);
            $html .= $tempHtml;
        }
        
        if ($displayed == 0) {
            $html .= "<tr>";
            $html .= "<td></td>";
            $html .= "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . __('No Captured 404 Records To Display', '404-solution') . "</td>";
            $html .= "<td></td>";
            $html .= "</tr>";
        }
        
        $html .= "</tbody>";
        $html .= "</table>";
        
        return $html;
    }

    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function echoAdminRedirectsPage() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $sub = 'abj404_redirects';
        
        $tableOptions = $abj404logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $abj404logic->sanitizePostData($tableOptions);

        echo $this->getTabFilters($sub, $tableOptions);

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        echo "<div class=\"tablenav admin-redirects-page-top\">";
        
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            $htmlTop = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/paginationLinksTop.html");
            echo $f->doNormalReplacements($htmlTop);
        }
        
        echo $this->getPaginationLinks($sub);

        
        // bulk operations dropdown -------------
        $bulkOptions = array();
        if ($tableOptions['filter'] != ABJ404_STATUS_AUTO) {
            $bulkOptions[] = '<option value="editRedirect">{Edit Redirects}</option>';
        }
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            $bulkOptions[] = '<option value="bulktrash">{Move to Trash}</option>';
        }
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $bulkOptions[] = '<option value="bulk_trash_restore">{Restore Redirects}</option>';
            $bulkOptions[] = '<option value="bulk_trash_delete_permanently">{Delete Permanently}</option>';
        }
        $allBulkOptions = implode("\n", $bulkOptions);

        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);
        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/bulkOperationsDropdown.html");
        $html = $f->str_replace('{action_url}', $url, $html);
        $html = $f->str_replace('{bulkOptions}', $allBulkOptions, $html);
        $html = $f->doNormalReplacements($html);
        echo $html;
        
        // ------------------ empty trash button
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            echo "<div class=\"alignleft actions vw\">";
            $eturl = "?page=" . ABJ404_PP . "&filter=" . ABJ404_TRASH_FILTER . "&subpage=" . $sub;
            $eturl = wp_nonce_url($eturl, "abj404_bulkProcess");

            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/emptyTrashButton.html");
            $html = $f->str_replace('{action_url}', $eturl, $html);
            $html = $f->str_replace('{action_value}', 'emptyRedirectTrash', $html);
            $html = $f->doNormalReplacements($html);
            echo $html;
            
            echo "</div>";
        }
        echo "</div>";

        echo $this->getAdminRedirectsPageTable($sub);

        echo "<div class=\"tablenav admin-redirects-page-bottom\">";

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/bulkOperationsDropdown2.html");
        $html = $f->str_replace('{action_url}', $url, $html);
        $html = $f->str_replace('{bulkOptions}', $allBulkOptions, $html);
        $html = $f->doNormalReplacements($html);
        echo $html;
        
        echo $this->getPaginationLinks($sub, false);
        
        echo "</div></form><!-- page-form big outer form could end here -->";

        // don't show the "add manual redirect" form on the trash page.
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            $this->echoAddManualRedirect($tableOptions);
        }
    }
    
    function getBulkOperationsFormURL($sub, $tableOptions) {
        $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }
        $url = wp_nonce_url($url, 'abj404_bulkProcess');
        return $url;
    }
    
    function getAdminRedirectsPageTable($sub) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $tableOptions = $abj404logic->getTableOptions($sub);
        
        // these are used for a GET request so they're not translated.
        $columns = array();
        $columns['url']['title'] = __('URL', '404-solution');
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "25%";
        $columns['status']['title'] = __('Status', '404-solution');
        $columns['status']['orderby'] = "status";
        $columns['status']['width'] = "5%";
        $columns['type']['title'] = __('Type', '404-solution');
        $columns['type']['orderby'] = "type";
        $columns['type']['width'] = "10%";
        $columns['dest']['title'] = __('Destination', '404-solution');;
        $columns['dest']['orderby'] = "final_dest";
        $columns['dest']['width'] = "22%";
        $columns['code']['title'] = __('Redirect', '404-solution');
        $columns['code']['orderby'] = "code";
        $columns['code']['width'] = "5%";
        $columns['hits']['title'] = __('Hits', '404-solution');
        $columns['hits']['orderby'] = "logshits";
        $columns['hits']['width'] = "7%";
        $columns['hits']['title_attr'] = __('Changes may not be updated immediately when ordering by this column', '404-solution');
        $columns['timestamp']['title'] = __('Created', '404-solution');;
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "10%";
        $columns['last_used']['title'] = __('Last Used', '404-solution');;
        $columns['last_used']['orderby'] = "last_used";
        $columns['last_used']['width'] = "10%";
        $columns['last_used']['title_attr'] = __('Changes may not be updated immediately when ordering by this column', '404-solution');

        $html = "<table class=\"wp-list-table widefat fixed\">  <thead>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</thead>  <tfoot>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</tfoot>  <tbody id=\"the-list\">";
        
        $rows = $abj404dao->getRedirectsForView($sub, $tableOptions);
        $displayed = 0;
        $y = 1;
        foreach ($rows as $row) {
            $displayed++;
            $statusTitle = '';
            if ($row['status'] == ABJ404_STATUS_MANUAL) {
                $statusTitle = __('Manually created', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_AUTO) {
                $statusTitle = __('Automatically created', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_REGEX) {
                $statusTitle = __('Regular Expression (Manually Created)', '404-solution');
            } else {
                $statusTitle = __('Unknown', '404-solution');
            }

            $link = "";
            $title = __('Visit', '404-solution') . " ";
            if ($row['type'] == ABJ404_TYPE_EXTERNAL) {
                $link = $row['final_dest'];
                $title .= $row['final_dest'];
            } else if ($row['type'] == ABJ404_TYPE_CAT) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_CAT, 0);
                $link = $permalink['link'];
                $title .= __('Category:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_TAG) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_TAG, 0);
                $link = $permalink['link'];
                $title .= __('Tag:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_HOME) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_HOME, 0);
                $link = $permalink['link'];
                $title .= __('Home Page:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_POST) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_POST, 0);
                $link = $permalink['link'];
                $title .= $permalink['title'];
                
            } else if ($row['type'] == ABJ404_TYPE_404_DISPLAYED) {
            	$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_404_DISPLAYED, 0);
            	// for custom 404 page use the link
            	$link = $permalink['link'];
            	$title .= $permalink['title'];
            	
            	// for the normal 404 page just use #
            	if ($row['final_dest'] == '0') {
            	    $link = '';
            	}
            	
            } else {
                $abj404logging->errorMessage("Unexpected row type while displaying table: " . $row['type']);
            }
            
            if ($link != '') {
                $link = "href='$link'";
            }

            $hits = $row['logshits'];
            
            $last_used = $row['last_used'];
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never Used', '404-solution');
            }

            $editlink = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . absint($row['id']);
            $logslink = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . absint($row['logsid']);
            $trashlink = "?page=" . ABJ404_PP . "&id=" . absint($row['id']) .
            	"&subpage=" . $sub;
            $ajaxTrashLink = "admin-ajax.php?action=trashLink" . "&id=" . absint($row['id']) .
            	"&subpage=" . $sub;
            $deletelink = "?page=" . ABJ404_PP . "&remove=1&id=" . absint($row['id']) .
            	"&subpage=" . $sub;

            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $trashlink .= "&trash=0";
                $ajaxTrashLink .= "&trash=0";
                $trashtitle = __('Restore', '404-solution');
            } else {
                $trashlink .= "&trash=1";
                $ajaxTrashLink .= "&trash=1";
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
            $ajaxTrashLink = wp_nonce_url($ajaxTrashLink, "abj404_ajaxTrash");

            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $deleteaction = "abj404_removeRedirect";
                $deletelink = wp_nonce_url($deletelink, $deleteaction);
            }

            $class = "";
            if ($y == 0) {
                $class = "alternate";
                $y++;
            } else {
                $y = 0;
                $class = "normal-non-alternate";
            }
            // make the entire row red if the destination doesn't exist or is unpublished.
            $destinationDoesNotExistClass = '';
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationDoesNotExistClass = ' destination-does-not-exist';
                }
            }
            $class = $class . $destinationDoesNotExistClass;
            
            // -------------------------------------------
            if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
                $editlinkHTML = '<span class="edit"><a href="' . esc_url($editlink) . 
                    '" title="{Edit Redirect Details}">{Edit}</a></span> | ';
            } else {
                $editlinkHTML = '';
            }
            if ($row['logsid'] > 0) {
                $logslinkHTML = '<span class="view"><a href="{logsLink}" '
                        . 'title="{View Redirect Logs}">{View Logs}</a></span>';
            } else {
                $logslinkHTML = '<span class="view">{(No logs)}</a></span>';
            }
            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $deletePermanentlyHTML = '| <span class="delete"><a href="{deletelink}" '
                        . 'title="{Delete Redirect Permanently}">{Delete Permanently}</a></span>';
            } else {
                $deletePermanentlyHTML = '';
            }
            
            $destinationExists = '';
            $destinationDoesNotExist = 'display: none;';
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationExists = 'display: none;';
                    $destinationDoesNotExist = '';
                }
            }
            
            $htmlTemp = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowPageRedirects.html");
            $htmlTemp = $f->str_replace('{rowid}', $row['id'], $htmlTemp);
            $htmlTemp = $f->str_replace('{rowClass}', $class, $htmlTemp);
            $htmlTemp = $f->str_replace('{editLink}', $row['url'], $htmlTemp);
            $htmlTemp = $f->str_replace('{rowURL}', esc_html($row['url']), $htmlTemp);
            $htmlTemp = $f->str_replace('{editlinkHTML}', $editlinkHTML, $htmlTemp);
            $htmlTemp = $f->str_replace('{logslinkHTML}', $logslinkHTML, $htmlTemp);
            $htmlTemp = $f->str_replace('{deletePermanentlyHTML}', $deletePermanentlyHTML, $htmlTemp);
            $htmlTemp = $f->str_replace('{link}', $link, $htmlTemp);
            $htmlTemp = $f->str_replace('{title}', $title, $htmlTemp);
            $htmlTemp = $f->str_replace('{dest}', $row['dest_for_view'], $htmlTemp);
            $htmlTemp = $f->str_replace('{destination-exists}', $destinationExists, $htmlTemp);
            $htmlTemp = $f->str_replace('{destination-does-not-exist}', $destinationDoesNotExist, $htmlTemp);
            $htmlTemp = $f->str_replace('{status}', $row['status_for_view'], $htmlTemp);
            $htmlTemp = $f->str_replace('{statusTitle}', $statusTitle, $htmlTemp);
            $htmlTemp = $f->str_replace('{type}', $row['type_for_view'], $htmlTemp);
            $htmlTemp = $f->str_replace('{rowCode}', $row['code'], $htmlTemp);
            $htmlTemp = $f->str_replace('{hits}', $hits, $htmlTemp);
            $htmlTemp = $f->str_replace('{logsLink}', $logslink, $htmlTemp);
            $htmlTemp = $f->str_replace('{trashLink}', $trashlink, $htmlTemp);
            $htmlTemp = $f->str_replace('{ajaxTrashLink}', $ajaxTrashLink, $htmlTemp);
            $htmlTemp = $f->str_replace('{trashtitle}', $trashtitle, $htmlTemp);
            $htmlTemp = $f->str_replace('{deletelink}', $deletelink, $htmlTemp);
            $htmlTemp = $f->str_replace('{hits}', esc_html($hits), $htmlTemp);
            $htmlTemp = $f->str_replace('{created_date}', 
                    esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))), $htmlTemp);
            $htmlTemp = $f->str_replace('{last_used_date}', esc_html($last), $htmlTemp);
            
            $htmlTemp = $f->doNormalReplacements($htmlTemp);
            $html .= $htmlTemp;
        }
        if ($displayed == 0) {
            $html .= "<tr>\n" .
                "<td></td>" .
                "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . 
                __('No Redirect Records To Display', '404-solution') . "</td>" .
                "<td></td>" .
                "</tr>";
        }
        $html .= "</tbody>  </table>";
        
        return $html;
    }
    
    function echoAddManualRedirect($tableOptions) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        $options = $abj404logic->getOptions();
        
        $url = "?page=" . ABJ404_PP;
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        $link = wp_nonce_url($url, "abj404addRedirect");

        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";
        if (array_key_exists('url', $_POST) && isset($_POST['url']) && $_POST['url'] != '') {
            $postedURL = esc_url($_POST['url']);
        } else {
            $postedURL = $urlPlaceholder;
        }

        $selected301 = "";
        $selected302 = "";
        if ($options['default_redirect'] == '301') {
            $selected301 = " selected ";
        } else {
            $selected302 = " selected ";
        }
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectTop.html");
        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
                "/html/addManualRedirectPageSearchDropdown.html");

        $html = $f->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}', 
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $f->str_replace('{redirectPageTitle}', '', $html);
        $html = $f->str_replace('{pageIDAndType}', '', $html);
        $html = $f->str_replace('{redirectPageTitle}', '', $html);
        $html = $f->str_replace('{data-url}', 
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true", $html);

        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectBottom.html");
        $html = $f->str_replace('{addManualRedirectAction}', $link, $html);
        $html = $f->str_replace('{urlPlaceholder}', $urlPlaceholder, $html);
        $html = $f->str_replace('{postedURL}', $postedURL, $html);
        $html = $f->str_replace('{301selected}', $selected301, $html);
        $html = $f->str_replace('{302selected}', $selected302, $html);
        
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        echo $html;
    }
    
    /** This is used both to add and to edit a redirect.
     * @param string $destination
     * @param string $codeselected
     * @param string $label
     */
    function echoEditRedirect($destination, $codeselected, $label) {
        echo "\r\n<BR/><strong><label for=\"code\">" . __('Redirect Type', '404-solution') . 
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
        $content .= "\n" . '<optgroup label="' . __('Special', '404-solution') . '">' . "\n";

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

        $content .= "\n" . '</optgroup>' . "\n";
        
        return $content;
    }
    
    /** 
     * @global type $abj404dao
     * @global type $wpdb
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageAutoRedirects($options) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = "";
        $userSelectedDefault404Page = (array_key_exists('dest404page', $options) && 
                isset($options['dest404page']) ? $options['dest404page'] : null);
        $urlDestination = (array_key_exists('dest404pageURL', $options) && 
                isset($options['dest404pageURL']) ? $options['dest404pageURL'] : null);
        
        $pageMissingWarning = "";
        if ($userSelectedDefault404Page != null) {
        	$permalink = 
        		ABJ_404_Solution_Functions::permalinkInfoToArray($userSelectedDefault404Page, 0);
        	if (!in_array($permalink['status'], array('publish', 'published'))) {
        		$pageMissingWarning = __("(The specified page doesn't exist. " .
        				"Please update this setting.)", '404-solution');
        	}
        }

        $pageTitle = $abj404logic->getPageTitleFromIDAndType($userSelectedDefault404Page, $urlDestination);
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
                "/html/addManualRedirectPageSearchDropdown.html");
        $html = $f->str_replace('{redirect_to_label}', __('Redirect all unhandled 404s to', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
        		__('(An external URL will be used.)', '404-solution'), $html);
        $html = $f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', $pageMissingWarning, $html);
        
        $html = $f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $f->str_replace('{pageIDAndType}', $userSelectedDefault404Page, $html);
        $html = $f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $f->str_replace('{data-url}', 
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true", $html);
        $html = $f->doNormalReplacements($html);
        $content .= $html;
        
        // -----------------------------------------------
        
        $selectedAutoRedirects = "";
        if ($options['auto_redirects'] == '1') {
            $selectedAutoRedirects = " checked";
        }
        $content .= "<p><label for=\"auto_redirects\">" . __('Create automatic redirects', '404-solution') . ":</label> <input type=\"checkbox\" name=\"auto_redirects\" id=\"auto_redirects\" value=\"1\"" . $selectedAutoRedirects . "><BR/>";
        $content .= $spaces . __('Automatically creates redirects based on best possible suggested page.', '404-solution') . "</p>";

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

    function getAdminOptionsPageAdvancedSettings($options) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        // Only allow redirecting all requests on trusted sites because someone will break
        // their website and complain to me about it and I don't want to hear that because I have
        // other things to do besides deal with people that don't listen to warnings about things 
        // that will break their website.
        $hideRedirectAllRequests = 'true';
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, $GLOBALS['abj404_whitelist'])) {
        	$hideRedirectAllRequests = 'false';
        }
        
        $selectedDebugLogging = "";
        if (array_key_exists('debug_mode', $options) && $options['debug_mode'] == '1') {
        	$selectedDebugLogging = " checked";
        }
        $selectedRedirectAllRequests = "";
        if (array_key_exists('redirect_all_requests', $options) && $options['redirect_all_requests'] == '1') {
        	$selectedRedirectAllRequests = " checked";
        }
        $selectedLogRawIPs = '';
        if (array_key_exists('log_raw_ips', $options) && $options['log_raw_ips'] == '1') {
            $selectedLogRawIPs = " checked";
        }
        
        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = $abj404logic->getDebugLogFileLink();
        $debugExplanation = $f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $abj404logging->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $abj404logging->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        $debugFileSize = sprintf(__("Debug file size: %s KB (%s MB).", '404-solution'), 
                $kbFileSizePretty, $mbFileSizePretty);
        
        $allPostTypesTemp = $abj404dao->getAllPostTypes();
        $allPostTypes = esc_html(implode(', ', $allPostTypesTemp));
        
        // ----
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvanced.html");
        $html = $f->str_replace('{DATABASE_VERSION}', esc_html($options['DB_VERSION']), $html);
        $html = $f->str_replace('checked="debug_mode"', $selectedDebugLogging, $html);
        $html = $f->str_replace('checked="redirect_all_requests"', $selectedRedirectAllRequests, $html);
        $html = $f->str_replace('checked="log_raw_ips"', $selectedLogRawIPs, $html);
        $html = $f->str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = $f->str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);
        
        $html = $f->str_replace('{ignore_dontprocess}', 
            str_replace('\\n', "\n", wp_kses_post($options['ignore_dontprocess'])), $html);
        $html = $f->str_replace('{ignore_doprocess}', 
            str_replace('\\n', "\n", wp_kses_post($options['ignore_doprocess'])), $html);
        $html = $f->str_replace('{recognized_post_types}', 
            str_replace('\\n', "\n", wp_kses_post($options['recognized_post_types'])), $html);
        $html = $f->str_replace('{all_post_types}', $allPostTypes, $html);
        $html = $f->str_replace('{days_wait_before_major_update}', $options['days_wait_before_major_update'], $html);
        
        $html = $f->str_replace('{recognized_categories}', 
            str_replace('\\n', "\n", wp_kses_post($options['recognized_categories'])), $html);
        $html = $f->str_replace('{folders_files_ignore}', 
            str_replace('\\n', "\n", wp_kses_post($options['folders_files_ignore'])), $html);
        
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($options['plugin_admin_users']));
        if (is_array($pluginAdminUsers)) {
        	$pluginAdminUsers = implode("\n", $pluginAdminUsers);
        }
        $html = $f->str_replace('{plugin_admin_users}', wp_kses_post($pluginAdminUsers), $html);
        
        $html = $f->str_replace('{OPTION_MIN_AUTO_SCORE}', esc_attr($options['auto_score']), $html);
        $html = $f->str_replace('{OPTION_TEMPLATE_REDIRECT_PRIORITY}', esc_attr($options['template_redirect_priority']), $html);
        
        $html = $f->str_replace('{disallow-redirect-all-requests}', $hideRedirectAllRequests, $html);
        
        $html = $f->str_replace('{add-exclude-page-data-url}',
        	"admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=false&includeSpecial=false", $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
        	__('(Type a page name)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
        	__('(A page has been selected.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
        	__('(An external URL will be used.)', '404-solution'), $html);

        $html = $f->str_replace('{loaded-excluded-pages}',
        	urlencode($options['excludePages[]']), $html);
        
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        // ------------------
         
        return $html;
    }

    /** 
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageGeneralSettings($options) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $selectedDefaultRedirect301 = "";
        if ($options['default_redirect'] == '301') {
            $selectedDefaultRedirect301 = " selected";
        }
        $selectedDefaultRedirect302 = "";
        if ($options['default_redirect'] == '302') {
            $selectedDefaultRedirect302 = " selected";
        }

        $selectedCapture404 = "";
        if ($options['capture_404'] == '1') {
            $selectedCapture404 = " checked";
        }
        $selectedSendErrorLogs = "";
        if ($options['send_error_logs'] == '1') {
            $selectedSendErrorLogs = " checked";
        }

        $selectedUnderSettings = "";
        $selecteSsettingsLevel = "";
        if ($options['menuLocation'] == 'settingsLevel') {
            $selecteSsettingsLevel = " selected";
        } else {
            $selectedUnderSettings = " selected";
        }
        
        $logSizeBytes = $abj404dao->getLogDiskUsage();
        $logSizeMB = round($logSizeBytes / (1024 * 1000), 2);
        $totalLogLines = $abj404dao->getLogsCount(0);

        $timeToDisplay = $abj404dao->getEarliestLogTimestamp();
        $earliestLogDate = 'N/A';
        if ($timeToDisplay >= 0) {
            $earliestLogDate = date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
            date('A', $timeToDisplay);
        }
        
        $selectedRemoveMatches = "";
        if ($options['remove_matches'] == '1') {
            $selectedRemoveMatches = " checked";
        }
        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsGeneral.html");
        $html = $f->str_replace('{selectedSendErrorLogs}', $selectedSendErrorLogs, $html);
        $html = $f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $f->str_replace('{admin_notification}', $options['admin_notification'], $html);
        $html = $f->str_replace('{capture_deletion}', $options['capture_deletion'], $html);
        $html = $f->str_replace('{manual_deletion}', $options['manual_deletion'], $html);
        $html = $f->str_replace('{maximum_log_disk_usage}', $options['maximum_log_disk_usage'], $html);
        $html = $f->str_replace('{logCurrentSizeDiskUsage}', $logSizeMB, $html);
        $html = $f->str_replace('{logCurrentRowCount}', $totalLogLines, $html);
        $html = $f->str_replace('{earliestLogDate}', $earliestLogDate, $html);
        $html = $f->str_replace('{selectedRemoveMatches}', $selectedRemoveMatches, $html);
        $html = $f->str_replace('{selectedUnderSettings}', $selectedUnderSettings, $html);
        $html = $f->str_replace('{selecteSsettingsLevel}', $selecteSsettingsLevel, $html);
        $html = $f->str_replace('{admin_notification_email}', $options['admin_notification_email'], $html);
        $html = $f->str_replace('{default_wordpress_admin_email}', get_option('admin_email'), $html);
        $html = $f->str_replace('{PHP_VERSION}', PHP_VERSION, $html);

        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        return $html;
    }
    
    /** 
     * @global type $abj404dao
     */
    function echoAdminLogsPage() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $sub = 'abj404_logs';
        $tableOptions = $abj404logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $abj404logic->sanitizePostData($tableOptions);

        echo "<BR/><BR/><BR/>";
        echo '<form id="logs_search_form" name="admin-logs-page" method="GET" action="" '
            . 'style="clear: both; display: block;" class="clearbothdisplayblock">';
        echo '<input type="hidden" name="page" value="' . ABJ404_PP . '">';
        echo "<input type=\"hidden\" name=\"subpage\" value=\"abj404_logs\">";

        // ----------------- dropdown search box. begin.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
                "/html/viewLogsForSearchBox.html");
        
        $redirectPageTitle = $abj404dao->getPostOrGetSanitize('redirect_to_data_field_title');
        $pageIDAndType = $abj404dao->getPostOrGetSanitize('redirect_to_data_field_id');
        
        $html = $f->str_replace('{redirect_to_label}', __('View logs for', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Begin typing a URL)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}', 
                __('(Please choose from the dropdown list instead of typing your own URL.)', '404-solution'), $html);
        $html = $f->str_replace('{pageIDAndType}', $pageIDAndType, $html);
        $html = $f->str_replace('{redirectPageTitle}', $redirectPageTitle, $html);
        $html = $f->str_replace('{data-url}', "admin-ajax.php?action=echoViewLogsFor", $html);
        $html = $f->doNormalReplacements($html);
        echo $html;
        // ----------------- dropdown search box. end.

        echo "</form><!-- end admin-logs-page -->";


        echo "<div class=\"tablenav admin-logs-page-top\">";
        echo $this->getPaginationLinks($sub);
        echo "</div>";

        echo $this->getAdminLogsPageTable($sub);

        echo "<div class=\"tablenav admin-logs-page-bottom\">";
        echo $this->getPaginationLinks($sub, false);
        echo "</div>";
    }
    
    function getAdminLogsPageTable($sub) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $tableOptions = $abj404logic->getTableOptions($sub);
        
        $columns = array();
        $columns['url']['title'] = __('URL', '404-solution');
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "25%";
        $columns['host']['title'] = __('IP Address', '404-solution');
        $columns['host']['orderby'] = "remote_host";
        $columns['host']['width'] = "12%";
        $columns['refer']['title'] = __('Referrer', '404-solution');
        $columns['refer']['orderby'] = "referrer";
        $columns['refer']['width'] = "25%";
        $columns['dest']['title'] = __('Action Taken', '404-solution');
        $columns['dest']['orderby'] = "action";
        $columns['dest']['width'] = "25%";
        $columns['timestamp']['title'] = __('Date', '404-solution');
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "15%";
        $columns['username']['title'] = __('User', '404-solution');
        $columns['username']['orderby'] = "username";
        $columns['username']['width'] = "10%";
        
        $html = "<table class=\"wp-list-table widefat fixed\">";
        $html .= "<thead>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</thead>";
        $html .= "<tfoot>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</tfoot>";
        $html .= "<tbody>";

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        $rows = $abj404dao->getLogRecords($tableOptions);
        $logRecordsDisplayed = 0;
        $y = 1;

        foreach ($rows as $row) {
            $class = "";
            if ($y == 0) {
                $class = " class=\"alternate\"";
                $y++;
            } else {
                $y = 0;
                $class = ' class="normal-non-alternate"';
            }
            $html .= "<tr" . $class . ">";
            $html .= "<td></td>";
            
            $html .= "<td>" . esc_html($row['url']);
            if ($row['url_detail'] != null && trim($row['url_detail']) != '') {
                $html .= ' (' . esc_html(trim($row['url_detail'])) . ')';
            }
            $html .= "</td>";
            
            $html .= "<td>" . esc_html($row['remote_host']) . "</td>";
            $html .= "<td>";
            if ($row['referrer'] != "") {
                $html .= "<a href=\"" . esc_url($row['referrer']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['referrer']) . "\" target=\"_blank\">" . esc_html($row['referrer']) . "</a>";
            } else {
                $html .= "&nbsp;";
            }
            $html .= "</td>";
            $html .= "<td>";
            if ($row['action'] == "404") {
                $html .= __('Displayed 404 Page', '404-solution');
            } else {
                $html .= __('Redirected to', '404-solution') . " ";
                $html .= "<a href=\"" . esc_url($row['action']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['action']) . "\" target=\"_blank\">" . esc_html($row['action']) . "</a>";
            }
            $html .= "</td>";
            $timeToDisplay = abs(intval($row['timestamp']));
            $html .= "<td>" . date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
                    date('A', $timeToDisplay) . "</td>";
            
            $html .= "<td>" . esc_html($row['username']) . "</td>";
            
            $html .= "<td></td>";
            $html .= "</tr>";
            $logRecordsDisplayed++;
        }
        $abj404logging->debugMessage($logRecordsDisplayed . " log records displayed on the page.");
        if ($logRecordsDisplayed == 0) {
            $html .= "<tr>";
            $html .= "<td></td>";
            $html .= "<td colspan=\"5\" style=\"text-align: center; font-weight: bold;\">" . __('No Results To Display', '404-solution') . "</td>";
            $html .= "<td></td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";
        
        return $html;
    }

    /** 
     * @param string $sub
     * @param array $tableOptions
     * @param array $columns
     */
    function getTableColumns($sub, $columns) {
    	$f = ABJ_404_Solution_Functions::getInstance();
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $tableOptions = $abj404logic->getTableOptions($sub);
        
        $html = "<tr>";
        
        $cbinfo = 'class="manage-column column-cb check-column" style="{cb-info-style}"';
        $cbinfoStyle = 'vertical-align: middle; padding-bottom: 6px;';
        if ($sub == 'abj404_logs') {
            $cbinfoStyle .= ' width: 0px;';
        }
        $cbinfo = $f->str_replace('{cb-info-style}', $cbinfoStyle, $cbinfo);
        
        $html .= "<th " . $cbinfo . ">";
        if ($sub != 'abj404_logs') {
            $html .= "<input type=\"checkbox\" name=\"bulkSelectorCheckbox\" onchange=\"enableDisableApplyButton();\" >";
        }
        $html .= "</th>";
        
        foreach ($columns as $column) {
            $style = "";
            if ($column['width'] != "") {
                $style = " style=\"width: " . esc_attr($column['width']) . ";\" ";
            }
            $nolink = 0;
            $sortorder = "";
            if ($tableOptions['orderby'] == $column['orderby']) {
                $thClass = " sorted";
                if ($tableOptions['order'] == "ASC") {
                    $thClass .= " asc";
                    $sortorder = "DESC";
                } else {
                    $thClass .= " desc";
                    $sortorder = "ASC";
                }
            } else {
                if ($column['orderby'] != "") {
                    $thClass = " sortable";
                    if ($column['orderby'] == "timestamp" || 
                            $column['orderby'] == "last_used" ||
                            $column['orderby'] == "logshits") {
                        $thClass .= " asc";
                        $sortorder = "DESC";
                    } else {
                        $thClass .= " desc";
                        $sortorder = "ASC";
                    }
                } else {
                    $thClass = "";
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

            $cssTooltip = '';
            $title_attr = '';
            if (array_key_exists('title_attr', $column)) {
                $title_attr = $column['title_attr'];
                $cssTooltip = '<span class="lefty-tooltiptext">' . $title_attr . '</span>' . "\n";
                $thClass .= ' lefty-tooltip';
            }
            
            $html .= "<th " . $style . " class=\"manage-column column-title" . $thClass . "\"> \n";
            $html .= $cssTooltip;
            
            if ($nolink == 1) {
                $html .= $column['title'];
            } else {
                $html .= "<a href=\"" . esc_url($url) . "\">";
                $html .= '<span class="table_header_' . $column['orderby'] . '" >' . 
                        esc_html($column['title']) . $cssTooltip ."</span>";
                $html .= "<span class=\"sorting-indicator\"></span>";
                $html .= "</a>";
            }
            $html .= "</th>";
        }
        $html .= "<th style=\"width: 1px;\"></th>";
        $html .= "</tr>";
        
        return $html;
    }

    /** 
     * @global type $abj404dao
     * @param string $sub
     * @param array $tableOptions
     */
    function getPaginationLinks($sub, $showSearchFilter = true) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $tableOptions = $abj404logic->getTableOptions($sub);

        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_logs') {
            $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
        }

        $url .= "&orderby=" . $tableOptions['orderby'];
        $url .= "&order=" . $tableOptions['order'];
        $url .= "&filter=" . $tableOptions['filter'];

        if ($sub == 'abj404_logs') {
            $num_records = $abj404dao->getLogsCount($tableOptions['logsid']);
        } else {
            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $num_records = $abj404dao->getRedirectsForViewCount($sub, $tableOptions);
            } else {
                $num_records = $abj404dao->getRedirectsForViewCount($sub, $tableOptions);
            }
        }

        $total_pages = ceil($num_records / $tableOptions['perpage']);
        if ($total_pages == 0) {
            $total_pages = 1;
        }

        $firsturl = $url;

        if ($tableOptions['paged'] == 1) {
            $prevurl = $url;
        } else {
            $prev = $tableOptions['paged'] - 1;
            $prevurl = $url . "&paged=" . $prev;
        }

        if ($tableOptions['paged'] + 1 > $total_pages) {
            if ($tableOptions['paged'] == 1) {
                $nexturl = $url;
            } else {
                $nexturl = $url . "&paged=" . $tableOptions['paged'];
            }
        } else {
            $next = $tableOptions['paged'] + 1;
            $nexturl = $url . "&paged=" . $next;
        }

        if ($tableOptions['paged'] + 1 > $total_pages) {
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

        $ajaxPaginationLink = "admin-ajax.php?action=ajaxUpdatePaginationLinks&subpage=" . $sub .
                "&nonce=" . wp_create_nonce('abj404_updatePaginationLink');
        
        $searchFilterControl = '<!--';
        if ($sub == 'abj404_redirects' || $sub == 'abj404_captured') {
            $searchFilterControl = '';
        }
        if (!$showSearchFilter) {
            $searchFilterControl = '<!--';
        }
        
        if ($tableOptions['filterText'] != '') {
            $nexturl .= '&filterText=' . $tableOptions['filterText'];
            $prevurl .= '&filterText=' . $tableOptions['filterText'];
            $firsturl .= '&filterText=' . $tableOptions['filterText'];
            $lasturl .= '&filterText=' . $tableOptions['filterText'];
        }

        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/paginationLinks.html");
        // do special replacements
        $html = $f->str_replace(' value="' . $tableOptions['perpage'] . '"', 
                ' value="' . $tableOptions['perpage'] . '" selected', 
                $html);
        $html = $f->str_replace('{changeItemsPerPage}', $showRowsLink, $html);
        $html = $f->str_replace('{showSearchFilter}', $searchFilterControl, $html);
        $html = $f->str_replace('{TEXT_BEFORE_LINKS}', $currentlyShowingText, $html);
        $html = $f->str_replace('{TEXT_SHOW_ROWS}', $showRowsText, $html);
        $html = $f->str_replace('{LINK_FIRST_PAGE}', esc_url($firsturl), $html);
        $html = $f->str_replace('{LINK_PREVIOUS_PAGE}', esc_url($prevurl), $html);
        $html = $f->str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = $f->str_replace('{LINK_NEXT_PAGE}', esc_url($nexturl), $html);
        $html = $f->str_replace('{LINK_LAST_PAGE}', esc_url($lasturl), $html);
        $html = $f->str_replace('{filterText}', $tableOptions['filterText'], $html);
        $html = $f->str_replace('{data-pagination-ajax-url}', $ajaxPaginationLink, $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        return $html;
    }    
    
    /** Output the filters for a tab.
     * @global type $abj404dao
     * @param string $sub
     * @param array $tableOptions
     */
    function getTabFilters($sub, $tableOptions) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        if (empty($tableOptions)) {
        	$tableOptions = $abj404logic->getTableOptions($sub);
        }
        
        $html = '';
        $html .= "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span>";
        
        $html .= $this->getSubSubSub($sub);
        
        $html .= "</span>";
        
        return $html;
    }
    
    function getSubSubSub($sub) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        global $abj404_redirect_types;
        global $abj404_captured_types;
        
        $tableOptions = $abj404logic->getTableOptions($sub);
        
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
            $types = $abj404_redirect_types;
        } else if ($sub == 'abj404_captured') {
            $types = $abj404_captured_types;
        } else {
            $abj404logging->debugMessage("Unexpected sub type for tab filter: " . $sub);
            $types = $abj404_captured_types;
        }

        $class = "";
        if ($tableOptions['filter'] == 0) {
            $class = " class=\"current\"";
        }
        
        $html = '<ul class="subsubsub" >';
        if ($sub != 'abj404_captured') {
            $html .= "<li>";
            $html .= "<a href=\"" . esc_url($url) . "\"" . $class . ">" . __('All', '404-solution');
            $html .= " <span class=\"count\">(" . esc_html($abj404dao->getRecordCount($types)) . ")</span>";
            $html .= "</a>";
            $html .= "</li>";
        }
        foreach ($types as $type) {
            $thisurl = $url . "&filter=" . $type;

            $class = "";
            if ($tableOptions['filter'] == $type) {
                $class = " class=\"current\"";
            }

            $recordCount = 0;
            if ($type == ABJ404_STATUS_MANUAL) {
                $title = "Manual Redirects";
                $recordCount = $abj404dao->getRecordCount(array($type, ABJ404_STATUS_REGEX));
            } else if ($type == ABJ404_STATUS_AUTO) {
                $title = "Automatic Redirects";
                $recordCount = $abj404dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_CAPTURED) {
                $title = "Captured URLs";
                $recordCount = $abj404dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_IGNORED) {
                $title = "Ignored 404s";
                $recordCount = $abj404dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_LATER) {
                $title = "Organize Later";
                $recordCount = $abj404dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_REGEX) {
                // don't include a tab here because these are included in the manual redirects.
                continue;
            } else {
                $abj404logging->errorMessage("Unrecognized redirect type in View: " . esc_html($type));
            }

            $html .= "<li>";
            if ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) {
                $html .= " | ";
            }
            $html .= "<a href=\"" . esc_url($thisurl) . "\"" . $class . ">" . ( $title );
            $html .= " <span class=\"count\">(" . esc_html($recordCount) . ")</span>";
            $html .= "</a>";
            $html .= "</li>";
        }


        $trashurl = $url . "&filter=" . ABJ404_TRASH_FILTER;
        $class = "";
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $class = " class=\"current\"";
        }
        $html .= "<li> | ";
        $html .= "<a href=\"" . esc_url($trashurl) . "\"" . $class . ">" . __('Trash', '404-solution');
        $html .= " <span class=\"count\">(" . esc_html($abj404dao->getRecordCount($types, 1)) . ")</span>";
        $html .= "</a>";
        $html .= "</li>";
        $html .= "</ul>";
        $html .= "\n\n<!-- page-form big outer form could go here -->\n\n";
        
        $oneBigFormActionURL = $this->getBulkOperationsFormURL($sub, $tableOptions);
        $html .= '<form method="POST" name="bulk-operations-form" action="' . $oneBigFormActionURL . '">';

        
        return $html;
    }
}
