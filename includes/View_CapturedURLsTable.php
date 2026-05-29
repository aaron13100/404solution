<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captured 404 URLs admin page renderer. Owns the page wrapper, the table
 * shell, and the per-row action buttons for the "Captured 404 URLs" admin
 * page (subpage abj404_captured).
 *
 * Outside callers (via the View facade __call dispatch):
 *   - includes/ajax/ViewUpdater.php (pagination AJAX -> getCapturedURLSPageTable)
 *   - PluginLogic admin page entry points (echoAdminCapturedURLsPage)
 */
class ABJ_404_Solution_View_CapturedURLsTable extends ABJ_404_Solution_ViewComponent {

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string,string> $vars */
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

    /** @return void */
    public function echoAdminCapturedURLsPage() {
        $sub = 'abj404_captured';

        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);

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
        $subsubsubHtml = $this->listTableChrome->buildSubsubsubFilters('abj404_captured', $items, $tableOptions);

        // Filter bar with server-side search
        $filterText = is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
        $perPage = (int)($tableOptions['perpage'] ?? 25);

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
            $bulkButtons = $this->listTableChrome->buildBulkButton('bulkignore', __('Dismiss', '404-solution'))
                . $this->listTableChrome->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        } else {
            if ($currentFilter != ABJ404_STATUS_CAPTURED) { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulkcaptured', __('Mark Captured', '404-solution')); }
            if ($currentFilter != ABJ404_STATUS_IGNORED)  { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulkignore',   __('Mark Ignored', '404-solution')); }
            if ($currentFilter != ABJ404_STATUS_LATER)    { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulklater',    __('Organize Later', '404-solution')); }
            if ($currentFilter != ABJ404_TRASH_FILTER)    { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulktrash',    __('Move to Trash', '404-solution')); }
            $bulkButtons .= $this->listTableChrome->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        }

        // Bulk form action URL + nonce field
        $formAction = $this->listTableChrome->getBulkOperationsFormURL($sub, $tableOptions);
        ob_start();
        wp_nonce_field('abj404_bulkProcess');
        $nonceField = (string)ob_get_clean();

        $warmup = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->listTableChrome->paginationRefreshStrings();

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
            '{perpage_options}' => $this->listTableChrome->buildPerpageOptions($perPage),
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

    public function getCapturedURLSPageTable(string $sub): string {

        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);

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
}
