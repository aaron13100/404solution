<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Stats methods.
 */
class ABJ_404_Solution_View_Stats extends ABJ_404_Solution_ViewComponent {


    /**
     * Load an HTML template from includes/html/ as a string.
     *
     * Centralized so every section of this class loads templates the same
     * way. Returns the raw template contents; callers perform their own
     * placeholder substitution via Functions::str_replace().
     *
     * @param string $name Filename relative to includes/html/.
     * @return string
     */
    private function tpl($name) {
        return (string)ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/' . $name);
    }

    /**
     * Output the stats page.
     * @return void
     */
    function outputAdminStatsPage() {
        global $abj404view;

        $statsSnapshot = $this->statsRepository->getStatsDashboardSnapshot(true);
        $statsData = $statsSnapshot['data'];
        $statsHash = $statsSnapshot['hash'];

        // Header (container open + h2 + Expand All button).
        $header = $this->tpl('viewStatsPageHeader.html');
        $header = $this->f->str_replace('{title}', esc_html__('Statistics', '404-solution'), $header);
        $header = $this->f->str_replace('{expand_all}', esc_html__('Expand All', '404-solution'), $header);
        echo $header;

        // Config for stale-while-refresh stats snapshot updates (no visible table overwrite).
        $refresh = $this->tpl('viewStatsRefreshConfig.html');
        $refresh = $this->f->str_replace('{refresh_nonce}', esc_attr(wp_create_nonce('abj404_refreshStatsDashboard')), $refresh);
        $refresh = $this->f->str_replace('{current_hash}', esc_attr($statsHash), $refresh);
        $refresh = $this->f->str_replace('{available_text}', esc_attr(__('Refresh available', '404-solution')), $refresh);
        echo $refresh;

        // Flow layout for stats cards.
        echo $this->tpl('viewStatsFlowLayoutOpen.html');

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

        // In Simple mode, replace technical "301"/"302" labels with plain language
        if (abj_service('settings_mode_preference')->getMode() === 'simple') {
            $content = $this->f->str_replace('{Automatic 301 Redirects}', esc_html__('Automatic Permanent Redirects', '404-solution'), $content);
            $content = $this->f->str_replace('{Automatic 302 Redirects}', esc_html__('Automatic Temporary Redirects', '404-solution'), $content);
            $content = $this->f->str_replace('{Manual 301 Redirects}', esc_html__('Manual Permanent Redirects', '404-solution'), $content);
            $content = $this->f->str_replace('{Manual 302 Redirects}', esc_html__('Manual Temporary Redirects', '404-solution'), $content);
        }

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

        // Match Confidence distribution card (full-width)
        $this->echoConfidenceDistributionSection();

        // Trend Analytics section (full-width, below the flow layout cards)
        $this->echoTrendsSection();

        // Broken Internal Links section
        $this->echoBrokenInternalLinksSection();

        // Closes flow layout, settings content, and container in that order.
        echo $this->tpl('viewStatsPageFooter.html');
    }

    /**
     * Output the Match Confidence distribution card on the Stats page.
     * Queries the redirects table for score band counts and renders a Chart.js doughnut.
     * @return void
     */
    public function echoConfidenceDistributionSection() {
        global $abj404view, $wpdb;

        if (!isset($wpdb)) {
            return;
        }

        $dbCore = abj_service('db_core');
        $redirectsTable = $dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        // Query score distribution bands. Route through the DAO so the
        // 5x SUM(CASE...) aggregate inherits the centralized 60s SELECT
        // timeout — the redirects table can be very large on busy sites.
        $high = ABJ_404_Solution_ScoreThresholds::HIGH;
        $medium = ABJ_404_Solution_ScoreThresholds::MEDIUM;
        $sql = "SELECT
               SUM(CASE WHEN score IS NULL THEN 1 ELSE 0 END) AS manual_count,
               SUM(CASE WHEN score >= {$high} THEN 1 ELSE 0 END) AS high_count,
               SUM(CASE WHEN score >= {$medium} AND score < {$high} THEN 1 ELSE 0 END) AS medium_count,
               SUM(CASE WHEN score IS NOT NULL AND score < {$medium} THEN 1 ELSE 0 END) AS low_count,
               AVG(score) AS avg_score
             FROM `{$redirectsTable}`
             WHERE disabled = %d AND status != %d";

        $result = $dbCore->queryAndGetResults($sql, array('query_params' => array(0, 0)));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return;
        }
        $row = $rows[0];

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

        // Configuration carrier for statsConfidenceChart.js. The external JS
        // reads labels + band counts from this canvas's data attribute, so
        // PHP doesn't need to inline any JavaScript.
        $confidenceConfig = wp_json_encode(array(
            'labelHigh'   => $labelHigh,
            'labelMedium' => $labelMedium,
            'labelLow'    => $labelLow,
            'labelManual' => $labelManual,
            'high'        => $highCount,
            'medium'      => $mediumCount,
            'low'         => $lowCount,
            'manual'      => $manualCount,
        ));

        // Confidence chart rendering moved to includes/js/statsConfidenceChart.js.
        // The canvas in the template carries its config via data-abj404-confidence.
        $content = $this->tpl('viewStatsConfidenceDistribution.html');
        $content = $this->f->str_replace('{avg_label}', $avgLabel, $content);
        $content = $this->f->str_replace('{confidence_config}', esc_attr((string)$confidenceConfig), $content);
        $content = $this->f->str_replace('{label_high}', esc_html($labelHigh), $content);
        $content = $this->f->str_replace('{label_medium}', esc_html($labelMedium), $content);
        $content = $this->f->str_replace('{label_low}', esc_html($labelLow), $content);
        $content = $this->f->str_replace('{label_manual}', esc_html($labelManual), $content);
        $content = $this->f->str_replace('{high_count}', esc_html((string)$highCount), $content);
        $content = $this->f->str_replace('{medium_count}', esc_html((string)$mediumCount), $content);
        $content = $this->f->str_replace('{low_count}', esc_html((string)$lowCount), $content);
        $content = $this->f->str_replace('{manual_count}', esc_html((string)$manualCount), $content);

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
    public function echoTrendsSection() {
        global $abj404view;

        $trendNonce = wp_create_nonce('abj404_trendData');
        $ajaxUrl = admin_url('admin-ajax.php');

        $label7d  = esc_html__('7 days', '404-solution');
        $label30d = esc_html__('30 days', '404-solution');
        $label90d = esc_html__('90 days', '404-solution');

        // Trend chart rendering moved to includes/js/statsTrends.js. We emit a
        // small JSON config carrier; the external JS reads ajaxUrl/nonce/labels
        // from #abj404-trends-config's data attribute.
        $trendsConfig = wp_json_encode(array(
            'ajaxUrl'       => $ajaxUrl,
            'nonce'         => $trendNonce,
            'label404'      => __('404 Hits per Day', '404-solution'),
            'labelRedirect' => __('Redirects per Day', '404-solution'),
            'labelCapture'  => __('New Captures per Day', '404-solution'),
        ));

        $trendsContent = $this->tpl('viewStatsTrendsSection.html');
        $trendsContent = $this->f->str_replace('{period_aria_label}', esc_attr__('Period', '404-solution'), $trendsContent);
        $trendsContent = $this->f->str_replace('{label_7d}', $label7d, $trendsContent);
        $trendsContent = $this->f->str_replace('{label_30d}', $label30d, $trendsContent);
        $trendsContent = $this->f->str_replace('{label_90d}', $label90d, $trendsContent);
        $trendsContent = $this->f->str_replace('{loading_text}', esc_html__('Loading chart data…', '404-solution'), $trendsContent);
        $trendsContent = $this->f->str_replace('{error_text}', esc_html__('Could not load chart data.', '404-solution'), $trendsContent);
        $trendsContent = $this->f->str_replace('{trends_config}', esc_attr((string)$trendsConfig), $trendsContent);

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
    public function echoBrokenInternalLinksSection() {
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
            $content = $this->tpl('viewStatsBrokenLinksEmpty.html');
            $content = $this->f->str_replace('{empty_text}', esc_html__('No broken internal links found.', '404-solution'), $content);
        } else {
            $postCount = count(array_unique(array_column($results, 'post_id')));
            $rowLinkedTpl = $this->tpl('viewStatsBrokenLinksRowLinked.html');
            $rowPlainTpl  = $this->tpl('viewStatsBrokenLinksRowPlain.html');
            $rowsHtml = '';
            foreach ($results as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $postTitle = (string)$item['post_title'];
                $brokenUrl = (string)$item['broken_url'];
                $hitCount  = intval($item['hit_count']);
                $postId    = intval($item['post_id']);
                $editLink  = ($postId > 0) ? get_edit_post_link($postId) : '';
                if ($editLink) {
                    $row = $this->f->str_replace('{edit_link}', esc_url($editLink), $rowLinkedTpl);
                    $row = $this->f->str_replace('{post_title}', esc_html($postTitle), $row);
                } else {
                    $row = $this->f->str_replace('{post_title}', esc_html($postTitle), $rowPlainTpl);
                }
                $row = $this->f->str_replace('{broken_url}', esc_html($brokenUrl), $row);
                $row = $this->f->str_replace('{hit_count}', esc_html((string)$hitCount), $row);
                $rowsHtml .= $row;
            }
            $summary = esc_html(sprintf(
                /* translators: 1: number of broken links, 2: number of posts/pages */
                __('Found %1$d broken internal link(s) across %2$d post(s)/page(s).', '404-solution'),
                count($results),
                $postCount
            ));
            $content = $this->tpl('viewStatsBrokenLinksTable.html');
            $content = $this->f->str_replace('{summary_text}', $summary, $content);
            $content = $this->f->str_replace('{th_post}', esc_html__('Post/Page', '404-solution'), $content);
            $content = $this->f->str_replace('{th_broken_url}', esc_html__('Broken URL', '404-solution'), $content);
            $content = $this->f->str_replace('{th_hits}', esc_html__('404 Hits', '404-solution'), $content);
            $content = $this->f->str_replace('{rows}', $rowsHtml, $content);
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
        $isPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
        if ($isPluginAdmin) {
        	$filesToEcho = array($this->logger->getDebugFilePath(),
        			$this->logger->getDebugFilePathOld());
        	$wrapperTpl = $this->tpl('viewStatsDebugFileWrapper.html');
        	for ($i = 0; $i < count($filesToEcho); $i++) {
        		$currentFile = $filesToEcho[$i];
        		// Capture file contents into a buffer so they can be placed inside
        		// the wrapper template; preserves the exact rendered structure.
        		ob_start();
        		$this->echoFileContents($currentFile);
        		$contents = (string)ob_get_clean();
        		$row = $this->f->str_replace('{file_name}', esc_html((string)$currentFile), $wrapperTpl);
        		// {contents} carries already-escaped HTML (nl2br + esc_html) from echoFileContents.
        		$row = $this->f->str_replace('{contents}', $contents, $row);
        		echo $row;
        	}

	        } else {
	        	echo "Non-admin request to view debug file.";
	        	$current_user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
	        	$userLogin = $current_user !== null ? $current_user->getLogin() : '';
	        	$userDisplay = $current_user !== null ? $current_user->getDisplayName() : '';
	        	$userEmail = $current_user !== null ? $current_user->getEmail() : '';
	        	$userId = $current_user !== null ? $current_user->getId() : 0;
	        	$userInfo = "Login: " . $userLogin . ", display name: " .
	         		$userDisplay . ", Email: " . $userEmail .
	         		", UserID: " . $userId;
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
    				$truncatedTpl = $this->tpl('viewStatsDebugFileTruncated.html');
    				while (($line = fgets($handle)) !== false) {
    					$linesRead++;
    					echo nl2br(esc_html($line));

    					if ($linesRead > 1000000) {
    						echo $this->f->str_replace('{lines_read}', esc_html((string)$linesRead), $truncatedTpl);
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
        $view = $this->view;

        // Tools-page header (container open + h2 + Expand All button).
        $header = $this->tpl('viewStatsToolsPageHeader.html');
        $header = $this->f->str_replace('{title}', esc_html__('Tools', '404-solution'), $header);
        $header = $this->f->str_replace('{expand_all}', esc_html__('Expand All', '404-solution'), $header);
        echo $header;

        // Export Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_exportRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsExportForm.html");
        $html = $this->f->str_replace('{toolsExportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection('tools-export', 'abj404-exportRedirects', __('Export', '404-solution'), $html, true, $view->getCardIcon('download'));

        // Import Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_importRedirectsFile");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsImportForm.html");
        $html = $this->f->str_replace('{toolsImportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection('tools-import', 'abj404-importRedirects', __('Import', '404-solution'), $html, false, $view->getCardIcon('upload'));

        // Purge Card
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsPurgeForm.html");
        $html = $this->f->str_replace('{toolsPurgeFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection('tools-purge', 'abj404-purgeRedirects', __('Purge Options', '404-solution'), $html, false, $view->getCardIcon('trash'));

        // Cache Management Card
        $ngramLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_rebuildNgramCache");
        $spellingLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_clearSpellingCache");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsCacheForm.html");
        $html = $this->f->str_replace('{toolsNgramCacheFormActionLink}', $ngramLink, $html);
        $html = $this->f->str_replace('{toolsSpellingCacheFormActionLink}', $spellingLink, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection('tools-cache', 'abj404-cacheTools', __('Cache Management', '404-solution'), $html, false, $view->getCardIcon('database'));

        // Diagnostics Card
        $html = $this->getToolsDiagnosticsMarkup();
        $view->echoOptionsSection('tools-diagnostics', 'abj404-diagnosticsTools', __('Diagnostics', '404-solution'), $html, false, $view->getCardIcon('warning'));

        // Etcetera Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        $link .= '&manually_fired=true';
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsEtcForm.html");
        $html = $this->f->str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection('tools-etc', 'abj404-etcTools', __('Etcetera', '404-solution'), $html, false, $view->getCardIcon('cog'));

        // Migrate from Another Plugin Card
        $html = $this->getMigrateFromPluginMarkup();
        $view->echoOptionsSection('tools-migrate', 'abj404-migrateFromPlugin', __('Migrate from Another Plugin', '404-solution'), $html, false, $view->getCardIcon('upload'));

        // Closes settings content + container.
        echo $this->tpl('viewStatsToolsPageFooter.html');
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
    public function getMigrateFromPluginMarkup(): string {
        $redirectsRepo = abj_service('redirects_repository');
        $logger        = abj_service('logging');
        $importer = new ABJ_404_Solution_CrossPluginImporter($redirectsRepo, $logger);

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

        if (empty($availableSources)) {
            $html = $this->tpl('viewStatsMigrateEmpty.html');
            $html = $this->f->str_replace('{empty_text}', esc_html__('No supported redirect plugins detected on this site.', '404-solution'), $html);
            $html = $this->f->str_replace('{supported_text}', esc_html__('Supported plugins: Rank Math, Yoast SEO Premium, AIOSEO, Safe Redirect Manager, Redirection.', '404-solution'), $html);
            return $html;
        }

        $detectedNames = array_values($availableSources);

        $previewNonce = wp_create_nonce('abj404_crossPluginPreview');
        $ajaxUrl      = admin_url('admin-ajax.php');

        // Build the <option> list for the source selector.
        $optionTpl = $this->tpl('viewStatsMigrateOption.html');
        $sourceOptionsHtml = '';
        foreach ($availableSources as $slug => $label) {
            $opt = $this->f->str_replace('{slug}', esc_attr((string)$slug), $optionTpl);
            $opt = $this->f->str_replace('{label}', esc_html((string)$label), $opt);
            $sourceOptionsHtml .= $opt;
        }

        // Two-step flow JS moved to includes/js/toolsMigratePlugin.js. Emit a
        // JSON config carrier; the external JS reads ajaxUrl/nonce/messages
        // from #abj404-migrate-config's data attribute.
        // allow-em-dash: preserving shipped translation string verbatim (extracted, not authored, here)
        $msgFound = __('Found %d redirect(s) from %s — proceed with import?', '404-solution');
        $migrateConfig = wp_json_encode(array(
            'ajaxUrl'  => $ajaxUrl,
            'nonce'    => $previewNonce,
            'msgFound' => $msgFound,
            'msgNone'  => __('No redirects found in %s. Nothing to import.', '404-solution'),
            'msgError' => __('Could not fetch preview. Please try again.', '404-solution'),
        ));

        $html = $this->tpl('viewStatsMigrateForm.html');
        $html = $this->f->str_replace('{detected_label}', esc_html__('Detected redirect plugins:', '404-solution'), $html);
        $html = $this->f->str_replace('{detected_names}', esc_html(implode(', ', $detectedNames)), $html);
        $html = $this->f->str_replace('{source_label}', esc_html__('Source plugin:', '404-solution'), $html);
        $html = $this->f->str_replace('{source_options}', $sourceOptionsHtml, $html);
        $html = $this->f->str_replace('{preview_text}', esc_html__('Preview Import', '404-solution'), $html);
        $html = $this->f->str_replace('{action_url}', esc_url($migrateActionUrl), $html);
        $html = $this->f->str_replace('{confirm_text}', esc_attr__('Confirm Import', '404-solution'), $html);
        $html = $this->f->str_replace('{back_text}', esc_html__('Back', '404-solution'), $html);
        $html = $this->f->str_replace('{note_text}', esc_html__('This will import all active redirects from the selected plugin into 404 Solution. Regex and redirect codes are preserved.', '404-solution'), $html);
        $html = $this->f->str_replace('{migrate_config}', esc_attr((string)$migrateConfig), $html);

        return $html;
    }

    /**
     * Build compact diagnostics markup for the Tools page.
     * This is intentionally read-only and lightweight.
     *
     * @return string
     */
    public function getToolsDiagnosticsMarkup() {
        $rows = $this->getToolsDiagnosticsRows();
        $rowTpl = $this->tpl('viewStatsDiagnosticsRow.html');
        $rowsHtml = '';

        foreach ($rows as $row) {
            $label = array_key_exists('label', $row) ? $row['label'] : '';
            $value = array_key_exists('value', $row) ? $row['value'] : '';
            $valueHtml = array_key_exists('value_html', $row) ? $row['value_html'] : '';
            $status = array_key_exists('status', $row) ? $row['status'] : 'info';
            $statusLabel = ($status === 'ok') ? __('OK', '404-solution') : (($status === 'warn') ? __('Warning', '404-solution') : __('Info', '404-solution'));
            $statusClass = ($status === 'ok') ? 'abj404-pill-success' : (($status === 'warn') ? 'abj404-pill-warning' : 'abj404-pill-info');

            $valueCell = ($valueHtml !== '') ? wp_kses_post($valueHtml) : esc_html($value);

            $rowHtml = $this->f->str_replace('{label}', esc_html($label), $rowTpl);
            $rowHtml = $this->f->str_replace('{value_cell}', $valueCell, $rowHtml);
            $rowHtml = $this->f->str_replace('{status_class}', esc_attr($statusClass), $rowHtml);
            $rowHtml = $this->f->str_replace('{status_label}', esc_html($statusLabel), $rowHtml);
            $rowsHtml .= $rowHtml;
        }

        $html = $this->tpl('viewStatsDiagnosticsTable.html');
        $html = $this->f->str_replace('{intro_text}', esc_html__('Quick environment checks for troubleshooting and support.', '404-solution'), $html);
        $html = $this->f->str_replace('{rows}', $rowsHtml, $html);

        return $html;
    }

    /**
     * Collect diagnostics fields displayed on the Tools page.
     *
     * @return array<int, array<string, string>>
     */
    public function getToolsDiagnosticsRows() {
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
            $valueText = ($latencyMs > 0)
                ? sprintf(__('ON (%d ms per plugin query)', '404-solution'), $latencyMs)
                : __('OFF', '404-solution');
            $controlsHtml = $this->tpl('viewStatsDiagnosticsLatencyControls.html');
            $controlsHtml = $this->f->str_replace('{value_text}', esc_html($valueText), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{url_250}', esc_url($latencyUrls[250]), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{url_500}', esc_url($latencyUrls[500]), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{url_900}', esc_url($latencyUrls[900]), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{url_0}', esc_url($latencyUrls[0]), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{label_250}', esc_html(__('250ms', '404-solution')), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{label_500}', esc_html(__('500ms', '404-solution')), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{label_900}', esc_html(__('900ms', '404-solution')), $controlsHtml);
            $controlsHtml = $this->f->str_replace('{label_disable}', esc_html(__('Disable', '404-solution')), $controlsHtml);
            $rows[] = array(
                'label' => __('Simulated DB Latency', '404-solution'),
                'value' => $valueText,
                'value_html' => $controlsHtml,
                'status' => ($latencyMs > 0) ? 'warn' : 'info',
            );
        }

        return $rows;
    }


}
