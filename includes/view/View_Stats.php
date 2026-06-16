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
        return (string)ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name);
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

        $content = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/statsRedirectsBox.html");

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
        $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('stats-redirects', 'abj404-redirectStats', __('Redirects', '404-solution'), $content, true, $abj404view->getCardIcon('chart')));

        // Captured URLs Statistics Card
        $capturedStats = (is_array($statsData) && isset($statsData['captured']) && is_array($statsData['captured']))
            ? $statsData['captured']
            : array();
        $captured = intval($capturedStats['captured'] ?? 0);
        $ignored = intval($capturedStats['ignored'] ?? 0);
        $trashed = intval($capturedStats['trashed'] ?? 0);

        $total = $captured + $ignored + $trashed;

        $content = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/statsCapturedURLsBox.html");
        $content = $this->f->str_replace('{captured}', esc_html((string)$captured), $content);
        $content = $this->f->str_replace('{ignored}', esc_html((string)$ignored), $content);
        $content = $this->f->str_replace('{trashed}', esc_html((string)$trashed), $content);
        $content = $this->f->str_replace('{total}', esc_html((string)$total), $content);
        $content = $this->f->doNormalReplacements($content);
        $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('stats-captured', 'abj404-capturedStats', __('Captured URLs', '404-solution'), $content, true, $abj404view->getCardIcon('warning')));

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

            $content = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/statsPeriodicBox.html");
            $content = $this->f->str_replace('{disp404}', esc_html((string)$disp404), $content);
            $content = $this->f->str_replace('{distinct404}', esc_html((string)$distinct404), $content);
            $content = $this->f->str_replace('{visitors404}', esc_html((string)$visitors404), $content);
            $content = $this->f->str_replace('{refer404}', esc_html((string)$refer404), $content);
            $content = $this->f->str_replace('{redirected}', esc_html((string)$redirected), $content);
            $content = $this->f->str_replace('{distinctredirected}', esc_html((string)$distinctredirected), $content);
            $content = $this->f->str_replace('{distinctvisitors}', esc_html((string)$distinctvisitors), $content);
            $content = $this->f->str_replace('{distinctrefer}', esc_html((string)$distinctrefer), $content);
            $content = $this->f->doNormalReplacements($content);
            $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('stats-periodic-' . $x, 'abj404-stats' . $x, $title, $content, ($x == 0), $abj404view->getCardIcon('clock')));
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
     * Asks the stats repository for the score-band counts and renders them
     * into the Chart.js doughnut card. Presentation only: the SQL, table name,
     * and HIGH/MEDIUM thresholds live in
     * ABJ_404_Solution_StatsReadRepository::getConfidenceBandCounts().
     * @return void
     */
    public function echoConfidenceDistributionSection() {
        global $abj404view;

        // Ask the stats repository for the band counts. The repository owns the
        // table name, the SQL, and the HIGH/MEDIUM thresholds; this view method
        // only formats the result into the card template.
        $bands = $this->statsRepository->getConfidenceBandCounts();
        if (!is_array($bands) || (int)($bands['total'] ?? 0) === 0) {
            return;
        }

        $highCount   = (int)($bands['high']   ?? 0);
        $mediumCount = (int)($bands['medium'] ?? 0);
        $lowCount    = (int)($bands['low']    ?? 0);
        $manualCount = (int)($bands['manual'] ?? 0);
        $avgRaw      = $bands['avg'] ?? null;
        $avgScore    = is_numeric($avgRaw) ? (float)$avgRaw : null;

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

        $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
            'stats-confidence',
            'abj404-confidenceSection',
            __('Match Confidence', '404-solution'),
            $content,
            false,
            $abj404view->getCardIcon('check')
        ));
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

        $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
            'stats-trends',
            'abj404-trendsSection',
            __('Trend Analytics', '404-solution'),
            $trendsContent,
            false,
            $abj404view->getCardIcon('chart')
        ));
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

        $abj404view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
            'stats-broken-links',
            'abj404-brokenLinksSection',
            __('Broken Internal Links', '404-solution'),
            $content,
            false,
            $abj404view->getCardIcon('warning')
        ));
    }

}
