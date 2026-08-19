<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standalone Add/Edit redirect form rendering. Owns the legacy
 * "Add Manual Redirect" form, the Edit Redirect form (active-from/until +
 * conditions + cancel/submit row), and the default destination <optgroup>
 * shared by the redirect-to dropdowns. The modern Add Redirect modal lives
 * on View_RedirectsTable (it is rendered as part of the Redirects page).
 *
 * Outside callers (via the View facade __call dispatch):
 *   - PluginLogic admin edit flow (echoEditRedirect)
 *   - Legacy add-redirect paths (echoAddManualRedirect)
 *   - Redirect-to dropdown builders (echoRedirectDestinationOptionsDefaults)
 */
class ABJ_404_Solution_View_RedirectForms extends ABJ_404_Solution_ViewComponent {

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string,string> $vars */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    public function echoAddManualRedirect($tableOptions) {

        $options = $this->optionsPresenter->getOptionsWithDefaults();

        $url = "?page=" . ABJ404_PP . "&subpage=abj404_redirects";
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        if (!($orderby == "url" && $order == "ASC")) {
            $url .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
        }
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        $link = wp_nonce_url($url, "abj404addRedirect");

        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";
        if (isset($_POST['url']) && $_POST['url'] != '') {
            $postedURL = esc_url(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['url']));
        } else {
            $postedURL = $urlPlaceholder;
        }

        $selected301 = ($options['default_redirect'] == '301') ? ' selected ' : '';
        $selected302 = ($options['default_redirect'] == '302') ? ' selected ' : '';
        $selected307 = ($options['default_redirect'] == '307') ? ' selected ' : '';
        $selected308 = ($options['default_redirect'] == '308') ? ' selected ' : '';
        $selected410 = '';
        $selected451 = '';
        $selected0 = '';

        // read the html content.
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/addManualRedirectTop.html");
        $html .= ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) .
                "/html/addManualRedirectPageSearchDropdown.html");

        $html = $this->f->str_replace(
            array('{redirect_to_label}', '{TOOLTIP_POPUP_EXPLANATION_EMPTY}', '{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                  '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}', '{TOOLTIP_POPUP_EXPLANATION_URL}',
                  '{REDIRECT_TO_USER_FIELD_WARNING}', '{redirectPageTitle}', '{pageIDAndType}', '{data-url}'),
            array(__('Redirect to', '404-solution'),
                  __('(Type a page name or an external URL)', '404-solution'),
                  __('(A page has been selected.)', '404-solution'),
                  __('(A custom string has been entered.)', '404-solution'),
                  __('(An external URL will be used.)', '404-solution'),
                  '', '', '',
                  "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax')),
            $html
        );

        $html .= ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/addManualRedirectBottom.html");
        $html = $this->f->str_replace(
            array('{addManualRedirectAction}', '{urlPlaceholder}', '{postedURL}',
                  '{301selected}', '{302selected}', '{307selected}', '{308selected}',
                  '{410selected}', '{451selected}', '{0selected}'),
            array($link, esc_attr($urlPlaceholder), esc_attr($postedURL),
                  $selected301, $selected302, $selected307, $selected308,
                  $selected410, $selected451, $selected0),
            $html
        );

        // constants and translations.
        $html = $this->f->doNormalReplacements($html);

        echo $html;
    }

    /** This is used both to add and to edit a redirect.
     * @param ABJ_404_Solution_EditRedirectFormContext $ctx
     * @return void
     */
    public function echoEditRedirect(ABJ_404_Solution_EditRedirectFormContext $ctx) {
        $codeselected = $ctx->codeSelected;
        $label = $ctx->label;
        $source_page = $ctx->sourcePage;
        $filter = $ctx->filter;
        $orderby = $ctx->orderby;
        $order = $ctx->order;
        $startDate = $ctx->startDate;
        $endDate = $ctx->endDate;
        // allow-em-dash: comment-only context describing button grid section
        // Redirect type button grid with hidden input
        $this->redirectTypeUI->echoRedirectTypeButtonGrid((string)$codeselected);

        // Advanced Options: Active From/Until + Conditions (collapsed by default)
        $redirectId = 0;
        $getRedirectId = $_GET['id'] ?? null;
        $postRedirectId = $_POST['id'] ?? null;
        if (is_scalar($getRedirectId) && $this->f->regexMatch('[0-9]+', (string)$getRedirectId)) {
            $redirectId = absint($getRedirectId);
        } elseif (is_scalar($postRedirectId) && $this->f->regexMatch('[0-9]+', (string)$postRedirectId)) {
            $redirectId = absint($postRedirectId);
        }
        $hasExistingConditions = ($redirectId > 0) && !empty($this->redirectsRepository->getRedirectConditions($redirectId));
        $hasAdvancedValues = ($startDate !== '' || $endDate !== '' || $hasExistingConditions);
        $openAttr = $hasAdvancedValues ? ' open' : '';

        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsSection = (string)ob_get_clean();

        echo $this->fillTpl('viewRedirectsTableAdvancedOptions.html', array(
            '{open_attr}' => $openAttr,
            '{advanced_options_label}' => esc_html__('Advanced Options', '404-solution'),
            '{active_from_label}' => esc_html__('Active From (optional)', '404-solution'),
            '{start_date_value}' => esc_attr($startDate),
            '{active_from_help}' => esc_html__('Leave blank to activate immediately', '404-solution'),
            '{active_until_label}' => esc_html__('Active Until (optional)', '404-solution'),
            '{end_date_value}' => esc_attr($endDate),
            '{active_until_help}' => esc_html__('Leave blank to never expire', '404-solution'),
            '{conditions_section}' => $conditionsSection,
        ));

        // Cancel button URL
        $cancelUrl = '?page=' . ABJ404_PP;
        if ($source_page) {
            $cancelUrl .= '&subpage=' . esc_attr($source_page);
        }
        if ($filter !== null) {
            $cancelUrl .= '&filter=' . esc_attr($filter);
        }
        if ($orderby !== null) {
            $cancelUrl .= '&orderby=' . esc_attr($orderby);
        }
        if ($order !== null) {
            $cancelUrl .= '&order=' . esc_attr($order);
        }

        echo $this->f->str_replace(
            array('{cancel_url}', '{cancel_label}', '{submit_label}'),
            array(esc_url($cancelUrl), esc_html__('Cancel', '404-solution'), esc_html($label)),
            $this->tpl('viewRedirectsTableEditButtonGroup.html')
        );
    }

    /**
     * @param string $currentlySelected
     * @return string
     */
    public function echoRedirectDestinationOptionsDefaults($currentlySelected) {
        $content = "";
        $content .= "\n" . '<optgroup label="' . __('Special', '404-solution') . '">' . "\n";

        $selected = "";
        if ($currentlySelected == ABJ404_TYPE_EXTERNAL) {
            $selected = " selected";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL . "\"" . $selected . ">" .
                __('External Page', '404-solution') . "</option>";

        if ($currentlySelected == ABJ404_TYPE_HOME) {
            $selected = " selected";
        } else {
            $selected = "";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_HOME . "|" . ABJ404_TYPE_HOME . "\"" . $selected . ">" .
                __('Home Page', '404-solution') . "</option>";

        $content .= "\n" . '</optgroup>' . "\n";

        return $content;
    }
}
