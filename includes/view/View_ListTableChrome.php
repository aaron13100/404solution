<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin list-table chrome shared by the Page Redirects and Captured 404 URLs
 * admin tables. Owns the widgets that surround the data rows: subsubsub filter
 * row, per-page selector, score-range filter, bulk-action form URL/options,
 * empty-trash form, and the i18n strings for the pagination auto-refresh
 * indicator. Pagination itself is rendered by ABJ_404_Solution_AdminPaginationLinks
 * (single source of truth) and reaches the page over the same AJAX warmup channel.
 */
class ABJ_404_Solution_View_ListTableChrome extends ABJ_404_Solution_ViewComponent {

    /** Load a template file from includes/html/ and trim its trailing newline. */
    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
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
     * Build the i18n string for the pagination "Refresh available" pill
     * data-* attr. The pill is the only background-refresh surface: the table
     * always renders live from the denorm read, so there is no "refreshing /
     * data refreshed" progress toast (and no started/finished strings).
     *
     * @return array{available:string}
     */
    public function paginationRefreshStrings(): array {
        $available = __('Refresh available', '404-solution');
        return array('available' => $available);
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
        $itemTpl = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/listTableSubsubsubItem.html");
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

        $outer = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/listTableSubsubsub.html");
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

}
