<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the static Redirect Logs admin page shell.
 */
class ABJ_404_Solution_AdminLogsPageShell {

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

    /** @return string */
    public function render(): string {
        $sub = 'abj404_logs';
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $tableOptions = $this->logic->settingsUpdate()->sanitizePostData($tableOptions);

        $perpage = array_key_exists('perpage', $tableOptions) && is_scalar($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'timestamp';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'DESC';
        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');

        $perpageOptionsHtml = $this->renderPerPageOptions($perpage);
        $searchForm = $this->renderSearchForm();
        $warmup = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/tableWarmupPlaceholder.html');

        return $this->f->str_replace(
            $this->wrapperTokens(),
            $this->wrapperValues($sub, $paginationNonce, $orderby, $order,
                $searchForm, $perpageOptionsHtml, $warmup),
            $this->tpl('viewLogsPageWrapper.html')
        );
    }

    /** @return array<int, string> */
    private function wrapperTokens(): array {
        return array(
            '{logs_title}',
            '{data-pagination-ajax-url}',
            '{data-pagination-ajax-subpage}',
            '{data-pagination-ajax-nonce}',
            '{data-pagination-current-orderby}',
            '{data-pagination-current-order}',
            '{data-pagination-current-logsid}',
            '{refresh_available_text}',
            '{search_form}',
            '{rows_per_page_label}',
            '{perpage_options}',
            '{warmup_placeholder}',
            '{loading_badge_text}',
        );
    }

    /** @return array<int, mixed> */
    private function wrapperValues(string $sub, string $paginationNonce, string $orderby,
            string $order, string $searchForm, string $perpageOptionsHtml, string $warmup): array {
        $currentLogsId = $this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_id');
        return array(
            __('Redirect Logs', '404-solution'),
            esc_attr(admin_url('admin-ajax.php')),
            esc_attr($sub),
            esc_attr($paginationNonce),
            esc_attr($orderby),
            esc_attr($order),
            esc_attr(is_scalar($currentLogsId) ? (string)$currentLogsId : ''),
            esc_attr(__('Refresh available', '404-solution')),
            $searchForm,
            __('Rows per page:', '404-solution'),
            $perpageOptionsHtml,
            $warmup,
            esc_html__('Loading...', '404-solution'),
        );
    }

    /** @param mixed $perpage */
    private function renderPerPageOptions($perpage): string {
        $optionTpl = $this->tpl('viewLogsPerpageOption.html');
        $html = '';
        foreach (array(10, 25, 50, 100, 250) as $opt) {
            $selected = ($perpage == $opt) ? ' selected' : '';
            $html .= $this->f->str_replace(
                array('{value}', '{selected_attr}'),
                array((string)$opt, $selected),
                $optionTpl
            ) . "\n";
        }
        return $html;
    }

    private function renderSearchForm(): string {
        $searchBox = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/viewLogsForSearchBox.html');
        $redirectPageTitle = $this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_title');
        $pageIDAndType = $this->shared->viewGetPostOrGetSanitize('redirect_to_data_field_id');
        $searchBox = $this->f->str_replace('{redirect_to_label}', __('View logs for', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', __('(Begin typing a URL)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', __('(A page has been selected.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}', __('(A custom string has been entered.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}', __('(Please choose from the dropdown list instead of typing your own URL.)', '404-solution'), $searchBox);
        $searchBox = $this->f->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $searchBox);
        $searchBox = $this->f->str_replace('{redirectPageTitle}', esc_attr($redirectPageTitle), $searchBox);
        $searchBox = $this->f->str_replace('{data-url}', 'admin-ajax.php?action=echoViewLogsFor&nonce=' . wp_create_nonce('abj404_ajax'), $searchBox);
        $searchBox = $this->f->doNormalReplacements($searchBox);

        return $this->f->str_replace(
            array('{page_constant}', '{search_dropdown}'),
            array(ABJ404_PP, $searchBox),
            $this->tpl('viewLogsSearchFormShell.html')
        );
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
