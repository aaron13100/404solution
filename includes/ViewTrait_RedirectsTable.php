<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirects and captured URLs table/list pages.
 */
trait ViewTrait_RedirectsTable {

    /** @return void */
    function echoAdminCapturedURLsPage() {
        $sub = 'abj404_captured';

        $tableOptions = $this->logic->getTableOptions($sub);

        // Modern page wrapper
        echo '<div class="abj404-table-page">';

        // Header with page title
        $isSimpleMode = $this->logic->getSettingsMode() === 'simple';
        echo '<div class="abj404-table-header">';
        echo '<div class="abj404-table-header-text">';
        echo '<h2>' . __('Captured 404 URLs', '404-solution') . '</h2>';
        if ($isSimpleMode) {
            echo '<p class="abj404-guidance-subtitle">'
                . esc_html__('Broken links visitors tried to reach. Create Redirect for important ones, Dismiss the rest.', '404-solution')
                . '</p>';
        }
        echo '</div>';
        echo '</div>';

        // Content tabs — counts are placeholders, populated via AJAX
        echo '<div class="abj404-content-tabs" data-tab-counts-placeholder="1">';
        if ($isSimpleMode) {
            // Simple mode: two tabs — "Needs Review" and "Handled"
            $this->echoContentTab('abj404_captured', ABJ404_STATUS_CAPTURED, __('Needs Review', '404-solution'), '…', $tableOptions);
            $this->echoContentTab('abj404_captured', ABJ404_HANDLED_FILTER, __('Handled', '404-solution'), '…', $tableOptions);
        } else {
            // Advanced mode: full 5-tab layout
            $this->echoContentTab('abj404_captured', 0, __('All', '404-solution'), '…', $tableOptions);
            $this->echoContentTab('abj404_captured', ABJ404_STATUS_CAPTURED, __('Captured', '404-solution'), '…', $tableOptions);
            $this->echoContentTab('abj404_captured', ABJ404_STATUS_IGNORED, __('Ignored', '404-solution'), '…', $tableOptions);
            $this->echoContentTab('abj404_captured', ABJ404_STATUS_LATER, __('Later', '404-solution'), '…', $tableOptions);
            $this->echoContentTab('abj404_captured', ABJ404_TRASH_FILTER, __('Trash', '404-solution'), '…', $tableOptions);
        }
        echo '</div>';

        // Filter bar with server-side search
        $filterText = is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');
        $autoRefresh = '1'; // $sub is always 'abj404_captured' here
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

        echo '<div class="abj404-filter-bar tablenav"'
                . ' data-pagination-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
                . ' data-pagination-ajax-action="ajaxUpdatePaginationLinks"'
                . ' data-pagination-ajax-subpage="' . esc_attr($sub) . '"'
                . ' data-pagination-ajax-nonce="' . esc_attr($paginationNonce) . '"'
                . ' data-pagination-inflight-nonce="' . esc_attr($inflightNonce) . '"'
                . ' data-pagination-current-signature=""'
                . ' data-pagination-current-orderby="' . esc_attr($currentOrderBy) . '"'
                . ' data-pagination-current-order="' . esc_attr($currentOrder) . '"'
                . ' data-pagination-current-filter="' . esc_attr((string)$currentFilter) . '"'
                . ' data-pagination-current-paged="' . esc_attr((string)$currentPaged) . '"'
                . ' data-pagination-current-logsid=""'
                . ' data-pagination-initial-load="1"'
                . ' data-pagination-auto-refresh="' . esc_attr($autoRefresh) . '"'
                . ' data-pagination-refresh-started-text="' . esc_attr(__('Refreshing data in background…', '404-solution')) . '"'
                . ' data-pagination-refresh-finished-text="' . esc_attr(__('Data refreshed', '404-solution')) . '"'
                . ' data-pagination-refresh-available-text="' . esc_attr(__('Refresh available', '404-solution')) . '">';
        echo '<div class="abj404-search-box">';
        echo '<input type="search" name="searchFilter" placeholder="' . esc_attr__('Type to filter URLs... (press Enter)', '404-solution') . '" value="' . esc_attr($filterText) . '" data-lpignore="true">';
        echo '</div>';
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . esc_html__('Rows per page:', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select perpage" name="perpage" onchange="paginationLinksChange(this);">';
        foreach ([10, 25, 50, 100, 200] as $opt) {
            $selected = ($perPage == $opt) ? ' selected' : '';
            echo '<option value="' . $opt . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Empty trash button
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $eturl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ABJ404_TRASH_FILTER;
            $eturl = wp_nonce_url($eturl, 'abj404_bulkProcess');
            echo '<a href="' . esc_url($eturl) . '&abj404action=emptyCapturedTrash" class="button abj404-empty-trash-btn" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete all items in trash?', '404-solution')) . '\');">';
            echo esc_html__('Empty Trash', '404-solution');
            echo '</a>';
        }
        echo '<span class="abj404-refresh-status" aria-live="polite"></span>';
        echo '</div>';

        // Bulk actions bar
        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);
        echo '<div class="abj404-bulk-actions">';
        echo '<div class="abj404-selection-info"><strong>0</strong> ' . __('selected', '404-solution') . '</div>';
        echo '<div class="abj404-bulk-buttons">';

        // Bulk action buttons based on current filter and mode
        if ($isSimpleMode) {
            // Simple mode: just "Dismiss" and "Create Redirect"
            echo '<button type="submit" name="abj404action" value="bulkignore" form="bulk-action-form" class="button">' . __('Dismiss', '404-solution') . '</button>';
            echo '<button type="submit" name="abj404action" value="editRedirect" form="bulk-action-form" class="button">' . __('Create Redirect', '404-solution') . '</button>';
        } else {
            // Advanced mode: full set of bulk actions
            if ($currentFilter != ABJ404_STATUS_CAPTURED) {
                echo '<button type="submit" name="abj404action" value="bulkcaptured" form="bulk-action-form" class="button">' . __('Mark Captured', '404-solution') . '</button>';
            }
            if ($currentFilter != ABJ404_STATUS_IGNORED) {
                echo '<button type="submit" name="abj404action" value="bulkignore" form="bulk-action-form" class="button">' . __('Mark Ignored', '404-solution') . '</button>';
            }
            if ($currentFilter != ABJ404_STATUS_LATER) {
                echo '<button type="submit" name="abj404action" value="bulklater" form="bulk-action-form" class="button">' . __('Organize Later', '404-solution') . '</button>';
            }
            if ($currentFilter != ABJ404_TRASH_FILTER) {
                echo '<button type="submit" name="abj404action" value="bulktrash" form="bulk-action-form" class="button">' . __('Move to Trash', '404-solution') . '</button>';
            }
            echo '<button type="submit" name="abj404action" value="editRedirect" form="bulk-action-form" class="button">' . __('Create Redirect', '404-solution') . '</button>';
        }

        echo '</div>';
        echo '<button type="button" class="abj404-clear-selection" onclick="abj404ClearSelection()">' . __('Clear', '404-solution') . '</button>';
        echo '</div>';

        // Hidden form for bulk actions
        echo '<form id="bulk-action-form" method="POST" action="' . esc_url($url) . '">';
        wp_nonce_field('abj404_bulkProcess');

        // Table + pagination placeholders. Full data is loaded via AJAX
        // so initial page render is not blocked on heavy queries.
        echo '<table class="abj404-table" data-table-awaiting-load="1">';
        echo '<thead><tr><th>' . esc_html__('Loading captured URLs…', '404-solution') . '</th></tr></thead>';
        echo '<tbody><tr><td class="abj404-empty-message">' . esc_html__('Loading captured URLs…', '404-solution') . '</td></tr></tbody>';
        echo '</table>';

        // Pagination placeholder (bottom only, matching original layout).
        echo '<div class="abj404-pagination tablenav abj404-pagination-right">';
        echo '<span class="abj404-refresh-status" aria-live="polite">' . esc_html__('Loading…', '404-solution') . '</span>';
        echo '</div>';

        echo '</form>';
        echo '</div><!-- .abj404-table-page -->';
    }
    
    /**
     * @param string $sub
     * @return string
     */
    function getCapturedURLSPageTable($sub) {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting
        $hitsTooltip = $this->getHitsColumnTooltip($tableOptions);
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'status' => array('title' => __('Status', '404-solution'), 'orderby' => 'status'),
            'hits' => array('title' => __('Hits', '404-solution'), 'orderby' => 'logshits', 'title_attr_html' => $hitsTooltip),
            'timestamp' => array('title' => __('Created', '404-solution'), 'orderby' => 'timestamp', 'class' => 'hide-on-tablet'),
            'last_used' => array('title' => __('Last Used', '404-solution'), 'orderby' => 'last_used', 'title_attr_html' => $hitsTooltip),
        );

        $html = '<table class="abj404-table">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col"><input type="checkbox" id="select-all-captured" aria-label="' . esc_attr__('Select all', '404-solution') . '"></th>';

        // Generate sortable column headers
        foreach ($columns as $key => $col) {
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ($tableOptions['filter'] ?? 0);
            $sortUrl .= "&orderby=" . $col['orderby'];
            $sortState = $this->getHeaderSortState($tableOptions, (string)$col['orderby'], false);
            $newOrder = $sortState['nextOrder'];
            $sortUrl .= "&order=" . $newOrder;

            $extraClass = isset($col['class']) ? ' ' . esc_attr($col['class']) : '';
            $sortClass = trim($sortState['thClass'] . $extraClass);
            $sortIndicator = $sortState['indicator'];

            $classAttr = $sortClass ? ' class="' . trim($sortClass) . '"' : '';

            // Build tooltip HTML if present
            $tooltipHtml = '';
            if (isset($col['title_attr_html']) && !empty($col['title_attr_html'])) {
                // Raw HTML (already escaped where needed)
                $tooltipHtml = '<span class="abj404-header-tooltip lefty-tooltip" aria-label="' . esc_attr__('More info', '404-solution') . '">' .
                    '<span class="abj404-header-tooltip-icon" aria-hidden="true">?</span>' .
                    '<span class="lefty-tooltiptext">' . $col['title_attr_html'] . '</span>' .
                    '</span>';
            }

            $html .= '<th scope="col"' . $classAttr . '><a href="' . esc_url($sortUrl) . '">' . esc_html($col['title']) . $sortIndicator . '</a>' . $tooltipHtml . '</th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody id="the-list">';

        $rows = $this->dao->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRows */
        $typedRows = array_values(array_filter($rows, 'is_array'));
        $this->rememberTableDataSignature($sub, $typedRows);
        $displayed = 0;

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
            $links = $this->buildTableActionLinks($row, $sub, $tableOptions, true);
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

            // Build row action buttons
            $editBtnHTML = '';
            $logsBtnHTML = '';
            $trashBtnHTML = '';
            $deleteBtnHTML = '';
            $ignoreBtnHTML = '';
            $laterBtnHTML = '';

            $currentFilter = $tableOptions['filter'] ?? 0;
            $isSimpleModeRow = $this->logic->getSettingsMode() === 'simple';

            if ($isSimpleModeRow) {
                // Simple mode: just "Create Redirect" and "Dismiss"
                $editBtnHTML = '<a href="' . esc_url($editlink) . '" class="abj404-action-link" title="' . esc_attr__('Create Redirect', '404-solution') . '">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg> '
                    . esc_html__('Create Redirect', '404-solution') . '</a>';
                if ($row['status'] != ABJ404_STATUS_IGNORED) {
                    $ignoreBtnHTML = ' | <a href="' . esc_url($ignorelink) . '" class="abj404-action-link" title="' . esc_attr__('Dismiss', '404-solution') . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Dismiss', '404-solution') . '</a>';
                }
            } else {
                // Advanced mode: full action links
                if ($currentFilter != ABJ404_TRASH_FILTER) {
                    $editBtnHTML = '<a href="' . esc_url($editlink) . '" class="abj404-action-link" title="' . esc_attr__('Edit', '404-solution') . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg> '
                        . esc_html__('Edit', '404-solution') . '</a>';
                }

                if (($row['logsid'] ?? 0) > 0) {
                    $logsBtnHTML = '<a href="' . esc_url($logslink) . '" class="abj404-action-link" title="' . esc_attr__('View Logs', '404-solution') . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Logs', '404-solution') . '</a>';
                }

                if ($currentFilter != ABJ404_TRASH_FILTER) {
                    $trashBtnHTML = '<a href="' . esc_url($trashlink) . '" class="abj404-action-link danger" title="' . esc_attr($trashtitle) . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Trash', '404-solution') . '</a>';
                }

                if ($currentFilter == ABJ404_TRASH_FILTER) {
                    // Show Restore button
                    $trashBtnHTML = '<a href="' . esc_url($trashlink) . '" class="abj404-action-link" title="' . esc_attr__('Restore', '404-solution') . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Restore', '404-solution') . '</a>';
                    $deleteBtnHTML = ' | <a href="' . esc_url($deletelink) . '" class="abj404-action-link danger" title="' . esc_attr__('Delete Permanently', '404-solution') . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete this item?', '404-solution')) . '\');">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Delete', '404-solution') . '</a>';
                } else {
                    // Show Ignore and Later buttons (with separators)
                    if ($row['status'] != ABJ404_STATUS_IGNORED) {
                        $ignoreBtnHTML = ' | <a href="' . esc_url($ignorelink) . '" class="abj404-action-link" title="' . esc_attr($ignoretitle) . '">'
                            . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg> '
                            . esc_html__('Ignore', '404-solution') . '</a>';
                    }
                    if ($row['status'] != ABJ404_STATUS_LATER) {
                        $laterBtnHTML = ' | <a href="' . esc_url($laterlink) . '" class="abj404-action-link" title="' . esc_attr($latertitle) . '">'
                            . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg> '
                            . esc_html__('Later', '404-solution') . '</a>';
                    }
                }
            }

            // Build full URL for visiting
            $capturedRowUrl = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
            $capturedRowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
            $fullVisitorURL = esc_url(home_url($capturedRowUrl));

            $tempHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowCapturedURLs.html");
            $tempHtml = $this->f->str_replace('{rowid}', $capturedRowId, $tempHtml);
            $tempHtml = $this->f->str_replace('{rowClass}', '', $tempHtml);
            $tempHtml = $this->f->str_replace('{visitorURL}', $fullVisitorURL, $tempHtml);
            $tempHtml = $this->f->str_replace('{url}', esc_html($capturedRowUrl), $tempHtml);
            $tempHtml = $this->f->str_replace('{statusBadgeClass}', $statusBadgeClass, $tempHtml);
            $tempHtml = $this->f->str_replace('{statusTitle}', esc_attr($statusTitle), $tempHtml);
            $tempHtml = $this->f->str_replace('{status}', $statusText, $tempHtml);
            $capturedEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
            $capturedEngineHTML = ($capturedEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($capturedEngine) . '</span>' : '';
            $tempHtml = $this->f->str_replace('{engineHTML}', $capturedEngineHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{hits}', esc_html((string)$hits), $tempHtml);
            $tempHtml = $this->f->str_replace('{created_date}',
                    esc_html((string)wp_date("Y/m/d h:i:s A", abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0))), $tempHtml);
            $tempHtml = $this->f->str_replace('{last_used_date}', esc_html($last), $tempHtml);
            $tempHtml = $this->f->str_replace('{lastUsedClass}', $lastUsedClass, $tempHtml);
            $tempHtml = $this->f->str_replace('{editBtnHTML}', $editBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{logsBtnHTML}', $logsBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{trashBtnHTML}', $trashBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{deleteBtnHTML}', $deleteBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{ignoreBtnHTML}', $ignoreBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{laterBtnHTML}', $laterBtnHTML, $tempHtml);

            $tempHtml = $this->f->doNormalReplacements($tempHtml);
            $html .= $tempHtml;
        }

        if ($displayed == 0) {
            $html .= '<tr><td colspan="6" class="abj404-empty-message">' . __('No Captured 404 Records To Display', '404-solution') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
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

        // Modern table page wrapper
        echo '<div class="abj404-table-page">';

        // Health status summary — placeholder, populated via a dedicated AJAX call
        // (ajaxRefreshHealthBar) so the slow getHighImpactCapturedCount() query
        // never blocks the table's first paint. JS reads the attrs below to fire
        // an independent request as soon as the placeholder is in the DOM.
        $healthBarNonce = wp_create_nonce('abj404_refreshHealthBar');
        echo '<div class="abj404-health-bar" data-health-bar-placeholder="1"'
            . ' data-health-bar-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
            . ' data-health-bar-ajax-action="ajaxRefreshHealthBar"'
            . ' data-health-bar-nonce="' . esc_attr($healthBarNonce) . '">';
        echo '<span class="abj404-health-dot"></span>';
        echo esc_html__('Loading status…', '404-solution');
        echo '</div>';

        // Page header with Add Redirect button
        echo '<div class="abj404-table-header">';
        echo '<div class="abj404-table-header-text">';
        echo '<h1>' . esc_html__('Page Redirects', '404-solution') . '</h1>';
        if ($this->logic->getSettingsMode() === 'simple') {
            echo '<p class="abj404-guidance-subtitle">'
                . esc_html__('The plugin creates these automatically. You only need to act when the status bar above says so.', '404-solution')
                . '</p>';
        }
        echo '</div>';
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            echo '<button type="button" class="abj404-btn abj404-btn-primary" data-modal-open="abj404-add-redirect-modal">';
            echo '+ ' . esc_html__('Add Redirect', '404-solution');
            echo '</button>';
        }
        echo '</div>';

        // Content tabs — counts are placeholders, populated via AJAX
        echo '<div class="abj404-content-tabs" data-tab-counts-placeholder="1">';
        $this->echoContentTab($sub, 0, __('All', '404-solution'), '…', $tableOptions);
        $this->echoContentTab($sub, ABJ404_STATUS_MANUAL, __('Manual', '404-solution'), '…', $tableOptions);
        $this->echoContentTab($sub, ABJ404_STATUS_AUTO, __('Automatic', '404-solution'), '…', $tableOptions);
        $this->echoContentTab($sub, ABJ404_TRASH_FILTER, __('Trash', '404-solution'), '…', $tableOptions);
        echo '</div>';

        // Filter bar with server-side search
        $filterText = is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $inflightNonce = wp_create_nonce('abj404_fetchInflightStage');
        $autoRefresh = '1'; // $sub is always 'abj404_redirects' here
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $currentOrderBy = is_string($rawOrderBy) ? $rawOrderBy : 'url';
        $rawOrder = $tableOptions['order'] ?? '';
        $currentOrder = is_string($rawOrder) ? $rawOrder : 'ASC';
        $rawPaged = $tableOptions['paged'] ?? 1;
        $currentPaged = is_scalar($rawPaged) ? intval($rawPaged) : 1;
        if ($currentPaged < 1) {
            $currentPaged = 1;
        }
        echo '<div class="abj404-filter-bar tablenav"'
                . ' data-pagination-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
                . ' data-pagination-ajax-action="ajaxUpdatePaginationLinks"'
                . ' data-pagination-ajax-subpage="' . esc_attr($sub) . '"'
                . ' data-pagination-ajax-nonce="' . esc_attr($paginationNonce) . '"'
                . ' data-pagination-inflight-nonce="' . esc_attr($inflightNonce) . '"'
                . ' data-pagination-current-signature=""'
                . ' data-pagination-current-orderby="' . esc_attr($currentOrderBy) . '"'
                . ' data-pagination-current-order="' . esc_attr($currentOrder) . '"'
                . ' data-pagination-current-filter="' . esc_attr((string)$currentFilter) . '"'
                . ' data-pagination-current-paged="' . esc_attr((string)$currentPaged) . '"'
                . ' data-pagination-current-logsid=""'
                . ' data-pagination-initial-load="1"'
                . ' data-pagination-auto-refresh="' . esc_attr($autoRefresh) . '"'
                . ' data-pagination-refresh-started-text="' . esc_attr(__('Refreshing data in background…', '404-solution')) . '"'
                . ' data-pagination-refresh-finished-text="' . esc_attr(__('Data refreshed', '404-solution')) . '"'
                . ' data-pagination-refresh-available-text="' . esc_attr(__('Refresh available', '404-solution')) . '">';
        echo '<div class="abj404-search-box">';
        echo '<input type="search" name="searchFilter" placeholder="' . esc_attr__('Type to filter redirects... (press Enter)', '404-solution') . '" value="' . esc_attr($filterText) . '" data-lpignore="true">';
        echo '</div>';
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . esc_html__('Rows per page:', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select perpage" name="perpage" onchange="paginationLinksChange(this);">';
        foreach ([10, 25, 50, 100, 200] as $opt) {
            $selected = ($perPage == $opt) ? ' selected' : '';
            echo '<option value="' . $opt . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Confidence filter dropdown
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $currentScoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $rawFilter = $tableOptions['filter'] ?? 0;
        $scoreRangeBaseUrl = '?page=' . ABJ404_PP . '&subpage=' . esc_attr($sub) . '&filter=' . (int)(is_scalar($rawFilter) ? $rawFilter : 0);
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . esc_html__('Confidence:', '404-solution') . '</span>';
        $scoreRangeBaseUrlJs = esc_js(esc_url($scoreRangeBaseUrl));
        echo '<select class="abj404-filter-select" name="score_range_filter" onchange="window.location=\'' . $scoreRangeBaseUrlJs . '&score_range=\'+encodeURIComponent(this.value);">';
        $scoreRangeOptions = array(
            'all'    => __('All', '404-solution'),
            'high'   => __('High (≥80%)', '404-solution'),
            'medium' => __('Medium (50–79%)', '404-solution'),
            'low'    => __('Low (<50%)', '404-solution'),
            'manual' => __('Manual (no score)', '404-solution'),
        );
        foreach ($scoreRangeOptions as $val => $label) {
            $sel = ($currentScoreRange === $val) ? ' selected' : '';
            echo '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<span class="abj404-refresh-status" aria-live="polite"></span>';
        echo '</div>';

        // Bulk actions bar (hidden by default, shown when items selected)
        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);

        // Form must wrap both bulk actions and table so dropdown values are submitted
        echo '<form method="POST" action="' . esc_url($url) . '">';

        echo '<div class="abj404-bulk-actions" id="abj404-bulk-actions">';
        echo '<span class="abj404-selection-info"><strong>0</strong> ' . esc_html__('redirects selected', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select" name="abj404action">';
        echo '<option value="">' . esc_html__('Bulk Actions', '404-solution') . '</option>';
        if ($currentFilter != ABJ404_STATUS_AUTO) {
            echo '<option value="editRedirect">' . esc_html__('Edit Redirects', '404-solution') . '</option>';
        }
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            echo '<option value="bulktrash">' . esc_html__('Move to Trash', '404-solution') . '</option>';
        }
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            echo '<option value="bulk_trash_restore">' . esc_html__('Restore Redirects', '404-solution') . '</option>';
            echo '<option value="bulk_trash_delete_permanently">' . esc_html__('Delete Permanently', '404-solution') . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html__('Apply', '404-solution') . '</button>';
        echo '<button type="button" class="abj404-btn abj404-btn-secondary abj404-clear-selection" onclick="abj404ClearSelection()">' . esc_html__('Clear Selection', '404-solution') . '</button>';
        echo '</div>';

        // Table container
        echo '<div class="abj404-table-container">';
        echo '<table class="abj404-table" data-table-awaiting-load="1">';
        echo '<thead><tr><th>' . esc_html__('Loading redirects…', '404-solution') . '</th></tr></thead>';
        echo '<tbody><tr><td class="abj404-empty-message">' . esc_html__('Loading redirects…', '404-solution') . '</td></tr></tbody>';
        echo '</table>';
        echo '</div>';

        // Pagination placeholder (bottom only, matching original layout).
        echo '<div class="abj404-pagination tablenav abj404-pagination-right">';
        echo '<span class="abj404-refresh-status" aria-live="polite">' . esc_html__('Loading…', '404-solution') . '</span>';
        echo '</div>';

        echo '</form>';

        // Empty trash button (within page container but outside form)
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $eturl = "?page=" . ABJ404_PP . "&filter=" . ABJ404_TRASH_FILTER . "&subpage=" . $sub;
            $eturl = wp_nonce_url($eturl, "abj404_bulkProcess");
            echo '<div style="padding: 0 32px 20px;">';
            echo '<form method="POST" action="' . esc_url($eturl) . '">';
            echo '<input type="hidden" name="action" value="emptyRedirectTrash">';
            echo '<button type="submit" class="abj404-btn abj404-btn-secondary" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete all items in the trash?', '404-solution')) . '\')">';
            echo esc_html__('Empty Trash', '404-solution');
            echo '</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>'; // end abj404-table-page

        // Add redirect modal (outside the main container)
        if (($tableOptions['filter'] ?? 0) != ABJ404_TRASH_FILTER) {
            $this->echoAddRedirectModal($tableOptions);
        }
    }

    /**
     * Echo a content tab for the table pages
     */
    /**
     * @param string $sub
     * @param int|string $filter
     * @param string $label
     * @param int|string $count
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    function echoContentTab($sub, $filter, $label, $count, $tableOptions) {
        $currentFilter = $tableOptions['filter'] ?? 0;
        $isActive = ($currentFilter == $filter) ? 'active' : '';
        $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        echo '<a href="' . esc_url($url) . '" class="abj404-content-tab ' . $isActive . '" data-tab-filter="' . esc_attr((string)$filter) . '">';
        echo esc_html($label);
        echo '<span class="abj404-tab-count">' . esc_html((string)$count) . '</span>';
        echo '</a>';
    }

    /**
     * Echo the modern Add Redirect modal
     */
    /**
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    function echoAddRedirectModal($tableOptions) {
        $options = $this->getOptionsWithDefaults();
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

        echo '<div class="abj404-modal" id="abj404-add-redirect-modal">';
        echo '<div class="abj404-modal-content">';
        echo '<div class="abj404-modal-header">';
        echo '<h2>' . esc_html__('Add Manual Redirect', '404-solution') . '</h2>';
        echo '<button type="button" class="abj404-modal-close" onclick="abj404CloseAddRedirectModal()">&times;</button>';
        echo '</div>';
        echo '<form method="POST" action="' . esc_url($link) . '" onsubmit="return validateAddManualRedirectForm(event);">';
        echo '<input type="hidden" name="action" value="addRedirect">';
        echo '<div class="abj404-modal-body">';

        // URL field
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label">' . esc_html__('URL', '404-solution') . ' *</label>';
        echo '<input type="text" name="manual_redirect_url" class="abj404-form-input" placeholder="' . esc_attr($urlPlaceholder) . '" required>';
        echo '<p class="abj404-form-help">' . esc_html__('The URL path that should be redirected (without domain)', '404-solution') . '</p>';
        echo '<div class="abj404-checkbox-group" style="margin-top: 12px;">';
        echo '<input type="checkbox" name="is_regex_url" id="modal_is_regex" class="abj404-checkbox-input" value="1">';
        echo '<label for="modal_is_regex" class="abj404-checkbox-label">' . esc_html__('Treat this URL as a regular expression', '404-solution') . '</label>';
        echo ' <a href="#" class="abj404-regex-toggle" onclick="abj404ToggleRegexInfo(event)">' . esc_html__('(Explain)', '404-solution') . '</a>';
        echo '</div>';
        echo '<div class="abj404-regex-info" style="display: none;">';
        echo '<p>' . esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution') . '</p>';
        echo '<p><strong>' . esc_html__('Example:', '404-solution') . '</strong> <code>/events/(.+)</code></p>';
        echo '<p>' . esc_html__('/events/(.+) will match any URL that begins with /events/ and redirect to the specified page. Since a capture group is used, you can use a $1 replacement in the destination string of an external URL.', '404-solution') . '</p>';
        echo '<p>' . esc_html__('First, all of the normal "exact match" URLs are checked, then all of the regular expression URLs are checked.', '404-solution') . '</p>';
        echo '</div>';
        echo '</div>';

        // Redirect to field - using the existing AJAX autocomplete template.
        // The template provides the label via {redirect_to_label}; no separate PHP label needed.
        echo '<div class="abj404-form-group abj404-autocomplete-wrapper">';

        $redirectHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectPageSearchDropdown.html");
        $redirectHtml = $this->f->str_replace('{redirect_to_label}', esc_html__('Redirect to', '404-solution') . ' *', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
            __('(Type a page name or an external URL)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
            __('(A page has been selected.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
            __('(A custom string has been entered.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
            __('(An external URL will be used.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{redirectPageTitle}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{pageIDAndType}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{data-url}',
            "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $redirectHtml);
        $redirectHtml = $this->f->doNormalReplacements($redirectHtml);
        echo $redirectHtml;

        echo '</div>';

        // Redirect type — button grid
        $rawDefault = $options['default_redirect'] ?? '301';
        $defaultCode = is_string($rawDefault) ? $rawDefault : '301';
        $this->echoRedirectTypeButtonGrid($defaultCode);

        // Advanced Options: schedule + conditions
        echo '<details class="abj404-advanced-options">';
        echo '<summary class="abj404-advanced-options__summary">' . esc_html__('Advanced Options', '404-solution') . '</summary>';
        echo '<div class="abj404-advanced-options__body">';

        // Active From
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label" for="redirect_start_date">' . esc_html__('Active From (optional)', '404-solution') . '</label>';
        echo '<input type="date" name="redirect_start_date" id="redirect_start_date" class="abj404-form-input" value="">';
        echo '<p class="abj404-form-help">' . esc_html__('Leave blank to activate immediately', '404-solution') . '</p>';
        echo '</div>';

        // Active Until
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label" for="redirect_end_date">' . esc_html__('Active Until (optional)', '404-solution') . '</label>';
        echo '<input type="date" name="redirect_end_date" id="redirect_end_date" class="abj404-form-input" value="">';
        echo '<p class="abj404-form-help">' . esc_html__('Leave blank to never expire', '404-solution') . '</p>';
        echo '</div>';

        // Conditions
        $this->echoRedirectConditionsSection();

        echo '</div>'; // end .abj404-advanced-options__body
        echo '</details>';

        echo '</div>'; // end .abj404-modal-body
        echo '<div class="abj404-modal-footer">';
        echo '<button type="button" class="abj404-btn abj404-btn-secondary" onclick="abj404CloseAddRedirectModal()">' . esc_html__('Cancel', '404-solution') . '</button>';
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html__('Add Redirect', '404-solution') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the redirect type button grid, hidden input, and the JS handler.
     * Used by both the Add Redirect modal and the Edit Redirect page.
     *
     * @param string $selectedCode The currently selected code value (e.g. '301').
     * @return void
     */
    /**
     * Get a plain-language label for an HTTP redirect code.
     * Used in Simple mode to replace technical numeric codes.
     *
     * @param string $code The numeric redirect code (e.g. '301', '302').
     * @return string Human-readable label.
     */
    private static function getPlainLanguageCodeLabel(string $code): string {
        $labels = array(
            '301' => __('Permanent', '404-solution'),
            '308' => __('Permanent', '404-solution'),
            '302' => __('Temporary', '404-solution'),
            '307' => __('Temporary', '404-solution'),
            '410' => __('Gone', '404-solution'),
            '451' => __('Blocked', '404-solution'),
            '0'   => __('Meta Refresh', '404-solution'),
        );
        return isset($labels[$code]) ? $labels[$code] : $code;
    }

    private function echoRedirectTypeButtonGrid(string $selectedCode): void {
        $isSimple = $this->logic->getSettingsMode() === 'simple';

        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label">' . esc_html__('Redirect Type', '404-solution') . '</label>';
        echo '<input type="hidden" id="code" name="code" value="' . esc_attr($selectedCode) . '">';
        echo '<div class="abj404-redirect-type-grid">';

        if ($isSimple) {
            // Simple mode: show only Permanent and Temporary
            $codeButtons = array(
                301 => array(__('Permanent', '404-solution'),  __('Best for moved pages', '404-solution')),
                302 => array(__('Temporary', '404-solution'),  __('Best for seasonal or test pages', '404-solution')),
            );
        } else {
            $codeButtons = array(
                301 => array(__('301', '404-solution'),          __('Permanent', '404-solution')),
                302 => array(__('302', '404-solution'),          __('Temporary', '404-solution')),
                307 => array(__('307', '404-solution'),          __('Temp, method-safe', '404-solution')),
                308 => array(__('308', '404-solution'),          __('Perm, method-safe', '404-solution')),
                410 => array(__('410', '404-solution'),          __('Gone', '404-solution')),
                451 => array(__('451', '404-solution'),          __('Legal reasons', '404-solution')),
                0   => array(__('Meta Refresh', '404-solution'), __('HTTP 200 + meta tag', '404-solution')),
            );
        }

        foreach ($codeButtons as $code => $labels) {
            $isActive = ((string)$code === $selectedCode) ? ' abj404-redirect-type-btn--active' : '';
            $isFull   = ($code === 0) ? ' abj404-redirect-type-btn--full' : '';
            echo '<button type="button"'
                . ' class="abj404-redirect-type-btn' . $isActive . $isFull . '"'
                . ' data-code="' . esc_attr((string)$code) . '"'
                . ' onclick="abj404SelectRedirectType(this)">';
            echo '<strong>' . esc_html($labels[0]) . '</strong>';
            echo '<span>' . esc_html($labels[1]) . '</span>';
            echo '</button>';
        }
        echo '</div>';
        if ($isSimple) {
            echo '<p class="abj404-form-help">' . esc_html__('Permanent is best for most redirects. Use Temporary if the page may come back.', '404-solution') . '</p>';
        } else {
            echo '<p class="abj404-form-help">' . esc_html__('Use 301 for permanent page moves. Use 302 for A/B tests or seasonal pages.', '404-solution') . '</p>';
        }
        echo '</div>';
        echo '<script type="text/javascript">';
        echo 'if (typeof window.abj404SelectRedirectType === "undefined") {';
        echo '    window.abj404SelectRedirectType = function(btn) {';
        echo '        var grid = btn.closest(".abj404-redirect-type-grid");';
        echo '        grid.querySelectorAll(".abj404-redirect-type-btn").forEach(function(b) {';
        echo '            b.classList.remove("abj404-redirect-type-btn--active");';
        echo '        });';
        echo '        btn.classList.add("abj404-redirect-type-btn--active");';
        echo '        var hidden = document.getElementById("code");';
        echo '        if (hidden) {';
        echo '            hidden.value = btn.dataset.code;';
        echo '            if (typeof jQuery !== "undefined") { jQuery("#code").trigger("change"); }';
        echo '        }';
        echo '    };';
        echo '}';
        echo '</script>';
    }

	    /**
	     * Get modern pagination HTML
	     */
	    /**
	     * @param string $sub
	     * @param array<string, mixed> $tableOptions
	     * @return string
	     */
	    function getModernPagination($sub, $tableOptions) {
	        $logsid = isset($tableOptions['logsid']) ? intval(is_scalar($tableOptions['logsid']) ? $tableOptions['logsid'] : 0) : 0;
	        $filter = isset($tableOptions['filter']) ? intval(is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0) : 0;
	        $orderby = isset($tableOptions['orderby']) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
	        $order = isset($tableOptions['order']) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';

	        // Use appropriate count method based on sub type
	        $logsidInt = (int)$logsid;
	        if ($sub == 'abj404_logs') {
	            $totalRows = $this->dao->getLogsCount($logsidInt);
	        } else {
	            $totalRows = $this->dao->getRedirectsForViewCount($sub, $tableOptions);
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
	        // Include logsid for logs pagination
	        if ($sub == 'abj404_logs' && isset($tableOptions['logsid'])) {
	            $baseUrl .= "&id=" . $tableOptions['logsid'];
	        }
	        if ($filter != 0) {
	            $baseUrl .= "&filter=" . $filter;
	        }
	        if (!( $orderby == "url" && $order == "ASC" )) {
	            $baseUrl .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
	        }

        // Different label for logs vs redirects
        $itemLabel = ($sub == 'abj404_logs') ? __('logs', '404-solution') : __('redirects', '404-solution');

        $html = '<div class="abj404-pagination">';
        $html .= '<div class="abj404-pagination-info">';
        $html .= sprintf(
            /* translators: %1$d is start item, %2$d is end item, %3$d is total count, %4$s is item type (logs/redirects) */
            esc_html__('Showing %1$d-%2$d of %3$d %4$s', '404-solution'),
            $startItem,
            $endItem,
            $totalRows,
            $itemLabel
        );
        $html .= '</div>';
        $html .= '<div class="abj404-pagination-controls">';

        // Previous button
        if ($currentPage > 1) {
            $html .= '<a href="' . esc_url($baseUrl . '&paged=' . ($currentPage - 1)) . '" class="abj404-page-btn">&lsaquo;</a>';
        } else {
            $html .= '<span class="abj404-page-btn disabled">&lsaquo;</span>';
        }

        // Page numbers
        $range = 2;
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $range && $i <= $currentPage + $range)) {
                $activeClass = ($i == $currentPage) ? ' active' : '';
                $html .= '<a href="' . esc_url($baseUrl . '&paged=' . $i) . '" class="abj404-page-btn' . $activeClass . '">' . $i . '</a>';
            } elseif ($i == $currentPage - $range - 1 || $i == $currentPage + $range + 1) {
                $html .= '<span class="abj404-page-ellipsis">&hellip;</span>';
            }
        }

        // Next button
        if ($currentPage < $totalPages) {
            $html .= '<a href="' . esc_url($baseUrl . '&paged=' . ($currentPage + 1)) . '" class="abj404-page-btn">&rsaquo;</a>';
        } else {
            $html .= '<span class="abj404-page-btn disabled">&rsaquo;</span>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
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
        
        // these are used for a GET request so they're not translated.
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
        $hitsTooltip = $this->getHitsColumnTooltip($tableOptions);
        $columns['hits']['title_attr_html'] = $hitsTooltip;
        $columns['timestamp']['title'] = __('Created', '404-solution');
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "10%";
        $columns['timestamp']['class'] = "hide-on-tablet";
        $columns['last_used']['title'] = __('Last Used', '404-solution');
        $columns['last_used']['orderby'] = "last_used";
        $columns['last_used']['width'] = "10%";
        $columns['last_used']['title_attr_html'] = $hitsTooltip;

        $html = "<table class=\"abj404-table\"><thead>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</thead><tbody id=\"the-list\">";
        
        $deadDestIds = function_exists('get_transient') ? get_transient('abj404_dead_dest_ids') : false;
        if (!is_array($deadDestIds)) {
            $deadDestIds = array();
        }

        $rows = $this->dao->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRedirectRows */
        $typedRedirectRows = array_values(array_filter($rows, 'is_array'));
        $this->rememberTableDataSignature($sub, $typedRedirectRows);
        $displayed = 0;
        $y = 1;
        foreach ($typedRedirectRows as $row) {
            $displayed++;
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

            $link = "";
            $title = __('Visit', '404-solution') . " ";
            if ($rowType == ABJ404_TYPE_EXTERNAL) {
                if ($rowFinalDest !== '') {
                    $link = $rowFinalDest;
                    $title .= $rowFinalDest;
                }
            } else if ($rowType == ABJ404_TYPE_CAT) {
                if ($rowFinalDest !== '') {
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . "|" . ABJ404_TYPE_CAT, 0);
                    $link = is_string($permalink['link']) ? $permalink['link'] : '';
                    $title .= __('Category:', '404-solution') . " " . (is_string($permalink['title']) ? $permalink['title'] : '');
                }
            } else if ($rowType == ABJ404_TYPE_TAG) {
                if ($rowFinalDest !== '') {
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . "|" . ABJ404_TYPE_TAG, 0);
                    $link = is_string($permalink['link']) ? $permalink['link'] : '';
                    $title .= __('Tag:', '404-solution') . " " . (is_string($permalink['title']) ? $permalink['title'] : '');
                }
            } else if ($rowType == ABJ404_TYPE_HOME) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . "|" . ABJ404_TYPE_HOME, 0);
                $link = is_string($permalink['link']) ? $permalink['link'] : '';
                $title .= __('Home Page:', '404-solution') . " " . (is_string($permalink['title']) ? $permalink['title'] : '');
            } else if ($rowType == ABJ404_TYPE_POST) {
                if ($rowFinalDest !== '') {
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . "|" . ABJ404_TYPE_POST, 0);
                    $link = is_string($permalink['link']) ? $permalink['link'] : '';
                    $title .= is_string($permalink['title']) ? $permalink['title'] : '';
                }
                
            } else if ($rowType == ABJ404_TYPE_404_DISPLAYED) {
            	$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rowFinalDest . "|" . ABJ404_TYPE_404_DISPLAYED, 0);
            	// for custom 404 page use the link
            	$link = is_string($permalink['link']) ? $permalink['link'] : '';
            	$title .= is_string($permalink['title']) ? $permalink['title'] : '';
            	
            	// for the normal 404 page just use #
            	if ($rowFinalDest == '0') {
            	    $link = '';
            	}
            	
            } else {
                $this->logger->errorMessage("Unexpected row type while displaying table: " . $rowType);
            }
            
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
            $links = $this->buildTableActionLinks($row, $sub, $tableOptions, false);
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
            
            // -------------------------------------------
            // Build modern row action buttons with icons
            $editBtnHTML = '';
            $logsBtnHTML = '';
            $trashBtnHTML = '';
            $deleteBtnHTML = '';

            $currentFilter = $tableOptions['filter'] ?? 0;
            if ($currentFilter != ABJ404_TRASH_FILTER) {
                $editBtnHTML = '<a href="' . esc_url($editlink) . '" class="abj404-action-link" title="{Edit Redirect Details}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg> '
                    . '{Edit}</a>';
                $trashBtnHTML = '<a href="#" class="abj404-action-link danger ajax-trash-link" data-url="{ajaxTrashLink}" title="{Trash Redirected URL}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                    . '{Trash}</a>';
            }
            if (($row['logsid'] ?? 0) > 0) {
                $logsBtnHTML = '<a href="{logsLink}" class="abj404-action-link" title="{View Redirect Logs}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg> '
                    . '{Logs}</a>';
            }
            if ($currentFilter == ABJ404_TRASH_FILTER) {
                $trashBtnHTML = '<a href="{trashLink}" class="abj404-action-link" title="{Restore}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> '
                    . '{Restore}</a>';
                $deleteBtnHTML = ' | <a href="{deletelink}" class="abj404-action-link danger" title="{Delete Redirect Permanently}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                    . '{Delete}</a>';
            }

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
                $codeDisplay = self::getPlainLanguageCodeLabel($rowCode);
            }

            $lastUsedClass = '';
            if ($last_used == 0) {
                $lastUsedClass = 'abj404-never-used';
            }

            // Legacy variables for backwards compatibility
            $editlinkHTML = '';
            $logslinkHTML = '';
            $deletePermanentlyHTML = '';
            
            $destinationExists = '';
            $destinationDoesNotExist = 'display: none;';
            $destinationWarningText = __("This page doesn't exist or is not published so the redirect won't work.", '404-solution');
            if ($destinationIsMissing) {
                $destinationExists = 'display: none;';
                $destinationDoesNotExist = '';
                $destinationWarningText = __('Destination missing. Edit this redirect and choose a destination.', '404-solution');
                if (trim((string)$destForView) === '') {
                    $destForView = __('(Destination missing)', '404-solution');
                }
            }
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationExists = 'display: none;';
                    $destinationDoesNotExist = '';
                    if (trim((string)$destForView) === '') {
                        $destForView = __('(Destination unavailable)', '404-solution');
                    }
                }
            }

            // Dead destination: destination exists in DB but is generating 404s
            $rowIdStr = is_scalar($row['id'] ?? '') ? (string) ($row['id'] ?? '') : '';
            if (in_array($rowIdStr, $deadDestIds, true)) {
                $destinationExists    = 'display: none;';
                $destinationDoesNotExist = '';
                $destinationWarningText = __('Destination returned 404 recently — redirect suspended until destination is restored.', '404-solution');
            }

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

            $htmlTemp = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowPageRedirects.html");
            $htmlTemp = $this->f->str_replace('{rowid}', $rowId, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowClass}', $class, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{visitorURL}', $fullVisitorURL, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowURL}', esc_html($rowUrl), $htmlTemp);

            // URL regex warning
            $htmlTemp = $this->f->str_replace('{url-is-normal}', $urlIsNormal, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{url-looks-like-regex}', $urlLooksLikeRegexWarning, $htmlTemp);

            // Modern row action buttons
            $htmlTemp = $this->f->str_replace('{editBtnHTML}', $editBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{logsBtnHTML}', $logsBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashBtnHTML}', $trashBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{deleteBtnHTML}', $deleteBtnHTML, $htmlTemp);

            // Badge classes
            $htmlTemp = $this->f->str_replace('{statusBadgeClass}', $statusBadgeClass, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{codeBadgeClass}', $codeBadgeClass, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{lastUsedClass}', $lastUsedClass, $htmlTemp);

	            $htmlTemp = $this->f->str_replace('{link}', $link, $htmlTemp);
	            $htmlTemp = $this->f->str_replace('{title}', esc_attr($title), $htmlTemp);
	            $htmlTemp = $this->f->str_replace('{dest}', esc_attr($destForView), $htmlTemp);
	            $htmlTemp = $this->f->str_replace('{destination-exists}', $destinationExists, $htmlTemp);
	            $htmlTemp = $this->f->str_replace('{destination-does-not-exist}', $destinationDoesNotExist, $htmlTemp);
                $htmlTemp = $this->f->str_replace('{destination-warning-text}', $destinationWarningText, $htmlTemp);
            $statusForView = is_string($row['status_for_view'] ?? '') ? (string)($row['status_for_view'] ?? '') : '';
            $typeForView = is_string($row['type_for_view'] ?? '') ? (string)($row['type_for_view'] ?? '') : '';
            $htmlTemp = $this->f->str_replace('{status}', $statusForView, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{statusTitle}', $statusTitle, $htmlTemp);
            $rowEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
            $engineHTML = ($rowEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($rowEngine) . '</span>' : '';
            $htmlTemp = $this->f->str_replace('{engineHTML}', $engineHTML, $htmlTemp);
            $rawScore = $row['score'] ?? null;
            // Keep {rowScore} empty — score now lives in its own Confidence column.
            $htmlTemp = $this->f->str_replace('{rowScore}', '', $htmlTemp);
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
                $scoreCell = '<span class="abj404-score-badge ' . $scoreBadgeClass . '">' . esc_html($scorePct) . '%</span>';
            } else {
                $noScoreTitle = ($rowEngine !== '')
                    ? __('No confidence score for this engine', '404-solution')
                    : __('Manual redirect — no confidence score', '404-solution');
                $scoreCell = '<span class="abj404-score-manual" title="' . esc_attr($noScoreTitle) . '">—</span>';
            }
            $htmlTemp = $this->f->str_replace('{scoreCell}', $scoreCell, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{type}', $typeForView, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowCode}', esc_html($codeDisplay), $htmlTemp);
            $htmlTemp = $this->f->str_replace('{hits}', esc_html((string)$hits), $htmlTemp);
            $htmlTemp = $this->f->str_replace('{logsLink}', $logslink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashLink}', $trashlink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{ajaxTrashLink}', $ajaxTrashLink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashtitle}', $trashtitle, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{deletelink}', $deletelink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{created_date}',
                    esc_html((string)wp_date("Y/m/d h:i:s A", abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0))), $htmlTemp);
            $htmlTemp = $this->f->str_replace('{last_used_date}', esc_html($last), $htmlTemp);

            $htmlTemp = $this->f->doNormalReplacements($htmlTemp);
            $html .= $htmlTemp;
        }
        if ($displayed == 0) {
            $html .= "<tr>\n" .
                "<td colspan=\"10\" class=\"abj404-empty-state\">" .
                "<div class=\"abj404-empty-state-icon\">📋</div>" .
                "<h3>" . __('No Redirect Records To Display', '404-solution') . "</h3>" .
                "<p>" . __('Redirects will appear here once created.', '404-solution') . "</p>" .
                "</td></tr>";
        }
        $html .= "</tbody></table>";
        
        return $html;
    }
    
    /**
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    function echoAddManualRedirect($tableOptions) {

        $options = $this->getOptionsWithDefaults();
        
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

        $html = $this->f->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}', 
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $this->f->str_replace('{redirectPageTitle}', '', $html);
        $html = $this->f->str_replace('{pageIDAndType}', '', $html);
        $html = $this->f->str_replace('{redirectPageTitle}', '', $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);

        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectBottom.html");
        $html = $this->f->str_replace('{addManualRedirectAction}', $link, $html);
        $html = $this->f->str_replace('{urlPlaceholder}', esc_attr($urlPlaceholder), $html);
        $html = $this->f->str_replace('{postedURL}', esc_attr($postedURL), $html);
        $html = $this->f->str_replace('{301selected}', $selected301, $html);
        $html = $this->f->str_replace('{302selected}', $selected302, $html);
        $html = $this->f->str_replace('{307selected}', $selected307, $html);
        $html = $this->f->str_replace('{308selected}', $selected308, $html);
        $html = $this->f->str_replace('{410selected}', $selected410, $html);
        $html = $this->f->str_replace('{451selected}', $selected451, $html);
        $html = $this->f->str_replace('{0selected}', $selected0, $html);

        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        echo $html;
    }
    
    /** This is used both to add and to edit a redirect.
     * @param string $destination
     * @param string $codeselected
     * @param string $label
     */
    /**
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
        // Redirect type — button grid with hidden input
        $this->echoRedirectTypeButtonGrid((string)$codeselected);

        // Advanced Options: Active From/Until + Conditions (collapsed by default)
        {
            $redirectId = 0;
            if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
                $redirectId = absint($_GET['id']);
            } elseif (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
                $redirectId = absint($_POST['id']);
            }
            $hasExistingConditions = ($redirectId > 0) && !empty($this->dao->getRedirectConditions($redirectId));
            $hasAdvancedValues = ($startDate !== '' || $endDate !== '' || $hasExistingConditions);
            $openAttr = $hasAdvancedValues ? ' open' : '';
            echo '<details class="abj404-advanced-options"' . $openAttr . '>';
            echo '<summary class="abj404-advanced-options__summary">' . esc_html__('Advanced Options', '404-solution') . '</summary>';
            echo '<div class="abj404-advanced-options__body">';

            // Active From
            echo '<div class="abj404-form-group">';
            echo '<label class="abj404-form-label" for="redirect_start_date">' . esc_html__('Active From (optional)', '404-solution') . '</label>';
            echo '<input type="date" name="redirect_start_date" id="redirect_start_date" class="abj404-form-input" value="' . esc_attr($startDate) . '">';
            echo '<p class="abj404-form-help">' . esc_html__('Leave blank to activate immediately', '404-solution') . '</p>';
            echo '</div>';

            // Active Until
            echo '<div class="abj404-form-group">';
            echo '<label class="abj404-form-label" for="redirect_end_date">' . esc_html__('Active Until (optional)', '404-solution') . '</label>';
            echo '<input type="date" name="redirect_end_date" id="redirect_end_date" class="abj404-form-input" value="' . esc_attr($endDate) . '">';
            echo '<p class="abj404-form-help">' . esc_html__('Leave blank to never expire', '404-solution') . '</p>';
            echo '</div>';

            // Conditions
            $this->echoRedirectConditionsSection();

            echo '</div>'; // end abj404-advanced-options__body
            echo '</details>';
        }

        // Button group
        echo '<div class="abj404-button-group">';

        // Cancel button
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
        echo '<a href="' . esc_url($cancelUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Cancel', '404-solution') . '</a>';

        // Submit button
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html($label) . '</button>';
        echo '</div>';
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

    /**
     * Render the Conditions section on the Edit Redirect page.
     *
     * Reads the current redirect ID from GET/POST so existing conditions can
     * be pre-populated.  New redirects (id = 0) render an empty container.
     *
     * @return void
     */
    private function echoRedirectConditionsSection(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
            $redirectId = absint($_GET['id']);
        } elseif (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
            $redirectId = absint($_POST['id']);
        }

        $existingConditions = ($redirectId > 0) ? $this->dao->getRedirectConditions($redirectId) : [];

        echo '<div class="abj404-form-group abj404-conditions-section">';
        echo '<h4>' . esc_html__('Conditions (optional)', '404-solution') . '</h4>';
        echo '<p class="abj404-form-help">' . esc_html__('This redirect only fires when all conditions are met. Leave empty to always redirect.', '404-solution') . '</p>';

        echo '<div id="abj404-conditions-container">';
        foreach ($existingConditions as $i => $cond) {
            $this->echoConditionRow($i, $cond);
        }
        echo '</div>';

        echo '<button type="button" onclick="abj404AddConditionRow()" class="button abj404-btn-add-condition">'
            . esc_html__('+ Add Condition', '404-solution')
            . '</button>';

        echo '</div>';

        // Hidden template row (display:none) cloned by JS.
        echo '<script type="text/template" id="abj404-condition-row-template">';
        $this->echoConditionRow('__IDX__', []);
        echo '</script>';

        // Inline JS for dynamic condition rows.
        $this->echoConditionsJavaScript();
    }

    /**
     * Render a single condition row.
     *
     * @param int|string           $index  Row index (used in field names).
     * @param array<string, mixed> $cond   Existing condition data (empty = defaults).
     * @return void
     */
    private function echoConditionRow($index, array $cond): void {
        $logic    = isset($cond['logic'])          && is_string($cond['logic'])          ? $cond['logic']          : 'AND';
        $type     = isset($cond['condition_type']) && is_string($cond['condition_type']) ? $cond['condition_type'] : '';
        $operator = isset($cond['operator'])       && is_string($cond['operator'])       ? $cond['operator']       : 'equals';
        $value    = isset($cond['value'])          && is_string($cond['value'])          ? $cond['value']          : '';
        $sortOrder = isset($cond['sort_order'])    && is_scalar($cond['sort_order'])     ? (int)$cond['sort_order'] : (int)$index;

        $namePrefix = 'conditions[' . $index . ']';

        echo '<div class="abj404-condition-row" data-index="' . esc_attr((string)$index) . '">';

        // Logic (AND / OR) — shown only on rows after the first.
        echo '<select name="' . esc_attr($namePrefix . '[logic]') . '" class="abj404-condition-logic" aria-label="' . esc_attr__('Logic', '404-solution') . '">';
        foreach (['AND' => __('AND', '404-solution'), 'OR' => __('OR', '404-solution')] as $logicVal => $logicLabel) {
            $sel = ($logic === $logicVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($logicVal) . '"' . $sel . '>' . esc_html($logicLabel) . '</option>';
        }
        echo '</select>';

        // Condition type.
        $typeOptions = [
            'login_status' => __('Login Status', '404-solution'),
            'user_role'    => __('User Role', '404-solution'),
            'referrer'     => __('Referrer URL', '404-solution'),
            'user_agent'   => __('User Agent', '404-solution'),
            'ip_range'     => __('IP Range (CIDR)', '404-solution'),
            'http_header'  => __('HTTP Header', '404-solution'),
        ];
        echo '<select name="' . esc_attr($namePrefix . '[condition_type]') . '" class="abj404-condition-type" aria-label="' . esc_attr__('Condition type', '404-solution') . '">';
        echo '<option value="">' . esc_html__('— Select type —', '404-solution') . '</option>';
        foreach ($typeOptions as $typeVal => $typeLabel) {
            $sel = ($type === $typeVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($typeVal) . '"' . $sel . '>' . esc_html($typeLabel) . '</option>';
        }
        echo '</select>';

        // Operator.
        $operatorOptions = [
            'equals'       => __('equals', '404-solution'),
            'not_equals'   => __('not equals', '404-solution'),
            'contains'     => __('contains', '404-solution'),
            'not_contains' => __('does not contain', '404-solution'),
            'regex'        => __('matches regex', '404-solution'),
        ];
        echo '<select name="' . esc_attr($namePrefix . '[operator]') . '" class="abj404-condition-operator" aria-label="' . esc_attr__('Operator', '404-solution') . '">';
        foreach ($operatorOptions as $opVal => $opLabel) {
            $sel = ($operator === $opVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($opVal) . '"' . $sel . '>' . esc_html($opLabel) . '</option>';
        }
        echo '</select>';

        // Value input.
        echo '<input type="text" name="' . esc_attr($namePrefix . '[value]') . '" class="abj404-condition-value abj404-form-input" value="' . esc_attr($value) . '" placeholder="' . esc_attr__('Value', '404-solution') . '" aria-label="' . esc_attr__('Condition value', '404-solution') . '">';

        // Sort order (hidden).
        echo '<input type="hidden" name="' . esc_attr($namePrefix . '[sort_order]') . '" class="abj404-condition-sort-order" value="' . esc_attr((string)$sortOrder) . '">';

        // Remove button.
        echo '<button type="button" class="button abj404-remove-condition" onclick="abj404RemoveConditionRow(this)" aria-label="' . esc_attr__('Remove condition', '404-solution') . '">'
            . esc_html__('Remove', '404-solution')
            . '</button>';

        echo '</div>';
    }

    /**
     * Output inline JavaScript that manages dynamic condition rows.
     *
     * @return void
     */
    private function echoConditionsJavaScript(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id'])) {
            $redirectId = (int)$_GET['id'];
        } elseif (isset($_POST['id']) && is_scalar($_POST['id']) && ctype_digit((string)$_POST['id'])) {
            $redirectId = (int)$_POST['id'];
        }
        $initialIndex = ($redirectId > 0) ? max(1, count($this->dao->getRedirectConditions($redirectId))) : 1;

        echo '<script type="text/javascript">' . "\n";
        echo '(function() {' . "\n";
        echo '    var abj404ConditionIndex = ' . (int)$initialIndex . ';' . "\n";
        echo "\n";
        echo '    window.abj404AddConditionRow = function() {' . "\n";
        echo '        var template = document.getElementById(\'abj404-condition-row-template\');' . "\n";
        echo '        if (!template) { return; }' . "\n";
        echo '        var html = template.innerHTML.replace(/__IDX__/g, String(abj404ConditionIndex));' . "\n";
        echo '        var container = document.getElementById(\'abj404-conditions-container\');' . "\n";
        echo '        if (!container) { return; }' . "\n";
        echo '        var div = document.createElement(\'div\');' . "\n";
        echo '        div.innerHTML = html;' . "\n";
        echo '        while (div.firstChild) {' . "\n";
        echo '            container.appendChild(div.firstChild);' . "\n";
        echo '        }' . "\n";
        echo '        abj404UpdateConditionSortOrders();' . "\n";
        echo '        abj404ConditionIndex++;' . "\n";
        echo '    };' . "\n";
        echo "\n";
        echo '    window.abj404RemoveConditionRow = function(btn) {' . "\n";
        echo '        var row = btn.closest(\'.abj404-condition-row\');' . "\n";
        echo '        if (row) {' . "\n";
        echo '            row.parentNode.removeChild(row);' . "\n";
        echo '            abj404UpdateConditionSortOrders();' . "\n";
        echo '        }' . "\n";
        echo '    };' . "\n";
        echo "\n";
        echo '    function abj404UpdateConditionSortOrders() {' . "\n";
        echo '        var rows = document.querySelectorAll(\'#abj404-conditions-container .abj404-condition-row\');' . "\n";
        echo '        for (var i = 0; i < rows.length; i++) {' . "\n";
        echo '            var so = rows[i].querySelector(\'.abj404-condition-sort-order\');' . "\n";
        echo '            if (so) { so.value = String(i); }' . "\n";
        echo '        }' . "\n";
        echo '    }' . "\n";
        echo '}());' . "\n";
        echo '</script>' . "\n";
    }

}
