<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the Page Redirects admin page wrapper and table controls.
 */
class ABJ_404_Solution_RedirectsTablePagePresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_View_ListTableChrome */
    private $listTableChrome;

    /** @var ABJ_404_Solution_RedirectAddModalPresenter */
    private $addModalPresenter;

    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_PluginLogic $logic,
            ABJ_404_Solution_View_ListTableChrome $listTableChrome,
            ABJ_404_Solution_RedirectAddModalPresenter $addModalPresenter) {
        $this->functions = $functions;
        $this->logic = $logic;
        $this->listTableChrome = $listTableChrome;
        $this->addModalPresenter = $addModalPresenter;
    }

    /**
     * @return string
     */
    public function render(): string {
        $sub = 'abj404_redirects';
        $tableOptions = $this->logic->settingsUpdate()->sanitizePostData(
            $this->logic->settingsUpdate()->getTableOptions($sub)
        );
        $currentFilter = $this->currentFilter($tableOptions);
        $paginationState = $this->paginationState($tableOptions);
        $refresh = $this->listTableChrome->paginationRefreshStrings();

        $html = $this->fillTpl('viewRedirectsTableRedirectsPageWrapper.html', array(
            '{data-health-bar-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-health-bar-nonce}' => esc_attr(wp_create_nonce('abj404_refreshHealthBar')),
            '{loading_status_text}' => esc_html__('Loading status...', '404-solution'),
            '{redirects_title}' => esc_html__('Page Redirects', '404-solution'),
            '{subtitle}' => $this->subtitleHtml(),
            '{add_redirect_button}' => $this->addRedirectButtonHtml($currentFilter),
            '{subsubsub}' => $this->subsubsubHtml($sub, $tableOptions),
            '{data-pagination-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-nonce}' => esc_attr(wp_create_nonce('abj404_runLazyBackfill')),
            '{data-pagination-ajax-subpage}' => esc_attr($sub),
            '{data-pagination-ajax-nonce}' => esc_attr(wp_create_nonce('abj404_updatePaginationLink')),
            '{data-pagination-inflight-nonce}' => esc_attr(wp_create_nonce('abj404_fetchInflightStage')),
            '{data-pagination-current-orderby}' => esc_attr($paginationState['orderby']),
            '{data-pagination-current-order}' => esc_attr($paginationState['order']),
            '{data-pagination-current-filter}' => esc_attr((string)$currentFilter),
            '{data-pagination-current-paged}' => esc_attr((string)$paginationState['paged']),
            '{data-pagination-current-score-range}' => esc_attr($paginationState['scoreRange']),
            '{data-pagination-auto-refresh}' => esc_attr('1'),
            '{data-pagination-refresh-available-text}' => esc_attr($refresh['available']),
            '{search_placeholder}' => esc_attr__('Type to filter redirects... (press Enter)', '404-solution'),
            '{filter_text}' => esc_attr($this->filterText($tableOptions)),
            '{rows_per_page_label}' => esc_html__('Rows per page:', '404-solution'),
            '{perpage_options}' => $this->listTableChrome->buildPerpageOptions($this->perPage($tableOptions)),
            '{confidence_label}' => esc_html__('Confidence:', '404-solution'),
            '{score_range_base_url_js}' => esc_js(esc_url($this->scoreRangeBaseUrl($currentFilter))),
            '{score_range_options}' => $this->listTableChrome->buildScoreRangeOptions($paginationState['scoreRange']),
            '{form_action}' => esc_url($this->listTableChrome->getBulkOperationsFormURL($sub, $tableOptions)),
            '{selected_label}' => esc_html__('redirects selected', '404-solution'),
            '{bulk_actions_label}' => esc_html__('Bulk Actions', '404-solution'),
            '{bulk_action_options}' => $this->listTableChrome->buildRedirectsBulkActionOptions($currentFilter),
            '{apply_label}' => esc_html__('Apply', '404-solution'),
            '{clear_selection_label}' => esc_html__('Clear Selection', '404-solution'),
            '{warmup_placeholder}' => ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/tableWarmupPlaceholder.html'),
            '{loading_badge_text}' => esc_html__('Loading...', '404-solution'),
            '{empty_trash_form}' => $currentFilter == ABJ404_TRASH_FILTER ? $this->listTableChrome->buildEmptyTrashForm($sub) : '',
        ));

        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $html .= $this->addModalPresenter->render($tableOptions);
        }
        return $html;
    }

    /** @param array<string, mixed> $tableOptions */
    private function currentFilter(array $tableOptions): int {
        $rawFilter = $tableOptions['filter'] ?? 0;
        return is_scalar($rawFilter) ? (int)$rawFilter : 0;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return array{orderby: string, order: string, paged: int, scoreRange: string}
     */
    private function paginationState(array $tableOptions): array {
        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = is_scalar($rawPaged) ? intval($rawPaged) : 1;
        $rawOrderby = $tableOptions['orderby'] ?? '';
        $rawOrder = $tableOptions['order'] ?? '';
        $rawScoreRange = $tableOptions['score_range'] ?? '';

        return array(
            'orderby' => is_string($rawOrderby) ? $rawOrderby : 'url',
            'order' => is_string($rawOrder) ? $rawOrder : 'ASC',
            'paged' => max(1, $paged),
            'scoreRange' => is_string($rawScoreRange) && $rawScoreRange !== '' ? $rawScoreRange : 'all',
        );
    }

    private function subtitleHtml(): string {
        if (abj_service('settings_mode_preference')->getMode() !== 'simple') {
            return '';
        }
        return $this->functions->str_replace(
            '{text}',
            esc_html__('The plugin creates these automatically. You only need to act when the status bar above says so.', '404-solution'),
            $this->tpl('viewRedirectsTableSubtitle.html')
        ) . "\n";
    }

    private function addRedirectButtonHtml(int $currentFilter): string {
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            return '';
        }
        return $this->functions->str_replace(
            '{label}',
            esc_html__('Add Redirect', '404-solution'),
            $this->tpl('viewRedirectsTableAddRedirectButton.html')
        );
    }

    /** @param array<string, mixed> $tableOptions */
    private function subsubsubHtml(string $sub, array $tableOptions): string {
        return $this->listTableChrome->buildSubsubsubFilters($sub, array(
            array(0, __('All', '404-solution')),
            array(ABJ404_STATUS_MANUAL, __('Manual', '404-solution')),
            array(ABJ404_STATUS_AUTO, __('Automatic', '404-solution')),
            array(ABJ404_TRASH_FILTER, __('Trash', '404-solution')),
        ), $tableOptions);
    }

    /** @param array<string, mixed> $tableOptions */
    private function filterText(array $tableOptions): string {
        return is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return int|string
     */
    private function perPage(array $tableOptions) {
        $perPage = $tableOptions['perpage'] ?? 25;
        return (is_int($perPage) || is_string($perPage)) ? $perPage : 25;
    }

    private function scoreRangeBaseUrl(int $currentFilter): string {
        return '?page=' . ABJ404_PP . '&subpage=abj404_redirects&filter=' . $currentFilter;
    }

    /** @param array<string, string> $vars */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->functions->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
