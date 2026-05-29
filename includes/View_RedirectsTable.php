<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Redirects admin page renderer. Owns the page wrapper, the table
 * shell, per-row HTML, score-cell and destination-link/warning resolution,
 * and the modern Add Redirect modal. The Captured 404 URLs page lives on
 * View_CapturedURLsTable; standalone add/edit forms live on View_RedirectForms;
 * shared list-table chrome (subsubsub, pagination, bulk URL, perpage selector,
 * empty-trash) lives on View_ListTableChrome.
 *
 * Outside callers (via the View facade __call dispatch):
 *   - includes/ajax/ViewUpdater.php (pagination AJAX -> getAdminRedirectsPageTable)
 *   - PluginLogic admin page entry points (echoAdminRedirectsPage)
 *   - tests/BugProof_ReleaseReadinessTest greps buildRedirectsColumnDefs source
 */
class ABJ_404_Solution_View_RedirectsTable extends ABJ_404_Solution_ViewComponent {

    /** Load a template file from includes/html/ and trim its trailing newline. */
    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * Load a template and substitute an associative array of placeholders.
     * @param array<string,string> $vars
     */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    /**
     * Build an action link with SVG icon.
     *
     * @param array<string,string> $vars
     */
    private function buildActionLink(string $tplName, array $vars): string {
        $tpl = $this->tpl($tplName);
        foreach ($vars as $k => $v) {
            $tpl = $this->f->str_replace('{' . $k . '}', $v, $tpl);
        }
        return $tpl;
    }

    /**
     * @return void
     */
    public function echoAdminRedirectsPage() {

        $sub = 'abj404_redirects';

        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $this->logic->sanitizePostData($tableOptions);
        $rawFilter = $tableOptions['filter'] ?? 0;
        $currentFilter = is_scalar($rawFilter) ? $rawFilter : 0;

        // Health bar
        $healthBarNonce = wp_create_nonce('abj404_refreshHealthBar');
        // allow-em-dash: pre-existing translation string with U+2026 ellipsis in published .po files
        $loadingStatusText = esc_html__('Loading status…', '404-solution');

        // Subtitle
        $subtitleHtml = '';
        if ($this->logic->getSettingsMode() === 'simple') {
            $subtitleHtml = $this->f->str_replace(
                '{text}',
                esc_html__('The plugin creates these automatically. You only need to act when the status bar above says so.', '404-solution'),
                $this->tpl('viewRedirectsTableSubtitle.html')
            ) . "\n";
        }

        // Add Redirect button
        $addRedirectBtnHtml = '';
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $addRedirectBtnHtml = $this->f->str_replace(
                '{label}',
                esc_html__('Add Redirect', '404-solution'),
                $this->tpl('viewRedirectsTableAddRedirectButton.html')
            );
        }

        $subsubsubHtml = $this->listTableChrome->buildSubsubsubFilters($sub, array(
            array(0,                      __('All', '404-solution')),
            array(ABJ404_STATUS_MANUAL,   __('Manual', '404-solution')),
            array(ABJ404_STATUS_AUTO,     __('Automatic', '404-solution')),
            array(ABJ404_TRASH_FILTER,    __('Trash', '404-solution')),
        ), $tableOptions);

        // Filter bar with server-side search
        $filterText = is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');
        $autoRefresh = '1';
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $currentOrderBy = is_string($rawOrderBy) ? $rawOrderBy : 'url';
        $rawOrder = $tableOptions['order'] ?? '';
        $currentOrder = is_string($rawOrder) ? $rawOrder : 'ASC';
        $rawPaged = $tableOptions['paged'] ?? 1;
        $currentPaged = is_scalar($rawPaged) ? intval($rawPaged) : 1;
        if ($currentPaged < 1) {
            $currentPaged = 1;
        }
        $rawScoreRangeForAttr = $tableOptions['score_range'] ?? 'all';
        $currentScoreRangeForAttr = is_string($rawScoreRangeForAttr) ? $rawScoreRangeForAttr : 'all';

        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $currentScoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $scoreRangeBaseUrl = '?page=' . ABJ404_PP . '&subpage=' . esc_attr($sub) . '&filter=' . (int)(is_scalar($rawFilter) ? $rawFilter : 0);
        $scoreRangeBaseUrlJs = esc_js(esc_url($scoreRangeBaseUrl));
        $scoreRangeOptionsHtml = $this->listTableChrome->buildScoreRangeOptions($currentScoreRange);

        $bulkActionOptionsHtml = $this->listTableChrome->buildRedirectsBulkActionOptions($currentFilter);

        $formAction = $this->listTableChrome->getBulkOperationsFormURL($sub, $tableOptions);

        $emptyTrashFormHtml = ($currentFilter == ABJ404_TRASH_FILTER)
            ? $this->listTableChrome->buildEmptyTrashForm($sub)
            : '';

        $warmup = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->listTableChrome->paginationRefreshStrings();

        echo $this->fillTpl('viewRedirectsTableRedirectsPageWrapper.html', array(
            '{data-health-bar-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-health-bar-nonce}' => esc_attr($healthBarNonce),
            '{loading_status_text}' => $loadingStatusText,
            '{redirects_title}' => esc_html__('Page Redirects', '404-solution'),
            '{subtitle}' => $subtitleHtml,
            '{add_redirect_button}' => $addRedirectBtnHtml,
            '{subsubsub}' => $subsubsubHtml,
            '{data-pagination-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-pagination-ajax-subpage}' => esc_attr($sub),
            '{data-pagination-ajax-nonce}' => esc_attr($paginationNonce),
            '{data-pagination-inflight-nonce}' => esc_attr($inflightNonce),
            '{data-pagination-current-orderby}' => esc_attr($currentOrderBy),
            '{data-pagination-current-order}' => esc_attr($currentOrder),
            '{data-pagination-current-filter}' => esc_attr((string)$currentFilter),
            '{data-pagination-current-paged}' => esc_attr((string)$currentPaged),
            '{data-pagination-current-score-range}' => esc_attr($currentScoreRangeForAttr),
            '{data-pagination-auto-refresh}' => esc_attr($autoRefresh),
            '{data-pagination-refresh-started-text}' => esc_attr($refresh['started']),
            '{data-pagination-refresh-finished-text}' => esc_attr($refresh['finished']),
            '{data-pagination-refresh-available-text}' => esc_attr($refresh['available']),
            '{search_placeholder}' => esc_attr__('Type to filter redirects... (press Enter)', '404-solution'),
            '{filter_text}' => esc_attr($filterText),
            '{rows_per_page_label}' => esc_html__('Rows per page:', '404-solution'),
            '{perpage_options}' => $this->listTableChrome->buildPerpageOptions($perPage),
            '{confidence_label}' => esc_html__('Confidence:', '404-solution'),
            '{score_range_base_url_js}' => $scoreRangeBaseUrlJs,
            '{score_range_options}' => $scoreRangeOptionsHtml,
            '{form_action}' => esc_url($formAction),
            '{selected_label}' => esc_html__('redirects selected', '404-solution'),
            '{bulk_actions_label}' => esc_html__('Bulk Actions', '404-solution'),
            '{bulk_action_options}' => $bulkActionOptionsHtml,
            '{apply_label}' => esc_html__('Apply', '404-solution'),
            '{clear_selection_label}' => esc_html__('Clear Selection', '404-solution'),
            '{warmup_placeholder}' => $warmup,
            '{empty_trash_form}' => $emptyTrashFormHtml,
        ));

        // Add redirect modal (outside the main container)
        if (($tableOptions['filter'] ?? 0) != ABJ404_TRASH_FILTER) {
            $this->echoAddRedirectModal($tableOptions);
        }
    }

    /**
     * Echo the modern Add Redirect modal.
     *
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    public function echoAddRedirectModal($tableOptions) {
        $options = $this->shared->getOptionsWithDefaults();
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_redirects";
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        if (!($orderby == "url" && $order == "ASC")) {
            $url .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
        }
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        $link = wp_nonce_url($url, "abj404addRedirect");
        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";

        // Redirect-to autocomplete (existing template)
        $redirectHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectPageSearchDropdown.html");
        $redirectHtml = $this->f->str_replace(
            array('{redirect_to_label}', '{TOOLTIP_POPUP_EXPLANATION_EMPTY}', '{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                  '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}', '{TOOLTIP_POPUP_EXPLANATION_URL}',
                  '{REDIRECT_TO_USER_FIELD_WARNING}', '{redirectPageTitle}', '{pageIDAndType}', '{data-url}'),
            array(esc_html__('Redirect to', '404-solution') . ' *',
                  __('(Type a page name or an external URL)', '404-solution'),
                  __('(A page has been selected.)', '404-solution'),
                  __('(A custom string has been entered.)', '404-solution'),
                  __('(An external URL will be used.)', '404-solution'),
                  '', '', '',
                  "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax')),
            $redirectHtml
        );
        $redirectHtml = $this->f->doNormalReplacements($redirectHtml);

        // Redirect type button grid
        $rawDefault = $options['default_redirect'] ?? '301';
        $defaultCode = is_string($rawDefault) ? $rawDefault : '301';
        ob_start();
        $this->redirectTypeUI->echoRedirectTypeButtonGrid($defaultCode);
        $redirectTypeGrid = (string)ob_get_clean();

        // Advanced Options
        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsSection = (string)ob_get_clean();

        $advancedHtml = $this->fillTpl('viewRedirectsTableAdvancedOptions.html', array(
            '{open_attr}' => '',
            '{advanced_options_label}' => esc_html__('Advanced Options', '404-solution'),
            '{active_from_label}' => esc_html__('Active From (optional)', '404-solution'),
            '{start_date_value}' => '',
            '{active_from_help}' => esc_html__('Leave blank to activate immediately', '404-solution'),
            '{active_until_label}' => esc_html__('Active Until (optional)', '404-solution'),
            '{end_date_value}' => '',
            '{active_until_help}' => esc_html__('Leave blank to never expire', '404-solution'),
            '{conditions_section}' => $conditionsSection,
        ));

        echo $this->fillTpl('viewRedirectsTableAddModal.html', array(
            '{modal_title}' => esc_html__('Add Manual Redirect', '404-solution'),
            '{form_action}' => esc_url($link),
            '{url_label}' => esc_html__('URL', '404-solution'),
            '{url_placeholder}' => esc_attr($urlPlaceholder),
            '{url_help}' => esc_html__('The URL path that should be redirected (without domain)', '404-solution'),
            '{regex_label}' => esc_html__('Treat this URL as a regular expression', '404-solution'),
            '{explain_label}' => esc_html__('(Explain)', '404-solution'),
            '{regex_help_1}' => esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution'),
            '{example_label}' => esc_html__('Example:', '404-solution'),
            '{regex_help_2}' => esc_html__('/events/(.+) will match any URL that begins with /events/ and redirect to the specified page. Since a capture group is used, you can use a $1 replacement in the destination string of an external URL.', '404-solution'),
            '{regex_help_3}' => esc_html__('First, all of the normal "exact match" URLs are checked, then all of the regular expression URLs are checked.', '404-solution'),
            '{redirect_to_html}' => $redirectHtml,
            '{redirect_type_grid}' => $redirectTypeGrid,
            '{advanced_options}' => $advancedHtml,
            '{cancel_label}' => esc_html__('Cancel', '404-solution'),
            '{add_redirect_label}' => esc_html__('Add Redirect', '404-solution'),
        ));
    }

    /**
     * @param string $sub
     * @return string
     */
    public function getAdminRedirectsPageTable($sub) {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $columns = $this->buildRedirectsColumnDefs($tableOptions);

        $headerColumns = $this->logs->getTableColumns($sub, $columns);

        $deadDestIds = function_exists('get_transient') ? get_transient('abj404_dead_dest_ids') : false;
        if (!is_array($deadDestIds)) {
            $deadDestIds = array();
        }

        $rows = $this->viewReadService->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRedirectRows */
        $typedRedirectRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedRedirectRows);
        $displayed = 0;
        $y = 1;
        $bodyRows = '';
        foreach ($typedRedirectRows as $row) {
            $bodyRows .= $this->buildRedirectRowHTML($row, $sub, $tableOptions, $deadDestIds, $y);
            $y = ($y === 0) ? 1 : 0;
            $displayed++;
        }
        if ($displayed == 0) {
            $bodyRows .= $this->f->str_replace(
                array('{title}', '{help}'),
                array(
                    __('No Redirect Records To Display', '404-solution'),
                    __('Redirects will appear here once created.', '404-solution'),
                ),
                $this->tpl('viewRedirectsTableRedirectsEmptyState.html')
            );
        }

        return $this->f->str_replace(
            array('{header_columns}', '{body_rows}'),
            array($headerColumns, $bodyRows),
            $this->tpl('viewRedirectsTableRedirectsTableShell.html')
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return array<string, array<string, string>>
     */
    public function buildRedirectsColumnDefs(array $tableOptions): array {
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
        $columns['confidence']['title'] = __('Confidence', '404-solution');
        $columns['confidence']['orderby'] = "score";
        $columns['confidence']['width'] = "7%";
        $columns['confidence']['class'] = "hide-on-tablet";
        $columns['hits']['title'] = __('Hits', '404-solution');
        $columns['hits']['orderby'] = "logshits";
        $columns['hits']['width'] = "7%";
        $hitsTooltip = $this->shared->getHitsColumnTooltip($tableOptions);
        $columns['hits']['title_attr_html'] = $hitsTooltip;
        $columns['timestamp']['title'] = __('Created', '404-solution');
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "10%";
        $columns['timestamp']['class'] = "hide-on-tablet";
        $columns['last_used']['title'] = __('Last Used', '404-solution');
        $columns['last_used']['orderby'] = "last_used";
        $columns['last_used']['width'] = "10%";
        $columns['last_used']['title_attr_html'] = $hitsTooltip;
        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<mixed> $deadDestIds
     * @param int $y
     * @return string
     */
    public function buildRedirectRowHTML(array $row, string $sub, array $tableOptions, array $deadDestIds, int $y): string {
            $rowType = $row['type'] ?? 0;
            $rowStatus = $row['status'] ?? 0;
            $rowFinalDest = is_string($row['final_dest'] ?? '') ? (string)($row['final_dest'] ?? '') : '';
            $destForView = trim(is_scalar($row['dest_for_view'] ?? '') ? (string)($row['dest_for_view'] ?? '') : '');
            $statusTitle = '';
            if ($rowStatus == ABJ404_STATUS_MANUAL) {
                $statusTitle = __('Manually created', '404-solution');
            } else if ($rowStatus == ABJ404_STATUS_AUTO) {
                $statusTitle = __('Automatically created', '404-solution');
            } else if ($rowStatus == ABJ404_STATUS_REGEX) {
                $statusTitle = __('Regular Expression (Manually Created)', '404-solution');
            } else {
                $statusTitle = __('Unknown', '404-solution');
            }

            $destLink = $this->resolveRedirectDestLink($rowType, $rowFinalDest);
            $link = $destLink['link'];
            $title = $destLink['title'];
            if ($link != '') {
                $link = "href='" . esc_url($link) . "'";
            }

            $hits = is_scalar($row['logshits'] ?? 0) ? (int)($row['logshits'] ?? 0) : 0;

            $last_used = is_scalar($row['last_used'] ?? 0) ? (int)($row['last_used'] ?? 0) : 0;
            if ($last_used != 0) {
                $last = (string)wp_date("Y/m/d h:i:s A", abs($last_used));
            } else {
                $last = __('Never Used', '404-solution');
            }

            // Build action links using helper method
            /** @var array<string, mixed> $row */
            $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, false);
            $editlink = '';
            $logslink = '';
            $trashlink = '';
            $trashtitle = '';
            $deletelink = '';
            $ajaxTrashLink = '';
            extract($links);

            $class = "";
            if ($y == 0) {
                $class = "alternate";
                $y++;
            } else {
                $y = 0;
                $class = "normal-non-alternate";
            }
            // make the entire row red if the destination is missing, doesn't exist, or is unpublished.
            $destinationDoesNotExistClass = '';
            $destinationIsMissing = false;
            if ($rowType != ABJ404_TYPE_404_DISPLAYED && trim((string)$rowFinalDest) === '') {
                $destinationIsMissing = true;
                $destinationDoesNotExistClass = ' destination-does-not-exist';
            }
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationDoesNotExistClass = ' destination-does-not-exist';
                }
            }

            // Check if URL looks like a regex pattern but is not marked as a regex redirect
            $urlLooksLikeRegexClass = '';
            $rowUrl = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
            $urlLooksLikeRegex = ABJ_404_Solution_Functions::urlLooksLikeRegex($rowUrl);
            $isRegexStatus = ($rowStatus == ABJ404_STATUS_REGEX);
            if ($urlLooksLikeRegex && !$isRegexStatus) {
                $urlLooksLikeRegexClass = ' url-looks-like-regex';
            }

            $class = $class . $destinationDoesNotExistClass . $urlLooksLikeRegexClass;

            $currentFilter = $tableOptions['filter'] ?? 0;
            $logsId = is_scalar($row['logsid'] ?? 0) ? (int)($row['logsid'] ?? 0) : 0;
            $btns = $this->buildRedirectRowActionButtons($currentFilter, $editlink, $logsId);
            $editBtnHTML   = $btns['edit'];
            $logsBtnHTML   = $btns['logs'];
            $trashBtnHTML  = $btns['trash'];
            $deleteBtnHTML = $btns['delete'];

            // Determine badge classes
            $statusBadgeClass = 'abj404-badge-manual';
            if ($rowStatus == ABJ404_STATUS_AUTO) {
                $statusBadgeClass = 'abj404-badge-auto';
            } else if ($rowStatus == ABJ404_STATUS_REGEX) {
                $statusBadgeClass = 'abj404-badge-regex';
            }

            $rowCode = is_scalar($row['code'] ?? '') ? (string)($row['code'] ?? '') : '';
            $codeBadgeMap = array(
                '301' => 'abj404-badge-301',
                '302' => 'abj404-badge-302',
                '307' => 'abj404-badge-307',
                '308' => 'abj404-badge-308',
                '410' => 'abj404-badge-410',
                '451' => 'abj404-badge-451',
                '0'   => 'abj404-badge-meta',
            );
            $codeBadgeClass = isset($codeBadgeMap[$rowCode]) ? $codeBadgeMap[$rowCode] : 'abj404-badge-302';

            // In Simple mode, show plain language labels instead of numeric codes
            $codeDisplay = $rowCode;
            if ($this->logic->getSettingsMode() === 'simple') {
                $codeDisplay = ABJ_404_Solution_View_RedirectTypeUI::getPlainLanguageCodeLabel($rowCode);
            }

            $lastUsedClass = '';
            if ($last_used == 0) {
                $lastUsedClass = 'abj404-never-used';
            }

            $destWarning = $this->resolveDestinationWarnings($row, $rowType, $rowFinalDest, $destForView, $destinationIsMissing, $deadDestIds);
            $destinationExists = $destWarning['exists'];
            $destinationDoesNotExist = $destWarning['notExists'];
            $destinationWarningText = $destWarning['text'];
            $destForView = $destWarning['destForView'];

            // URL regex warning visibility
            $urlIsNormal = '';
            $urlLooksLikeRegexWarning = 'display: none;';
            if ($urlLooksLikeRegex && !$isRegexStatus) {
                $urlIsNormal = 'display: none;';
                $urlLooksLikeRegexWarning = '';
            }

            // Build full URL with WordPress base path for subdirectory installations
            $rowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
            $fullVisitorURL = esc_url(home_url($rowUrl));

            $rowEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
            $engineHTML = ($rowEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($rowEngine) . '</span>' : '';
            $scoreCell = $this->buildScoreCell($row['score'] ?? null, $rowEngine);
            $statusForView = is_string($row['status_for_view'] ?? '') ? (string)($row['status_for_view'] ?? '') : '';
            $typeForView = is_string($row['type_for_view'] ?? '') ? (string)($row['type_for_view'] ?? '') : '';

            return $this->fillRedirectRowTemplate([
                '{rowid}' => $rowId, '{rowClass}' => $class,
                '{visitorURL}' => $fullVisitorURL, '{rowURL}' => esc_html($rowUrl),
                '{url-is-normal}' => $urlIsNormal, '{url-looks-like-regex}' => $urlLooksLikeRegexWarning,
                '{editBtnHTML}' => $editBtnHTML, '{logsBtnHTML}' => $logsBtnHTML,
                '{trashBtnHTML}' => $trashBtnHTML, '{deleteBtnHTML}' => $deleteBtnHTML,
                '{statusBadgeClass}' => $statusBadgeClass, '{codeBadgeClass}' => $codeBadgeClass,
                '{lastUsedClass}' => $lastUsedClass,
                '{link}' => $link, '{title}' => esc_attr($title),
                '{dest}' => esc_attr($destForView),
                '{destination-exists}' => $destinationExists,
                '{destination-does-not-exist}' => $destinationDoesNotExist,
                '{destination-warning-text}' => $destinationWarningText,
                '{status}' => $statusForView, '{statusTitle}' => $statusTitle,
                '{engineHTML}' => $engineHTML, '{rowScore}' => '', '{scoreCell}' => $scoreCell,
                '{type}' => $typeForView, '{rowCode}' => esc_html($codeDisplay),
                '{hits}' => esc_html((string)$hits),
                '{logsLink}' => $logslink, '{trashLink}' => $trashlink,
                '{ajaxTrashLink}' => $ajaxTrashLink, '{trashtitle}' => $trashtitle,
                '{deletelink}' => $deletelink,
                '{created_date}' => esc_html((string)wp_date("Y/m/d h:i:s A", abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0))),
                '{last_used_date}' => esc_html($last),
            ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @param string $destForView
     * @param bool $destinationIsMissing
     * @param array<mixed> $deadDestIds
     * @return array{exists: string, notExists: string, text: string, destForView: string}
     */
    public function resolveDestinationWarnings(array $row, $rowType, string $rowFinalDest, string $destForView, bool $destinationIsMissing, array $deadDestIds): array {
        $exists = '';
        $notExists = 'display: none;';
        $text = __("This page doesn't exist or is not published so the redirect won't work.", '404-solution');
        if ($destinationIsMissing) {
            $exists = 'display: none;';
            $notExists = '';
            $text = __('Destination missing. Edit this redirect and choose a destination.', '404-solution');
            if (trim((string)$destForView) === '') {
                $destForView = __('(Destination missing)', '404-solution');
            }
        }
        if (array_key_exists('published_status', $row) && $row['published_status'] == '0') {
            $exists = 'display: none;';
            $notExists = '';
            if (trim((string)$destForView) === '') {
                $destForView = __('(Destination unavailable)', '404-solution');
            }
        }
        $rowIdStr = is_scalar($row['id'] ?? '') ? (string) ($row['id'] ?? '') : '';
        if (in_array($rowIdStr, $deadDestIds, true)) {
            $exists = 'display: none;';
            $notExists = '';
            $text = __('Destination returned 404 recently, redirect suspended until destination is restored.', '404-solution');
        }
        return ['exists' => $exists, 'notExists' => $notExists, 'text' => $text, 'destForView' => $destForView];
    }

    /**
     * Build the four action buttons shown in a Page Redirects table row.
     * Hrefs/titles in the returned HTML use {placeholders} resolved later
     * by fillRedirectRowTemplate() via tableRowPageRedirects.html.
     *
     * @param mixed $currentFilter
     * @param string $editlink Already-escaped URL for the edit action.
     * @param int $logsId
     * @return array{edit:string,logs:string,trash:string,delete:string}
     */
    private function buildRedirectRowActionButtons($currentFilter, string $editlink, int $logsId): array {
        $svgEdit    = $this->tpl('viewRedirectsTableSvgEdit.html');
        $svgLogs    = $this->tpl('viewRedirectsTableSvgLogs.html');
        $svgTrash   = $this->tpl('viewRedirectsTableSvgTrash.html');
        $svgRestore = $this->tpl('viewRedirectsTableSvgRestore.html');

        $edit = $logs = $trash = $delete = '';

        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $edit = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($editlink), 'class' => 'abj404-action-link',
                'title' => '{Edit Redirect Details}', 'svg_path' => $svgEdit, 'label' => '{Edit}',
            ));
            $trash = $this->buildActionLink('viewRedirectsTableActionLinkAjax.html', array(
                'data_url' => '{ajaxTrashLink}', 'class' => 'abj404-action-link danger ajax-trash-link',
                'title' => '{Trash Redirected URL}', 'svg_path' => $svgTrash, 'label' => '{Trash}',
            ));
        }
        if ($logsId > 0) {
            $logs = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => '{logsLink}', 'class' => 'abj404-action-link',
                'title' => '{View Redirect Logs}', 'svg_path' => $svgLogs, 'label' => '{Logs}',
            ));
        }
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $trash = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => '{trashLink}', 'class' => 'abj404-action-link',
                'title' => '{Restore}', 'svg_path' => $svgRestore, 'label' => '{Restore}',
            ));
            $delete = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                'href' => '{deletelink}', 'class' => 'abj404-action-link danger',
                'title' => '{Delete Redirect Permanently}', 'svg_path' => $svgTrash, 'label' => '{Delete}',
            ));
        }
        return ['edit' => $edit, 'logs' => $logs, 'trash' => $trash, 'delete' => $delete];
    }

    /**
     * @param array<string, string> $replacements
     * @return string
     */
    public function fillRedirectRowTemplate(array $replacements): string {
        $htmlTemp = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowPageRedirects.html");
        $htmlTemp = $this->f->str_replace(array_keys($replacements), array_values($replacements), $htmlTemp);
        return $this->f->doNormalReplacements($htmlTemp);
    }

    /**
     * @param mixed $rawScore
     * @param string $rowEngine
     * @return string
     */
    public function buildScoreCell($rawScore, string $rowEngine): string {
        if ($rawScore !== null && $rawScore !== '') {
            $scoreNum = (float)(is_numeric($rawScore) ? $rawScore : 0);
            $scorePct = number_format($scoreNum, 0);
            if ($scoreNum >= 80) {
                $scoreBadgeClass = 'abj404-score-high';
            } elseif ($scoreNum >= 50) {
                $scoreBadgeClass = 'abj404-score-medium';
            } else {
                $scoreBadgeClass = 'abj404-score-low';
            }
            return $this->f->str_replace(
                array('{badge_class}', '{score_pct}'),
                array($scoreBadgeClass, esc_html($scorePct)),
                $this->tpl('viewRedirectsTableScoreBadge.html')
            );
        }
        $noScoreTitle = ($rowEngine !== '')
            ? __('No confidence score for this engine', '404-solution')
            : __('Manual redirect, no confidence score', '404-solution');
        return $this->f->str_replace(
            '{title_attr}',
            esc_attr($noScoreTitle),
            $this->tpl('viewRedirectsTableScoreManual.html')
        );
    }

    /**
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @return array{link: string, title: string}
     */
    public function resolveRedirectDestLink($rowType, string $rowFinalDest): array {
        $link = '';
        $title = __('Visit', '404-solution') . ' ';
        if ($rowType == ABJ404_TYPE_EXTERNAL) {
            if ($rowFinalDest !== '') {
                $link = $rowFinalDest;
                $title .= $rowFinalDest;
            }
        } else if ($rowType == ABJ404_TYPE_CAT) {
            if ($rowFinalDest !== '') {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . '|' . ABJ404_TYPE_CAT, 0);
                $link = is_string($permalink['link']) ? $permalink['link'] : '';
                $title .= __('Category:', '404-solution') . ' ' . (is_string($permalink['title']) ? $permalink['title'] : '');
            }
        } else if ($rowType == ABJ404_TYPE_TAG) {
            if ($rowFinalDest !== '') {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . '|' . ABJ404_TYPE_TAG, 0);
                $link = is_string($permalink['link']) ? $permalink['link'] : '';
                $title .= __('Tag:', '404-solution') . ' ' . (is_string($permalink['title']) ? $permalink['title'] : '');
            }
        } else if ($rowType == ABJ404_TYPE_HOME) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . '|' . ABJ404_TYPE_HOME, 0);
            $link = is_string($permalink['link']) ? $permalink['link'] : '';
            $title .= __('Home Page:', '404-solution') . ' ' . (is_string($permalink['title']) ? $permalink['title'] : '');
        } else if ($rowType == ABJ404_TYPE_POST) {
            if ($rowFinalDest !== '') {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . '|' . ABJ404_TYPE_POST, 0);
                $link = is_string($permalink['link']) ? $permalink['link'] : '';
                $title .= is_string($permalink['title']) ? $permalink['title'] : '';
            }
        } else if ($rowType == ABJ404_TYPE_404_DISPLAYED) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . '|' . ABJ404_TYPE_404_DISPLAYED, 0);
            $link = is_string($permalink['link']) ? $permalink['link'] : '';
            $title .= is_string($permalink['title']) ? $permalink['title'] : '';
            if ($rowFinalDest == '0') {
                $link = '';
            }
        } else {
            $this->logger->errorMessage('Unexpected row type while displaying table: ' . $rowType);
        }
        return ['link' => $link, 'title' => $title];
    }
}
