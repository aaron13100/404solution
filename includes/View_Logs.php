<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Logs methods.
 */
class ABJ_404_Solution_View_Logs extends ABJ_404_Solution_ViewComponent {

    /**
     * Load a template file from includes/html/ and trim its trailing newline.
     *
     * @param string $name
     * @return string
     */
    private function tpl($name) {
        $raw = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * @return void
     */
    function echoAdminLogsPage() {

        $sub = 'abj404_logs';
        $tableOptions = $this->logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $this->logic->sanitizePostData($tableOptions);

        // Extract current table options for the filter bar data attributes
        $perpage = array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'timestamp';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'DESC';
        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');

        // Build the rows-per-page <option> list.
        $perPageOptions = array(10, 25, 50, 100, 250);
        $optionTpl = $this->tpl('viewLogsPerpageOption.html');
        $perpageOptionsHtml = '';
        foreach ($perPageOptions as $opt) {
            $selected = ($perpage == $opt) ? ' selected' : '';
            $perpageOptionsHtml .= $this->f->str_replace(
                array('{value}', '{selected_attr}'),
                array((string)$opt, $selected),
                $optionTpl
            ) . "\n";
        }

        // Search dropdown (existing template)
        $searchBox = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/viewLogsForSearchBox.html");
        $redirectPageTitle = $this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_title');
        $pageIDAndType = $this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_id');
        $searchBox = $this->f->str_replace('{redirect_to_label}', __('View logs for', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Begin typing a URL)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
                __('(A custom string has been entered.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(Please choose from the dropdown list instead of typing your own URL.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $searchBox);
        $searchBox = $this->f->str_replace('{redirectPageTitle}', esc_attr($redirectPageTitle), $searchBox);
        $searchBox = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoViewLogsFor&nonce=" . wp_create_nonce('abj404_ajax'), $searchBox);
        $searchBox = $this->f->doNormalReplacements($searchBox);

        $searchForm = $this->f->str_replace(
            array('{page_constant}', '{search_dropdown}'),
            array(ABJ404_PP, $searchBox),
            $this->tpl('viewLogsSearchFormShell.html')
        );

        // Silent warmup placeholder. Data loaded via AJAX. Per
        // UI_AESTHETIC.md, native WP list tables show no loading
        // chrome during pagination, so no visible text is rendered.
        $warmup = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableWarmupPlaceholder.html");

        $wrapper = $this->tpl('viewLogsPageWrapper.html');
        $wrapper = $this->f->str_replace(
            array(
                '{logs_title}',
                '{data-pagination-ajax-url}',
                '{data-pagination-ajax-subpage}',
                '{data-pagination-ajax-nonce}',
                '{data-pagination-inflight-nonce}',
                '{data-pagination-current-orderby}',
                '{data-pagination-current-order}',
                '{data-pagination-current-logsid}',
                '{refresh_started_text}',
                '{refresh_finished_text}',
                '{refresh_available_text}',
                '{search_form}',
                '{rows_per_page_label}',
                '{perpage_options}',
                '{warmup_placeholder}',
            ),
            array(
                __('Redirect Logs', '404-solution'),
                esc_attr(admin_url('admin-ajax.php')),
                esc_attr($sub),
                esc_attr($paginationNonce),
                esc_attr($inflightNonce),
                esc_attr((string)$orderby),
                esc_attr((string)$order),
                esc_attr((string)$this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_id')),
                esc_attr(__('Refreshing data in background…', '404-solution')),
                esc_attr(__('Data refreshed', '404-solution')),
                esc_attr(__('Refresh available', '404-solution')),
                $searchForm,
                __('Rows per page:', '404-solution'),
                $perpageOptionsHtml,
                $warmup,
            ),
            $wrapper
        );
        echo $wrapper;
    }

    /**
     * @param string $sub
     * @return string
     */
    function getAdminLogsPageTable($sub) {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting.
        // Engine is displayed inside the Action cell; User is displayed inside the Date cell.
        // Perf audit F5: only `url` (alias for requested_url) and `timestamp`
        // have indexes on logsv2. IP / Referrer / Action map to unindexed
        // columns, so we render them as plain non-clickable headers to avoid
        // offering a sort that would trigger filesort on large logs tables.
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'host' => array('title' => __('IP Address', '404-solution'), 'orderby' => ''),
            'refer' => array('title' => __('Referrer', '404-solution'), 'orderby' => ''),
            'dest' => array('title' => __('Action', '404-solution'), 'orderby' => ''),
            'timestamp' => array('title' => __('Date', '404-solution'), 'orderby' => 'timestamp'),
        );

        $plainHeaderTpl = $this->tpl('viewLogsTableHeaderCellPlain.html');
        $sortHeaderTpl = $this->tpl('viewLogsTableHeaderCellSortable.html');

        $headerCells = '';
        foreach ($columns as $key => $col) {
            $orderbyCol = $col['orderby'];
            if ($orderbyCol === '') {
                $headerCells .= $this->f->str_replace('{title}', esc_html($col['title']), $plainHeaderTpl);
                continue;
            }
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_logs";
            $sortUrl .= "&orderby=" . $orderbyCol;
            $sortState = $this->shared->getHeaderSortState($tableOptions, $orderbyCol, false);
            $newOrder = $sortState['nextOrder'];
            $sortUrl .= "&order=" . $newOrder;

            $sortClass = trim($sortState['thClass']);
            $sortClassAttr = ($sortClass !== '') ? ' class="' . $sortClass . '"' : '';
            $sortIndicator = $sortState['indicator'];

            $headerCells .= $this->f->str_replace(
                array('{class_attr}', '{sort_url}', '{title}', '{sort_indicator}'),
                array($sortClassAttr, esc_url($sortUrl), esc_html($col['title']), $sortIndicator),
                $sortHeaderTpl
            );
        }

        // Render body rows by template instantiation.
        $rowTpl = $this->tpl('viewLogsTableRow.html');
        $toggleEnabledTpl = $this->tpl('viewLogsTraceToggleEnabled.html');
        $toggleDisabledTpl = $this->tpl('viewLogsTraceToggleDisabled.html');
        $urlCellTpl = $this->tpl('viewLogsUrlCell.html');
        $urlDetailTpl = $this->tpl('viewLogsUrlDetailSpan.html');
        $referrerLinkTpl = $this->tpl('viewLogsReferrerLink.html');
        $referrerMutedTpl = $this->tpl('viewLogsReferrerMuted.html');
        $badge404Tpl = $this->tpl('viewLogsActionBadge404.html');
        $badgeRedirectTpl = $this->tpl('viewLogsActionBadgeRedirect.html');
        $engineLabelTpl = $this->tpl('viewLogsEngineLabel.html');
        $dateCellTpl = $this->tpl('viewLogsDateCell.html');
        $usernameLabelTpl = $this->tpl('viewLogsUsernameLabel.html');
        $traceRowTpl = $this->tpl('viewLogsTraceDetailRow.html');
        $traceStepTpl = $this->tpl('viewLogsTraceStep.html');
        $traceStepDetailTpl = $this->tpl('viewLogsTraceStepDetail.html');
        $emptyRowTpl = $this->tpl('viewLogsEmptyRow.html');

        $rows = $this->logsRepository->getLogRecords($tableOptions);
        /** @var array<int, array<string, mixed>> $typedLogRows */
        $typedLogRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedLogRows);
        $logRecordsDisplayed = 0;
        $bodyRows = '';

        foreach ($typedLogRows as $row) {
            $logId = is_scalar($row['log_id'] ?? '') ? (string)($row['log_id'] ?? '') : '';
            $rawTrace = isset($row['pipeline_trace']) && is_string($row['pipeline_trace']) ? $row['pipeline_trace'] : null;
            $traceSteps = ABJ_404_Solution_LogsRepository::decompressPipelineTrace($rawTrace);
            $hasTrace = is_array($traceSteps) && !empty($traceSteps);

            if ($hasTrace) {
                $traceToggle = $this->f->str_replace(
                    array('{row_id}', '{title}'),
                    array(esc_attr($logId), esc_attr__('Show pipeline trace', '404-solution')),
                    $toggleEnabledTpl
                );
            } else {
                $traceToggle = $this->f->str_replace(
                    '{title}', esc_attr__('No trace available', '404-solution'),
                    $toggleDisabledTpl
                );
            }

            // URL column
            $url = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
            $urlDetail = is_string($row['url_detail'] ?? '') ? (string)($row['url_detail'] ?? '') : '';
            $fullVisitorURL = esc_url(home_url($url));
            $detailSpan = '';
            if ($urlDetail !== '' && trim($urlDetail) !== '') {
                $detailSpan = $this->f->str_replace('{detail}', esc_html(trim($urlDetail)), $urlDetailTpl);
            }
            $urlCell = $this->f->str_replace(
                array('{full_url}', '{url_attr}', '{url_text}', '{url_detail_span}'),
                array($fullVisitorURL, esc_attr($url), esc_html($url), $detailSpan),
                $urlCellTpl
            );

            // IP Address
            $remoteHost = is_string($row['remote_host'] ?? '') ? (string)($row['remote_host'] ?? '') : '';
            $ipCell = esc_html($remoteHost);

            // Referrer
            $referrer = is_string($row['referrer'] ?? '') ? (string)($row['referrer'] ?? '') : '';
            if ($referrer != "") {
                $referrerCell = $this->f->str_replace(
                    array('{referrer_url}', '{referrer_attr}', '{referrer_text}'),
                    array(esc_url($referrer), esc_attr($referrer), esc_html($referrer)),
                    $referrerLinkTpl
                );
            } else {
                $referrerCell = $referrerMutedTpl;
            }

            // Action Taken (with engine on second line)
            $action = trim(is_string($row['action'] ?? '') ? (string)($row['action'] ?? '') : '');
            $engineVal = is_string($row['engine'] ?? '') ? (string)($row['engine'] ?? '') : '';
            if ($action === '' || $action == "404" || $action == "http://404") {
                $actionCell = $this->f->str_replace('{label}', __('404', '404-solution'), $badge404Tpl);
            } else {
                $actionCell = $this->f->str_replace(
                    array('{label}', '{action_url}', '{action_attr}', '{action_text}'),
                    array(__('Redirect', '404-solution'), esc_url($action), esc_attr($action), esc_html($action)),
                    $badgeRedirectTpl
                );
            }
            if ($engineVal !== '') {
                $actionCell .= $this->f->str_replace(
                    array('{engine_title}', '{engine_text}'),
                    array(esc_attr__('Engine', '404-solution'), esc_html($engineVal)),
                    $engineLabelTpl
                );
            }

            // Date (with user on second line)
            $timeToDisplay = abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0);
            $rowUsername = is_string($row['username'] ?? '') ? (string)($row['username'] ?? '') : '';
            $usernamePart = '';
            if ($rowUsername !== '') {
                $usernamePart = $this->f->str_replace('{username}', esc_html($rowUsername), $usernameLabelTpl);
            }
            $dateCell = $this->f->str_replace(
                array('{date_part}', '{time_part}', '{username_part}'),
                array((string)wp_date('Y/m/d', $timeToDisplay), (string)wp_date('h:i:s A', $timeToDisplay), $usernamePart),
                $dateCellTpl
            );

            $bodyRows .= $this->f->str_replace(
                array('{trace_toggle}', '{url_cell}', '{ip_cell}', '{referrer_cell}', '{action_cell}', '{date_cell}'),
                array($traceToggle, $urlCell, $ipCell, $referrerCell, $actionCell, $dateCell),
                $rowTpl
            );

            // Hidden detail row with pipeline trace
            if ($hasTrace && $logId !== '') {
                $stepsHtml = '';
                foreach ($traceSteps as $step) {
                    $stepName = esc_html($this->translateTraceLabel($step['step']));
                    $outcome  = esc_html($this->translateTraceLabel($step['outcome']));
                    $detail   = ($step['detail'] !== '')
                        ? $this->f->str_replace('{detail_text}', esc_html($step['detail']), $traceStepDetailTpl)
                        : '';
                    $outcomeClass = $this->traceOutcomeClass($step['outcome']);
                    $stepsHtml .= $this->f->str_replace(
                        array('{step_name}', '{outcome_class}', '{outcome}', '{detail}'),
                        array($stepName, $outcomeClass, $outcome, $detail),
                        $traceStepTpl
                    );
                }
                $bodyRows .= $this->f->str_replace(
                    array('{row_id}', '{trace_steps}'),
                    array(esc_attr($logId), $stepsHtml),
                    $traceRowTpl
                );
            }

            $logRecordsDisplayed++;
        }

        $this->logger->debugMessage($logRecordsDisplayed . " log records displayed on the page.");

        if ($logRecordsDisplayed == 0) {
            $bodyRows .= $this->f->str_replace('{message}', __('No Results To Display', '404-solution'), $emptyRowTpl);
        }

        $traceScript = $this->tpl('viewLogsTraceToggleScript.html');

        $html = $this->f->str_replace(
            array('{header_cells}', '{body_rows}', '{trace_script}'),
            array($headerCells, $bodyRows, $traceScript),
            $this->tpl('viewLogsTableShell.html')
        );

        return $html;
    }

    /**
     * Translate a known pipeline-trace string for admin display.
     *
     * Step names and outcomes are stored in English in the database so they
     * remain stable across language switches.  This method translates them
     * at render time into the current admin locale.
     *
     * @param string $text  The English string stored in the trace.
     * @return string       The translated string (falls through unchanged
     *                      for dynamic/unknown values like engine names or URLs).
     */
    public function translateTraceLabel(string $text): string {
        // Static map -- evaluated once per request.
        static $map = null;
        if ($map === null) {
            $map = [
                // Step names
                'Ignore list'                       => __('Ignore list', '404-solution'),
                'Redirect lookup'                   => __('Redirect lookup', '404-solution'),
                'Redirect lookup (without comments)' => __('Redirect lookup (without comments)', '404-solution'),
                'Conditions'                        => __('Conditions', '404-solution'),
                'Conditions (without comments)'     => __('Conditions (without comments)', '404-solution'),
                'Health check'                      => __('Health check', '404-solution'),
                'Health check (without comments)'   => __('Health check (without comments)', '404-solution'),
                'Regex rules'                       => __('Regex rules', '404-solution'),
                'Suggestion engines'                => __('Suggestion engines', '404-solution'),
                'Result'                            => __('Result', '404-solution'),
                // Outcomes
                'Not ignored'                       => __('Not ignored', '404-solution'),
                'Matched — request ignored'         => __('Matched — request ignored', '404-solution'),
                'Found existing redirect'           => __('Found existing redirect', '404-solution'),
                'No matching redirect'              => __('No matching redirect', '404-solution'),
                'All conditions met'                => __('All conditions met', '404-solution'),
                'Blocked by conditions'             => __('Blocked by conditions', '404-solution'),
                'Destination unreachable — skipped' => __('Destination unreachable — skipped', '404-solution'),
                'Matched'                           => __('Matched', '404-solution'),
                'No match'                          => __('No match', '404-solution'),
                'Skipped'                           => __('Skipped', '404-solution'),
                'Excluded'                          => __('Excluded', '404-solution'),
                'Error'                             => __('Error', '404-solution'),
                'No match found'                    => __('No match found', '404-solution'),
                'Showed 404 page'                   => __('Showed 404 page', '404-solution'),
                'Redirected to external URL'        => __('Redirected to external URL', '404-solution'),
                'No redirect — showed 404 page'     => __('No redirect — showed 404 page', '404-solution'),
            ];
        }

        // Exact match
        if (isset($map[$text])) {
            return $map[$text];
        }

        // Dynamic patterns: "Engine: Spelling", "Redirected (301)", etc.
        if (strpos($text, 'Engine: ') === 0) {
            $engineName = substr($text, 8);
            return sprintf(__('Engine: %s', '404-solution'), $engineName);
        }
        if (preg_match('/^Redirected \((\d+)\)$/', $text, $m)) {
            return sprintf(__('Redirected (%s)', '404-solution'), $m[1]);
        }
        if (preg_match('/^Responded with (.+)$/', $text, $m)) {
            return sprintf(__('Responded with %s', '404-solution'), $m[1]);
        }
        if (preg_match('/^Showed 404 page — (.+)$/', $text, $m)) {
            return sprintf(__('Showed 404 page — %s', '404-solution'), $m[1]);
        }

        return $text;
    }

    /**
     * Return a CSS class for the trace outcome badge based on the outcome text.
     *
     * @param string $outcome
     * @return string
     */
    public function traceOutcomeClass(string $outcome): string {
        $lower = strtolower($outcome);
        // Negative / blocking outcomes
        if (strpos($lower, 'blocked') !== false || strpos($lower, 'unreachable') !== false
            || strpos($lower, 'error') !== false || strpos($lower, 'ignored') !== false
            || strpos($lower, 'missing') !== false || strpos($lower, 'invalid') !== false) {
            return 'abj404-trace-outcome-fail';
        }
        // Final result outcomes
        if (strpos($lower, 'redirected') !== false || strpos($lower, 'responded') !== false
            || strpos($lower, 'showed 404') !== false || strpos($lower, 'no redirect') !== false) {
            return 'abj404-trace-outcome-result';
        }
        // Positive / pass-through outcomes
        if (strpos($lower, 'found') !== false || strpos($lower, 'matched') !== false
            || strpos($lower, 'passed') !== false || strpos($lower, 'not ignored') !== false
            || strpos($lower, 'all conditions met') !== false) {
            return 'abj404-trace-outcome-pass';
        }
        // Neutral (skipped, no match, etc.)
        return 'abj404-trace-outcome-neutral';
    }

    /**
     * @param string $sub
     * @param array<string, array<string, string>> $columns
     * @return string
     */
    function getTableColumns($sub, $columns) {
        $tableOptions = $this->logic->getTableOptions($sub);

        $cbinfoStyle = 'vertical-align: middle; padding-bottom: 6px;';
        if ($sub == 'abj404_logs') {
            $cbinfoStyle .= ' width: 0px;';
        }
        $selectAllCheckbox = '';
        if ($sub != 'abj404_logs') {
            $selectAllCheckbox = $this->f->str_replace(
                '{select_all_label}', esc_attr__('Select all', '404-solution'),
                $this->tpl('viewLogsColumnsSelectAllCheckbox.html')
            );
        }
        $selectAllTh = $this->f->str_replace(
            array('{cb_info_style}', '{select_all_checkbox}'),
            array($cbinfoStyle, $selectAllCheckbox),
            $this->tpl('viewLogsColumnsSelectAllTh.html')
        );

        $headerThTpl = $this->tpl('viewLogsColumnsHeaderTh.html');
        $headerLinkTpl = $this->tpl('viewLogsColumnsHeaderLink.html');
        $tooltipTpl = $this->tpl('viewLogsColumnsHeaderTooltip.html');

        $columnThs = '';
        foreach ($columns as $column) {
            $style = "";
            if (isset($column['width']) && $column['width'] != "") {
                $style = ' style="width: ' . esc_attr($column['width']) . ';" ';
            }
            $nolink = 0;
            $sortorder = "";
            $sortIndicator = '';
            $orderby = isset($column['orderby']) ? $column['orderby'] : '';
            $preferDescOnFirstClick = ($orderby == "timestamp" ||
                    $orderby == "last_used" ||
                    $orderby == "logshits");
            $sortState = $this->shared->getHeaderSortState($tableOptions, (string)$orderby, $preferDescOnFirstClick);
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
                $tooltipHtml = $this->f->str_replace(
                    array('{more_info_label}', '{tooltip_body}'),
                    array(esc_attr__('More info', '404-solution'), (string)$column['title_attr_html']),
                    $tooltipTpl
                ) . "\n";
            } elseif (array_key_exists('title_attr', $column) && !empty($column['title_attr'])) {
                // Plain text - escape it
                $tooltipHtml = $this->f->str_replace(
                    array('{more_info_label}', '{tooltip_body}'),
                    array(esc_attr__('More info', '404-solution'), esc_html($column['title_attr'])),
                    $tooltipTpl
                ) . "\n";
            }

            // Support custom column classes (e.g., hide-on-tablet, hide-on-mobile)
            if (isset($column['class']) && $column['class'] != '') {
                $thClass .= ' ' . esc_attr($column['class']);
            }

            $title = isset($column['title']) ? $column['title'] : '';
            if ($nolink == 1) {
                $titleContent = $title;
            } else {
                $titleContent = $this->f->str_replace(
                    array('{url}', '{orderby}', '{title}', '{sort_indicator}'),
                    array(esc_url($url), (string)$orderby, esc_html($title), $sortIndicator),
                    $headerLinkTpl
                );
            }

            $columnThs .= $this->f->str_replace(
                array('{style_attr}', '{extra_class}', '{title_content}', '{tooltip_html}'),
                array($style, $thClass, $titleContent, $tooltipHtml),
                $headerThTpl
            );
        }

        return $this->f->str_replace(
            array('{select_all_th}', '{column_ths}'),
            array($selectAllTh, $columnThs),
            $this->tpl('viewLogsColumnsHeaderRow.html')
        );
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
            $num_records = $this->viewReadService->getLogsCount((int)$logsid);
        } else {
            $num_records = $this->viewReadService->getRedirectsForViewCount($sub, $tableOptions);
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
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');

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
        // Build navigation buttons: disabled (span) when at the boundary page, link (a) otherwise.
        $onFirstPage = ($paged <= 1);
        $onLastPage  = ($paged >= $total_pages);

        $disabledTpl = $this->tpl('viewLogsPaginationBtnDisabled.html');
        $linkTpl = $this->tpl('viewLogsPaginationBtnLink.html');

        $firstLabel = esc_attr__('Go to first page', '404-solution');
        $prevLabel  = esc_attr__('Go to previous page', '404-solution');
        $nextLabel  = esc_attr__('Go to next page', '404-solution');
        $lastLabel  = esc_attr__('Go to last page', '404-solution');

        if ($onFirstPage) {
            $btnFirst = $this->f->str_replace(array('{label}', '{glyph}'), array($firstLabel, '&laquo;'), $disabledTpl);
            $btnPrev  = $this->f->str_replace(array('{label}', '{glyph}'), array($prevLabel, '&lsaquo;'), $disabledTpl);
        } else {
            $btnFirst = $this->f->str_replace(array('{href}', '{label}', '{glyph}'), array(esc_url($firsturl), $firstLabel, '&laquo;'), $linkTpl);
            $btnPrev  = $this->f->str_replace(array('{href}', '{label}', '{glyph}'), array(esc_url($prevurl), $prevLabel, '&lsaquo;'), $linkTpl);
        }

        if ($onLastPage) {
            $btnNext = $this->f->str_replace(array('{label}', '{glyph}'), array($nextLabel, '&rsaquo;'), $disabledTpl);
            $btnLast = $this->f->str_replace(array('{label}', '{glyph}'), array($lastLabel, '&raquo;'), $disabledTpl);
        } else {
            $btnNext = $this->f->str_replace(array('{href}', '{label}', '{glyph}'), array(esc_url($nexturl), $nextLabel, '&rsaquo;'), $linkTpl);
            $btnLast = $this->f->str_replace(array('{href}', '{label}', '{glyph}'), array(esc_url($lasturl), $lastLabel, '&raquo;'), $linkTpl);
        }

        $html = $this->f->str_replace('{BTN_FIRST_PAGE}', $btnFirst, $html);
        $html = $this->f->str_replace('{BTN_PREV_PAGE}', $btnPrev, $html);
        $html = $this->f->str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = $this->f->str_replace('{BTN_NEXT_PAGE}', $btnNext, $html);
        $html = $this->f->str_replace('{BTN_LAST_PAGE}', $btnLast, $html);
        $html = $this->f->str_replace('{filterText}', esc_attr($filterText), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-url}', esc_attr(admin_url('admin-ajax.php')), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-action}', esc_attr($ajaxAction), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-subpage}', esc_attr($sub), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-nonce}', esc_attr($ajaxNonce), $html);
        $html = $this->f->str_replace('{data-pagination-inflight-nonce}', esc_attr($inflightNonce), $html);
        $html = $this->f->str_replace('{data-pagination-current-signature}', esc_attr($this->shared->getCurrentTableDataSignature($sub)), $html);
        $html = $this->f->str_replace('{data-pagination-current-orderby}', esc_attr((string)$orderby), $html);
        $html = $this->f->str_replace('{data-pagination-current-order}', esc_attr((string)$order), $html);
        $html = $this->f->str_replace('{data-pagination-current-filter}', esc_attr((string)$filter), $html);
        $html = $this->f->str_replace('{data-pagination-current-paged}', esc_attr((string)$paged), $html);
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRangeForAttr = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $html = $this->f->str_replace('{data-pagination-current-score-range}', esc_attr($scoreRangeForAttr), $html);
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
        $html .= $this->tpl('viewLogsTabFiltersClearBar.html');

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
            $counts = $this->viewReadService->getRedirectStatusCounts();
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
            $counts = $this->viewReadService->getCapturedStatusCounts();
        } else {
            $this->logger->debugMessage("Unexpected sub type for tab filter: " . $sub);
            $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);
            $counts = array();
        }

        $itemTpl = $this->tpl('viewLogsTabFilterItem.html');

        $allItemClass = ($filter == 0) ? ' class="current"' : '';

        $html = $this->tpl('viewLogsTabFiltersListOpen.html');
        if ($sub != 'abj404_captured') {
            $html .= $this->f->str_replace(
                array('{prefix}', '{url}', '{class_attr}', '{title}', '{count}'),
                array('', esc_url($url), $allItemClass, __('All', '404-solution'), esc_html((string)($counts['all'] ?? 0))),
                $itemTpl
            );
        }
        foreach ($types as $type) {
            $thisurl = $url . "&filter=" . $type;

            $typeClass = ($filter == $type) ? ' class="current"' : '';

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

            $prefix = ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) ? ' | ' : '';
            $html .= $this->f->str_replace(
                array('{prefix}', '{url}', '{class_attr}', '{title}', '{count}'),
                array($prefix, esc_url($thisurl), $typeClass, $title, esc_html((string)$recordCount)),
                $itemTpl
            );
        }


        $trashurl = $url . "&filter=" . ABJ404_TRASH_FILTER;
        $trashClass = (($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER) ? ' class="current"' : '';
        $html .= $this->f->str_replace(
            array('{prefix}', '{url}', '{class_attr}', '{title}', '{count}'),
            array(' | ', esc_url($trashurl), $trashClass, __('Trash', '404-solution'), esc_html((string)($counts['trash'] ?? 0))),
            $itemTpl
        );
        $html .= "</ul>";
        $html .= "\n\n<!-- page-form big outer form could go here -->\n\n";

        $oneBigFormActionURL = $this->redirectsTable->getBulkOperationsFormURL($sub, $tableOptions);
        $html .= $this->f->str_replace(
            '{action_url}', $oneBigFormActionURL,
            $this->tpl('viewLogsTabFiltersBulkForm.html')
        );


        return $html;
    }


}
