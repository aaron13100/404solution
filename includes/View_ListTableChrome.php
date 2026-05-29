<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin list-table chrome shared by the Page Redirects and Captured 404 URLs
 * admin tables. Owns the widgets that surround the data rows: subsubsub filter
 * row, modern pagination, per-page selector, score-range filter, bulk-action
 * form URL/options, empty-trash form, and the i18n strings for the pagination
 * auto-refresh indicator.
 *
 * Outside callers:
 *   - includes/ajax/ViewUpdater.php (pagination AJAX -> getModernPagination)
 *   - includes/View_Logs.php (logs table reuses getBulkOperationsFormURL)
 */
class ABJ_404_Solution_View_ListTableChrome extends ABJ_404_Solution_ViewComponent {

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
     * Build the rows-per-page <option> list HTML.
     *
     * @param int|string $currentPerPage
     */
    public function buildPerpageOptions($currentPerPage): string {
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

    /** Build a bulk-action button (the type that posts to the bulk form). */
    public function buildBulkButton(string $value, string $label): string {
        return $this->f->str_replace(
            array('{value}', '{label}'),
            array(esc_attr($value), $label),
            $this->tpl('viewRedirectsTableBulkButton.html')
        ) . "\n";
    }

    /**
     * Build the i18n strings for pagination data-* attrs.
     *
     * @return array{started:string, finished:string, available:string}
     */
    public function paginationRefreshStrings(): array {
        // allow-em-dash: pre-existing translation string with U+2026 ellipsis in published .po files; replacing the char would break translations
        $started = __('Refreshing data in background…', '404-solution');
        $finished = __('Data refreshed', '404-solution');
        $available = __('Refresh available', '404-solution');
        return array('started' => $started, 'finished' => $finished, 'available' => $available);
    }

    /** Build the Confidence-filter <option> list for the Redirects table. */
    public function buildScoreRangeOptions(string $currentScoreRange): string {
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
    public function buildRedirectsBulkActionOptions($currentFilter): string {
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
    public function buildEmptyTrashForm(string $sub): string {
        $eturl = wp_nonce_url("?page=" . ABJ404_PP . "&filter=" . ABJ404_TRASH_FILTER . "&subpage=" . $sub, "abj404_bulkProcess");
        return $this->fillTpl('viewRedirectsTableEmptyTrashForm.html', array(
            '{action_url}' => esc_url($eturl),
            '{confirm_js}' => esc_js(__('Are you sure you want to permanently delete all items in the trash?', '404-solution')),
            '{label}' => esc_html__('Empty Trash', '404-solution'),
        ));
    }

    /**
     * Build the native-WordPress subsubsub filter row for a list-table page.
     *
     * Counts are emitted as a placeholder character; the real numbers are
     * populated by the pagination AJAX response (see view_updater_pagination.js).
     *
     * @param string $sub Subpage key (abj404_redirects, abj404_captured).
     * @param array<int, array{0:int|string, 1:string}> $items One [filterValue, label] pair per link.
     * @param array<string, mixed> $tableOptions Current table options (for active-link detection).
     */
    public function buildSubsubsubFilters(string $sub, array $items, array $tableOptions): string {
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
     * @param array<string, mixed> $tableOptions
     */
    public function getBulkOperationsFormURL(string $sub, array $tableOptions): string {
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
     * Get modern pagination HTML.
     *
     * @param array<string, mixed> $tableOptions
     */
    public function getModernPagination(string $sub, array $tableOptions): string {
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
}
