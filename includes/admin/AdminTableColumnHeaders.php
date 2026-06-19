<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders shared admin list-table column header rows.
 */
class ABJ_404_Solution_AdminTableColumnHeaders {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;
    /** @var ABJ_404_Solution_View_Shared */
    private $shared;
    /** @var ABJ_404_Solution_ViewReadServiceInterface|null Sort-key backfill readiness, for the captured-tab pending-sort headers. */
    private $viewReadService;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_View_Shared $shared
     * @param ABJ_404_Solution_ViewReadServiceInterface|null $viewReadService Optional;
     *   when supplied, the URL / Destination headers on the captured tab are
     *   rendered non-sortable with a progress tooltip while their narrow sort-key
     *   backfill is still running (the post-upgrade window). Null in contexts that
     *   never reach that state (e.g. the logs table).
     */
    public function __construct(ABJ_404_Solution_Functions $functions,
            ABJ_404_Solution_PluginLogic $pluginLogic, ABJ_404_Solution_View_Shared $shared,
            $viewReadService = null) {
        $this->f = $functions;
        $this->logic = $pluginLogic;
        $this->shared = $shared;
        $this->viewReadService = $viewReadService;
    }

    /**
     * @param string $sub
     * @param array<string, array<string, string>> $columns
     */
    public function render(string $sub, array $columns): string {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $selectAllTh = $this->renderSelectAllHeader($sub);
        $columnThs = '';
        foreach ($columns as $column) {
            $columnThs .= $this->renderColumnHeader($sub, $tableOptions, $column);
        }

        return $this->f->str_replace(
            array('{select_all_th}', '{column_ths}'),
            array($selectAllTh, $columnThs),
            $this->tpl('viewLogsColumnsHeaderRow.html')
        );
    }

    private function renderSelectAllHeader(string $sub): string {
        $cbinfoStyle = 'vertical-align: middle; padding-bottom: 4px;';
        if ($sub == 'abj404_logs') {
            $cbinfoStyle .= ' width: 0px;';
        }
        $selectAllCheckbox = '';
        if ($sub != 'abj404_logs') {
            $selectAllCheckbox = $this->f->str_replace(
                '{select_all_label}',
                esc_attr__('Select all', '404-solution'),
                $this->tpl('viewLogsColumnsSelectAllCheckbox.html')
            );
        }

        return $this->f->str_replace(
            array('{cb_info_style}', '{select_all_checkbox}'),
            array($cbinfoStyle, $selectAllCheckbox),
            $this->tpl('viewLogsColumnsSelectAllTh.html')
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, string> $column
     */
    private function renderColumnHeader(string $sub, array $tableOptions, array $column): string {
        $style = '';
        if (isset($column['width']) && $column['width'] != '') {
            $style = ' style="width: ' . esc_attr($column['width']) . ';" ';
        }

        $orderby = isset($column['orderby']) ? $column['orderby'] : '';
        $sortState = $this->shared->getHeaderSortState(
            $tableOptions,
            (string)$orderby,
            ($orderby == 'timestamp' || $orderby == 'last_used' || $orderby == 'logshits')
        );

        // Captured tab, post-upgrade window: the URL / Destination sorts cannot be
        // served index-ordered until their narrow sort-key backfill converges, and
        // ordering by the wide source column would filesort the captured majority
        // (a max_statement_time risk on large sites). Render the header
        // non-sortable with a progress tooltip instead of offering a sort that
        // would hang or silently fall back. Self-heals: once the latch flips the
        // header becomes sortable again with no user action.
        $pendingTooltip = $this->pendingSortTooltip($sub, (string)$orderby);
        if ($pendingTooltip !== '') {
            $sortState['isSortable'] = false;
            $column['title_attr'] = $pendingTooltip;
            $column['pending_sort_orderby'] = (string)$orderby;
            unset($column['title_attr_html']);
        }

        $thClass = $sortState['isSortable'] ? ' ' . $sortState['thClass'] : '';
        if (isset($column['class']) && $column['class'] != '') {
            $thClass .= ' ' . esc_attr($column['class']);
        }

        $titleContent = $this->titleContent($sub, $tableOptions, $column, $sortState);
        return $this->f->str_replace(
            array('{style_attr}', '{extra_class}', '{title_content}', '{tooltip_html}'),
            array($style, $thClass, $titleContent, $this->tooltipHtml($column)),
            $this->tpl('viewLogsColumnsHeaderTh.html')
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, string> $column
     * @param array<string, mixed> $sortState
     */
    private function titleContent(string $sub, array $tableOptions, array $column, array $sortState): string {
        $title = isset($column['title']) ? $column['title'] : '';
        if (!$sortState['isSortable']) {
            return $title;
        }

        $orderby = isset($column['orderby']) ? $column['orderby'] : '';
        $url = '?page=' . ABJ404_PP;
        if ($sub !== '') {
            $url .= '&subpage=' . rawurlencode((string)$sub);
        }
        if ($sub == 'abj404_logs') {
            $rawLogsId = $tableOptions['logsid'] ?? 0;
            $url .= '&id=' . (is_scalar($rawLogsId) ? (string)$rawLogsId : '0');
        }
        if (($tableOptions['filter'] ?? 0) != 0) {
            $rawFilter = $tableOptions['filter'] ?? 0;
            $url .= '&filter=' . (is_scalar($rawFilter) ? (string)$rawFilter : '0');
        }
        $rawFilterText = $tableOptions['filterText'] ?? '';
        if (is_scalar($rawFilterText) && (string)$rawFilterText !== '') {
            $url .= '&filterText=' . rawurlencode((string)$rawFilterText);
        }
        $rawNextOrder = $sortState['nextOrder'] ?? '';
        $nextOrder = is_scalar($rawNextOrder) ? (string)$rawNextOrder : '';
        $url .= '&orderby=' . $orderby . '&order=' . $nextOrder;

        return $this->f->str_replace(
            array('{url}', '{orderby}', '{title}', '{sort_indicator}'),
            array(esc_url($url), (string)$orderby, esc_html($title), $sortState['indicator']),
            $this->tpl('viewLogsColumnsHeaderLink.html')
        );
    }

    /**
     * The hover-tooltip text for a URL / Destination header on a redirects-table
     * tab whose narrow sort key cannot yet be served index-ordered, or '' when the
     * sort is available, this is the Logs tab (a different table, not backed by the
     * redirects sort keys), or no readiness service was supplied. Delegates the
     * readiness + percentage + message to View_Shared::pendingSortTooltipText so
     * the captured-tab renderer (View_CapturedURLsTable) shares the same source.
     * See ViewQueryBuilder::resolveEffectiveSort for the matching query-side
     * substitution (the list shows newest-first meanwhile).
     *
     * @param string $sub
     * @param string $orderby
     * @return string
     */
    private function pendingSortTooltip(string $sub, string $orderby): string {
        // The narrow sort keys back the redirects table (Page Redirects + Captured
        // tabs). The Logs tab sorts a different table, so its url/dest columns are
        // never gated by sort-key readiness. (This renderer serves Page Redirects
        // and Logs; the captured tab uses View_CapturedURLsTable.)
        if ($sub === 'abj404_logs') {
            return '';
        }
        return $this->shared->pendingSortTooltipText($orderby, $this->viewReadService);
    }

    /** @param array<string, string> $column */
    private function tooltipHtml(array $column): string {
        if (array_key_exists('title_attr_html', $column) && !empty($column['title_attr_html'])) {
            return $this->f->str_replace(
                array('{more_info_label}', '{tooltip_body}'),
                array(esc_attr__('More info', '404-solution'), (string)$column['title_attr_html']),
                $this->tpl('viewLogsColumnsHeaderTooltip.html')
            ) . "\n";
        }
        if (array_key_exists('title_attr', $column) && !empty($column['title_attr'])) {
            $tooltipHtml = $this->f->str_replace(
                array('{more_info_label}', '{tooltip_body}'),
                array(esc_attr__('More info', '404-solution'), esc_html($column['title_attr'])),
                $this->tpl('viewLogsColumnsHeaderTooltip.html')
            );
            if (array_key_exists('pending_sort_orderby', $column) && is_scalar($column['pending_sort_orderby'])) {
                $tooltipHtml = str_replace(
                    'class="abj404-header-tooltip lefty-tooltip"',
                    'class="abj404-header-tooltip lefty-tooltip" data-abj404-pending-sort="' . esc_attr((string)$column['pending_sort_orderby']) . '"',
                    $tooltipHtml
                );
            }
            return $tooltipHtml . "\n";
        }

        return '';
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
