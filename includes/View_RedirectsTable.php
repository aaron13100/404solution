<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirects and captured URLs table/list pages.
 */
class ABJ_404_Solution_View_RedirectsTable extends ABJ_404_Solution_ViewComponent {

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
     * Build the rows-per-page <option> list HTML.
     *
     * @param int|string $currentPerPage
     * @return string
     */
    private function buildPerpageOptions($currentPerPage) {
        $optionTpl = $this->tpl('viewRedirectsTablePerpageOption.html');
        $html = '';
        foreach (array(10, 25, 50, 100, 200) as $opt) {
            $selected = ($currentPerPage == $opt) ? ' selected' : '';
            $html .= $this->f->str_replace(
                array('{value}', '{selected_attr}'),
                array((string)$opt, $selected),
                $optionTpl
            ) . "\n";
        }
        return $html;
    }

    /**
     * Build a bulk-action button (the type that posts to the bulk form).
     *
     * @param string $value
     * @param string $label
     * @return string
     */
    private function buildBulkButton($value, $label) {
        return $this->f->str_replace(
            array('{value}', '{label}'),
            array(esc_attr($value), $label),
            $this->tpl('viewRedirectsTableBulkButton.html')
        ) . "\n";
    }

    /**
     * Build an action link with SVG icon.
     *
     * @param string $tplName Template name (e.g. viewRedirectsTableActionLink.html).
     * @param array<string,string> $vars
     * @return string
     */
    private function buildActionLink($tplName, array $vars) {
        $tpl = $this->tpl($tplName);
        foreach ($vars as $k => $v) {
            $tpl = $this->f->str_replace('{' . $k . '}', $v, $tpl);
        }
        return $tpl;
    }

    /**
     * Load a template and substitute an associative array of placeholders.
     * @param array<string,string> $vars
     */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    /**
     * Build the i18n strings for pagination data-* attrs (extracted to keep
     * the ellipsis-bearing translation calls in one place with their allowance markers).
     *
     * @return array{started:string, finished:string, available:string}
     */
    private function paginationRefreshStrings(): array {
        // allow-em-dash: pre-existing translation string with U+2026 ellipsis in published .po files; replacing the char would break translations
        $started = __('Refreshing data in background…', '404-solution');
        $finished = __('Data refreshed', '404-solution');
        $available = __('Refresh available', '404-solution');
        return array('started' => $started, 'finished' => $finished, 'available' => $available);
    }

    /** Build the Confidence-filter <option> list for the Redirects table. */
    private function buildScoreRangeOptions(string $currentScoreRange): string {
        $opts = array(
            'all'    => __('All', '404-solution'),
            // allow-em-dash: pre-existing translation string with U+2265 in published .po files
            'high'   => __('High (≥80%)', '404-solution'),
            // allow-em-dash: pre-existing translation string with U+2013 in published .po files
            'medium' => __('Medium (50–79%)', '404-solution'),
            'low'    => __('Low (<50%)', '404-solution'),
            'manual' => __('Manual (no score)', '404-solution'),
        );
        $tpl = $this->tpl('viewRedirectsTableScoreRangeOption.html');
        $html = '';
        foreach ($opts as $val => $label) {
            $html .= $this->f->str_replace(
                array('{value}', '{selected_attr}', '{label}'),
                array(esc_attr($val), ($currentScoreRange === $val) ? ' selected' : '', esc_html($label)),
                $tpl
            ) . "\n";
        }
        return $html;
    }

    /**
     * Build the bulk-action <option> list for the Redirects table.
     * @param mixed $currentFilter
     */
    private function buildRedirectsBulkActionOptions($currentFilter): string {
        $tpl = $this->tpl('viewRedirectsTableBulkActionOption.html');
        $opts = array();
        if ($currentFilter != ABJ404_STATUS_AUTO)  { $opts[] = array('editRedirect', esc_html__('Edit Redirects', '404-solution')); }
        if ($currentFilter != ABJ404_TRASH_FILTER) { $opts[] = array('bulktrash',    esc_html__('Move to Trash', '404-solution')); }
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $opts[] = array('bulk_trash_restore',           esc_html__('Restore Redirects', '404-solution'));
            $opts[] = array('bulk_trash_delete_permanently', esc_html__('Delete Permanently', '404-solution'));
        }
        $html = '';
        foreach ($opts as $o) {
            $html .= $this->f->str_replace(array('{value}', '{label}'), $o, $tpl) . "\n";
        }
        return $html;
    }

    /** Build the standalone Empty-Trash form shown below the redirects table when viewing the Trash filter. */
    private function buildEmptyTrashForm(string $sub): string {
        $eturl = wp_nonce_url("?page=" . ABJ404_PP . "&filter=" . ABJ404_TRASH_FILTER . "&subpage=" . $sub, "abj404_bulkProcess");
        return $this->fillTpl('viewRedirectsTableEmptyTrashForm.html', array(
            '{action_url}' => esc_url($eturl),
            '{confirm_js}' => esc_js(__('Are you sure you want to permanently delete all items in the trash?', '404-solution')),
            '{label}' => esc_html__('Empty Trash', '404-solution'),
        ));
    }

    /** @return void */
    function echoAdminCapturedURLsPage() {
        $sub = 'abj404_captured';

        $tableOptions = $this->logic->getTableOptions($sub);

        $isSimpleMode = $this->logic->getSettingsMode() === 'simple';

        // Filter row (native WP subsubsub). Counts are placeholders, populated via AJAX.
        if ($isSimpleMode) {
            $items = array(
                array(ABJ404_STATUS_CAPTURED, __('Needs Review', '404-solution')),
                array(ABJ404_HANDLED_FILTER,  __('Handled', '404-solution')),
            );
        } else {
            $items = array(
                array(0,                       __('All', '404-solution')),
                array(ABJ404_STATUS_CAPTURED,  __('Captured', '404-solution')),
                array(ABJ404_STATUS_IGNORED,   __('Ignored', '404-solution')),
                array(ABJ404_STATUS_LATER,     __('Later', '404-solution')),
                array(ABJ404_TRASH_FILTER,     __('Trash', '404-solution')),
            );
        }
        $subsubsubHtml = $this->buildSubsubsubFilters('abj404_captured', $items, $tableOptions);

        // Filter bar with server-side search
        $filterText = is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');
        $autoRefresh = '1';
        $rawFilter = $tableOptions['filter'] ?? 0;
        $currentFilter = is_scalar($rawFilter) ? $rawFilter : 0;
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $currentOrderBy = is_string($rawOrderBy) ? $rawOrderBy : 'url';
        $rawOrder = $tableOptions['order'] ?? '';
        $currentOrder = is_string($rawOrder) ? $rawOrder : 'ASC';
        $rawPaged = $tableOptions['paged'] ?? 1;
        $currentPaged = is_scalar($rawPaged) ? intval($rawPaged) : 1;
        if ($currentPaged < 1) {
            $currentPaged = 1;
        }
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $currentScoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';

        // Subtitle
        $subtitleHtml = '';
        if ($isSimpleMode) {
            $subtitleHtml = $this->f->str_replace(
                '{text}',
                esc_html__('Broken links visitors tried to reach. Create Redirect for important ones, Dismiss the rest.', '404-solution'),
                $this->tpl('viewRedirectsTableSubtitle.html')
            ) . "\n";
        }

        $emptyTrashHtml = '';
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $eturl = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ABJ404_TRASH_FILTER, 'abj404_bulkProcess');
            $emptyTrashHtml = $this->fillTpl('viewRedirectsTableEmptyTrashButton.html', array(
                '{href}' => esc_url($eturl . '&abj404action=emptyCapturedTrash'),
                '{confirm_js}' => esc_js(__('Are you sure you want to permanently delete all items in trash?', '404-solution')),
                '{label}' => esc_html__('Empty Trash', '404-solution'),
            )) . "\n";
        }

        $bulkButtons = '';
        if ($isSimpleMode) {
            $bulkButtons = $this->buildBulkButton('bulkignore', __('Dismiss', '404-solution'))
                . $this->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        } else {
            if ($currentFilter != ABJ404_STATUS_CAPTURED) { $bulkButtons .= $this->buildBulkButton('bulkcaptured', __('Mark Captured', '404-solution')); }
            if ($currentFilter != ABJ404_STATUS_IGNORED)  { $bulkButtons .= $this->buildBulkButton('bulkignore',   __('Mark Ignored', '404-solution')); }
            if ($currentFilter != ABJ404_STATUS_LATER)    { $bulkButtons .= $this->buildBulkButton('bulklater',    __('Organize Later', '404-solution')); }
            if ($currentFilter != ABJ404_TRASH_FILTER)    { $bulkButtons .= $this->buildBulkButton('bulktrash',    __('Move to Trash', '404-solution')); }
            $bulkButtons .= $this->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        }

        // Bulk form action URL + nonce field
        $formAction = $this->getBulkOperationsFormURL($sub, $tableOptions);
        ob_start();
        wp_nonce_field('abj404_bulkProcess');
        $nonceField = (string)ob_get_clean();

        $warmup = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->paginationRefreshStrings();

        echo $this->fillTpl('viewRedirectsTableCapturedPageWrapper.html', array(
            '{captured_title}' => __('Captured 404 URLs', '404-solution'),
            '{subtitle}' => $subtitleHtml,
            '{subsubsub}' => $subsubsubHtml,
            '{data-pagination-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-pagination-ajax-subpage}' => esc_attr($sub),
            '{data-pagination-ajax-nonce}' => esc_attr($paginationNonce),
            '{data-pagination-inflight-nonce}' => esc_attr($inflightNonce),
            '{data-pagination-current-orderby}' => esc_attr($currentOrderBy),
            '{data-pagination-current-order}' => esc_attr($currentOrder),
            '{data-pagination-current-filter}' => esc_attr((string)$currentFilter),
            '{data-pagination-current-paged}' => esc_attr((string)$currentPaged),
            '{data-pagination-current-score-range}' => esc_attr($currentScoreRange),
            '{data-pagination-auto-refresh}' => esc_attr($autoRefresh),
            '{data-pagination-refresh-started-text}' => esc_attr($refresh['started']),
            '{data-pagination-refresh-finished-text}' => esc_attr($refresh['finished']),
            '{data-pagination-refresh-available-text}' => esc_attr($refresh['available']),
            '{search_placeholder}' => esc_attr__('Type to filter URLs... (press Enter)', '404-solution'),
            '{filter_text}' => esc_attr($filterText),
            '{rows_per_page_label}' => esc_html__('Rows per page:', '404-solution'),
            '{perpage_options}' => $this->buildPerpageOptions($perPage),
            '{empty_trash_button}' => $emptyTrashHtml,
            '{selected_label}' => __('selected', '404-solution'),
            '{bulk_buttons}' => $bulkButtons,
            '{clear_label}' => __('Clear', '404-solution'),
            '{form_action}' => esc_url($formAction),
            '{nonce_field}' => $nonceField,
            '{warmup_placeholder}' => $warmup,
        ));
    }

    /**
     * @param string $sub
     * @return string
     */
    /**
     * Build the per-row action buttons (edit/logs/trash/delete/ignore/later/
     * dismiss/create-redirect) for the Captured URLs table. Supports both
     * Simple and Advanced settings modes and the Trash filter variant.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tableOptions
     * @param array<string, string> $links Pre-built action URLs / titles.
     * @return array{edit:string,logs:string,trash:string,delete:string,ignore:string,later:string}
     */
    private function buildCapturedRowActionButtons(array $row, array $tableOptions, array $links): array {
        $svgEdit    = $this->tpl('viewRedirectsTableSvgEdit.html');
        $svgDismiss = $this->tpl('viewRedirectsTableSvgDismiss.html');
        $svgLogs    = $this->tpl('viewRedirectsTableSvgLogs.html');
        $svgTrash   = $this->tpl('viewRedirectsTableSvgTrash.html');
        $svgRestore = $this->tpl('viewRedirectsTableSvgRestore.html');
        $svgX       = $this->tpl('viewRedirectsTableSvgX.html');
        $svgClock   = $this->tpl('viewRedirectsTableSvgClock.html');

        $edit = $logs = $trash = $delete = $ignore = $later = '';

        $currentFilter = $tableOptions['filter'] ?? 0;
        $isSimpleModeRow = $this->logic->getSettingsMode() === 'simple';

        if ($isSimpleModeRow) {
            $edit = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['editlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Create Redirect', '404-solution'),
                'svg_path' => $svgEdit, 'label' => esc_html__('Create Redirect', '404-solution'),
            ));
            if ($row['status'] != ABJ404_STATUS_IGNORED) {
                $ignore = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['ignorelink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr__('Dismiss', '404-solution'),
                    'svg_path' => $svgDismiss, 'label' => esc_html__('Dismiss', '404-solution'),
                ));
            }
            return ['edit'=>$edit,'logs'=>$logs,'trash'=>$trash,'delete'=>$delete,'ignore'=>$ignore,'later'=>$later];
        }

        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $edit = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['editlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Edit', '404-solution'),
                'svg_path' => $svgEdit, 'label' => esc_html__('Edit', '404-solution'),
            ));
        }
        if (($row['logsid'] ?? 0) > 0) {
            $logs = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['logslink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('View Logs', '404-solution'),
                'svg_path' => $svgLogs, 'label' => esc_html__('Logs', '404-solution'),
            ));
        }
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $trash = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['trashlink']), 'class' => 'abj404-action-link danger',
                'title' => esc_attr($links['trashtitle']),
                'svg_path' => $svgTrash, 'label' => esc_html__('Trash', '404-solution'),
            ));
        }

        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $trash = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['trashlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Restore', '404-solution'),
                'svg_path' => $svgRestore, 'label' => esc_html__('Restore', '404-solution'),
            ));
            $delete = $this->buildActionLink('viewRedirectsTableActionLinkConfirm.html', array(
                'href' => esc_url($links['deletelink']), 'class' => 'abj404-action-link danger',
                'title' => esc_attr__('Delete Permanently', '404-solution'),
                'confirm_js' => esc_js(__('Are you sure you want to permanently delete this item?', '404-solution')),
                'svg_path' => $svgX, 'label' => esc_html__('Delete', '404-solution'),
            ));
        } else {
            if ($row['status'] != ABJ404_STATUS_IGNORED) {
                $ignore = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['ignorelink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr($links['ignoretitle']),
                    'svg_path' => $svgDismiss, 'label' => esc_html__('Ignore', '404-solution'),
                ));
            }
            if ($row['status'] != ABJ404_STATUS_LATER) {
                $later = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['laterlink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr($links['latertitle']),
                    'svg_path' => $svgClock, 'label' => esc_html__('Later', '404-solution'),
                ));
            }
        }
        return ['edit'=>$edit,'logs'=>$logs,'trash'=>$trash,'delete'=>$delete,'ignore'=>$ignore,'later'=>$later];
    }

    function getCapturedURLSPageTable(string $sub): string {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting
        $hitsTooltip = $this->shared->getHitsColumnTooltip($tableOptions);
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'status' => array('title' => __('Status', '404-solution'), 'orderby' => 'status'),
            'hits' => array('title' => __('Hits', '404-solution'), 'orderby' => 'logshits', 'title_attr_html' => $hitsTooltip),
            'timestamp' => array('title' => __('Created', '404-solution'), 'orderby' => 'timestamp', 'class' => 'hide-on-tablet'),
            'last_used' => array('title' => __('Last Used', '404-solution'), 'orderby' => 'last_used', 'title_attr_html' => $hitsTooltip),
        );

        $headerTpl = $this->tpl('viewRedirectsTableCapturedSortableHeader.html');
        $tooltipTpl = $this->tpl('viewRedirectsTableHeaderTooltip.html');

        $headerCells = '';
        foreach ($columns as $key => $col) {
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ($tableOptions['filter'] ?? 0);
            $sortUrl .= "&orderby=" . $col['orderby'];
            $sortState = $this->shared->getHeaderSortState($tableOptions, (string)$col['orderby'], false);
            $newOrder = $sortState['nextOrder'];
            $sortUrl .= "&order=" . $newOrder;

            $extraClass = isset($col['class']) ? ' ' . esc_attr($col['class']) : '';
            $sortClass = trim($sortState['thClass'] . $extraClass);
            $sortIndicator = $sortState['indicator'];

            $classAttr = $sortClass ? ' class="' . trim($sortClass) . '"' : '';

            // Build tooltip HTML if present
            $tooltipHtml = '';
            if (isset($col['title_attr_html']) && !empty($col['title_attr_html'])) {
                $tooltipHtml = $this->f->str_replace(
                    array('{more_info_label}', '{tooltip_body}'),
                    array(esc_attr__('More info', '404-solution'), (string)$col['title_attr_html']),
                    $tooltipTpl
                );
            }

            $headerCells .= $this->f->str_replace(
                array('{class_attr}', '{sort_url}', '{title}', '{sort_indicator}', '{tooltip_html}'),
                array($classAttr, esc_url($sortUrl), esc_html($col['title']), $sortIndicator, $tooltipHtml),
                $headerTpl
            ) . "\n";
        }

        $rows = $this->viewReadService->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRows */
        $typedRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedRows);
        $displayed = 0;
        $bodyRows = '';

        foreach ($typedRows as $row) {
            $displayed++;

            $hits = is_scalar($row['logshits'] ?? 0) ? (int)($row['logshits'] ?? 0) : 0;

            $last_used = is_scalar($row['last_used'] ?? 0) ? (int)($row['last_used'] ?? 0) : 0;
            $lastUsedClass = '';
            if ($last_used != 0) {
                $last = (string)wp_date("Y/m/d h:i:s A", abs($last_used));
            } else {
                $last = __('Never', '404-solution');
                $lastUsedClass = 'abj404-never-used';
            }

            // Build action links using helper method
            /** @var array<string, mixed> $row */
            $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, true);
            $editlink = '';
            $logslink = '';
            $trashlink = '';
            $trashtitle = '';
            $deletelink = '';
            $ignorelink = '';
            $ignoretitle = '';
            $laterlink = '';
            $latertitle = '';
            $ajaxTrashLink = '';
            extract($links);

            // Determine status badge
            $statusBadgeClass = 'abj404-badge-captured';
            $statusText = __('Captured', '404-solution');
            $statusTitle = __('Captured 404 URL', '404-solution');

            if ($row['status'] == ABJ404_STATUS_IGNORED) {
                $statusBadgeClass = 'abj404-badge-ignored';
                $statusText = __('Ignored', '404-solution');
                $statusTitle = __('Ignored URL - will not be suggested', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_LATER) {
                $statusBadgeClass = 'abj404-badge-later';
                $statusText = __('Later', '404-solution');
                $statusTitle = __('Organize Later', '404-solution');
            }

            $btns = $this->buildCapturedRowActionButtons($row, $tableOptions, array(
                'editlink' => $editlink, 'logslink' => $logslink,
                'trashlink' => $trashlink, 'trashtitle' => $trashtitle,
                'deletelink' => $deletelink, 'ignorelink' => $ignorelink,
                'ignoretitle' => $ignoretitle, 'laterlink' => $laterlink,
                'latertitle' => $latertitle,
            ));
            $editBtnHTML   = $btns['edit'];
            $logsBtnHTML   = $btns['logs'];
            $trashBtnHTML  = $btns['trash'];
            $deleteBtnHTML = $btns['delete'];
            $ignoreBtnHTML = $btns['ignore'];
            $laterBtnHTML  = $btns['later'];

            // Build full URL for visiting
            $capturedRowUrl = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
            $capturedRowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
            $fullVisitorURL = esc_url(home_url($capturedRowUrl));

            $capturedEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
            $capturedEngineHTML = ($capturedEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($capturedEngine) . '</span>' : '';
            $tempHtml = $this->f->str_replace(
                array_keys($vars = array(
                    '{rowid}' => $capturedRowId,
                    '{rowClass}' => '',
                    '{visitorURL}' => $fullVisitorURL,
                    '{url}' => esc_html($capturedRowUrl),
                    '{statusBadgeClass}' => $statusBadgeClass,
                    '{statusTitle}' => esc_attr($statusTitle),
                    '{status}' => $statusText,
                    '{engineHTML}' => $capturedEngineHTML,
                    '{hits}' => esc_html((string)$hits),
                    '{created_date}' => esc_html((string)wp_date("Y/m/d h:i:s A", abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0))),
                    '{last_used_date}' => esc_html($last),
                    '{lastUsedClass}' => $lastUsedClass,
                    '{editBtnHTML}' => $editBtnHTML,
                    '{logsBtnHTML}' => $logsBtnHTML,
                    '{trashBtnHTML}' => $trashBtnHTML,
                    '{deleteBtnHTML}' => $deleteBtnHTML,
                    '{ignoreBtnHTML}' => $ignoreBtnHTML,
                    '{laterBtnHTML}' => $laterBtnHTML,
                )),
                array_values($vars),
                ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowCapturedURLs.html")
            );
            $bodyRows .= $this->f->doNormalReplacements($tempHtml);
        }

        if ($displayed == 0) {
            $bodyRows .= $this->f->str_replace(
                '{message}',
                __('No Captured 404 Records To Display', '404-solution'),
                $this->tpl('viewRedirectsTableCapturedEmptyRow.html')
            ) . "\n";
        }

        return $this->f->str_replace(
            array('{select_all_label}', '{header_cells}', '{body_rows}'),
            array(esc_attr__('Select all', '404-solution'), $headerCells, $bodyRows),
            $this->tpl('viewRedirectsTableCapturedTableShell.html')
        );
    }

    /**
     * @return void
     */
    function echoAdminRedirectsPage() {

        $sub = 'abj404_redirects';

        $tableOptions = $this->logic->getTableOptions($sub);

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

        $subsubsubHtml = $this->buildSubsubsubFilters($sub, array(
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
        $scoreRangeOptionsHtml = $this->buildScoreRangeOptions($currentScoreRange);

        $bulkActionOptionsHtml = $this->buildRedirectsBulkActionOptions($currentFilter);

        $formAction = $this->getBulkOperationsFormURL($sub, $tableOptions);

        $emptyTrashFormHtml = ($currentFilter == ABJ404_TRASH_FILTER)
            ? $this->buildEmptyTrashForm($sub)
            : '';

        $warmup = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->paginationRefreshStrings();

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
            '{perpage_options}' => $this->buildPerpageOptions($perPage),
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
     * Build the native-WordPress subsubsub filter row for a list-table page.
     *
     * Counts are emitted as a placeholder character; the real numbers are
     * populated by the pagination AJAX response (see view_updater_pagination.js).
     *
     * @param string $sub               Subpage key (abj404_redirects, abj404_captured).
     * @param array<int, array{0:int|string, 1:string}> $items One [filterValue, label] pair per link.
     * @param array<string, mixed> $tableOptions Current table options (for active-link detection).
     * @return string Rendered HTML for the filter row.
     */
    function buildSubsubsubFilters($sub, array $items, array $tableOptions) {
        $currentFilter = isset($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        $itemTpl = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/listTableSubsubsubItem.html");
        $itemsHtml = '';
        $lastIndex = count($items) - 1;
        foreach ($items as $i => $pair) {
            list($filter, $label) = $pair;
            $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
            if ($filter != 0) {
                $url .= "&filter=" . $filter;
            }
            $isCurrent = ($currentFilter == $filter);
            $classAttr = $isCurrent ? ' class="current"' : '';
            $separator = ($i < $lastIndex) ? ' |' : '';

            $row = $itemTpl;
            $row = str_replace('{url}',       esc_url($url),                   $row);
            $row = str_replace('{classAttr}', $classAttr,                      $row);
            $row = str_replace('{filter}',    esc_attr((string)$filter),       $row);
            $row = str_replace('{label}',     esc_html($label),                $row);
            $row = str_replace('{count}',     '&hellip;',                      $row);
            $row = str_replace('{separator}', $separator,                      $row);
            $itemsHtml .= $row;
        }

        $outer = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/listTableSubsubsub.html");
        return str_replace('{items}', $itemsHtml, $outer);
    }

    /**
     * Echo the modern Add Redirect modal.
     *
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    function echoAddRedirectModal($tableOptions) {
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
     * Get modern pagination HTML.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    function getModernPagination($sub, $tableOptions) {
        $logsid = isset($tableOptions['logsid']) ? intval(is_scalar($tableOptions['logsid']) ? $tableOptions['logsid'] : 0) : 0;
        $filter = isset($tableOptions['filter']) ? intval(is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0) : 0;
        $orderby = isset($tableOptions['orderby']) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = isset($tableOptions['order']) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';

        $logsidInt = (int)$logsid;
        if ($sub == 'abj404_logs') {
            $totalRows = $this->viewReadService->getLogsCount($logsidInt);
        } else {
            $totalRows = $this->viewReadService->getRedirectsForViewCount($sub, $tableOptions);
        }
        $rawPerpage = array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;
        $perPage = intval($rawPerpage);
        if ($perPage <= 0) {
            $perPage = 25;
        }
        $rawPaged = array_key_exists('paged', $tableOptions) && is_scalar($tableOptions['paged']) ? $tableOptions['paged'] : 1;
        $currentPage = intval($rawPaged);
        $totalPages = ceil($totalRows / $perPage);

        if ($totalPages <= 1) {
            return '';
        }

        $startItem = (($currentPage - 1) * $perPage) + 1;
        $endItem = min($currentPage * $perPage, $totalRows);

        $baseUrl = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        if ($sub == 'abj404_logs' && isset($tableOptions['logsid'])) {
            $baseUrl .= "&id=" . $tableOptions['logsid'];
        }
        if ($filter != 0) {
            $baseUrl .= "&filter=" . $filter;
        }
        if (!( $orderby == "url" && $order == "ASC" )) {
            $baseUrl .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
        }

        $itemLabel = ($sub == 'abj404_logs') ? __('logs', '404-solution') : __('redirects', '404-solution');

        $infoText = sprintf(
            /* translators: %1$d is start item, %2$d is end item, %3$d is total count, %4$s is item type (logs/redirects) */
            esc_html__('Showing %1$d-%2$d of %3$d %4$s', '404-solution'),
            $startItem,
            $endItem,
            $totalRows,
            $itemLabel
        );

        $linkTpl = $this->tpl('viewRedirectsTablePaginationLink.html');
        $disabledTpl = $this->tpl('viewRedirectsTablePaginationDisabled.html');
        $ellipsisTpl = $this->tpl('viewRedirectsTablePaginationEllipsis.html');

        // Previous button
        if ($currentPage > 1) {
            $prevBtn = $this->f->str_replace(
                array('{href}', '{active_class}', '{label}'),
                array(esc_url($baseUrl . '&paged=' . ($currentPage - 1)), '', '&lsaquo;'),
                $linkTpl
            );
        } else {
            $prevBtn = $this->f->str_replace('{label}', '&lsaquo;', $disabledTpl);
        }

        // Page numbers
        $range = 2;
        $pageNumbers = '';
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $range && $i <= $currentPage + $range)) {
                $activeClass = ($i == $currentPage) ? ' active' : '';
                $pageNumbers .= $this->f->str_replace(
                    array('{href}', '{active_class}', '{label}'),
                    array(esc_url($baseUrl . '&paged=' . $i), $activeClass, (string)$i),
                    $linkTpl
                );
            } elseif ($i == $currentPage - $range - 1 || $i == $currentPage + $range + 1) {
                $pageNumbers .= $ellipsisTpl;
            }
        }

        // Next button
        if ($currentPage < $totalPages) {
            $nextBtn = $this->f->str_replace(
                array('{href}', '{active_class}', '{label}'),
                array(esc_url($baseUrl . '&paged=' . ($currentPage + 1)), '', '&rsaquo;'),
                $linkTpl
            );
        } else {
            $nextBtn = $this->f->str_replace('{label}', '&rsaquo;', $disabledTpl);
        }

        return $this->f->str_replace(
            array('{info_text}', '{prev_btn}', '{page_numbers}', '{next_btn}'),
            array($infoText, $prevBtn, $pageNumbers, $nextBtn),
            $this->tpl('viewRedirectsTablePagination.html')
        );
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    function getBulkOperationsFormURL($sub, $tableOptions) {
        $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        if (!($orderby == "url" && $order == "ASC")) {
            $url .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
        }
        $url = wp_nonce_url($url, 'abj404_bulkProcess');
        return $url;
    }

    /**
     * @param string $sub
     * @return string
     */
    function getAdminRedirectsPageTable($sub) {
        $tableOptions = $this->logic->getTableOptions($sub);
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

    /**
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    function echoAddManualRedirect($tableOptions) {

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
        if (isset($_POST['url']) && $_POST['url'] != '') {
            $postedURL = esc_url($_POST['url']);
        } else {
            $postedURL = $urlPlaceholder;
        }

        $selected301 = ($options['default_redirect'] == '301') ? ' selected ' : '';
        $selected302 = ($options['default_redirect'] == '302') ? ' selected ' : '';
        $selected307 = ($options['default_redirect'] == '307') ? ' selected ' : '';
        $selected308 = ($options['default_redirect'] == '308') ? ' selected ' : '';
        $selected410 = '';
        $selected451 = '';
        $selected0 = '';

        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectTop.html");
        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/addManualRedirectPageSearchDropdown.html");

        $html = $this->f->str_replace(
            array('{redirect_to_label}', '{TOOLTIP_POPUP_EXPLANATION_EMPTY}', '{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                  '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}', '{TOOLTIP_POPUP_EXPLANATION_URL}',
                  '{REDIRECT_TO_USER_FIELD_WARNING}', '{redirectPageTitle}', '{pageIDAndType}', '{data-url}'),
            array(__('Redirect to', '404-solution'),
                  __('(Type a page name or an external URL)', '404-solution'),
                  __('(A page has been selected.)', '404-solution'),
                  __('(A custom string has been entered.)', '404-solution'),
                  __('(An external URL will be used.)', '404-solution'),
                  '', '', '',
                  "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax')),
            $html
        );

        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectBottom.html");
        $html = $this->f->str_replace(
            array('{addManualRedirectAction}', '{urlPlaceholder}', '{postedURL}',
                  '{301selected}', '{302selected}', '{307selected}', '{308selected}',
                  '{410selected}', '{451selected}', '{0selected}'),
            array($link, esc_attr($urlPlaceholder), esc_attr($postedURL),
                  $selected301, $selected302, $selected307, $selected308,
                  $selected410, $selected451, $selected0),
            $html
        );

        // constants and translations.
        $html = $this->f->doNormalReplacements($html);

        echo $html;
    }

    /** This is used both to add and to edit a redirect.
     * @param string $destination
     * @param string $codeselected
     * @param string $label
     * @param string|null $source_page
     * @param string|null $filter
     * @param string|null $orderby
     * @param string|null $order
     * @param string $startDate
     * @param string $endDate
     * @return void
     */
    function echoEditRedirect($destination, $codeselected, $label, $source_page = null, $filter = null, $orderby = null, $order = null, $startDate = '', $endDate = '') {
        // allow-em-dash: comment-only context describing button grid section
        // Redirect type button grid with hidden input
        $this->redirectTypeUI->echoRedirectTypeButtonGrid((string)$codeselected);

        // Advanced Options: Active From/Until + Conditions (collapsed by default)
        $redirectId = 0;
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
            $redirectId = absint($_GET['id']);
        } elseif (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
            $redirectId = absint($_POST['id']);
        }
        $hasExistingConditions = ($redirectId > 0) && !empty($this->redirectsRepository->getRedirectConditions($redirectId));
        $hasAdvancedValues = ($startDate !== '' || $endDate !== '' || $hasExistingConditions);
        $openAttr = $hasAdvancedValues ? ' open' : '';

        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsSection = (string)ob_get_clean();

        echo $this->fillTpl('viewRedirectsTableAdvancedOptions.html', array(
            '{open_attr}' => $openAttr,
            '{advanced_options_label}' => esc_html__('Advanced Options', '404-solution'),
            '{active_from_label}' => esc_html__('Active From (optional)', '404-solution'),
            '{start_date_value}' => esc_attr($startDate),
            '{active_from_help}' => esc_html__('Leave blank to activate immediately', '404-solution'),
            '{active_until_label}' => esc_html__('Active Until (optional)', '404-solution'),
            '{end_date_value}' => esc_attr($endDate),
            '{active_until_help}' => esc_html__('Leave blank to never expire', '404-solution'),
            '{conditions_section}' => $conditionsSection,
        ));

        // Cancel button URL
        $cancelUrl = '?page=' . ABJ404_PP;
        if ($source_page) {
            $cancelUrl .= '&subpage=' . esc_attr($source_page);
        }
        if ($filter !== null) {
            $cancelUrl .= '&filter=' . esc_attr($filter);
        }
        if ($orderby !== null) {
            $cancelUrl .= '&orderby=' . esc_attr($orderby);
        }
        if ($order !== null) {
            $cancelUrl .= '&order=' . esc_attr($order);
        }

        echo $this->f->str_replace(
            array('{cancel_url}', '{cancel_label}', '{submit_label}'),
            array(esc_url($cancelUrl), esc_html__('Cancel', '404-solution'), esc_html($label)),
            $this->tpl('viewRedirectsTableEditButtonGroup.html')
        );
    }

    /**
     * @param string $currentlySelected
     * @return string
     */
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

}
