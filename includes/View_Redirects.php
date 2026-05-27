<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edit redirect page and destination option helpers.
 */
class ABJ_404_Solution_View_Redirects extends ABJ_404_Solution_ViewComponent {


    /**
     * Resolve final destination, pageIDAndType, and redirect code from a redirect row.
     *
     * @param array<string, mixed> $redirect
     * @param array<string, mixed> $options
     * @return array{final: string, pageIDAndType: string, codeSelected: string}
     */
    public function resolveRedirectDestinationInfo(array $redirect, array $options): array {
        $final = "";
        $pageIDAndType = "";
        $redirectType = $redirect['type'] ?? null;
        $redirectFinalDestRaw = $redirect['final_dest'] ?? 0;
        $redirectFinalDest = is_scalar($redirectFinalDestRaw) ? (string)$redirectFinalDestRaw : '0';
        if ($redirectType == ABJ404_TYPE_EXTERNAL) {
            $final = $redirectFinalDest;
            $pageIDAndType = ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL;
            
        } else if ($redirectFinalDest != 0) {
            // if a destination has been specified then let's fill it in.
            $pageIDAndType = $redirectFinalDest . "|" . $redirectType;
            
        } else if ($redirectType == ABJ404_TYPE_404_DISPLAYED) {
        	$pageIDAndType = ABJ404_TYPE_404_DISPLAYED . "|" . ABJ404_TYPE_404_DISPLAYED;
        }
        
        $rawCode = $redirect['code'] ?? '';
        if ($rawCode == "") {
            $rawDefault = $options['default_redirect'] ?? '301';
            $codeSelected = is_string($rawDefault) ? $rawDefault : '301';
        } else {
            $codeSelected = is_string($rawCode) ? $rawCode : '301';
        }

        return array('final' => $final, 'pageIDAndType' => $pageIDAndType, 'codeSelected' => $codeSelected);
    }

    /**
     * Build the redirect-to autocomplete dropdown HTML from the template.
     *
     * @param string $pageTitle
     * @param string $pageIDAndType
     * @return string
     */
    public function buildRedirectToDropdownHtml(string $pageTitle, string $pageIDAndType): string {
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/addManualRedirectPageSearchDropdown.html");
        $html = $this->f->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $this->f->str_replace('{redirectPageTitle}', esc_attr($pageTitle), $html);
        $html = $this->f->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        return $html;
    }


    /**
     * Build hidden input + form-table row HTML for bulk redirect editing.
     *
     * @param array<int, int> $recnums_multiple
     * @return array{redirect: array<string, mixed>, redirects_multiple: array<int, array<string, mixed>>, hiddenInput: string, rowHtml: string}|null Null on error (already echoed).
     */
    public function renderBulkRedirectFormFields(array $recnums_multiple): ?array {
        $redirects_multiple = $this->redirectsRepository->getRedirectsByIDs($recnums_multiple);
        if ($redirects_multiple == null) {
            echo "Error: Invalid ID Numbers! (ids: " . esc_html(implode(',', $recnums_multiple)) . ")";
            $this->logger->debugMessage("Error: Invalid ID Numbers! (ids: " .
                    esc_html(implode(',', $recnums_multiple)) . ")");
            return null;
        }

        $items = '';
        foreach ($redirects_multiple as $bulkRedirect) {
            /** @var array<string, mixed> $bulkRedirect */
            $bulkUrl = is_string($bulkRedirect['url'] ?? '') ? (string)($bulkRedirect['url'] ?? '') : '';
            $items .= '<li><code>' . esc_html($bulkUrl) . '</code></li>';
        }

        $rowHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectBulkUrls.html');
        $rowHtml = $this->f->str_replace('{bulk_urls_label}', esc_html__('URLs to redirect', '404-solution'), $rowHtml);
        $rowHtml = $this->f->str_replace('{bulk_count}', (string)count($redirects_multiple), $rowHtml);
        $rowHtml = $this->f->str_replace('{bulk_url_items}', $items, $rowHtml);

        $hiddenInput = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectIdsMultipleHiddenInput.html');
        $hiddenInput = $this->f->str_replace('{ids_multiple}', esc_attr(implode(',', $recnums_multiple)), $hiddenInput);

        // here we set the variable to the first value returned because it's used to set default values
        // in the form data.
        $redirect = reset($redirects_multiple);

        return array(
            'redirect' => $redirect,
            'redirects_multiple' => $redirects_multiple,
            'hiddenInput' => $hiddenInput,
            'rowHtml' => $rowHtml,
        );
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
            $typeLabel = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectSuggestionTypeLabel.html');
            $typeLabel = $this->f->str_replace('{type_label}', esc_html($suggestion['type_label']), $typeLabel);
        }

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectSuggestionBlock.html');
        $html = $this->f->str_replace('{suggestion_label}', esc_html__('Suggested destination', '404-solution'), $html);
        $html = $this->f->str_replace('{suggestion_title}', esc_html($suggestion['title']), $html);
        $html = $this->f->str_replace('{suggestion_title_attr}', esc_attr($suggestion['title']), $html);
        $html = $this->f->str_replace('{suggestion_type_label}', $typeLabel, $html);
        $html = $this->f->str_replace('{suggestion_score_bucket}', $bucket, $html);
        $html = $this->f->str_replace('{suggestion_score}', esc_html((string)$suggestion['score']), $html);
        $html = $this->f->str_replace('{suggestion_id_and_type}', esc_attr($suggestion['id_and_type']), $html);
        $html = $this->f->str_replace('{match_text}', esc_html__('match', '404-solution'), $html);
        $html = $this->f->str_replace('{accept_label}', esc_html__('Accept Suggestion', '404-solution'), $html);
        $html = $this->f->str_replace('{pick_different_label}', esc_html__('Pick a Different Page', '404-solution'), $html);
        return $html;
    }

    /**
     * Render the suggestion block for a captured URL's best match.
     *
     * @param array{title: string, score: int, id_and_type: string, type_label: string} $suggestion
     * @return void
     */
    public function renderSuggestionBlock(array $suggestion): void {
        echo $this->buildSuggestionBlockHtml($suggestion);
    }

    /** @return void */
    function echoAdminEditRedirectPage() {

        $options = $this->shared->getOptionsWithDefaults();

        // Compute source page early so we can use it in the back link
        $source_page = $this->shared->viewGetPostOrGetSanitize('source_page');
        if ($source_page === '') {
            $source_page = $this->shared->viewGetPostOrGetSanitize('subpage');
        }
        if ($source_page === '' || $source_page == 'abj404_edit') {
            $source_page = 'abj404_redirects';
        }
        $backUrl = '?page=' . ABJ404_PP . '&subpage=' . esc_attr($source_page);

        $isSimpleMode = $this->logic->getSettingsMode() === 'simple';
        $isFromCaptured = ($source_page === 'abj404_captured');

        if ($isSimpleMode && $isFromCaptured) {
            $title = __('Create Redirect', '404-solution');
            $backLabel = __('Back to Captured 404s', '404-solution');
        } else {
            $title = __('Edit Redirect', '404-solution');
            $backLabel = __('Back to Redirects', '404-solution');
        }

        $actionUrl = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_edit", "abj404editRedirect");

        // Build hidden inputs that preserve source navigation state.
        $filter = $this->shared->viewGetPostOrGetSanitize('filter');
        $orderby = $this->shared->viewGetPostOrGetSanitize('orderby');
        $order = $this->shared->viewGetPostOrGetSanitize('order');
        $paged = $this->shared->viewGetPostOrGetSanitize('paged');
        $hiddenInputs = $this->buildSourceHiddenInputs($source_page, $filter, $orderby, $order, $paged);

        // Resolve target record(s).
        $recnum = null;
        $recnums_multiple = null;
        $startDate = '';
        $endDate = '';
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', $_GET['id'])) {
            $this->logger->debugMessage("Edit redirect page. GET ID: " .
                    wp_kses_post((string)json_encode($_GET['id'])));
            $recnum = absint($_GET['id']);

        } else if (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', $_POST['id'])) {
            $this->logger->debugMessage("Edit redirect page. POST ID: " .
                    wp_kses_post((string)json_encode($_POST['id'])));
            $recnum = absint($_POST['id']);

        } else if ($this->shared->viewGetPostOrGetSanitize('idnum') !== '' || isset($_GET['idnum']) || isset($_POST['idnum'])) {
            $rawIdnum = isset($_GET['idnum']) ? $_GET['idnum'] : (isset($_POST['idnum']) ? $_POST['idnum'] : $this->shared->viewGetPostOrGetSanitize('idnum'));
            $recnums_multiple = array_values(array_filter(array_map(function($v) { return absint($v); }, (array)$rawIdnum), function($v) { return $v > 0; }));
            $this->logger->debugMessage("Edit redirect page. ids_multiple: " .
                    wp_kses_post((string)json_encode($recnums_multiple)));

        } else {
            echo __('Error: No ID(s) found for edit request.', '404-solution');
            $this->logger->debugMessage("No ID(s) found in GET or POST data for edit request.");
            return;
        }

        // Body parts composed below.
        $preTableBlock = '';
        $formRows = '';
        $redirectUrl = '';

        if ($recnum != null) {
            $singleResult = $this->buildSingleRecordContent($recnum, $isSimpleMode);
            if ($singleResult === null) {
                return;
            }
            $redirect = $singleResult['redirect'];
            $redirects_multiple = $singleResult['redirects_multiple'];
            $redirectUrl = $singleResult['redirectUrl'];
            $startDate = $singleResult['startDate'];
            $endDate = $singleResult['endDate'];
            $hiddenInputs .= $singleResult['hiddenInputs'];
            $formRows .= $singleResult['formRows'];

        } else if ($recnums_multiple != null) {
            $bulkResult = $this->renderBulkRedirectFormFields($recnums_multiple);
            if ($bulkResult === null) {
                return;
            }
            $redirect = $bulkResult['redirect'];
            $redirects_multiple = $bulkResult['redirects_multiple'];
            $hiddenInputs .= $bulkResult['hiddenInput'];
            $formRows .= $bulkResult['rowHtml'];

        } else {
            $idsText = isset($rawIdnum) && is_array($rawIdnum) ? implode(',', array_map(function($v) { return is_scalar($v) ? (string)$v : ''; }, $rawIdnum)) : '';
            echo $errorText = ($recnum === 0 || $idsText !== '') ? "Error: Invalid ID Number(s) specified! (id: " . esc_html((string)$recnum) . ", ids: " . esc_html($idsText) . ")" : __('Error: No ID(s) found for edit request.', '404-solution');
            $this->logger->debugMessage($errorText . " (id: " . esc_html((string)$recnum) .
                    ", ids: " . esc_html($idsText) . ")");
            return;
        }

        $destInfo = $this->resolveRedirectDestinationInfo($redirect, $options);
        $final = $destInfo['final'];
        $pageIDAndType = $destInfo['pageIDAndType'];
        $codeSelected = $destInfo['codeSelected'];

        // Try to find a suggested destination for captured URLs.
        $suggestion = null;
        if ($isFromCaptured && !empty($redirectUrl)) {
            $suggestion = $this->getSuggestedDestination($redirectUrl, $options);
        }
        if ($suggestion !== null) {
            $preTableBlock .= $this->buildSuggestionBlockHtml($suggestion);
        }

        // Redirect-to autocomplete row. When creating from captured URLs, clear the
        // redirect_to field so the placeholder text is visible.
        $redirectFinalDest = is_scalar($redirect['final_dest'] ?? 0) ? (string)($redirect['final_dest'] ?? '0') : '0';
        if ($isFromCaptured) {
            $pageTitle = '';
            $pageIDAndType = '';
        } else {
            $pageTitle = $this->logic->getPageTitleFromIDAndType($pageIDAndType, $redirectFinalDest);
        }
        $manualPickerHiddenClass = ($suggestion !== null && $isSimpleMode) ? ' abj404-hidden' : '';
        $redirectToInner = $this->buildRedirectToDropdownHtml($pageTitle, $pageIDAndType);
        $redirectToBody = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectManualPickerWrapper.html');
        $redirectToBody = $this->f->str_replace('{hidden_class}', $manualPickerHiddenClass, $redirectToBody);
        $redirectToBody = $this->f->str_replace('{inner_html}', $redirectToInner, $redirectToBody);
        $formRows .= $this->buildFieldRowHtml('redirect_to_user_field', $this->buildRequiredLabel(__('Redirect to', '404-solution')), $redirectToBody);

        // Capture the redirect-type button grid output and place it inside a form-table row.
        ob_start();
        $this->redirectTypeUI->echoRedirectTypeButtonGrid((string)$codeSelected);
        $typeGridHtml = (string)ob_get_clean();
        $formRows .= $this->buildFieldRowHtml('code', esc_html__('Redirect Type', '404-solution'), $typeGridHtml);

        // Build advanced options (dates + conditions).
        $advancedOptions = $this->buildAdvancedOptionsHtml($startDate, $endDate);

        // Compose the page using the shell template.
        $cancelUrl = $this->buildCancelUrl($source_page, $filter, $orderby, $order);

        $shell = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectFormShell.html');
        $shell = $this->f->str_replace('{title}', esc_html($title), $shell);
        $shell = $this->f->str_replace('{back_url}', esc_url($backUrl), $shell);
        $shell = $this->f->str_replace('{back_label}', esc_html($backLabel), $shell);
        $shell = $this->f->str_replace('{action_url}', esc_attr($actionUrl), $shell);
        $shell = $this->f->str_replace('{hidden_inputs}', $hiddenInputs, $shell);
        $shell = $this->f->str_replace('{pre_table_block}', $preTableBlock, $shell);
        $shell = $this->f->str_replace('{form_rows}', $formRows, $shell);
        $shell = $this->f->str_replace('{advanced_options}', $advancedOptions, $shell);
        $shell = $this->f->str_replace('{submit_label}', esc_html__('Update Redirect', '404-solution'), $shell);
        $shell = $this->f->str_replace('{cancel_url}', esc_url($cancelUrl), $shell);
        $shell = $this->f->str_replace('{cancel_label}', esc_html__('Cancel', '404-solution'), $shell);

        echo $shell;
    }

    /**
     * Build the form-row HTML, hidden id input, and date strings for a single-record edit.
     *
     * @return array{redirect: array<string, mixed>, redirects_multiple: array<int, array<string, mixed>>, redirectUrl: string, startDate: string, endDate: string, hiddenInputs: string, formRows: string}|null Null on error (already echoed).
     */
    private function buildSingleRecordContent(int $recnum, bool $isSimpleMode): ?array {
        $redirects_multiple = $this->redirectsRepository->getRedirectsByIDs(array($recnum));
        if (empty($redirects_multiple)) {
            echo "Error: Invalid ID Number! (id: " . esc_html((string)$recnum) . ")";
            $this->logger->errorMessage("Error: Invalid ID Number! (id: " . esc_html((string)$recnum) . ")");
            return null;
        }

        /** @var array<string, mixed> $redirect */
        $redirect = reset($redirects_multiple);
        $row = ABJ_404_Solution_RedirectRow::fromRaw($redirect);

        $redirectId = $row !== null ? (string)$row->getId() : '';
        $redirectUrl = $row !== null ? $row->getUrl() : '';
        $redirectEngine = $row !== null ? $row->getEngine() : '';

        $hiddenInputs = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectIdHiddenInput.html');
        $hiddenInputs = $this->f->str_replace('{redirect_id}', esc_attr($redirectId), $hiddenInputs);
        $formRows = $this->buildUrlRowHtml($redirectUrl, $redirectEngine);
        if (!$isSimpleMode) {
            $isRegexChecked = ($row !== null && $row->isRegex()) ? ' checked' : '';
            $formRows .= $this->buildRegexRowHtml($isRegexChecked);
        }

        $startTs = $row !== null ? $row->getStartTs() : 0;
        $endTs = $row !== null ? $row->getEndTs() : 0;

        return array(
            'redirect' => $redirect,
            'redirects_multiple' => $redirects_multiple,
            'redirectUrl' => $redirectUrl,
            'startDate' => $startTs > 0 ? date('Y-m-d', $startTs) : '',
            'endDate' => $endTs > 0 ? date('Y-m-d', $endTs) : '',
            'hiddenInputs' => $hiddenInputs,
            'formRows' => $formRows,
        );
    }

    /**
     * Build a form-table TH label with the native "(Required)" suffix.
     *
     * @return string
     */
    private function buildRequiredLabel(string $baseLabel): string {
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectRequiredLabelSuffix.html');
        $html = $this->f->str_replace('{base_label}', esc_html($baseLabel), $html);
        $html = $this->f->str_replace('{required_label}', esc_html__('(Required)', '404-solution'), $html);
        return $html;
    }

    /**
     * Build the URL form-table row with an optional "Auto-matched by" note.
     *
     * @return string
     */
    private function buildUrlRowHtml(string $redirectUrl, string $redirectEngine): string {
        $matchedByNote = '';
        if ($redirectEngine !== '') {
            $matchedByNote = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectMatchedByNote.html');
            $matchedByNote = $this->f->str_replace('{matched_by_label}', esc_html__('Auto-matched by:', '404-solution'), $matchedByNote);
            $matchedByNote = $this->f->str_replace('{engine_name}', esc_html($this->humanizeEngineName($redirectEngine)), $matchedByNote);
        }
        $urlBody = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectUrlRowBody.html');
        $urlBody = $this->f->str_replace('{url_value}', esc_attr($redirectUrl), $urlBody);
        $urlBody = $this->f->str_replace('{matched_by_note}', $matchedByNote, $urlBody);
        $label = $this->buildRequiredLabel(__('URL', '404-solution'));
        return $this->buildFieldRowHtml('url', $label, $urlBody);
    }

    /**
     * Build the "regular expression" form-table row (advanced-mode only).
     *
     * @param string $isRegexChecked ' checked' or ''
     * @return string
     */
    private function buildRegexRowHtml(string $isRegexChecked): string {
        $regexLabel = __('Treat this URL as a regular expression', '404-solution');
        $body = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectRegexBody.html');
        $body = $this->f->str_replace('{regex_label}', esc_html($regexLabel), $body);
        $body = $this->f->str_replace('{is_regex_checked}', $isRegexChecked, $body);
        $body = $this->f->str_replace('{regex_explain_link}', esc_html__('(Explain)', '404-solution'), $body);
        $body = $this->f->str_replace('{regex_explain_text}', esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution'), $body);
        $body = $this->f->str_replace('{regex_example_label}', esc_html__('Example:', '404-solution'), $body);
        $body = $this->f->str_replace('{regex_example_text}', esc_html__('/events/(.+) will match any URL that begins with /events/ and redirect to the specified page. Since a capture group is used, you can use a $1 replacement in the destination string of an external URL.', '404-solution'), $body);
        return $this->buildFieldRowHtml('is_regex_url', '&nbsp;', $body);
    }

    /**
     * Build hidden `source_*` inputs that preserve the originating list-table view.
     *
     * @return string
     */
    private function buildSourceHiddenInputs(string $sourcePage, string $filter, string $orderby, string $order, string $paged): string {
        $pairs = array(
            'source_page' => $sourcePage,
            'source_filter' => $filter,
            'source_orderby' => $orderby,
            'source_order' => $order,
            'source_paged' => $paged,
        );
        $template = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectSourceHiddenInput.html');
        $html = '';
        foreach ($pairs as $name => $value) {
            if ($value === '') {
                continue;
            }
            $line = $this->f->str_replace('{name}', esc_attr($name), $template);
            $line = $this->f->str_replace('{value}', esc_attr($value), $line);
            $html .= $line;
        }
        return $html;
    }

    /**
     * Build the back-to-list cancel URL with preserved filter/orderby/order params.
     *
     * @return string
     */
    private function buildCancelUrl(string $sourcePage, string $filter, string $orderby, string $order): string {
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
     * Build the Advanced Options section (schedule + conditions) HTML using the template.
     *
     * @param string $startDate ISO date for "Active From", or empty.
     * @param string $endDate ISO date for "Active Until", or empty.
     * @return string
     */
    private function buildAdvancedOptionsHtml(string $startDate, string $endDate): string {
        $redirectId = 0;
        $rawId = $_GET['id'] ?? ($_POST['id'] ?? null);
        if (is_scalar($rawId) && $this->f->regexMatch('[0-9]+', (string)$rawId)) {
            $redirectId = absint((string)$rawId);
        }
        $hasExistingConditions = ($redirectId > 0) && !empty($this->redirectsRepository->getRedirectConditions($redirectId));
        $hasAdvancedValues = ($startDate !== '' || $endDate !== '' || $hasExistingConditions);
        $openAttr = $hasAdvancedValues ? ' open' : '';

        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsHtml = (string)ob_get_clean();

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectAdvancedOptions.html');
        $html = $this->f->str_replace('{advanced_options_label}', esc_html__('Advanced Options', '404-solution'), $html);
        $html = $this->f->str_replace('{open_attr}', $openAttr, $html);
        $html = $this->f->str_replace('{start_date_label}', esc_html__('Active From (optional)', '404-solution'), $html);
        $html = $this->f->str_replace('{start_date_value}', esc_attr($startDate), $html);
        $html = $this->f->str_replace('{start_date_help}', esc_html__('Leave blank to activate immediately', '404-solution'), $html);
        $html = $this->f->str_replace('{end_date_label}', esc_html__('Active Until (optional)', '404-solution'), $html);
        $html = $this->f->str_replace('{end_date_value}', esc_attr($endDate), $html);
        $html = $this->f->str_replace('{end_date_help}', esc_html__('Leave blank to never expire', '404-solution'), $html);
        $html = $this->f->str_replace('{conditions_section}', $conditionsHtml, $html);
        return $html;
    }

    /**
     * Build a single `<tr><th><label></label></th><td>{body}</td></tr>` row using the field-row template.
     *
     * @param string $fieldId Form-control id used in the label's `for` attribute.
     * @param string $labelHtml Already-escaped label HTML (may include inline <span class="description">).
     * @param string $bodyHtml Already-built input/markup for the td cell.
     * @return string
     */
    private function buildFieldRowHtml(string $fieldId, string $labelHtml, string $bodyHtml): string {
        $row = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/editRedirectFieldRow.html');
        $row = $this->f->str_replace('{field_id}', esc_attr($fieldId), $row);
        $row = $this->f->str_replace('{field_label}', $labelHtml, $row);
        $row = $this->f->str_replace('{field_body}', $bodyHtml, $row);
        return $row;
    }
    
    /**
     * @param string $dest
     * @param array<int, object> $rows
     * @return string
     */
    function echoRedirectDestinationOptionsOthers($dest, $rows) {
        $content = array();

        $rowCounter = 0;
        $currentPostType = '';

        foreach ($rows as $row) {
            $rowCounter++;
            /** @var object{id: int, post_type: string, depth?: int} $row */
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            
            abj_service('request_context')->debug_info = 'Before row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
            
            if ($row->post_type != $currentPostType) {
                if ($currentPostType != '') {
                    $content[] = "\n" . '</optgroup>' . "\n";
                }
                
                $content[] = "\n" . '<optgroup label="' . __(ucwords($row->post_type), '404-solution') . '">' . "\n";
                $currentPostType = $row->post_type;
            }

            // this is split in this ridiculous way to help me figure out how to resolve a memory issue.
            // (https://wordpress.org/support/topic/options-tab-is-not-loading/)
            $content[] = "\n <option value=\"";
            $content[] = esc_attr($thisval);
            $content[] = "\"";
            $content[] = $selected;
            $content[] = ">";
            
            // insert some spaces for child pages.
            $depth = property_exists($row, 'depth') ? intval($row->depth) : 0;
            for ($i = 0; $i < $depth; $i++) {
                $content[] = "&nbsp;&nbsp;&nbsp;";
            }
            
            $content[] = __(ucwords($row->post_type), '404-solution');
            $content[] = ": ";
            $content[] = esc_html($theTitle);
            $content[] = "</option>";
            
            abj_service('request_context')->debug_info = 'After row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
        }
        
        $content[] = "\n" . '</optgroup>' . "\n";
        

        abj_service('request_context')->debug_info = 'Cleared after building redirect destination page list.';
        
        return implode('', $content);
    }

    /**
     * @param string $dest
     * @return string
     */
    function echoRedirectDestinationOptionsCatsTags($dest) {
        $content = "";
        $content .= "\n" . '<optgroup label="Categories">' . "\n";
        
        $customTagsEtc = array();

        // categories ---------------------------------------------
        $cats = $this->contentRepository->getPublishedCategories();
        foreach ($cats as $cat) {
            /** @var \WP_Term $cat */
            $taxonomy = $cat->taxonomy;
            if ($taxonomy != 'category') {
                continue;
            }
            
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_TYPE_CAT;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        /** @var array<int, object{taxonomy: string, name?: string}> $cats */
        $customTagsEtc = $this->logic->getMapOfCustomCategories($cats);

        // tags ---------------------------------------------
        $content .= "\n" . '<optgroup label="Tags">' . "\n";
        $tags = $this->contentRepository->getPublishedTags();
        foreach ($tags as $tag) {
            /** @var \WP_Term $tag */
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TYPE_TAG;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        
        // custom ---------------------------------------------
        foreach ($customTagsEtc as $taxonomy => $catRow) {
            $content .= "\n" . '<optgroup label="' . esc_html($taxonomy) . '">' . "\n";
            
            foreach ($catRow as $cat) {
                /** @var \WP_Term $cat */
                $id = $cat->term_id;
                $theTitle = $cat->name;
                $thisval = $id . "|" . ABJ404_TYPE_CAT;

                $selected = "";
                if ($thisval == $dest) {
                    $selected = " selected";
                }
                $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Custom', '404-solution') . ": " . $theTitle . "</option>";
            }
            
            $content .= "\n" . '</optgroup>' . "\n";
        }
        
        return $content;
    }
    
    /**
     * Convert a raw engine class name to a human-readable label.
     *
     * Examples:
     *   TitleMatchingEngine        → "Title Matching"
     *   SpellingMatchingEngine     → "Spelling Matching"
     *   CategoryTagMatchingEngine  → "Category/Tag Matching"
     *   UrlFixEngine               → "URL Fix"
     *   ArchiveFallbackEngine      → "Archive Fallback"
     *
     * @param string $rawName
     * @return string
     */
    public function humanizeEngineName(string $rawName): string {
        // Strip full namespace prefix if stored with it.
        $name = preg_replace('/^ABJ_404_Solution_/', '', $rawName);
        if (!is_string($name)) {
            $name = $rawName;
        }
        // Strip "MatchingEngine" or bare "Engine" suffix.
        $name = (string)preg_replace('/MatchingEngine$/', ' Matching', $name);
        $name = (string)preg_replace('/Engine$/', '', $name);
        // Insert a space before each upper-case letter that follows a lower-case letter
        // (e.g. CategoryTag → Category Tag).
        $name = (string)preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $name);
        $name = trim($name);
        // Fix known abbreviations.
        $name = str_replace(array('Url ', 'Url'), array('URL ', 'URL'), $name);
        // Fix Category/Tag — appears as "Category Tag Matching", make the separator a slash.
        $name = str_replace('Category Tag', 'Category/Tag', $name);
        return $name !== '' ? $name : $rawName;
    }

    /**
     * Get the best suggested destination for a captured URL using the spell-checker.
     *
     * @param string $url The captured 404 URL.
     * @param array<string, mixed> $options Plugin options.
     * @return array{title: string, score: int, id_and_type: string, type_label: string}|null The best match, or null if none found.
     */
    public function getSuggestedDestination(string $url, array $options): ?array {
        try {
            $spellChecker = abj_service('spell_checker');
            $permalinksPacket = $spellChecker->findMatchingPosts($url, '1', '1');
            $permalinks = is_array($permalinksPacket[0] ?? null) ? $permalinksPacket[0] : array();
            $rowType = is_string($permalinksPacket[1] ?? '') ? (string)($permalinksPacket[1] ?? '') : '';

            if (empty($permalinks)) {
                return null;
            }

            // Take the top match
            $topIdAndType = array_key_first($permalinks);
            $topScore = intval($permalinks[$topIdAndType]);

            // Only suggest if score is at least 25%
            if ($topScore < 25) {
                return null;
            }

            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray(
                $topIdAndType, $topScore, $rowType, $options
            );

            $title = is_string($permalink['title'] ?? '') ? (string)($permalink['title'] ?? '') : '';
            if ($title === '' || ($permalink['status'] ?? '') === 'trash') {
                return null;
            }

            // Determine a human-readable type label
            $typeParts = explode('|', is_string($topIdAndType) ? $topIdAndType : '');
            $typeInt = isset($typeParts[1]) && is_numeric($typeParts[1]) ? (int)$typeParts[1] : -1;
            $typeLabel = '';
            if ($typeInt === ABJ404_TYPE_POST) {
                $postType = get_post_type((int)$typeParts[0]);
                $typeLabel = ($postType === 'page') ? __('Page', '404-solution') : __('Post', '404-solution');
            } elseif ($typeInt === ABJ404_TYPE_CAT) {
                $typeLabel = __('Category', '404-solution');
            } elseif ($typeInt === ABJ404_TYPE_TAG) {
                $typeLabel = __('Tag', '404-solution');
            } elseif ($typeInt === ABJ404_TYPE_HOME) {
                $typeLabel = __('Home', '404-solution');
            }

            return array(
                'title' => $title,
                'score' => $topScore,
                'id_and_type' => is_string($topIdAndType) ? $topIdAndType : '',
                'type_label' => $typeLabel,
            );
        } catch (\Throwable $e) { // allow-silent-catch: spell-checker may fail on some URLs (encoding, length); null signals "no suggestion" which the caller already handles
            return null;
        }
    }

}
