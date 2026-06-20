<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds WordPress-style pagination controls for admin list pages.
 */
class ABJ_404_Solution_AdminPaginationLinks {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;
    /** @var ABJ_404_Solution_View_Shared */
    private $shared;
    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewReadService;

    /** @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
    public function __construct(ABJ_404_Solution_Functions $functions,
            ABJ_404_Solution_PluginLogic $pluginLogic, ABJ_404_Solution_View_Shared $shared,
            $viewReadService) {
        $this->f = $functions;
        $this->logic = $pluginLogic;
        $this->shared = $shared;
        $this->viewReadService = $viewReadService;
    }

    public function render(string $sub): string {
        $state = $this->paginationState($sub);
        return $this->renderTemplate($sub, $state);
    }

    /**
     * @return array{tableOptions: array<string, mixed>, logsid: string, orderby: string, order: string,
     *     filter: string, paged: int, perpage: int, totalPages: int, numRecords: int, filterText: string,
     *     urls: array{first: string, previous: string, next: string, last: string}}
     */
    private function paginationState(string $sub): array {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $logsid = array_key_exists('logsid', $tableOptions) && is_scalar($tableOptions['logsid']) ? $tableOptions['logsid'] : 0;
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;

        $logsidForUrl = $this->scalarToString($logsid);
        $filterForUrl = (int)$filter;
        $url = $this->baseUrl($sub, $logsidForUrl, $orderby, $order, $filterForUrl);
        $numRecords = (int)(($sub == 'abj404_logs')
            ? $this->viewReadService->getLogsCount((int)$logsid)
            : $this->viewReadService->getRedirectsForViewCount($sub, $tableOptions));

        $perpage = absint(array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : ABJ404_OPTION_MIN_PERPAGE);
        if ($perpage == 0) {
            $perpage = ABJ404_OPTION_MIN_PERPAGE;
        }
        $paged = absint(array_key_exists('paged', $tableOptions) && is_scalar($tableOptions['paged']) ? $tableOptions['paged'] : 1);
        if ($paged == 0) {
            $paged = 1;
        }

        $totalPages = (int)ceil($numRecords / $perpage);
        if ($totalPages == 0) {
            $totalPages = 1;
        }

        $urls = $this->navigationUrls($url, $paged, $totalPages);
        $filterText = array_key_exists('filterText', $tableOptions) && is_string($tableOptions['filterText']) ? $tableOptions['filterText'] : '';
        if ($filterText != '') {
            $urls = $this->appendFilterText($urls, $filterText);
        }

        return array(
            'tableOptions' => $tableOptions,
            'logsid' => $logsidForUrl,
            'orderby' => $orderby,
            'order' => $order,
            'filter' => (string)$filterForUrl,
            'paged' => $paged,
            'perpage' => $perpage,
            'totalPages' => $totalPages,
            'numRecords' => $numRecords,
            'filterText' => $filterText,
            'urls' => $urls,
        );
    }

    private function baseUrl(string $sub, string $logsid, string $orderby, string $order, int $filter): string {
        $url = '?page=' . ABJ404_PP;
        if ($sub !== '') {
            $url .= '&subpage=' . rawurlencode((string)$sub);
        }
        if ($sub == 'abj404_logs') {
            $url .= '&id=' . $logsid;
        }
        $url .= '&orderby=' . sanitize_text_field($orderby);
        $url .= '&order=' . sanitize_text_field($order);
        // Emit the signed filter value: the trash (-1) and handled (-2) views
        // are negative sentinels. absint() would fold -1 -> 1 (Manual) and
        // -2 -> 2 (Auto), so paginating within Trash/Handled would silently jump
        // to a different tab. $filter is already an int from paginationState().
        $url .= '&filter=' . (int)$filter;
        return $url;
    }

    /**
     * @return array{first: string, previous: string, next: string, last: string}
     */
    private function navigationUrls(string $url, int $paged, int $totalPages): array {
        $prevurl = ($paged == 1) ? $url : $url . '&paged=' . ($paged - 1);
        if ($paged + 1 > $totalPages) {
            $nexturl = ($paged == 1) ? $url : $url . '&paged=' . $paged;
            $lasturl = ($paged == 1) ? $url : $url . '&paged=' . $paged;
        } else {
            $nexturl = $url . '&paged=' . ($paged + 1);
            $lasturl = $url . '&paged=' . $totalPages;
        }

        return array(
            'first' => $url,
            'previous' => $prevurl,
            'next' => $nexturl,
            'last' => $lasturl,
        );
    }

    /**
     * @param array{first: string, previous: string, next: string, last: string} $urls
     * @return array{first: string, previous: string, next: string, last: string}
     */
    private function appendFilterText(array $urls, string $filterText): array {
        $encoded = rawurlencode($filterText);
        foreach ($urls as $key => $url) {
            $urls[$key] = $url . '&filterText=' . $encoded;
        }
        return $urls;
    }

    /**
     * @param array{tableOptions: array<string, mixed>, logsid: string, orderby: string, order: string,
     *     filter: string, paged: int, perpage: int, totalPages: int, numRecords: int, filterText: string,
     *     urls: array{first: string, previous: string, next: string, last: string}} $state
     */
    private function renderTemplate(string $sub, array $state): string {
        $tableOptions = $state['tableOptions'];
        $logsid = $state['logsid'];
        $orderby = $state['orderby'];
        $order = $state['order'];
        $filter = $state['filter'];
        $paged = $state['paged'];
        $totalPages = $state['totalPages'];
        $numRecords = $state['numRecords'];
        $filterText = $state['filterText'];
        $urls = $state['urls'];
        // Match the WordPress core WP_List_Table::pagination() text shape:
        // "{N} items" outer count and "{X} of {Y}" inline indicator. The
        // earlier "1 - 25 of 487 redirects" / "Page 25 of 487" wording made
        // the strip too wide to fit on the same tablenav row as bulk actions.
        $currentlyShowingText = sprintf(
            /* translators: %s is the total record count, already number-formatted for the current locale */
            _n('%s item', '%s items', $numRecords, '404-solution'),
            number_format_i18n($numRecords)
        );
        $currentPageText = sprintf(
            /* translators: %1$d is current page number, %2$d is total page count */
            esc_html__('%1$d of %2$d', '404-solution'),
            $paged,
            $totalPages
        );

        $html = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/paginationLinks.html');
        $html = $this->f->str_replace('{TEXT_BEFORE_LINKS}', $currentlyShowingText, $html);
        $html = $this->f->str_replace('{BTN_FIRST_PAGE}', $this->pageButton($paged <= 1, $urls['first'], esc_attr__('Go to first page', '404-solution'), '&laquo;'), $html);
        $html = $this->f->str_replace('{BTN_PREV_PAGE}', $this->pageButton($paged <= 1, $urls['previous'], esc_attr__('Go to previous page', '404-solution'), '&lsaquo;'), $html);
        $html = $this->f->str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = $this->f->str_replace('{BTN_NEXT_PAGE}', $this->pageButton($paged >= $totalPages, $urls['next'], esc_attr__('Go to next page', '404-solution'), '&rsaquo;'), $html);
        $html = $this->f->str_replace('{BTN_LAST_PAGE}', $this->pageButton($paged >= $totalPages, $urls['last'], esc_attr__('Go to last page', '404-solution'), '&raquo;'), $html);
        $html = $this->f->str_replace('{filterText}', esc_attr($filterText), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-url}', esc_attr(admin_url('admin-ajax.php')), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-action}', esc_attr('ajaxUpdatePaginationLinks'), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-subpage}', esc_attr($sub), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-nonce}', esc_attr(wp_create_nonce('abj404_updatePaginationLink')), $html);
        $html = $this->f->str_replace('{data-lazy-backfill-ajax-url}', esc_attr(admin_url('admin-ajax.php')), $html);
        $html = $this->f->str_replace('{data-lazy-backfill-nonce}', esc_attr(wp_create_nonce('abj404_runLazyBackfill')), $html);
        $html = $this->f->str_replace('{data-pagination-inflight-nonce}', esc_attr(wp_create_nonce('abj404_fetchInflightStage')), $html);
        $html = $this->f->str_replace('{data-pagination-current-signature}', esc_attr($this->shared->getCurrentTableDataSignature($sub)), $html);
        $html = $this->f->str_replace('{data-pagination-current-orderby}', esc_attr($orderby), $html);
        $html = $this->f->str_replace('{data-pagination-current-order}', esc_attr($order), $html);
        $html = $this->f->str_replace('{data-pagination-current-filter}', esc_attr($filter), $html);
        $html = $this->f->str_replace('{data-pagination-current-paged}', esc_attr((string)$paged), $html);
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRangeForAttr = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $html = $this->f->str_replace('{data-pagination-current-score-range}', esc_attr($scoreRangeForAttr), $html);
        $html = $this->f->str_replace('{data-pagination-current-logsid}', esc_attr($logsid), $html);
        $autoRefresh = (($sub === 'abj404_redirects' || $sub === 'abj404_captured' || $sub === 'abj404_logs') ? '1' : '0');
        $html = $this->f->str_replace('{data-pagination-auto-refresh}', esc_attr($autoRefresh), $html);
        $html = $this->f->str_replace('{data-pagination-refresh-available-text}', esc_attr(__('Refresh available', '404-solution')), $html);

        return $this->f->doNormalReplacements($html);
    }

    private function pageButton(bool $disabled, string $href, string $label, string $glyph): string {
        if ($disabled) {
            return $this->f->str_replace(array('{label}', '{glyph}'), array($label, $glyph), $this->tpl('viewLogsPaginationBtnDisabled.html'));
        }
        return $this->f->str_replace(array('{href}', '{label}', '{glyph}'), array(esc_url($href), $label, $glyph), $this->tpl('viewLogsPaginationBtnLink.html'));
    }

    /** @param bool|float|int|string $value */
    private function scalarToString($value): string {
        return (string)$value;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
