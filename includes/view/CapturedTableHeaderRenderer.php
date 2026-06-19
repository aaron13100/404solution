<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by ViewTableRenderingTest

/**
 * Renders the header cells for the Captured 404 URLs admin table.
 */
class ABJ_404_Solution_CapturedTableHeaderRenderer {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_View_Shared */
    private $shared;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewReadService;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_View_Shared $shared
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     */
    public function __construct($functions, ABJ_404_Solution_View_Shared $shared, $viewReadService) {
        $this->f = $functions;
        $this->shared = $shared;
        $this->viewReadService = $viewReadService;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function render(array $tableOptions): string {
        $columns = array(
            array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            array('title' => __('Status', '404-solution'), 'orderby' => 'status'),
            array('title' => __('Hits', '404-solution'), 'orderby' => 'logshits'),
            array('title' => __('Created', '404-solution'), 'orderby' => 'timestamp', 'class' => 'hide-on-tablet'),
            array('title' => __('Last Used', '404-solution'), 'orderby' => 'last_used'),
        );

        $headerCells = '';
        foreach ($columns as $col) {
            $headerCells .= $this->capturedHeaderCell($tableOptions, $col) . "\n";
        }
        return $headerCells;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $col
     */
    private function capturedHeaderCell(array $tableOptions, array $col): string {
        $rawFilter = $tableOptions['filter'] ?? 0;
        $currentFilter = is_scalar($rawFilter) ? $rawFilter : 0;
        $rawOrderby = $col['orderby'] ?? '';
        $orderby = is_scalar($rawOrderby) ? (string)$rawOrderby : '';

        // Post-upgrade window: a URL sort whose narrow url_sort_key cannot be
        // served index-ordered yet would filesort the captured majority (a
        // max_statement_time risk on large sites). The query already serves the
        // safe timestamp-DESC default (ViewQueryBuilder::resolveEffectiveSort), so
        // render this header non-sortable with a progress tooltip rather than a
        // clickable sort link that would silently not take effect. Self-heals:
        // once the sort key is index-ready the header is sortable again with no
        // user action. Shares the readiness + message with the Page Redirects
        // renderer via View_Shared::pendingSortTooltipText.
        $pendingTooltipBody = $this->shared->pendingSortTooltipText($orderby, $this->viewReadService);
        if ($pendingTooltipBody !== '') {
            return $this->capturedPendingHeaderCell($col, $pendingTooltipBody);
        }

        $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . $currentFilter;
        $rawFilterText = $tableOptions['filterText'] ?? '';
        if (is_scalar($rawFilterText) && (string)$rawFilterText !== '') {
            $sortUrl .= "&filterText=" . rawurlencode((string)$rawFilterText);
        }
        $sortUrl .= "&orderby=" . $orderby;
        $sortState = $this->shared->getHeaderSortState($tableOptions, $orderby, false);
        $sortUrl .= "&order=" . $sortState['nextOrder'];

        $rawExtraClass = $col['class'] ?? '';
        $extraClass = is_scalar($rawExtraClass) && $rawExtraClass !== ''
            ? ' ' . esc_attr((string)$rawExtraClass) : '';
        $rawThClass = $sortState['thClass'] ?? '';
        $sortClass = trim((is_scalar($rawThClass) ? (string)$rawThClass : '') . $extraClass);
        $classAttr = $sortClass ? ' class="' . trim($sortClass) . '"' : '';

        $tooltipHtml = '';
        if (isset($col['title_attr_html']) && !empty($col['title_attr_html'])) {
            $tooltipHtml = $this->f->str_replace(
                array('{more_info_label}', '{tooltip_body}'),
                array(esc_attr__('More info', '404-solution'), $this->columnScalar($col, 'title_attr_html')),
                $this->tpl('viewRedirectsTableHeaderTooltip.html')
            );
        }

        return $this->f->str_replace(
            array('{class_attr}', '{sort_url}', '{title}', '{sort_indicator}', '{tooltip_html}'),
            array($classAttr, esc_url($sortUrl), esc_html($this->columnScalar($col, 'title')),
                $this->sortStateScalar($sortState, 'indicator'), $tooltipHtml),
            $this->tpl('viewRedirectsTableCapturedSortableHeader.html')
        );
    }

    /**
     * Render a non-sortable captured-tab header (plain title + progress tooltip,
     * no sort link) for a column whose narrow sort key is not index-ready yet.
     *
     * @param array<string, mixed> $col
     * @param string $tooltipBody The pending-sort progress message.
     */
    private function capturedPendingHeaderCell(array $col, string $tooltipBody): string {
        $rawClass = $col['class'] ?? '';
        $classValue = is_scalar($rawClass) ? (string)$rawClass : '';
        $classAttr = $classValue !== '' ? ' class="' . esc_attr($classValue) . '"' : '';
        $rawTitle = $col['title'] ?? '';
        $titleValue = is_scalar($rawTitle) ? (string)$rawTitle : '';
        $tooltipHtml = $this->f->str_replace(
            array('{more_info_label}', '{tooltip_body}'),
            array(esc_attr__('More info', '404-solution'), esc_html($tooltipBody)),
            $this->tpl('viewRedirectsTableHeaderTooltip.html')
        );
        $tooltipHtml = $this->tagPendingSortTooltip($tooltipHtml, $this->columnScalar($col, 'orderby'));
        return $this->f->str_replace(
            array('{class_attr}', '{title}', '{tooltip_html}'),
            array($classAttr, esc_html($titleValue), $tooltipHtml),
            $this->tpl('viewRedirectsTableCapturedPendingHeader.html')
        );
    }

    private function tagPendingSortTooltip(string $tooltipHtml, string $orderby): string {
        return str_replace(
            'class="abj404-header-tooltip lefty-tooltip"',
            'class="abj404-header-tooltip lefty-tooltip" data-abj404-pending-sort="' . esc_attr($orderby) . '"',
            $tooltipHtml
        );
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string, mixed> $column */
    private function columnScalar(array $column, string $key): string {
        $value = $column[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /** @param array<string, mixed> $sortState */
    private function sortStateScalar(array $sortState, string $key): string {
        $value = $sortState[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}
