<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the Add Manual Redirect modal on the Page Redirects admin table.
 */
class ABJ_404_Solution_RedirectAddModalPresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_View_OptionsPresenter */
    private $optionsPresenter;

    /** @var ABJ_404_Solution_View_RedirectTypeUI */
    private $redirectTypeUI;

    /** @var ABJ_404_Solution_View_RedirectConditions */
    private $redirectConditions;

    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_View_OptionsPresenter $optionsPresenter,
            ABJ_404_Solution_View_RedirectTypeUI $redirectTypeUI,
            ABJ_404_Solution_View_RedirectConditions $redirectConditions) {
        $this->functions = $functions;
        $this->optionsPresenter = $optionsPresenter;
        $this->redirectTypeUI = $redirectTypeUI;
        $this->redirectConditions = $redirectConditions;
    }

    /**
     * @param array<string, mixed> $tableOptions
     */
    public function render(array $tableOptions): string {
        $options = $this->optionsPresenter->getOptionsWithDefaults();
        $link = wp_nonce_url($this->formUrl($tableOptions), 'abj404addRedirect');
        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . '/example';

        return $this->fillTpl('viewRedirectsTableAddModal.html', array(
            '{modal_title}' => esc_html__('Add Manual Redirect', '404-solution'),
            '{form_action}' => esc_url($link),
            '{url_label}' => esc_html__('URL', '404-solution'),
            '{url_placeholder}' => esc_attr($urlPlaceholder),
            '{url_help}' => esc_html__('The URL path that should be redirected (without domain)', '404-solution'),
            '{regex_label}' => esc_html__('Treat this URL as a regular expression', '404-solution'),
            '{explain_label}' => esc_html__('(Explain)', '404-solution'),
            '{regex_help_1}' => esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution'),
            '{example_label}' => esc_html__('Example:', '404-solution'),
            '{regex_help_2}' => esc_html__('/events/(.+) will match any URL that begins with /events/. Use $1 in the destination to insert the captured text. Site-relative paths such as /archive/$1 and full HTTP(S) URLs are supported.', '404-solution'),
            '{regex_help_3}' => esc_html__('First, all of the normal "exact match" URLs are checked, then all of the regular expression URLs are checked.', '404-solution'),
            '{redirect_to_html}' => $this->redirectToHtml(),
            '{redirect_type_grid}' => $this->redirectTypeGrid($options),
            '{advanced_options}' => $this->advancedOptionsHtml(),
            '{cancel_label}' => esc_html__('Cancel', '404-solution'),
            '{add_redirect_label}' => esc_html__('Add', '404-solution'),
        ));
    }

    /**
     * @param array<string, mixed> $tableOptions
     */
    private function formUrl(array $tableOptions): string {
        $url = '?page=' . ABJ404_PP . '&subpage=abj404_redirects';
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        if (!($orderby == 'url' && $order == 'ASC')) {
            $url .= '&orderby=' . sanitize_text_field($orderby) . '&order=' . sanitize_text_field($order);
        }
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        if ($filter != 0) {
            $url .= '&filter=' . $filter;
        }
        return $url;
    }

    private function redirectToHtml(): string {
        $redirectHtml = $this->tpl('addManualRedirectPageSearchDropdown.html');
        $redirectHtml = $this->functions->str_replace(
            array(
                '{redirect_to_label}',
                '{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                '{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
                '{TOOLTIP_POPUP_EXPLANATION_URL}',
                '{REDIRECT_TO_USER_FIELD_WARNING}',
                '{redirectPageTitle}',
                '{pageIDAndType}',
                '{data-url}',
            ),
            array(
                esc_html__('Redirect to', '404-solution') . ' *',
                __('(Type a page name or an external URL)', '404-solution'),
                __('(A page has been selected.)', '404-solution'),
                __('(A custom string has been entered.)', '404-solution'),
                __('(An external URL will be used.)', '404-solution'),
                '',
                '',
                '',
                'admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=' .
                    wp_create_nonce('abj404_ajax'),
            ),
            $redirectHtml
        );
        return $this->functions->doNormalReplacements($redirectHtml);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function redirectTypeGrid(array $options): string {
        $rawDefault = $options['default_redirect'] ?? '301';
        $defaultCode = is_string($rawDefault) ? $rawDefault : '301';
        ob_start();
        $this->redirectTypeUI->echoRedirectTypeButtonGrid($defaultCode);
        return (string)ob_get_clean();
    }

    private function advancedOptionsHtml(): string {
        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsSection = (string)ob_get_clean();

        return $this->fillTpl('viewRedirectsTableAdvancedOptions.html', array(
            '{open_attr}' => '',
            '{advanced_options_label}' => esc_html__('Advanced Options', '404-solution'),
            '{active_from_label}' => esc_html__('Active From (optional)', '404-solution'),
            '{start_date_value}' => '',
            '{active_from_help}' => esc_html__('Leave blank to activate immediately', '404-solution'),
            '{active_until_label}' => esc_html__('Active Until (optional)', '404-solution'),
            '{end_date_value}' => '',
            '{active_until_help}' => esc_html__('Leave blank to never expire', '404-solution'),
            '{conditions_section}' => $conditionsSection,
        ));
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
