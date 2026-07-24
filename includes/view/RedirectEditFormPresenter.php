<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders edit-redirect form templates and form-table rows.
 */
class ABJ_404_Solution_RedirectEditFormPresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_RedirectEngineLabeler */
    private $engineLabeler;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_RedirectEngineLabeler $engineLabeler
     */
    public function __construct($functions, ABJ_404_Solution_RedirectEngineLabeler $engineLabeler) {
        $this->functions = $functions;
        $this->engineLabeler = $engineLabeler;
    }

    /**
     * Build the redirect-to autocomplete dropdown HTML from the template.
     *
     * @param string $pageTitle
     * @param string $pageIDAndType
     * @return string
     */
    public function buildRedirectToDropdownHtml(string $pageTitle, string $pageIDAndType): string {
        $html = $this->readTemplate('addManualRedirectPageSearchDropdown.html');
        $html = $this->functions->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $this->functions->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $this->functions->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->functions->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
                __('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->functions->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->functions->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $this->functions->str_replace('{redirectPageTitle}', esc_attr($pageTitle), $html);
        $html = $this->functions->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $html);
        $html = $this->functions->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        return $this->functions->doNormalReplacements($html);
    }

    /**
     * @param array<int, array<string, mixed>> $redirects
     * @return string
     */
    public function buildBulkUrlsRowHtml(array $redirects): string {
        $items = '';
        $itemTemplate = $this->readTemplate('redirectEditBulkUrlItem.html');
        foreach ($redirects as $bulkRedirect) {
            $bulkUrl = is_string($bulkRedirect['url'] ?? '') ? (string)($bulkRedirect['url'] ?? '') : '';
            $items .= $this->functions->str_replace('{url}', esc_html($bulkUrl), $itemTemplate);
        }

        $rowHtml = $this->readTemplate('editRedirectBulkUrls.html');
        $rowHtml = $this->functions->str_replace('{bulk_urls_label}', esc_html__('URLs to redirect', '404-solution'), $rowHtml);
        $rowHtml = $this->functions->str_replace('{bulk_count}', (string)count($redirects), $rowHtml);
        return $this->functions->str_replace('{bulk_url_items}', $items, $rowHtml);
    }

    /**
     * @param array<int, int> $ids
     * @return string
     */
    public function buildIdsMultipleHiddenInput(array $ids): string {
        $hiddenInput = $this->readTemplate('editRedirectIdsMultipleHiddenInput.html');
        return $this->functions->str_replace('{ids_multiple}', esc_attr(implode(',', $ids)), $hiddenInput);
    }

    /**
     * Build the suggestion block HTML for a captured URL's best match.
     *
     * @param array{title: string, score: int, id_and_type: string, type_label: string} $suggestion
     * @return string
     */
    public function buildSuggestionBlockHtml(array $suggestion): string {
        $bucket = $suggestion['score'] >= 75 ? 'high' : ($suggestion['score'] >= 50 ? 'medium' : 'low');
        $typeLabel = '';
        if (!empty($suggestion['type_label'])) {
            $typeLabel = $this->readTemplate('editRedirectSuggestionTypeLabel.html');
            $typeLabel = $this->functions->str_replace('{type_label}', esc_html($suggestion['type_label']), $typeLabel);
        }

        $html = $this->readTemplate('editRedirectSuggestionBlock.html');
        $html = $this->functions->str_replace('{suggestion_label}', esc_html__('Suggested destination', '404-solution'), $html);
        $html = $this->functions->str_replace('{suggestion_title}', esc_html($suggestion['title']), $html);
        $html = $this->functions->str_replace('{suggestion_title_attr}', esc_attr($suggestion['title']), $html);
        $html = $this->functions->str_replace('{suggestion_type_label}', $typeLabel, $html);
        $html = $this->functions->str_replace('{suggestion_score_bucket}', $bucket, $html);
        $html = $this->functions->str_replace('{suggestion_score}', esc_html((string)$suggestion['score']), $html);
        $html = $this->functions->str_replace('{suggestion_id_and_type}', esc_attr($suggestion['id_and_type']), $html);
        $html = $this->functions->str_replace('{match_text}', esc_html__('match', '404-solution'), $html);
        $html = $this->functions->str_replace('{accept_label}', esc_html__('Accept Suggestion', '404-solution'), $html);
        return $this->functions->str_replace('{pick_different_label}', esc_html__('Pick a Different Page', '404-solution'), $html);
    }

    /**
     * @param string $redirectId
     * @return string
     */
    public function buildRedirectIdHiddenInput(string $redirectId): string {
        $hiddenInputs = $this->readTemplate('editRedirectIdHiddenInput.html');
        return $this->functions->str_replace('{redirect_id}', esc_attr($redirectId), $hiddenInputs);
    }

    /**
     * Build the URL form-table row with an optional "Auto-matched by" note.
     *
     * @return string
     */
    public function buildUrlRowHtml(string $redirectUrl, string $redirectEngine): string {
        $matchedByNote = '';
        if ($redirectEngine !== '') {
            $matchedByNote = $this->readTemplate('editRedirectMatchedByNote.html');
            $matchedByNote = $this->functions->str_replace('{matched_by_label}', esc_html__('Auto-matched by:', '404-solution'), $matchedByNote);
            $matchedByNote = $this->functions->str_replace('{engine_name}', esc_html($this->engineLabeler->humanize($redirectEngine)), $matchedByNote);
        }
        $urlBody = $this->readTemplate('editRedirectUrlRowBody.html');
        $urlBody = $this->functions->str_replace('{url_value}', esc_attr($redirectUrl), $urlBody);
        $urlBody = $this->functions->str_replace('{matched_by_note}', $matchedByNote, $urlBody);
        return $this->buildFieldRowHtml('url', $this->buildRequiredLabel(__('URL', '404-solution')), $urlBody);
    }

    /**
     * Build the "regular expression" form-table row.
     *
     * @param string $isRegexChecked ' checked' or ''
     * @return string
     */
    public function buildRegexRowHtml(string $isRegexChecked): string {
        $regexLabel = __('Treat this URL as a regular expression', '404-solution');
        $body = $this->readTemplate('editRedirectRegexBody.html');
        $body = $this->functions->str_replace('{regex_label}', esc_html($regexLabel), $body);
        $body = $this->functions->str_replace('{is_regex_checked}', $isRegexChecked, $body);
        $body = $this->functions->str_replace('{regex_explain_link}', esc_html__('(Explain)', '404-solution'), $body);
        $body = $this->functions->str_replace('{regex_explain_text}', esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution'), $body);
        $body = $this->functions->str_replace('{regex_example_label}', esc_html__('Example:', '404-solution'), $body);
        $body = $this->functions->str_replace('{regex_example_text}', esc_html__('/events/(.+) will match any URL that begins with /events/. Use $1 in the destination to insert the captured text. Site-relative paths such as /archive/$1 and full HTTP(S) URLs are supported.', '404-solution'), $body);
        return $this->buildFieldRowHtml('is_regex_url', '&nbsp;', $body);
    }

    /**
     * Build hidden `source_*` inputs that preserve the originating list-table view.
     *
     * @return string
     */
    public function buildSourceHiddenInputs(string $sourcePage, string $filter, string $orderby, string $order, string $paged): string {
        $pairs = array(
            'source_page' => $sourcePage,
            'source_filter' => $filter,
            'source_orderby' => $orderby,
            'source_order' => $order,
            'source_paged' => $paged,
        );
        $template = $this->readTemplate('editRedirectSourceHiddenInput.html');
        $html = '';
        foreach ($pairs as $name => $value) {
            if ($value === '') {
                continue;
            }
            $line = $this->functions->str_replace('{name}', esc_attr($name), $template);
            $line = $this->functions->str_replace('{value}', esc_attr($value), $line);
            $html .= $line;
        }
        return $html;
    }

    /**
     * Build the back-to-list cancel URL with preserved filter/orderby/order params.
     *
     * @return string
     */
    public function buildCancelUrl(string $sourcePage, string $filter, string $orderby, string $order): string {
        $url = '?page=' . ABJ404_PP;
        $pairs = array(
            'subpage' => $sourcePage,
            'filter' => $filter,
            'orderby' => $orderby,
            'order' => $order,
        );
        foreach ($pairs as $name => $value) {
            if ($value === '') {
                continue;
            }
            $url .= '&' . $name . '=' . $value;
        }
        return $url;
    }

    /**
     * Build the Advanced Options section HTML using the template.
     *
     * @param string $startDate ISO date for "Active From", or empty.
     * @param string $endDate ISO date for "Active Until", or empty.
     * @param string $conditionsHtml Already-rendered conditions section.
     * @param bool $isOpen Whether the details element starts open.
     * @return string
     */
    public function buildAdvancedOptionsHtml(string $startDate, string $endDate, string $conditionsHtml, bool $isOpen): string {
        $html = $this->readTemplate('editRedirectAdvancedOptions.html');
        $html = $this->functions->str_replace('{advanced_options_label}', esc_html__('Advanced Options', '404-solution'), $html);
        $html = $this->functions->str_replace('{open_attr}', $isOpen ? ' open' : '', $html);
        $html = $this->functions->str_replace('{start_date_label}', esc_html__('Active From (optional)', '404-solution'), $html);
        $html = $this->functions->str_replace('{start_date_value}', esc_attr($startDate), $html);
        $html = $this->functions->str_replace('{start_date_help}', esc_html__('Leave blank to activate immediately', '404-solution'), $html);
        $html = $this->functions->str_replace('{end_date_label}', esc_html__('Active Until (optional)', '404-solution'), $html);
        $html = $this->functions->str_replace('{end_date_value}', esc_attr($endDate), $html);
        $html = $this->functions->str_replace('{end_date_help}', esc_html__('Leave blank to never expire', '404-solution'), $html);
        return $this->functions->str_replace('{conditions_section}', $conditionsHtml, $html);
    }

    /**
     * @return string
     */
    public function buildRequiredLabel(string $baseLabel): string {
        $html = $this->readTemplate('editRedirectRequiredLabelSuffix.html');
        $html = $this->functions->str_replace('{base_label}', esc_html($baseLabel), $html);
        return $this->functions->str_replace('{required_label}', esc_html__('(Required)', '404-solution'), $html);
    }

    /**
     * Build a single form-table row using the field-row template.
     *
     * @param string $fieldId Form-control id used in the label's `for` attribute.
     * @param string $labelHtml Already-escaped label HTML.
     * @param string $bodyHtml Already-built input/markup for the td cell.
     * @return string
     */
    public function buildFieldRowHtml(string $fieldId, string $labelHtml, string $bodyHtml): string {
        $row = $this->readTemplate('editRedirectFieldRow.html');
        $row = $this->functions->str_replace('{field_id}', esc_attr($fieldId), $row);
        $row = $this->functions->str_replace('{field_label}', $labelHtml, $row);
        return $this->functions->str_replace('{field_body}', $bodyHtml, $row);
    }

    /**
     * @return string
     */
    public function buildManualPickerWrapperHtml(string $hiddenClass, string $innerHtml): string {
        $redirectToBody = $this->readTemplate('editRedirectManualPickerWrapper.html');
        $redirectToBody = $this->functions->str_replace('{hidden_class}', $hiddenClass, $redirectToBody);
        return $this->functions->str_replace('{inner_html}', $innerHtml, $redirectToBody);
    }

    /**
     * @param array<string, string> $parts
     * @return string
     */
    public function buildShellHtml(array $parts): string {
        $shell = $this->readTemplate('editRedirectFormShell.html');
        foreach ($parts as $placeholder => $value) {
            $shell = $this->functions->str_replace($placeholder, $value, $shell);
        }
        return $shell;
    }

    /**
     * @param string $name
     * @return string
     */
    private function readTemplate(string $name): string {
        return ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name);
    }
}
