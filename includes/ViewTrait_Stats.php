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

        // Match Confidence distribution card (full-width)
        $this->echoConfidenceDistributionSection();

        // Trend Analytics section (full-width, below the flow layout cards)
        $this->echoTrendsSection();

        // Broken Internal Links section
        $this->echoBrokenInternalLinksSection();

        echo "</div>"; // Close settings content
        echo "</div>"; // Close container
    }

    /**
     * Output the Match Confidence distribution card on the Stats page.
     * Queries the redirects table for score band counts and renders a Chart.js doughnut.
     * @return void
     */
    private function echoConfidenceDistributionSection() {
        global $abj404view, $wpdb;

        if (!isset($wpdb)) {
            return;
        }

        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $redirectsTable = $dao->doTableNameReplacements('{wp_abj404_redirects}');

        // Query score distribution bands.
        $query = $wpdb->prepare(
            "SELECT
               SUM(CASE WHEN score IS NULL THEN 1 ELSE 0 END) AS manual_count,
               SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) AS high_count,
               SUM(CASE WHEN score >= 50 AND score < 80 THEN 1 ELSE 0 END) AS medium_count,
               SUM(CASE WHEN score IS NOT NULL AND score < 50 THEN 1 ELSE 0 END) AS low_count,
               AVG(score) AS avg_score
             FROM `{$redirectsTable}`
             WHERE disabled = %d AND status != %d",
            0,
            0
        );

        $row = $wpdb->get_row($query, ARRAY_A);
        if (!is_array($row)) {
            return;
        }

        $highCount   = (int)($row['high_count']   ?? 0);
        $mediumCount = (int)($row['medium_count'] ?? 0);
        $lowCount    = (int)($row['low_count']    ?? 0);
        $manualCount = (int)($row['manual_count'] ?? 0);
        $avgScore    = ($row['avg_score'] !== null) ? round((float)$row['avg_score'], 1) : null;

        $total = $highCount + $mediumCount + $lowCount + $manualCount;
        if ($total === 0) {
            return;
        }

        $labelHigh   = esc_html__('High (≥80%)', '404-solution');
        $labelMedium = esc_html__('Medium (50–79%)', '404-solution');
        $labelLow    = esc_html__('Low (<50%)', '404-solution');
        $labelManual = esc_html__('Manual (no score)', '404-solution');

        $avgLabel = ($avgScore !== null)
            ? sprintf(
                '<strong>' . esc_html__('Avg confidence: %s%%', '404-solution') . '</strong>',
                esc_html(number_format($avgScore, 1))
            )
            : '';

        $content  = '<div class="abj404-confidence-dist">';
        $content .= '<p class="abj404-confidence-avg">' . $avgLabel . '</p>';
        $content .= '<canvas id="abj404-chart-confidence" style="max-height:200px;max-width:400px;"></canvas>';
        $content .= '<ul class="abj404-confidence-legend">';
        $content .= '<li><span class="abj404-legend-dot abj404-conf-high"></span>' . esc_html($labelHigh) . ' <strong>' . esc_html((string)$highCount) . '</strong></li>';
        $content .= '<li><span class="abj404-legend-dot abj404-conf-medium"></span>' . esc_html($labelMedium) . ' <strong>' . esc_html((string)$mediumCount) . '</strong></li>';
        $content .= '<li><span class="abj404-legend-dot abj404-conf-low"></span>' . esc_html($labelLow) . ' <strong>' . esc_html((string)$lowCount) . '</strong></li>';
        $content .= '<li><span class="abj404-legend-dot abj404-conf-manual"></span>' . esc_html($labelManual) . ' <strong>' . esc_html((string)$manualCount) . '</strong></li>';
        $content .= '</ul>';
        $content .= '</div>';

        $content .= '<style>'
            . '.abj404-confidence-dist { display: flex; align-items: center; gap: 32px; flex-wrap: wrap; }'
            . '.abj404-confidence-avg { font-size: 14px; margin-bottom: 8px; }'
            . '.abj404-confidence-legend { list-style: none; margin: 0; padding: 0; }'
            . '.abj404-confidence-legend li { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-size: 13px; }'
            . '.abj404-legend-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }'
            . '.abj404-conf-high   { background: #28a745; }'
            . '.abj404-conf-medium { background: #ffc107; }'
            . '.abj404-conf-low    { background: #dc3545; }'
            . '.abj404-conf-manual { background: #adb5bd; }'
            . '</style>';

        $content .= '<script>'
            . '(function() {'
            . '  function renderConfidenceChart() {'
            . '    var ctx = document.getElementById("abj404-chart-confidence");'
            . '    if (!ctx || !window.Chart) return;'
            . '    new Chart(ctx, {'
            . '      type: "doughnut",'
            . '      data: {'
            . '        labels: [' . json_encode($labelHigh) . ',' . json_encode($labelMedium) . ',' . json_encode($labelLow) . ',' . json_encode($labelManual) . '],'
            . '        datasets: [{ data: [' . $highCount . ',' . $mediumCount . ',' . $lowCount . ',' . $manualCount . '],'
            . '          backgroundColor: ["#28a745","#ffc107","#dc3545","#adb5bd"] }]'
            . '      },'
            . '      options: { responsive: true, plugins: { legend: { display: false } } }'
            . '    });'
            . '  }'
            . '  if (window.Chart) { renderConfidenceChart(); }'
            . '  else { document.addEventListener("abj404ChartJsLoaded", renderConfidenceChart); }'
            . '})();'
            . '</script>';

        $abj404view->echoOptionsSection(
            'stats-confidence',
            'abj404-confidenceSection',
            __('Match Confidence', '404-solution'),
            $content,
            false,
            $abj404view->getCardIcon('check')
        );
    }

    /**
     * Output the Trends (time-series charts) section on the Stats page.
     * @return void
     */
    private function echoTrendsSection() {
        global $abj404view;

        $trendNonce = wp_create_nonce('abj404_trendData');
        $ajaxUrl = admin_url('admin-ajax.php');

        $label7d  = esc_html__('7 days', '404-solution');
        $label30d = esc_html__('30 days', '404-solution');
        $label90d = esc_html__('90 days', '404-solution');

        $trendsContent  = '<div id="abj404-trends-container">';
        $trendsContent .= '<div class="abj404-trends-period-selector" role="group" aria-label="' . esc_attr__('Period', '404-solution') . '">';
        $trendsContent .= '<label class="abj404-trends-period-label"><input type="radio" name="abj404_trend_period" value="7"> ' . $label7d . '</label>';
        $trendsContent .= '<label class="abj404-trends-period-label"><input type="radio" name="abj404_trend_period" value="30" checked> ' . $label30d . '</label>';
        $trendsContent .= '<label class="abj404-trends-period-label"><input type="radio" name="abj404_trend_period" value="90"> ' . $label90d . '</label>';
        $trendsContent .= '</div>';
        $trendsContent .= '<p class="abj404-trends-loading">' . esc_html__('Loading chart data…', '404-solution') . '</p>';
        $trendsContent .= '<div id="abj404-trends-charts" style="display:none">';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-404s"></canvas></div>';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-redirects"></canvas></div>';
        $trendsContent .= '<div class="abj404-trend-chart-wrap"><canvas id="abj404-chart-captures"></canvas></div>';
        $trendsContent .= '</div>';
        $trendsContent .= '<p id="abj404-trends-error" style="display:none;color:#d63638">'
            . esc_html__('Could not load chart data.', '404-solution') . '</p>';
        $trendsContent .= '</div>';

        $trendsContent .= '<style>'
            . '.abj404-trends-period-selector { margin-bottom: 16px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }'
            . '.abj404-trends-period-label { cursor: pointer; font-weight: 500; }'
            . '.abj404-trends-period-label input { margin-right: 4px; }'
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
            . '    s.onload = function() {'
            . '      document.dispatchEvent(new Event("abj404ChartJsLoaded"));'
            . '      cb();'
            . '    };'
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
            . '    if (!ctx) return null;'
            . '    return new Chart(ctx, {'
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
            . '  var chartInstances = {};'
            . '  function getSelectedDays() {'
            . '    var radios = document.querySelectorAll("input[name=abj404_trend_period]");'
            . '    for (var i = 0; i < radios.length; i++) {'
            . '      if (radios[i].checked) return parseInt(radios[i].value, 10);'
            . '    }'
            . '    return 30;'
            . '  }'
            . '  function destroyCharts() {'
            . '    ["abj404-chart-404s","abj404-chart-redirects","abj404-chart-captures"].forEach(function(id) {'
            . '      if (chartInstances[id]) { chartInstances[id].destroy(); delete chartInstances[id]; }'
            . '    });'
            . '  }'
            . '  function fetchAndRender() {'
            . '    var days = getSelectedDays();'
            . '    var loadEl = document.querySelector(".abj404-trends-loading");'
            . '    var errEl  = document.getElementById("abj404-trends-error");'
            . '    var chartsEl = document.getElementById("abj404-trends-charts");'
            . '    if (loadEl) loadEl.style.display = "";'
            . '    if (errEl)  errEl.style.display  = "none";'
            . '    if (chartsEl) chartsEl.style.display = "none";'
            . '    destroyCharts();'
            . '    fetch(ajaxUrl + "?action=abj404getTrendData&nonce=" + encodeURIComponent(nonce) + "&days=" + days)'
            . '      .then(function(r) { return r.json(); })'
            . '      .then(function(resp) {'
            . '        if (loadEl) loadEl.style.display = "none";'
            . '        if (!resp || !resp.success || !Array.isArray(resp.data)) {'
            . '          if (errEl) errEl.style.display = "";'
            . '          return;'
            . '        }'
            . '        var rows = resp.data;'
            . '        var labels    = rows.map(function(r) { return r.date; });'
            . '        var vals404   = rows.map(function(r) { return r.hits_404; });'
            . '        var valsRedir = rows.map(function(r) { return r.hits_redirect; });'
            . '        var valsCapt  = rows.map(function(r) { return r.new_captures; });'
            . '        if (chartsEl) chartsEl.style.display = "";'
            . '        chartInstances["abj404-chart-404s"]      = buildChart("abj404-chart-404s",      "' . $label404      . '", "rgb(0,115,170)",  labels, vals404);'
            . '        chartInstances["abj404-chart-redirects"] = buildChart("abj404-chart-redirects", "' . $labelRedirect . '", "rgb(70,170,100)", labels, valsRedir);'
            . '        chartInstances["abj404-chart-captures"]  = buildChart("abj404-chart-captures",  "' . $labelCapture  . '", "rgb(220,100,50)", labels, valsCapt);'
            . '      })'
            . '      .catch(function() {'
            . '        if (loadEl) loadEl.style.display = "none";'
            . '        if (errEl) errEl.style.display = "";'
            . '      });'
            . '  }'
            . '  function onPeriodChange() { fetchAndRender(); }'
            . '  document.addEventListener("DOMContentLoaded", function() {'
            . '    loadChartJs(fetchAndRender);'
            . '    document.querySelectorAll("input[name=abj404_trend_period]").forEach(function(r) {'
            . '      r.addEventListener("change", onPeriodChange);'
            . '    });'
            . '  });'
            . '})();'
            . '</script>';

        $abj404view->echoOptionsSection(
            'stats-trends',
            'abj404-trendsSection',
            __('Trend Analytics', '404-solution'),
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
                $postTitle = (string)$item['post_title'];
                $brokenUrl = (string)$item['broken_url'];
                $hitCount  = intval($item['hit_count']);
                $postId    = intval($item['post_id']);
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

        echo "</div>";
        echo "</div>";
    }

    /**
     * Build the "Migrate from Another Plugin" card markup.
     * Auto-detects installed redirect plugins and renders a two-step preview+import flow.
     *
     * Step 1: User selects a plugin and clicks "Preview Import" — an AJAX request fetches
     *         the count of available redirects without importing anything.
     * Step 2: The count is shown. If N > 0, a "Confirm Import" button submits the real
     *         import form. If N = 0, "No redirects found" is shown with a Back button.
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

        $previewNonce = wp_create_nonce('abj404_crossPluginPreview');
        $ajaxUrl      = admin_url('admin-ajax.php');

        // Step 1: source selector + Preview button (visible by default)
        $html .= '<div id="abj404-migrate-step1">';
        $html .= '<p>';
        $html .= '<label for="abj404-import-source"><strong>' . esc_html__('Source plugin:', '404-solution') . '</strong></label> ';
        $html .= '<select name="import_source" id="abj404-import-source">';
        foreach ($availableSources as $slug => $label) {
            $html .= '<option value="' . esc_attr($slug) . '">' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        $html .= '</p>';
        $html .= '<p>';
        $html .= '<button type="button" id="abj404-migrate-preview-btn" class="button-secondary">';
        $html .= esc_html__('Preview Import', '404-solution');
        $html .= '</button>';
        $html .= ' <span id="abj404-migrate-preview-spinner" style="display:none;margin-left:6px;" class="spinner is-active"></span>';
        $html .= '</p>';
        $html .= '</div>';

        // Step 2: preview result + confirm form (hidden until preview completes)
        $html .= '<div id="abj404-migrate-step2" style="display:none;">';
        $html .= '<p id="abj404-migrate-preview-msg"></p>';
        $html .= '<form id="abj404-migrate-confirm-form" method="POST" action="' . esc_url($migrateActionUrl) . '" style="display:none;">';
        $html .= '<input type="hidden" name="action" value="importFromPlugin">';
        $html .= '<input type="hidden" name="import_source" id="abj404-migrate-confirm-source" value="">';
        $html .= '<input type="submit" class="button-primary" value="' . esc_attr__('Confirm Import', '404-solution') . '">';
        $html .= ' <button type="button" id="abj404-migrate-back-btn" class="button-secondary">';
        $html .= esc_html__('Back', '404-solution');
        $html .= '</button>';
        $html .= '</form>';
        $html .= '<div id="abj404-migrate-back-noform" style="display:none;">';
        $html .= '<button type="button" id="abj404-migrate-back-btn2" class="button-secondary">';
        $html .= esc_html__('Back', '404-solution');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<p><em>' . esc_html__('This will import all active redirects from the selected plugin into 404 Solution. Regex and redirect codes are preserved.', '404-solution') . '</em></p>';

        // Inline JS for the two-step flow
        $html .= '<script>(function() {';
        $html .= 'var ajaxUrl   = ' . json_encode($ajaxUrl) . ';';
        $html .= 'var nonce     = ' . json_encode($previewNonce) . ';';
        $html .= 'var msgFound  = ' . json_encode(__('Found %d redirect(s) from %s — proceed with import?', '404-solution')) . ';';
        $html .= 'var msgNone   = ' . json_encode(__('No redirects found in %s. Nothing to import.', '404-solution')) . ';';
        $html .= 'var msgError  = ' . json_encode(__('Could not fetch preview. Please try again.', '404-solution')) . ';';
        $html .= 'function showStep1() {';
        $html .= '  document.getElementById("abj404-migrate-step1").style.display = "";';
        $html .= '  document.getElementById("abj404-migrate-step2").style.display = "none";';
        $html .= '}';
        $html .= 'function showStep2(count, source, label) {';
        $html .= '  document.getElementById("abj404-migrate-step1").style.display = "none";';
        $html .= '  document.getElementById("abj404-migrate-step2").style.display = "";';
        $html .= '  var msgEl  = document.getElementById("abj404-migrate-preview-msg");';
        $html .= '  var form   = document.getElementById("abj404-migrate-confirm-form");';
        $html .= '  var noForm = document.getElementById("abj404-migrate-back-noform");';
        $html .= '  if (count > 0) {';
        $html .= '    msgEl.textContent = msgFound.replace("%d", count).replace("%s", label);';
        $html .= '    document.getElementById("abj404-migrate-confirm-source").value = source;';
        $html .= '    form.style.display = "";';
        $html .= '    noForm.style.display = "none";';
        $html .= '  } else {';
        $html .= '    msgEl.textContent = msgNone.replace("%s", label);';
        $html .= '    form.style.display = "none";';
        $html .= '    noForm.style.display = "";';
        $html .= '  }';
        $html .= '}';
        $html .= 'function showError() {';
        $html .= '  document.getElementById("abj404-migrate-step1").style.display = "none";';
        $html .= '  document.getElementById("abj404-migrate-step2").style.display = "";';
        $html .= '  document.getElementById("abj404-migrate-preview-msg").textContent = msgError;';
        $html .= '  document.getElementById("abj404-migrate-confirm-form").style.display = "none";';
        $html .= '  document.getElementById("abj404-migrate-back-noform").style.display = "";';
        $html .= '}';
        $html .= 'document.addEventListener("DOMContentLoaded", function() {';
        $html .= '  var previewBtn = document.getElementById("abj404-migrate-preview-btn");';
        $html .= '  var backBtn    = document.getElementById("abj404-migrate-back-btn");';
        $html .= '  var backBtn2   = document.getElementById("abj404-migrate-back-btn2");';
        $html .= '  if (previewBtn) {';
        $html .= '    previewBtn.addEventListener("click", function() {';
        $html .= '      var select = document.getElementById("abj404-import-source");';
        $html .= '      var source = select ? select.value : "";';
        $html .= '      if (!source) return;';
        $html .= '      var spinner = document.getElementById("abj404-migrate-preview-spinner");';
        $html .= '      if (spinner) spinner.style.display = "";';
        $html .= '      previewBtn.disabled = true;';
        $html .= '      var fd = new FormData();';
        $html .= '      fd.append("action", "abj404_crossPluginPreview");';
        $html .= '      fd.append("nonce", nonce);';
        $html .= '      fd.append("import_source", source);';
        $html .= '      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })';
        $html .= '        .then(function(r) { return r.json(); })';
        $html .= '        .then(function(resp) {';
        $html .= '          if (spinner) spinner.style.display = "none";';
        $html .= '          previewBtn.disabled = false;';
        $html .= '          if (resp && resp.success && resp.data) {';
        $html .= '            showStep2(parseInt(resp.data.count, 10) || 0, resp.data.source, resp.data.label);';
        $html .= '          } else { showError(); }';
        $html .= '        })';
        $html .= '        .catch(function() {';
        $html .= '          if (spinner) spinner.style.display = "none";';
        $html .= '          previewBtn.disabled = false;';
        $html .= '          showError();';
        $html .= '        });';
        $html .= '    });';
        $html .= '  }';
        $html .= '  if (backBtn)  { backBtn.addEventListener("click",  function() { showStep1(); }); }';
        $html .= '  if (backBtn2) { backBtn2.addEventListener("click", function() { showStep1(); }); }';
        $html .= '});';
        $html .= '})();</script>';

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
