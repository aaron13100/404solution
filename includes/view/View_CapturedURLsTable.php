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

    /** @var ABJ_404_Solution_CapturedTableHeaderRenderer|null */
    private $capturedTableHeaderRenderer = null;

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
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

        $isSimpleMode = abj_service('settings_mode_preference')->getMode() === 'simple';

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
        $perPage = isset($tableOptions['perpage']) ? (int)$tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');
        $lazyBackfillNonce = wp_create_nonce('abj404_runLazyBackfill');
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

        $warmup = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->listTableChrome->paginationRefreshStrings();

        echo $this->fillTpl('viewRedirectsTableCapturedPageWrapper.html', array(
            '{captured_title}' => __('Captured 404 URLs', '404-solution'),
            '{subtitle}' => $subtitleHtml,
            '{subsubsub}' => $subsubsubHtml,
            '{data-pagination-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-nonce}' => esc_attr($lazyBackfillNonce),
            '{data-pagination-ajax-subpage}' => esc_attr($sub),
            '{data-pagination-ajax-nonce}' => esc_attr($paginationNonce),
            '{data-pagination-inflight-nonce}' => esc_attr($inflightNonce),
            '{data-pagination-current-orderby}' => esc_attr($currentOrderBy),
            '{data-pagination-current-order}' => esc_attr($currentOrder),
            '{data-pagination-current-filter}' => esc_attr((string)$currentFilter),
            '{data-pagination-current-paged}' => esc_attr((string)$currentPaged),
            '{data-pagination-current-score-range}' => esc_attr($currentScoreRange),
            '{data-pagination-auto-refresh}' => esc_attr($autoRefresh),
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
            '{loading_badge_text}' => esc_html__('Loading...', '404-solution'),
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
        $isSimpleModeRow = abj_service('settings_mode_preference')->getMode() === 'simple';

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
        $rows = $this->viewReadService->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRows */
        $typedRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedRows);

        return $this->f->str_replace(
            array('{select_all_label}', '{header_cells}', '{body_rows}'),
            array(
                esc_attr__('Select all', '404-solution'),
                $this->buildCapturedHeaderCells($tableOptions),
                $this->buildCapturedBodyRows($sub, $tableOptions, $typedRows),
            ),
            $this->tpl('viewRedirectsTableCapturedTableShell.html')
        );
    }

    /** @param array<string, mixed> $tableOptions */
    private function buildCapturedHeaderCells(array $tableOptions): string {
        return $this->capturedTableHeaderRenderer()->render($tableOptions);
    }

    private function capturedTableHeaderRenderer(): ABJ_404_Solution_CapturedTableHeaderRenderer {
        if ($this->capturedTableHeaderRenderer === null) {
            $this->capturedTableHeaderRenderer = new ABJ_404_Solution_CapturedTableHeaderRenderer(
                $this->f,
                $this->shared,
                $this->viewReadService
            );
        }
        return $this->capturedTableHeaderRenderer;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildCapturedBodyRows(string $sub, array $tableOptions, array $rows): string {
        if (count($rows) === 0) {
            // Same i455 bug class as the redirects table: a pending/errored/
            // stale-empty staged read must not be shown as "No records" while
            // the live source count says captured rows exist.
            $emptyMessage = $this->viewReadService->lastRedirectsViewReadWasIncomplete()
                ? __('Preparing the captured 404s table. The list is still loading and will appear in a moment.', '404-solution')
                : __('No Captured 404 Records To Display', '404-solution');
            return $this->f->str_replace(
                '{message}',
                $emptyMessage,
                $this->tpl('viewRedirectsTableCapturedEmptyRow.html')
            ) . "\n";
        }

        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= $this->capturedBodyRow($sub, $tableOptions, $row);
        }
        return $bodyRows;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $row
     */
    private function capturedBodyRow(string $sub, array $tableOptions, array $row): string {
        $hits = is_scalar($row['logshits'] ?? 0) ? (int)($row['logshits'] ?? 0) : 0;
        $lastUsed = $this->capturedLastUsedPresentation($row);
        $status = $this->capturedStatusPresentation($row);
        $btns = $this->capturedActionButtons($sub, $tableOptions, $row);
        $vars = $this->capturedRowTemplateVars($row, $hits, $lastUsed, $status, $btns);

        $tempHtml = $this->f->str_replace(
            array_keys($vars),
            array_values($vars),
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/tableRowCapturedURLs.html")
        );
        return $this->f->doNormalReplacements($tempHtml);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{date: string, class: string}
     */
    private function capturedLastUsedPresentation(array $row): array {
        $last_used = is_scalar($row['last_used'] ?? 0) ? (int)($row['last_used'] ?? 0) : 0;
        if ($last_used != 0) {
            return array(
                'date' => (string)wp_date("Y/m/d h:i:s A", abs($last_used)),
                'class' => '',
            );
        }

        return array(
            'date' => __('Never', '404-solution'),
            'class' => 'abj404-never-used',
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{class: string, text: string, title: string}
     */
    private function capturedStatusPresentation(array $row): array {
        if ($row['status'] == ABJ404_STATUS_IGNORED) {
            return array(
                'class' => 'abj404-badge-ignored',
                'text' => __('Ignored', '404-solution'),
                'title' => __('Ignored URL - will not be suggested', '404-solution'),
            );
        }
        if ($row['status'] == ABJ404_STATUS_LATER) {
            return array(
                'class' => 'abj404-badge-later',
                'text' => __('Later', '404-solution'),
                'title' => __('Organize Later', '404-solution'),
            );
        }

        return array(
            'class' => 'abj404-badge-captured',
            'text' => __('Captured', '404-solution'),
            'title' => __('Captured 404 URL', '404-solution'),
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $row
     * @return array{edit: string, logs: string, trash: string, delete: string, ignore: string, later: string}
     */
    private function capturedActionButtons(string $sub, array $tableOptions, array $row): array {
        $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, true);

        return $this->buildCapturedRowActionButtons($row, $tableOptions, array(
            'editlink' => $this->linkValue($links, 'editlink'),
            'logslink' => $this->linkValue($links, 'logslink'),
            'trashlink' => $this->linkValue($links, 'trashlink'),
            'trashtitle' => $this->linkValue($links, 'trashtitle'),
            'deletelink' => $this->linkValue($links, 'deletelink'),
            'ignorelink' => $this->linkValue($links, 'ignorelink'),
            'ignoretitle' => $this->linkValue($links, 'ignoretitle'),
            'laterlink' => $this->linkValue($links, 'laterlink'),
            'latertitle' => $this->linkValue($links, 'latertitle'),
        ));
    }

    /** @param array<string, mixed> $links */
    private function linkValue(array $links, string $key): string {
        return is_scalar($links[$key] ?? '') ? (string)($links[$key] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array{date: string, class: string} $lastUsed
     * @param array{class: string, text: string, title: string} $status
     * @param array{edit: string, logs: string, trash: string, delete: string, ignore: string, later: string} $btns
     * @return array<string, string>
     */
    private function capturedRowTemplateVars(array $row, int $hits, array $lastUsed, array $status, array $btns): array {
        $capturedRowUrl = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
        $capturedRowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
        $capturedEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
        $capturedEngineHTML = ($capturedEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($capturedEngine) . '</span>' : '';
        $createdTimestamp = is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0;

        $vars = array(
            '{rowid}' => $capturedRowId,
            '{rowClass}' => '',
            '{visitorURL}' => esc_url(home_url($capturedRowUrl)),
            '{url}' => esc_html($capturedRowUrl),
            '{statusBadgeClass}' => $status['class'],
            '{statusTitle}' => esc_attr($status['title']),
            '{status}' => $status['text'],
            '{engineHTML}' => $capturedEngineHTML,
            '{hits}' => esc_html((string)$hits),
            '{created_date}' => esc_html((string)wp_date("Y/m/d h:i:s A", abs($createdTimestamp))),
            '{last_used_date}' => esc_html($lastUsed['date']),
            '{lastUsedClass}' => $lastUsed['class'],
            '{editBtnHTML}' => $btns['edit'],
            '{logsBtnHTML}' => $btns['logs'],
            '{trashBtnHTML}' => $btns['trash'],
            '{deleteBtnHTML}' => $btns['delete'],
            '{ignoreBtnHTML}' => $btns['ignore'],
            '{laterBtnHTML}' => $btns['later'],
        );
        return $vars;
    }
}
