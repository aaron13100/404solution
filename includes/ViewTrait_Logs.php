<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Logs methods.
 */
trait ViewTrait_Logs {

    
    /**
     * @return void
     */
    function echoAdminLogsPage() {

        $sub = 'abj404_logs';
        $tableOptions = $this->logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $this->logic->sanitizePostData($tableOptions);

        $timezoneRaw = get_option('timezone_string'); $timezone = is_string($timezoneRaw) ? $timezoneRaw : '';
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // Modern page wrapper
        echo '<div class="abj404-table-page">';

        // Header with page title
        echo '<div class="abj404-table-header">';
        echo '<h2>' . __('Redirect Logs', '404-solution') . '</h2>';
        echo '</div>';

        // Filter bar with search dropdown
        echo '<div class="abj404-filter-bar">';

        // Log search form
        echo '<form id="logs_search_form" name="admin-logs-page" method="GET" action="" class="abj404-logs-search-form">';
        echo '<input type="hidden" name="page" value="' . ABJ404_PP . '">';
        echo '<input type="hidden" name="subpage" value="abj404_logs">';

        // ----------------- dropdown search box. begin.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/viewLogsForSearchBox.html");

        $redirectPageTitle = $this->dao->getPostOrGetSanitize('redirect_to_data_field_title');
        $pageIDAndType = $this->dao->getPostOrGetSanitize('redirect_to_data_field_id');

        $html = $this->f->str_replace('{redirect_to_label}', __('View logs for', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Begin typing a URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(Please choose from the dropdown list instead of typing your own URL.)', '404-solution'), $html);
        $html = $this->f->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $html);
        $html = $this->f->str_replace('{redirectPageTitle}', esc_attr($redirectPageTitle), $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoViewLogsFor&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        echo $html;
        // ----------------- dropdown search box. end.

        echo '</form>';

        // Rows per page
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . __('Rows per page:', '404-solution') . '</span>';
        echo '<select onchange="window.location.href=this.value">';
        $perPageOptions = array(10, 25, 50, 100, 250);
        foreach ($perPageOptions as $opt) {
            $perpage = array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;
            $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'timestamp';
            $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'DESC';
            $selected = ($perpage == $opt) ? ' selected' : '';
            $url = "?page=" . ABJ404_PP . "&subpage=abj404_logs" .
                   "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order) . "&perpage=" . $opt;
            echo '<option value="' . esc_url($url) . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div><!-- .abj404-filter-bar -->';

        // Table
        echo $this->getAdminLogsPageTable($sub);

        // Pagination (AJAX-capable, includes background refresh config)
        echo $this->getPaginationLinks($sub, false);

        echo '</div><!-- .abj404-table-page -->';
    }
    
    /**
     * @param string $sub
     * @return string
     */
    function getAdminLogsPageTable($sub) {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting
        // Engine is displayed inside the Action cell; User is displayed inside the Date cell
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'host' => array('title' => __('IP Address', '404-solution'), 'orderby' => 'remote_host'),
            'refer' => array('title' => __('Referrer', '404-solution'), 'orderby' => 'referrer'),
            'dest' => array('title' => __('Action', '404-solution'), 'orderby' => 'action'),
            'timestamp' => array('title' => __('Date', '404-solution'), 'orderby' => 'timestamp'),
        );

        $html = '<table class="abj404-table abj404-logs-table">';
        $html .= '<thead><tr>';

        // Detail toggle column (not sortable)
        $html .= '<th scope="col" class="abj404-trace-th"></th>';

        // Generate sortable column headers
        foreach ($columns as $key => $col) {
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_logs";
            $sortUrl .= "&orderby=" . $col['orderby'];
            $sortState = $this->getHeaderSortState($tableOptions, (string)$col['orderby'], false);
            $newOrder = $sortState['nextOrder'];
            $sortUrl .= "&order=" . $newOrder;

            $sortClass = trim($sortState['thClass']);
            $sortClassAttr = ($sortClass !== '') ? ' class="' . $sortClass . '"' : '';
            $sortIndicator = $sortState['indicator'];

            $html .= '<th scope="col"' . $sortClassAttr . '><a href="' . esc_url($sortUrl) . '">' . esc_html($col['title']) . $sortIndicator . '</a></th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody id="the-list">';

        $rows = $this->dao->getLogRecords($tableOptions);
        /** @var array<int, array<string, mixed>> $typedLogRows */
        $typedLogRows = array_values(array_filter($rows, 'is_array'));
        $this->rememberTableDataSignature($sub, $typedLogRows);
        $logRecordsDisplayed = 0;

        foreach ($typedLogRows as $row) {
            $logId = is_scalar($row['log_id'] ?? '') ? (string)($row['log_id'] ?? '') : '';
            $rawTrace = isset($row['pipeline_trace']) && is_string($row['pipeline_trace']) ? $row['pipeline_trace'] : null;
            $traceSteps = ABJ_404_Solution_DataAccess::decompressPipelineTrace($rawTrace);
            $hasTrace = is_array($traceSteps) && !empty($traceSteps);

            $html .= '<tr>';

            // Detail toggle button
            $html .= '<td class="abj404-trace-toggle-cell">';
            if ($hasTrace) {
                $html .= '<button class="abj404-trace-toggle" data-row-id="' . esc_attr($logId) . '" title="' . esc_attr__('Show pipeline trace', '404-solution') . '">&#x25B6;</button>';
            } else {
                $html .= '<button class="abj404-trace-toggle" disabled title="' . esc_attr__('No trace available', '404-solution') . '">&#x25B6;</button>';
            }
            $html .= '</td>';

	            // URL column
	            $url = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
	            $urlDetail = is_string($row['url_detail'] ?? '') ? (string)($row['url_detail'] ?? '') : '';
	            $fullVisitorURL = esc_url(home_url($url));
	            $urlDisplay = '<a href="' . $fullVisitorURL . '" target="_blank" title="' . esc_attr($url) . '">' . esc_html($url) . '</a>';
	            if ($urlDetail !== '' && trim($urlDetail) !== '') {
	                $urlDisplay .= ' <span class="abj404-url-detail">(' . esc_html(trim($urlDetail)) . ')</span>';
	            }
	            $html .= '<td class="abj404-url-cell">' . $urlDisplay . '</td>';

	            // IP Address
	            $remoteHost = is_string($row['remote_host'] ?? '') ? (string)($row['remote_host'] ?? '') : '';
	            $html .= '<td class="abj404-ip-cell">' . esc_html($remoteHost) . '</td>';

	            // Referrer
	            $referrer = is_string($row['referrer'] ?? '') ? (string)($row['referrer'] ?? '') : '';
	            $html .= '<td class="abj404-url-cell">';
	            if ($referrer != "") {
	                $html .= '<a href="' . esc_url($referrer) . '" title="' . esc_attr($referrer) . '" target="_blank">' . esc_html($referrer) . '</a>';
	            } else {
	                $html .= '<span class="abj404-text-muted">-</span>';
	            }
	            $html .= '</td>';

	            // Action Taken (with engine on second line)
	            $action = trim(is_string($row['action'] ?? '') ? (string)($row['action'] ?? '') : '');
	            $engineVal = is_string($row['engine'] ?? '') ? (string)($row['engine'] ?? '') : '';
	            $html .= '<td>';
	            if ($action === '' || $action == "404" || $action == "http://404") {
	                $html .= '<span class="abj404-badge abj404-badge-404">' . __('404', '404-solution') . '</span>';
	            } else {
	                $html .= '<span class="abj404-badge abj404-badge-redirect">' . __('Redirect', '404-solution') . '</span>';
	                $html .= '<br><a href="' . esc_url($action) . '" title="' . esc_attr($action) . '" target="_blank" class="abj404-action-url">' . esc_html($action) . '</a>';
	            }
	            if ($engineVal !== '') {
	                $html .= '<br><span class="abj404-engine-label">' . esc_html($engineVal) . '</span>';
	            }
	            $html .= '</td>';

	            // Date (with user on second line)
	            $timeToDisplay = abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0);
	            $rowUsername = is_string($row['username'] ?? '') ? (string)($row['username'] ?? '') : '';
	            $html .= '<td class="abj404-date-cell">' . date('Y/m/d', $timeToDisplay) . '<br>' . date('h:i:s A', $timeToDisplay);
	            if ($rowUsername !== '') {
	                $html .= '<br><span class="abj404-username-label">' . esc_html($rowUsername) . '</span>';
	            }
	            $html .= '</td>';

            $html .= '</tr>';

            // Hidden detail row with pipeline trace
            if ($hasTrace && $logId !== '') {
                $html .= '<tr id="abj404-trace-' . esc_attr($logId) . '" class="abj404-trace-detail" style="display:none">';
                $html .= '<td colspan="6" class="abj404-trace-detail-cell">';
                $html .= '<ol class="abj404-trace-steps">';
                foreach ($traceSteps as $step) {
                    $stepName = esc_html($step['step']);
                    $outcome  = esc_html($step['outcome']);
                    $detail   = $step['detail'] !== ''
                        ? ' <em class="abj404-trace-detail-text">(' . esc_html($step['detail']) . ')</em>' : '';
                    $html .= '<li><strong>' . $stepName . '</strong> &rarr; ' . $outcome . $detail . '</li>';
                }
                $html .= '</ol>';
                $html .= '</td>';
                $html .= '</tr>';
            }

            $logRecordsDisplayed++;
        }

        $this->logger->debugMessage($logRecordsDisplayed . " log records displayed on the page.");

        if ($logRecordsDisplayed == 0) {
            $html .= '<tr><td colspan="6" class="abj404-empty-message">' . __('No Results To Display', '404-solution') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        // Inline JS for trace row expand/collapse (output once per page)
        $html .= '<script>
(function() {
    if (window._abj404TraceToggleInit) { return; }
    window._abj404TraceToggleInit = true;
    document.addEventListener("click", function(e) {
        var btn = e.target;
        if (!btn || !btn.classList || !btn.classList.contains("abj404-trace-toggle")) { return; }
        var rowId = btn.getAttribute("data-row-id");
        if (!rowId) { return; }
        var detail = document.getElementById("abj404-trace-" + rowId);
        if (!detail) { return; }
        var visible = detail.style.display !== "none";
        detail.style.display = visible ? "none" : "";
        btn.textContent = visible ? "\u25B6" : "\u25BC";
    });
}());
</script>';

        return $html;
    }

    /**
     * @param string $sub
     * @param array<string, array<string, string>> $columns
     * @return string
     */
    function getTableColumns($sub, $columns) {
        $tableOptions = $this->logic->getTableOptions($sub);
        
        $html = "<tr>";
        
        $cbinfo = 'class="manage-column column-cb check-column" style="{cb-info-style}"';
        $cbinfoStyle = 'vertical-align: middle; padding-bottom: 6px;';
        if ($sub == 'abj404_logs') {
            $cbinfoStyle .= ' width: 0px;';
        }
        $cbinfo = $this->f->str_replace('{cb-info-style}', $cbinfoStyle, $cbinfo);
        
        $html .= "<th scope=\"col\" " . $cbinfo . ">";
        if ($sub != 'abj404_logs') {
            $html .= "<input type=\"checkbox\" name=\"bulkSelectorCheckbox\" onchange=\"enableDisableApplyButton();\" aria-label=\"" . esc_attr__('Select all', '404-solution') . "\">";
        }
        $html .= "</th>";

        foreach ($columns as $column) {
            $style = "";
            if (isset($column['width']) && $column['width'] != "") {
                $style = " style=\"width: " . esc_attr($column['width']) . ";\" ";
            }
            $nolink = 0;
            $sortorder = "";
            $sortIndicator = '';
            $orderby = isset($column['orderby']) ? $column['orderby'] : '';
            $preferDescOnFirstClick = ($orderby == "timestamp" ||
                    $orderby == "last_used" ||
                    $orderby == "logshits");
            $sortState = $this->getHeaderSortState($tableOptions, (string)$orderby, $preferDescOnFirstClick);
            if (!$sortState['isSortable']) {
                $thClass = "";
                $nolink = 1;
            } else {
                $thClass = " " . $sortState['thClass'];
                $sortorder = $sortState['nextOrder'];
                $sortIndicator = $sortState['indicator'];
            }

            $url = "?page=" . ABJ404_PP;
            if ($sub !== '') {
                $url .= "&subpage=" . rawurlencode((string)$sub);
            }
            if ($sub == 'abj404_logs') {
                $url .= "&id=" . ($tableOptions['logsid'] ?? 0);
            }
            if (($tableOptions['filter'] ?? 0) != 0) {
                $url .= "&filter=" . $tableOptions['filter'];
            }
            $url .= "&orderby=" . $orderby . "&order=" . $sortorder;

            $tooltipHtml = '';
            if (array_key_exists('title_attr_html', $column) && !empty($column['title_attr_html'])) {
                // Raw HTML (already escaped where needed)
                $tooltipHtml = '<span class="abj404-header-tooltip lefty-tooltip" aria-label="' . esc_attr__('More info', '404-solution') . '">' .
                        '<span class="abj404-header-tooltip-icon" aria-hidden="true">?</span>' .
                        '<span class="lefty-tooltiptext">' . $column['title_attr_html'] . '</span>' .
                        '</span>' . "\n";
            } elseif (array_key_exists('title_attr', $column) && !empty($column['title_attr'])) {
                // Plain text - escape it
                $tooltipHtml = '<span class="abj404-header-tooltip lefty-tooltip" aria-label="' . esc_attr__('More info', '404-solution') . '">' .
                        '<span class="abj404-header-tooltip-icon" aria-hidden="true">?</span>' .
                        '<span class="lefty-tooltiptext">' . esc_html($column['title_attr']) . '</span>' .
                        '</span>' . "\n";
            }

            // Support custom column classes (e.g., hide-on-tablet, hide-on-mobile)
            if (isset($column['class']) && $column['class'] != '') {
                $thClass .= ' ' . esc_attr($column['class']);
            }

            $html .= "<th scope=\"col\" " . $style . " class=\"manage-column column-title" . $thClass . "\"> \n";

            $title = isset($column['title']) ? $column['title'] : '';
            if ($nolink == 1) {
                $html .= $title;
                $html .= $tooltipHtml;
            } else {
                $html .= "<a href=\"" . esc_url($url) . "\">";
                $html .= '<span class="table_header_' . $orderby . '">' .
                        esc_html($title) . $sortIndicator . "</span>";
                $html .= "</a>";
                $html .= $tooltipHtml;
            }
            $html .= "</th>";
        }
        $html .= "</tr>";

        return $html;
    }

    /**
     * @param string $sub
     * @param bool $showSearchFilter
     */
	    /**
	     * @param string $sub
	     * @param bool $showSearchFilter
	     * @return string
	     */
	    function getPaginationLinks($sub, $showSearchFilter = true) {
	        
	        $tableOptions = $this->logic->getTableOptions($sub);
	        $logsid = array_key_exists('logsid', $tableOptions) && is_scalar($tableOptions['logsid']) ? $tableOptions['logsid'] : 0;
	        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
	        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
	        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;

	        $url = "?page=" . ABJ404_PP;
	        if ($sub !== '') {
	            $url .= "&subpage=" . rawurlencode((string)$sub);
	        }
	        if ($sub == 'abj404_logs') {
	            $url .= "&id=" . $logsid;
	        }

	        $url .= "&orderby=" . sanitize_text_field($orderby);
	        $url .= "&order=" . sanitize_text_field($order);
	        $url .= "&filter=" . absint((int)$filter);

	        if ($sub == 'abj404_logs') {
	            $num_records = $this->dao->getLogsCount((int)$logsid);
	        } else {
	            $num_records = $this->dao->getRedirectsForViewCount($sub, $tableOptions);
	        }

        // Ensure perpage is never 0 to prevent division by zero
        $perpage = absint(array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : ABJ404_OPTION_MIN_PERPAGE);
        if ($perpage == 0) {
            $perpage = ABJ404_OPTION_MIN_PERPAGE;
        }

        // Ensure paged is a valid integer
        $paged = absint(array_key_exists('paged', $tableOptions) && is_scalar($tableOptions['paged']) ? $tableOptions['paged'] : 1);
        if ($paged == 0) {
            $paged = 1;
        }

        $total_pages = ceil($num_records / $perpage);
        if ($total_pages == 0) {
            $total_pages = 1;
        }

        $firsturl = $url;

        if ($paged == 1) {
            $prevurl = $url;
        } else {
            $prev = $paged - 1;
            $prevurl = $url . "&paged=" . $prev;
        }

        if ($paged + 1 > $total_pages) {
            if ($paged == 1) {
                $nexturl = $url;
            } else {
                $nexturl = $url . "&paged=" . $paged;
            }
        } else {
            $next = $paged + 1;
            $nexturl = $url . "&paged=" . $next;
        }

        if ($paged + 1 > $total_pages) {
            if ($paged == 1) {
                $lasturl = $url;
            } else {
                $lasturl = $url . "&paged=" . $paged;
            }
        } else {
            $lasturl = $url . "&paged=" . $total_pages;
        }

        // ------------
        $start = (($paged - 1) * $perpage) + 1;
        $end = min($start + $perpage - 1, $num_records);
        /* Translators: 1: Starting number, 2: Ending number, 3: Total count. */
        $currentlyShowingText = sprintf(__('%1$s - %2$s of %3$s', '404-solution'), $start, $end, $num_records);
        $currentPageText = __('Page', '404-solution') . " " . $paged . " " . __('of', '404-solution') . " " . esc_html((string)$total_pages);
        $showRowsText = __('Rows per page:', '404-solution');
        $showRowsLink = wp_nonce_url($url . '&action=changeItemsPerRow', "abj404_changeItemsPerRow");

        $ajaxAction = 'ajaxUpdatePaginationLinks';
        $ajaxNonce = wp_create_nonce('abj404_updatePaginationLink');
        
        $searchFilterControl = '<!--';
        if ($sub == 'abj404_redirects' || $sub == 'abj404_captured') {
            $searchFilterControl = '';
        }
        if (!$showSearchFilter) {
            $searchFilterControl = '<!--';
        }
        
        $filterText = array_key_exists('filterText', $tableOptions) && is_string($tableOptions['filterText']) ? $tableOptions['filterText'] : '';
        if ($filterText != '') {
            $encodedFilterText = rawurlencode((string)$filterText);
            $nexturl .= '&filterText=' . $encodedFilterText;
            $prevurl .= '&filterText=' . $encodedFilterText;
            $firsturl .= '&filterText=' . $encodedFilterText;
            $lasturl .= '&filterText=' . $encodedFilterText;
        }

        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/paginationLinks.html");
        // do special replacements
        $perpage = array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : ABJ404_OPTION_DEFAULT_PERPAGE;
        $html = $this->f->str_replace(' value="' . $perpage . '"',
                ' value="' . $perpage . '" selected',
                $html);
        $html = $this->f->str_replace('{changeItemsPerPage}', $showRowsLink, $html);
        $html = $this->f->str_replace('{showSearchFilter}', $searchFilterControl, $html);
        $html = $this->f->str_replace('{TEXT_BEFORE_LINKS}', $currentlyShowingText, $html);
        $html = $this->f->str_replace('{TEXT_SHOW_ROWS}', $showRowsText, $html);
        $html = $this->f->str_replace('{LINK_FIRST_PAGE}', esc_url($firsturl), $html);
        $html = $this->f->str_replace('{LINK_PREVIOUS_PAGE}', esc_url($prevurl), $html);
        $html = $this->f->str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = $this->f->str_replace('{LINK_NEXT_PAGE}', esc_url($nexturl), $html);
        $html = $this->f->str_replace('{LINK_LAST_PAGE}', esc_url($lasturl), $html);
        $html = $this->f->str_replace('{filterText}', esc_attr($filterText), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-url}', esc_attr(admin_url('admin-ajax.php')), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-action}', esc_attr($ajaxAction), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-subpage}', esc_attr($sub), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-nonce}', esc_attr($ajaxNonce), $html);
        $html = $this->f->str_replace('{data-pagination-current-signature}', esc_attr($this->getCurrentTableDataSignature($sub)), $html);
        $html = $this->f->str_replace('{data-pagination-current-orderby}', esc_attr((string)$orderby), $html);
        $html = $this->f->str_replace('{data-pagination-current-order}', esc_attr((string)$order), $html);
        $html = $this->f->str_replace('{data-pagination-current-filter}', esc_attr((string)$filter), $html);
        $html = $this->f->str_replace('{data-pagination-current-paged}', esc_attr((string)$paged), $html);
        $html = $this->f->str_replace('{data-pagination-current-logsid}', esc_attr((string)$logsid), $html);
        $autoRefresh = (($sub === 'abj404_redirects' || $sub === 'abj404_captured' || $sub === 'abj404_logs') ? '1' : '0');
        $html = $this->f->str_replace('{data-pagination-auto-refresh}', esc_attr($autoRefresh), $html);
        $html = $this->f->str_replace('{data-pagination-refresh-started-text}', esc_attr(__('Refreshing data in background…', '404-solution')), $html);
        $html = $this->f->str_replace('{data-pagination-refresh-finished-text}', esc_attr(__('Data refreshed', '404-solution')), $html);
        $html = $this->f->str_replace('{data-pagination-refresh-available-text}', esc_attr(__('Refresh available', '404-solution')), $html);
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        return $html;
    }    
    
    /** Output the filters for a tab.
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    function getTabFilters($sub, $tableOptions) {

        if (empty($tableOptions)) {
        	$tableOptions = $this->logic->getTableOptions($sub);
        }
        
        $html = '';
        $html .= "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span>";
        
        $html .= $this->getSubSubSub($sub);
        
        $html .= "</span>";
        
        return $html;
    }
    
		    /**
		     * @param string $sub
		     * @return string
		     */
		    function getSubSubSub($sub) {
		        global $abj404_redirect_types;
		        global $abj404_captured_types;
		        
		        $tableOptions = $this->logic->getTableOptions($sub);
		        $filter = isset($tableOptions['filter']) ? intval(is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0) : 0;
		        $orderby = isset($tableOptions['orderby']) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
		        $order = isset($tableOptions['order']) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
		        
		        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_redirects') {
            $url .= "&subpage=abj404_redirects";
        } else {
            $this->logger->errorMessage("Unexpected sub page: " . $sub);
        }

	        $url .= "&orderby=" . sanitize_text_field($orderby);
	        $url .= "&order=" . sanitize_text_field($order);

	        if ($sub == 'abj404_redirects') {
	            $types = array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX);
	            if (isset($abj404_redirect_types) && is_array($abj404_redirect_types)) {
	                // Some tests/plugins may set this global to a label map; only accept a numeric status list.
	                $candidate = array_values($abj404_redirect_types);
	                $isNumericList = true;
	                foreach ($candidate as $v) {
	                    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
	                        $isNumericList = false;
	                        break;
	                    }
	                }
	                if ($isNumericList && !empty($candidate)) {
	                    $types = array_map('intval', $candidate);
	                }
	            }
	            $counts = $this->dao->getRedirectStatusCounts();
	        } else if ($sub == 'abj404_captured') {
	            $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);
	            if (isset($abj404_captured_types) && is_array($abj404_captured_types)) {
	                $candidate = array_values($abj404_captured_types);
	                $isNumericList = true;
	                foreach ($candidate as $v) {
	                    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
	                        $isNumericList = false;
	                        break;
	                    }
	                }
	                if ($isNumericList && !empty($candidate)) {
	                    $types = array_map('intval', $candidate);
	                }
	            }
	            $counts = $this->dao->getCapturedStatusCounts();
	        } else {
	            $this->logger->debugMessage("Unexpected sub type for tab filter: " . $sub);
	            $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);
	            $counts = array();
	        }

	        $class = "";
	        if ($filter == 0) {
	            $class = " class=\"current\"";
	        }
        
		        $html = '<ul class="subsubsub" >';
		        if ($sub != 'abj404_captured') {
		            $html .= "<li>";
		            $html .= "<a href=\"" . esc_url($url) . "\"" . $class . ">" . __('All', '404-solution');
		            $html .= " <span class=\"count\">(" . esc_html((string)($counts['all'] ?? 0)) . ")</span>";
		            $html .= "</a>";
		            $html .= "</li>";
		        }
		        foreach ($types as $type) {
		            $thisurl = $url . "&filter=" . $type;

		            $class = "";
		            if ($filter == $type) {
		                $class = " class=\"current\"";
	            }

	            $recordCount = 0;
	            $title = __('Unknown', '404-solution');
	            if ($type == ABJ404_STATUS_MANUAL) {
	                $title = __('Manual Redirects', '404-solution');
	                $recordCount = intval($counts['manual'] ?? 0) + intval($counts['regex'] ?? 0);
	            } else if ($type == ABJ404_STATUS_AUTO) {
	                $title = __('Automatic Redirects', '404-solution');
	                $recordCount = intval($counts['auto'] ?? 0);
	            } else if ($type == ABJ404_STATUS_CAPTURED) {
	                $title = "Captured URLs";
	                $recordCount = intval($counts['captured'] ?? 0);
	            } else if ($type == ABJ404_STATUS_IGNORED) {
	                $title = "Ignored 404s";
	                $recordCount = intval($counts['ignored'] ?? 0);
	            } else if ($type == ABJ404_STATUS_LATER) {
	                $title = "Organize Later";
	                $recordCount = intval($counts['later'] ?? 0);
	            } else if ($type == ABJ404_STATUS_REGEX) {
	                // don't include a tab here because these are included in the manual redirects.
	                continue;
	            } else {
	                $this->logger->errorMessage("Unrecognized redirect type in View: " . esc_html((string)$type));
	            }

            $html .= "<li>";
            if ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) {
                $html .= " | ";
            }
            $html .= "<a href=\"" . esc_url($thisurl) . "\"" . $class . ">" . ( $title );
            $html .= " <span class=\"count\">(" . esc_html((string)$recordCount) . ")</span>";
            $html .= "</a>";
            $html .= "</li>";
        }


	        $trashurl = $url . "&filter=" . ABJ404_TRASH_FILTER;
	        $class = "";
	        if (($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER) {
	            $class = " class=\"current\"";
	        }
	        $html .= "<li> | ";
	        $html .= "<a href=\"" . esc_url($trashurl) . "\"" . $class . ">" . __('Trash', '404-solution');
	        $html .= " <span class=\"count\">(" . esc_html((string)($counts['trash'] ?? 0)) . ")</span>";
	        $html .= "</a>";
	        $html .= "</li>";
	        $html .= "</ul>";
	        $html .= "\n\n<!-- page-form big outer form could go here -->\n\n";
        
        $oneBigFormActionURL = $this->getBulkOperationsFormURL($sub, $tableOptions);
        $html .= '<form method="POST" name="bulk-operations-form" action="' . $oneBigFormActionURL . '">';

        
        return $html;
    }


}
