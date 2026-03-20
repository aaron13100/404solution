<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Stats methods.
 */
trait ViewTrait_Stats {


    /**
     * Output the stats page.
     * @return void
     */
    function outputAdminStatsPage() {
        global $abj404view;

        $statsSnapshot = $this->dao->getStatsDashboardSnapshot(true);
        $statsData = $statsSnapshot['data'];
        $statsHash = $statsSnapshot['hash'];

        // Main container
        echo "<div class=\"abj404-container\">";
        echo "<div class=\"abj404-settings-content\">";

        // Header row with Expand All button
        echo "<div class=\"abj404-header-row\">";
        echo "<h2>" . esc_html__('Statistics', '404-solution') . "</h2>";
        echo "<div class=\"abj404-header-controls\">";
        echo '<button type="button" id="abj404-expand-collapse-all" class="button">';
        echo esc_html__('Expand All', '404-solution');
        echo '</button>';
        echo "</div>";
        echo "</div>";

        // Config for stale-while-refresh stats snapshot updates (no visible table overwrite).
        echo '<div class="abj404-stats-refresh-config" style="display:none"'
            . ' data-stats-refresh-enabled="1"'
            . ' data-stats-refresh-action="ajaxRefreshStatsDashboard"'
            . ' data-stats-refresh-nonce="' . esc_attr(wp_create_nonce('abj404_refreshStatsDashboard')) . '"'
            . ' data-stats-refresh-current-hash="' . esc_attr($statsHash) . '"'
            . ' data-stats-refresh-available-text="' . esc_attr(__('Refresh available', '404-solution')) . '"></div>';

        // Flow layout for stats cards
        echo "<div class=\"abj404-flow-layout\">";

        // Redirects Statistics Card
        $redirectStats = (is_array($statsData) && isset($statsData['redirects']) && is_array($statsData['redirects']))
            ? $statsData['redirects']
            : array();
        $auto301 = intval($redirectStats['auto301'] ?? 0);
        $auto302 = intval($redirectStats['auto302'] ?? 0);
        $manual301 = intval($redirectStats['manual301'] ?? 0);
        $manual302 = intval($redirectStats['manual302'] ?? 0);
        $trashed = intval($redirectStats['trashed'] ?? 0);

        $total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

        $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsRedirectsBox.html");
        $content = $this->f->str_replace('{auto301}', esc_html((string)$auto301), $content);
        $content = $this->f->str_replace('{auto302}', esc_html((string)$auto302), $content);
        $content = $this->f->str_replace('{manual301}', esc_html((string)$manual301), $content);
        $content = $this->f->str_replace('{manual302}', esc_html((string)$manual302), $content);
        $content = $this->f->str_replace('{trashed}', esc_html((string)$trashed), $content);
        $content = $this->f->str_replace('{total}', esc_html((string)$total), $content);
        $content = $this->f->doNormalReplacements($content);
        $abj404view->echoOptionsSection('stats-redirects', 'abj404-redirectStats', __('Redirects', '404-solution'), $content, true, $abj404view->getCardIcon('chart'));

        // Captured URLs Statistics Card
        $capturedStats = (is_array($statsData) && isset($statsData['captured']) && is_array($statsData['captured']))
            ? $statsData['captured']
            : array();
        $captured = intval($capturedStats['captured'] ?? 0);
        $ignored = intval($capturedStats['ignored'] ?? 0);
        $trashed = intval($capturedStats['trashed'] ?? 0);

        $total = $captured + $ignored + $trashed;

        $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsCapturedURLsBox.html");
        $content = $this->f->str_replace('{captured}', esc_html((string)$captured), $content);
        $content = $this->f->str_replace('{ignored}', esc_html((string)$ignored), $content);
        $content = $this->f->str_replace('{trashed}', esc_html((string)$trashed), $content);
        $content = $this->f->str_replace('{total}', esc_html((string)$total), $content);
        $content = $this->f->doNormalReplacements($content);
        $abj404view->echoOptionsSection('stats-captured', 'abj404-capturedStats', __('Captured URLs', '404-solution'), $content, true, $abj404view->getCardIcon('warning'));

        // Periodic Stats Cards
        $periodicStats = (is_array($statsData) && isset($statsData['periods']) && is_array($statsData['periods']))
            ? $statsData['periods']
            : array();
        $periodMeta = array(
            array('title' => __("Today's Stats", '404-solution'), 'key' => 'today'),
            array('title' => __("This Month", '404-solution'), 'key' => 'month'),
            array('title' => __("This Year", '404-solution'), 'key' => 'year'),
            array('title' => __("All Stats", '404-solution'), 'key' => 'all'),
        );

        for ($x = 0; $x <= 3; $x++) {
            $title = $periodMeta[$x]['title'];
            $periodKey = $periodMeta[$x]['key'];
            $periodStats = (is_array($periodicStats) && isset($periodicStats[$periodKey]) && is_array($periodicStats[$periodKey]))
                ? $periodicStats[$periodKey]
                : array();
            $disp404 = intval($periodStats['disp404'] ?? 0);
            $distinct404 = intval($periodStats['distinct404'] ?? 0);
            $visitors404 = intval($periodStats['visitors404'] ?? 0);
            $refer404 = intval($periodStats['refer404'] ?? 0);
            $redirected = intval($periodStats['redirected'] ?? 0);
            $distinctredirected = intval($periodStats['distinctredirected'] ?? 0);
            $distinctvisitors = intval($periodStats['distinctvisitors'] ?? 0);
            $distinctrefer = intval($periodStats['distinctrefer'] ?? 0);

            $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsPeriodicBox.html");
            $content = $this->f->str_replace('{disp404}', esc_html((string)$disp404), $content);
            $content = $this->f->str_replace('{distinct404}', esc_html((string)$distinct404), $content);
            $content = $this->f->str_replace('{visitors404}', esc_html((string)$visitors404), $content);
            $content = $this->f->str_replace('{refer404}', esc_html((string)$refer404), $content);
            $content = $this->f->str_replace('{redirected}', esc_html((string)$redirected), $content);
            $content = $this->f->str_replace('{distinctredirected}', esc_html((string)$distinctredirected), $content);
            $content = $this->f->str_replace('{distinctvisitors}', esc_html((string)$distinctvisitors), $content);
            $content = $this->f->str_replace('{distinctrefer}', esc_html((string)$distinctrefer), $content);
            $content = $this->f->doNormalReplacements($content);
            $abj404view->echoOptionsSection('stats-periodic-' . $x, 'abj404-stats' . $x, $title, $content, ($x == 0), $abj404view->getCardIcon('clock'));
        }

        echo "</div>"; // Close flow layout
        echo "</div>"; // Close settings content
        echo "</div>"; // Close container
    }
    
    /** @return void */
    function echoAdminDebugFile() {
        if ($this->logic->userIsPluginAdmin()) {
        	$filesToEcho = array($this->logger->getDebugFilePath(), 
        			$this->logger->getDebugFilePathOld());
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
	        	$userInfo = "Login: " . ($current_user->user_login ?? '') . ", display name: " .
	         		($current_user->display_name ?? '') . ", Email: " . ($current_user->user_email ?? '') .
	         		", UserID: " . $current_user->ID;
	            $this->logger->infoMessage("Non-admin request to view debug file. User info: " .
	            	$userInfo);
	        }
	    }
    
	    /**
	     * @param string $fileName
	     * @return void
	     */
	    function echoFileContents($fileName) {

	    	if (!is_string($fileName)) {
	    		$fileName = '';
	    	}

	    	if (file_exists($fileName)) {
	    		$linesRead = 0;
	    		$handle = null;
	    		try {
    			if ($handle = fopen($fileName, "r")) {
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
    				$this->logger->errorMessage("Error opening debug file.");
    			}
    			
    		} catch (Exception $e) {
    			$this->logger->errorMessage("Error while reading debug file.", $e);
    		}
    		
    		if ($handle != null) {
    			fclose($handle);
    		}
    		
    	} else {
    		echo nl2br(__('(The log file does not exist.)', '404-solution'));
    	}
    }

    /**
     * Display the tools page.
     * @return void
     */
    function echoAdminToolsPage() {
        global $abj404view;

        // Main container
        echo "<div class=\"abj404-container\">";
        echo "<div class=\"abj404-settings-content\">";

        // Header row with Expand All button
        echo "<div class=\"abj404-header-row\">";
        echo "<h2>" . esc_html__('Tools', '404-solution') . "</h2>";
        echo "<div class=\"abj404-header-controls\">";
        echo '<button type="button" id="abj404-expand-collapse-all" class="button">';
        echo esc_html__('Expand All', '404-solution');
        echo '</button>';
        echo "</div>";
        echo "</div>";

        // Export Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_exportRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsExportForm.html");
        $html = $this->f->str_replace('{toolsExportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-export', 'abj404-exportRedirects', __('Export', '404-solution'), $html, true, $abj404view->getCardIcon('download'));

        // Import Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_importRedirectsFile");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsImportForm.html");
        $html = $this->f->str_replace('{toolsImportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-import', 'abj404-importRedirects', __('Import', '404-solution'), $html, false, $abj404view->getCardIcon('upload'));

        // Purge Card
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsPurgeForm.html");
        $html = $this->f->str_replace('{toolsPurgeFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-purge', 'abj404-purgeRedirects', __('Purge Options', '404-solution'), $html, false, $abj404view->getCardIcon('trash'));

        // Cache Management Card
        $ngramLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_rebuildNgramCache");
        $spellingLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_clearSpellingCache");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsCacheForm.html");
        $html = $this->f->str_replace('{toolsNgramCacheFormActionLink}', $ngramLink, $html);
        $html = $this->f->str_replace('{toolsSpellingCacheFormActionLink}', $spellingLink, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-cache', 'abj404-cacheTools', __('Cache Management', '404-solution'), $html, false, $abj404view->getCardIcon('database'));

        // Diagnostics Card
        $html = $this->getToolsDiagnosticsMarkup();
        $abj404view->echoOptionsSection('tools-diagnostics', 'abj404-diagnosticsTools', __('Diagnostics', '404-solution'), $html, false, $abj404view->getCardIcon('warning'));

        // Etcetera Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        $link .= '&manually_fired=true';
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsEtcForm.html");
        $html = $this->f->str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-etc', 'abj404-etcTools', __('Etcetera', '404-solution'), $html, false, $abj404view->getCardIcon('cog'));

        echo "</div>";
        echo "</div>";
    }

    /**
     * Build compact diagnostics markup for the Tools page.
     * This is intentionally read-only and lightweight.
     *
     * @return string
     */
    private function getToolsDiagnosticsMarkup() {
        $rows = $this->getToolsDiagnosticsRows();
        $html = '<div class="abj404-diagnostics-summary">';
        $html .= '<p>' . esc_html__('Quick environment checks for troubleshooting and support.', '404-solution') . '</p>';
        $html .= '<table class="widefat striped"><tbody>';

        foreach ($rows as $row) {
            $label = array_key_exists('label', $row) ? $row['label'] : '';
            $value = array_key_exists('value', $row) ? $row['value'] : '';
            $valueHtml = array_key_exists('value_html', $row) ? $row['value_html'] : '';
            $status = array_key_exists('status', $row) ? $row['status'] : 'info';
            $statusLabel = ($status === 'ok') ? __('OK', '404-solution') : (($status === 'warn') ? __('Warning', '404-solution') : __('Info', '404-solution'));
            $statusClass = ($status === 'ok') ? 'abj404-pill-success' : (($status === 'warn') ? 'abj404-pill-warning' : 'abj404-pill-info');

            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html($label) . '</strong></td>';
            if ($valueHtml !== '') {
                $html .= '<td>' . wp_kses_post($valueHtml) . '</td>';
            } else {
                $html .= '<td>' . esc_html($value) . '</td>';
            }
            $html .= '<td><span class="abj404-status-pill ' . esc_attr($statusClass) . '">' . esc_html($statusLabel) . '</span></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Collect diagnostics fields displayed on the Tools page.
     *
     * @return array<int, array<string, string>>
     */
    private function getToolsDiagnosticsRows() {
        $wpVersion = get_bloginfo('version');
        if (!is_string($wpVersion) || trim($wpVersion) === '') {
            $wpVersion = __('Unknown', '404-solution');
        }

        $uploadDir = '';
        if (function_exists('abj404_getUploadsDir')) {
            $uploadDir = (string)abj404_getUploadsDir();
        }
        $uploadDirReadable = ($uploadDir !== '' && is_dir($uploadDir));
        $uploadDirWritable = ($uploadDir !== '' && is_writable($uploadDir));

        $rows = array();
        $pluginVersion = defined('ABJ404_VERSION') ? ABJ404_VERSION : __('Unknown', '404-solution');
        $rows[] = array('label' => __('Plugin Version', '404-solution'), 'value' => $pluginVersion, 'status' => 'info');
        $rows[] = array('label' => __('WordPress Version', '404-solution'), 'value' => $wpVersion, 'status' => 'info');
        $rows[] = array('label' => __('PHP Version', '404-solution'), 'value' => PHP_VERSION, 'status' => 'info');
        $rows[] = array(
            'label' => __('Uploads Directory', '404-solution'),
            'value' => ($uploadDir !== '') ? $uploadDir : __('Not available', '404-solution'),
            'status' => $uploadDirReadable ? 'ok' : 'warn',
        );
        $rows[] = array(
            'label' => __('Uploads Writable', '404-solution'),
            'value' => $uploadDirWritable ? __('Yes', '404-solution') : __('No', '404-solution'),
            'status' => $uploadDirWritable ? 'ok' : 'warn',
        );
        $rows[] = array(
            'label' => __('mbstring Extension', '404-solution'),
            'value' => extension_loaded('mbstring') ? __('Loaded', '404-solution') : __('Missing', '404-solution'),
            'status' => extension_loaded('mbstring') ? 'ok' : 'warn',
        );
        $rows[] = array(
            'label' => __('ZipArchive Support', '404-solution'),
            'value' => class_exists('ZipArchive') ? __('Available', '404-solution') : __('Missing', '404-solution'),
            'status' => class_exists('ZipArchive') ? 'ok' : 'warn',
        );
        $rows[] = array(
            'label' => __('WP_DEBUG', '404-solution'),
            'value' => (defined('WP_DEBUG') && WP_DEBUG) ? __('Enabled', '404-solution') : __('Disabled', '404-solution'),
            'status' => 'info',
        );
        if (function_exists('abj404_is_local_debug_host') && function_exists('abj404_get_simulated_db_latency_ms') &&
                abj404_is_local_debug_host()) {
            $latencyMs = absint(abj404_get_simulated_db_latency_ms());
            $latencyUrls = array(
                250 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=250'), 'abj404_set_sim_db_ms'),
                500 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=500'), 'abj404_set_sim_db_ms'),
                900 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=900'), 'abj404_set_sim_db_ms'),
                0   => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=0'), 'abj404_set_sim_db_ms'),
            );
            $controls = '<a href="' . esc_url($latencyUrls[250]) . '">' . esc_html(__('250ms', '404-solution')) . '</a>'
                . ' | <a href="' . esc_url($latencyUrls[500]) . '">' . esc_html(__('500ms', '404-solution')) . '</a>'
                . ' | <a href="' . esc_url($latencyUrls[900]) . '">' . esc_html(__('900ms', '404-solution')) . '</a>'
                . ' | <a href="' . esc_url($latencyUrls[0]) . '">' . esc_html(__('Disable', '404-solution')) . '</a>';
            $rows[] = array(
                'label' => __('Simulated DB Latency', '404-solution'),
                'value' => ($latencyMs > 0)
                    ? sprintf(__('ON (%d ms per plugin query)', '404-solution'), $latencyMs)
                    : __('OFF', '404-solution'),
                'value_html' => '<div>' . esc_html(($latencyMs > 0)
                    ? sprintf(__('ON (%d ms per plugin query)', '404-solution'), $latencyMs)
                    : __('OFF', '404-solution')) . '</div><div>' . $controls . '</div>',
                'status' => ($latencyMs > 0) ? 'warn' : 'info',
            );
        }

        return $rows;
    }


}
