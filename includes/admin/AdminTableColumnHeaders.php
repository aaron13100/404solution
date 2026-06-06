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

    public function __construct(ABJ_404_Solution_Functions $functions,
            ABJ_404_Solution_PluginLogic $pluginLogic, ABJ_404_Solution_View_Shared $shared) {
        $this->f = $functions;
        $this->logic = $pluginLogic;
        $this->shared = $shared;
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
        $rawNextOrder = $sortState['nextOrder'] ?? '';
        $nextOrder = is_scalar($rawNextOrder) ? (string)$rawNextOrder : '';
        $url .= '&orderby=' . $orderby . '&order=' . $nextOrder;

        return $this->f->str_replace(
            array('{url}', '{orderby}', '{title}', '{sort_indicator}'),
            array(esc_url($url), (string)$orderby, esc_html($title), $sortState['indicator']),
            $this->tpl('viewLogsColumnsHeaderLink.html')
        );
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
            return $this->f->str_replace(
                array('{more_info_label}', '{tooltip_body}'),
                array(esc_attr__('More info', '404-solution'), esc_html($column['title_attr'])),
                $this->tpl('viewLogsColumnsHeaderTooltip.html')
            ) . "\n";
        }

        return '';
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
