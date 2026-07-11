<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Captured 404 URLs admin page wrapper/chrome: the subsubsub
 * filter row, subtitle, empty-trash button, bulk-action buttons, bulk-form
 * action/nonce, and the warmup placeholder that surrounds the AJAX-refreshed
 * table body. Extracted from View_CapturedURLsTable because page-chrome
 * rendering (page-level wrapper, filters, bulk actions) is a distinct
 * responsibility from table-body-row rendering.
 */
class ABJ_404_Solution_CapturedPageChromeRenderer {

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_View_ListTableChrome */
    private $listTableChrome;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    public function __construct(
        ABJ_404_Solution_PluginLogic $logic,
        ABJ_404_Solution_View_ListTableChrome $listTableChrome,
        ABJ_404_Solution_Functions $functions
    ) {
        $this->logic = $logic;
        $this->listTableChrome = $listTableChrome;
        $this->f = $functions;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string,string> $vars */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    public function render(): string {
        $sub = 'abj404_captured';

        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $isSimpleMode = abj_service('settings_mode_preference')->getMode() === 'simple';

        $subsubsubHtml = $this->buildSubsubsubHtml($sub, $isSimpleMode, $tableOptions);
        $display = $this->readDisplayOptions($tableOptions);

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        $lazyBackfillNonce = wp_create_nonce('abj404_runLazyBackfill');

        $subtitleHtml = $this->buildSubtitleHtml($isSimpleMode);
        $emptyTrashHtml = $this->buildEmptyTrashButtonHtml($display['currentFilter']);
        $bulkButtons = $this->buildBulkButtonsHtml($isSimpleMode, $display['currentFilter']);

        // Bulk form action URL + nonce field
        $formAction = $this->listTableChrome->getBulkOperationsFormURL($sub, $tableOptions);
        ob_start();
        wp_nonce_field('abj404_bulkProcess');
        $nonceField = (string)ob_get_clean();

        $warmup = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/tableWarmupPlaceholder.html");

        $refresh = $this->listTableChrome->paginationRefreshStrings();

        return $this->fillTpl('viewRedirectsTableCapturedPageWrapper.html', array(
            '{captured_title}' => __('Captured 404 URLs', '404-solution'),
            '{subtitle}' => $subtitleHtml,
            '{subsubsub}' => $subsubsubHtml,
            '{data-pagination-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-ajax-url}' => esc_attr(admin_url('admin-ajax.php')),
            '{data-lazy-backfill-nonce}' => esc_attr($lazyBackfillNonce),
            '{data-pagination-ajax-subpage}' => esc_attr($sub),
            '{data-pagination-ajax-nonce}' => esc_attr($paginationNonce),
            '{data-pagination-current-orderby}' => esc_attr($display['currentOrderBy']),
            '{data-pagination-current-order}' => esc_attr($display['currentOrder']),
            '{data-pagination-current-filter}' => esc_attr((string)$display['currentFilter']),
            '{data-pagination-current-paged}' => esc_attr((string)$display['currentPaged']),
            '{data-pagination-current-score-range}' => esc_attr($display['currentScoreRange']),
            '{data-pagination-auto-refresh}' => esc_attr('1'),
            '{data-pagination-refresh-available-text}' => esc_attr($refresh['available']),
            '{search_placeholder}' => esc_attr__('Type to filter URLs... (press Enter)', '404-solution'),
            '{filter_text}' => esc_attr($display['filterText']),
            '{rows_per_page_label}' => esc_html__('Rows per page:', '404-solution'),
            '{perpage_options}' => $this->listTableChrome->buildPerpageOptions($display['perPage']),
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

    /** @param array<string, mixed> $tableOptions */
    private function buildSubsubsubHtml(string $sub, bool $isSimpleMode, array $tableOptions): string {
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
        return $this->listTableChrome->buildSubsubsubFilters($sub, $items, $tableOptions);
    }

    /**
     * Reads the raw table options into typed, defaulted display values.
     *
     * @param array<string, mixed> $tableOptions
     * @return array{filterText: string, perPage: int, currentFilter: bool|float|int|string, currentOrderBy: string, currentOrder: string, currentPaged: int, currentScoreRange: string}
     */
    private function readDisplayOptions(array $tableOptions): array {
        $rawPerPage = $tableOptions['perpage'] ?? 25;
        $rawFilter = $tableOptions['filter'] ?? 0;
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $rawOrder = $tableOptions['order'] ?? '';
        $rawPaged = $tableOptions['paged'] ?? 1;
        $currentPaged = is_scalar($rawPaged) ? intval($rawPaged) : 1;
        if ($currentPaged < 1) {
            $currentPaged = 1;
        }
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';

        return array(
            'filterText' => is_string($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '',
            'perPage' => is_scalar($rawPerPage) ? (int)$rawPerPage : 25,
            'currentFilter' => is_scalar($rawFilter) ? $rawFilter : 0,
            'currentOrderBy' => is_string($rawOrderBy) ? $rawOrderBy : 'url',
            'currentOrder' => is_string($rawOrder) ? $rawOrder : 'ASC',
            'currentPaged' => $currentPaged,
            'currentScoreRange' => is_string($rawScoreRange) ? $rawScoreRange : 'all',
        );
    }

    private function buildSubtitleHtml(bool $isSimpleMode): string {
        if (!$isSimpleMode) {
            return '';
        }
        return $this->f->str_replace(
            '{text}',
            esc_html__('Broken links visitors tried to reach. Create Redirect for important ones, Dismiss the rest.', '404-solution'),
            $this->tpl('viewRedirectsTableSubtitle.html')
        ) . "\n";
    }

    /** @param bool|float|int|string $currentFilter */
    private function buildEmptyTrashButtonHtml($currentFilter): string {
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            return '';
        }
        $eturl = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ABJ404_TRASH_FILTER, 'abj404_bulkProcess');
        return $this->fillTpl('viewRedirectsTableEmptyTrashButton.html', array(
            '{href}' => esc_url($eturl . '&abj404action=emptyCapturedTrash'),
            '{confirm_js}' => esc_js(__('Are you sure you want to permanently delete all items in trash?', '404-solution')),
            '{label}' => esc_html__('Empty Trash', '404-solution'),
        )) . "\n";
    }

    /** @param bool|float|int|string $currentFilter */
    private function buildBulkButtonsHtml(bool $isSimpleMode, $currentFilter): string {
        if ($isSimpleMode) {
            return $this->listTableChrome->buildBulkButton('bulkignore', __('Dismiss', '404-solution'))
                . $this->listTableChrome->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        }

        $bulkButtons = '';
        if ($currentFilter != ABJ404_STATUS_CAPTURED) { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulkcaptured', __('Mark Captured', '404-solution')); }
        if ($currentFilter != ABJ404_STATUS_IGNORED)  { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulkignore',   __('Mark Ignored', '404-solution')); }
        if ($currentFilter != ABJ404_STATUS_LATER)    { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulklater',    __('Organize Later', '404-solution')); }
        if ($currentFilter != ABJ404_TRASH_FILTER)    { $bulkButtons .= $this->listTableChrome->buildBulkButton('bulktrash',    __('Move to Trash', '404-solution')); }
        $bulkButtons .= $this->listTableChrome->buildBulkButton('editRedirect', __('Create Redirect', '404-solution'));
        return $bulkButtons;
    }
}
