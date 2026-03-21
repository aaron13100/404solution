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

        // Security notice: surface cached threat detections from nightly analysis.
        $this->echoSecurityNotice();

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

        // Trend Analytics section (full-width, below the flow layout cards)
        $this->echoTrendsSection();

        // Broken Internal Links section
        $this->echoBrokenInternalLinksSection();

        echo "</div>"; // Close settings content
        echo "</div>"; // Close container
    }

    /**
     * Output the Trends (time-series charts) section on the Stats page.
     * @return void
     */
    private function echoTrendsSection() {
        global $abj404view;

        $trendNonce = wp_create_nonce('abj404_trendData');
        $ajaxUrl = admin_url('admin-ajax.php');

        $trendsContent  = '<div id="abj404-trends-container">';
        $trendsContent .= '<p class="abj404-trends-loading">' . esc_html__('Loading chart data\u2026', '404-solution') . '</p>';
        $trendsContent .= '<div id="abj404-trends-charts" style="display:none">';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-404s"></canvas></div>';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-redirects"></canvas></div>';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-captures"></canvas></div>';
        $trendsContent .= '</div>';
        $trendsContent .= '<p id="abj404-trends-error" style="display:none;color:#d63638">'
            . esc_html__('Could not load chart data.', '404-solution') . '</p>';
        $trendsContent .= '</div>';

        $trendsContent .= '<style>'
            . '.abj404-trend-chart-wrap { margin-bottom: 24px; }'
            . '.abj404-trends-loading { color: #646970; font-style: italic; }'
            . '</style>';

        // Inline JS: load Chart.js from CDN then fetch data and render charts.
        $label404      = esc_js(__('404 Hits per Day', '404-solution'));
        $labelRedirect = esc_js(__('Redirects per Day', '404-solution'));
        $labelCapture  = esc_js(__('New Captures per Day', '404-solution'));
        $ajaxUrlEsc    = esc_js($ajaxUrl);
        $nonceEsc      = esc_js($trendNonce);

        $trendsContent .= '<script>'
            . '(function() {'
            . '  var ajaxUrl = "' . $ajaxUrlEsc . '";'
            . '  var nonce   = "' . $nonceEsc . '";'
            . '  function loadChartJs(cb) {'
            . '    if (window.Chart) { cb(); return; }'
            . '    var s = document.createElement("script");'
            . '    s.src = "https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js";'
            . '    s.onload = cb;'
            . '    s.onerror = function() {'
            . '      var loadEl = document.querySelector(".abj404-trends-loading");'
            . '      if (loadEl) loadEl.style.display = "none";'
            . '      var errEl = document.getElementById("abj404-trends-error");'
            . '      if (errEl) errEl.style.display = "";'
            . '    };'
            . '    document.head.appendChild(s);'
            . '  }'
            . '  function buildChart(canvasId, label, color, labels, values) {'
            . '    var ctx = document.getElementById(canvasId);'
            . '    if (!ctx) return;'
            . '    new Chart(ctx, {'
            . '      type: "line",'
            . '      data: {'
            . '        labels: labels,'
            . '        datasets: [{'
            . '          label: label,'
            . '          data: values,'
            . '          borderColor: color,'
            . '          backgroundColor: color.replace("rgb(", "rgba(").replace(")", ", 0.15)"),'
            . '          tension: 0.3,'
            . '          fill: true,'
            . '          pointRadius: 3'
            . '        }]'
            . '      },'
            . '      options: {'
            . '        responsive: true,'
            . '        plugins: { legend: { display: true } },'
            . '        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }'
            . '      }'
            . '    });'
            . '  }'
            . '  function fetchAndRender() {'
            . '    fetch(ajaxUrl + "?action=abj404getTrendData&nonce=" + encodeURIComponent(nonce) + "&days=30")'
            . '      .then(function(r) { return r.json(); })'
            . '      .then(function(resp) {'
            . '        var loadEl = document.querySelector(".abj404-trends-loading");'
            . '        if (loadEl) loadEl.style.display = "none";'
            . '        if (!resp || !resp.success || !Array.isArray(resp.data)) {'
            . '          var errEl = document.getElementById("abj404-trends-error");'
            . '          if (errEl) errEl.style.display = "";'
            . '          return;'
            . '        }'
            . '        var rows = resp.data;'
            . '        var labels    = rows.map(function(r) { return r.date; });'
            . '        var vals404   = rows.map(function(r) { return r.hits_404; });'
            . '        var valsRedir = rows.map(function(r) { return r.hits_redirect; });'
            . '        var valsCapt  = rows.map(function(r) { return r.new_captures; });'
            . '        document.getElementById("abj404-trends-charts").style.display = "";'
            . '        buildChart("abj404-chart-404s",      "' . $label404      . '", "rgb(0,115,170)",  labels, vals404);'
            . '        buildChart("abj404-chart-redirects", "' . $labelRedirect . '", "rgb(70,170,100)", labels, valsRedir);'
            . '        buildChart("abj404-chart-captures",  "' . $labelCapture  . '", "rgb(220,100,50)", labels, valsCapt);'
            . '      })'
            . '      .catch(function() {'
            . '        var loadEl = document.querySelector(".abj404-trends-loading");'
            . '        if (loadEl) loadEl.style.display = "none";'
            . '        var errEl = document.getElementById("abj404-trends-error");'
            . '        if (errEl) errEl.style.display = "";'
            . '      });'
            . '  }'
            . '  document.addEventListener("DOMContentLoaded", function() {'
            . '    loadChartJs(fetchAndRender);'
            . '  });'
            . '})();'
            . '</script>';

        $abj404view->echoOptionsSection(
            'stats-trends',
            'abj404-trendsSection',
            __('Trends (Last 30 Days)', '404-solution'),
            $trendsContent,
            false,
            $abj404view->getCardIcon('chart')
        );
    }

    /**
     * Output the Broken Internal Links section on the Stats page (if results are cached).
     * @return void
     */
    private function echoBrokenInternalLinksSection() {
        global $abj404view;

        if (!class_exists('ABJ_404_Solution_InternalLinkScanner')) {
            return;
        }

        $scanner = new ABJ_404_Solution_InternalLinkScanner();
        $results = $scanner->getCachedResults();

        if ($results === false || !is_array($results)) {
            // No cached results yet — nothing to show.
            return;
        }

        if (empty($results)) {
            $content = '<p>' . esc_html__('No broken internal links found.', '404-solution') . '</p>';
        } else {
            $postCount = count(array_unique(array_column($results, 'post_id')));
            $content  = '<p>' . esc_html(sprintf(
                /* translators: 1: number of broken links, 2: number of posts/pages */
                __('Found %1$d broken internal link(s) across %2$d post(s)/page(s).', '404-solution'),
                count($results),
                $postCount
            )) . '</p>';
            $content .= '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Post/Page', '404-solution') . '</th>'
                . '<th>' . esc_html__('Broken URL', '404-solution') . '</th>'
                . '<th>' . esc_html__('404 Hits', '404-solution') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($results as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $postTitle = isset($item['post_title']) ? (string)$item['post_title'] : '';
                $brokenUrl = isset($item['broken_url']) ? (string)$item['broken_url'] : '';
                $hitCount  = isset($item['hit_count'])  ? intval($item['hit_count'])  : 0;
                $postId    = isset($item['post_id'])     ? intval($item['post_id'])    : 0;
                $editLink  = ($postId > 0) ? get_edit_post_link($postId) : '';
                $content .= '<tr>';
                if ($editLink) {
                    $content .= '<td><a href="' . esc_url($editLink) . '">' . esc_html($postTitle) . '</a></td>';
                } else {
                    $content .= '<td>' . esc_html($postTitle) . '</td>';
                }
                $content .= '<td><code>' . esc_html($brokenUrl) . '</code></td>';
                $content .= '<td>' . esc_html((string)$hitCount) . '</td>';
                $content .= '</tr>';
            }
            $content .= '</tbody></table>';
        }

        $abj404view->echoOptionsSection(
            'stats-broken-links',
            'abj404-brokenLinksSection',
            __('Broken Internal Links', '404-solution'),
            $content,
            false,
            $abj404view->getCardIcon('warning')
        );
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
     * Echo a security notice on the Stats page if the nightly analysis found threats.
     * Shows a notice box with a count summary. No-op when no cached results exist.
     *
     * @return void
     */
    private function echoSecurityNotice(): void {
        $dao    = ABJ_404_Solution_DataAccess::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();
        $monitor = new ABJ_404_Solution_SecurityMonitor($dao, $logger);

        $results = $monitor->getCachedResults();

        // false means no cached analysis yet — suppress the notice.
        if ($results === false || !is_array($results) || empty($results)) {
            return;
        }

        $count = count($results);
        echo '<div class="notice notice-warning abj404-security-notice" style="margin:0 0 16px 0;padding:12px 16px;">';
        echo '<p><strong>' . esc_html__('Security Scan', '404-solution') . '</strong> &mdash; ';
        echo esc_html(
            sprintf(
                _n(
                    'Security scan found potential attack patterns. %d suspicious 404 pattern detected in the last 24 hours.',
                    'Security scan found potential attack patterns. %d suspicious 404 patterns detected in the last 24 hours.',
                    $count,
                    '404-solution'
                ),
                $count
            )
        );
        echo '</p>';
        if ($count > 0) {
            echo '<ul style="margin:4px 0 0 16px;list-style:disc;">';
            foreach ($results as $threat) {
                if (!is_array($threat)) {
                    continue;
                }
                $detail = isset($threat['detail']) && is_string($threat['detail']) ? $threat['detail'] : '';
                if ($detail !== '') {
                    echo '<li>' . esc_html($detail) . '</li>';
                }
            }
            echo '</ul>';
        }
        echo '</div>';
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

        // Migrate from Another Plugin Card
        $html = $this->getMigrateFromPluginMarkup();
        $abj404view->echoOptionsSection('tools-migrate', 'abj404-migrateFromPlugin', __('Migrate from Another Plugin', '404-solution'), $html, false, $abj404view->getCardIcon('upload'));

        // Google Search Console Integration Card (renders its own card)
        $logger = ABJ_404_Solution_Logging::getInstance();
        $gsc = new ABJ_404_Solution_GoogleSearchConsole($logger);
        echo $gsc->renderAdminSection();

        echo "</div>";
        echo "</div>";
    }

    /**
     * Build the "Migrate from Another Plugin" card markup.
     * Auto-detects installed redirect plugins and renders a form for import.
     *
     * @return string
     */
    private function getMigrateFromPluginMarkup(): string {
        $dao    = ABJ_404_Solution_DataAccess::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();
        $importer = new ABJ_404_Solution_CrossPluginImporter($dao, $logger);

        $detected = $importer->detectInstalledPlugins();

        $pluginLabels = array(
            'rankmath'           => __('Rank Math', '404-solution'),
            'yoast'              => __('Yoast SEO Premium', '404-solution'),
            'aioseo'             => __('AIOSEO', '404-solution'),
            'safe-redirect-manager' => __('Safe Redirect Manager', '404-solution'),
            'redirection'        => __('Redirection Plugin', '404-solution'),
        );

        $availableSources = array();
        foreach ($detected as $slug => $isAvailable) {
            if ($isAvailable) {
                $availableSources[$slug] = isset($pluginLabels[$slug]) ? $pluginLabels[$slug] : $slug;
            }
        }

        $migrateActionUrl = wp_nonce_url(
            '?page=' . ABJ404_PP . '&subpage=abj404_tools',
            'abj404_importFromPlugin'
        );

        $html = '<p>';
        if (empty($availableSources)) {
            $html .= esc_html__('No supported redirect plugins detected on this site.', '404-solution');
            $html .= '</p>';
            $html .= '<p>' . esc_html__('Supported plugins: Rank Math, Yoast SEO Premium, AIOSEO, Safe Redirect Manager, Redirection.', '404-solution') . '</p>';
            return $html;
        }

        $detectedNames = array_values($availableSources);
        $html .= esc_html__('Detected redirect plugins:', '404-solution') . ' ';
        $html .= '<strong>' . esc_html(implode(', ', $detectedNames)) . '</strong>';
        $html .= '</p>';

        $html .= '<form method="POST" action="' . esc_url($migrateActionUrl) . '">';
        $html .= '<p>';
        $html .= '<label for="abj404-import-source"><strong>' . esc_html__('Source plugin:', '404-solution') . '</strong></label> ';
        $html .= '<select name="import_source" id="abj404-import-source">';
        foreach ($availableSources as $slug => $label) {
            $html .= '<option value="' . esc_attr($slug) . '">' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        $html .= '</p>';
        $html .= '<p>';
        $html .= '<input type="hidden" name="action" value="importFromPlugin">';
        $html .= '<input type="submit" class="button-secondary" value="' . esc_attr__('Import Redirects', '404-solution') . '">';
        $html .= '</p>';
        $html .= '<p><em>' . esc_html__('This will import all active redirects from the selected plugin into 404 Solution. Regex and redirect codes are preserved.', '404-solution') . '</em></p>';
        $html .= '</form>';

        return $html;
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
