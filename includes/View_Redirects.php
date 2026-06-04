<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edit redirect page and destination option helpers.
 */
class ABJ_404_Solution_View_Redirects extends ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_RedirectEditFormPresenter|null */
    private $editFormPresenter = null;

    /** @var ABJ_404_Solution_RedirectDestinationOptionsPresenter|null */
    private $destinationOptionsPresenter = null;

    /** @var ABJ_404_Solution_RedirectDestinationSuggestionService|null */
    private $suggestionService = null;

    /** @var ABJ_404_Solution_RedirectEngineLabeler|null */
    private $engineLabeler = null;

    /**
     * @return ABJ_404_Solution_RedirectEditFormPresenter
     */
    private function editFormPresenter(): ABJ_404_Solution_RedirectEditFormPresenter {
        if ($this->editFormPresenter === null) {
            $this->editFormPresenter = new ABJ_404_Solution_RedirectEditFormPresenter(
                $this->f,
                $this->engineLabeler()
            );
        }
        return $this->editFormPresenter;
    }

    /**
     * @return ABJ_404_Solution_RedirectDestinationOptionsPresenter
     */
    private function destinationOptionsPresenter(): ABJ_404_Solution_RedirectDestinationOptionsPresenter {
        if ($this->destinationOptionsPresenter === null) {
            $this->destinationOptionsPresenter = new ABJ_404_Solution_RedirectDestinationOptionsPresenter();
        }
        return $this->destinationOptionsPresenter;
    }

    /**
     * @return ABJ_404_Solution_RedirectDestinationSuggestionService
     */
    private function suggestionService(): ABJ_404_Solution_RedirectDestinationSuggestionService {
        if ($this->suggestionService === null) {
            $this->suggestionService = new ABJ_404_Solution_RedirectDestinationSuggestionService($this->logger);
        }
        return $this->suggestionService;
    }

    /**
     * @return ABJ_404_Solution_RedirectEngineLabeler
     */
    private function engineLabeler(): ABJ_404_Solution_RedirectEngineLabeler {
        if ($this->engineLabeler === null) {
            $this->engineLabeler = new ABJ_404_Solution_RedirectEngineLabeler();
        }
        return $this->engineLabeler;
    }


    /**
     * Resolve final destination, pageIDAndType, and redirect code from a redirect row.
     *
     * @param array<string, mixed> $redirect
     * @param array<string, mixed> $options
     * @return array{final: string, pageIDAndType: string, codeSelected: string}
     */
    public function resolveRedirectDestinationInfo(array $redirect, array $options): array {
        return $this->editFormPresenter()->resolveRedirectDestinationInfo($redirect, $options);
    }

    /**
     * Build the redirect-to autocomplete dropdown HTML from the template.
     *
     * @param string $pageTitle
     * @param string $pageIDAndType
     * @return string
     */
    public function buildRedirectToDropdownHtml(string $pageTitle, string $pageIDAndType): string {
        return $this->editFormPresenter()->buildRedirectToDropdownHtml($pageTitle, $pageIDAndType);
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

        $rowHtml = $this->editFormPresenter()->buildBulkUrlsRowHtml($redirects_multiple);
        $hiddenInput = $this->editFormPresenter()->buildIdsMultipleHiddenInput($recnums_multiple);

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
        return $this->editFormPresenter()->buildSuggestionBlockHtml($suggestion);
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

        $isSimpleMode = abj_service('settings_mode_preference')->getMode() === 'simple';
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
        $hiddenInputs = $this->editFormPresenter()->buildSourceHiddenInputs($source_page, $filter, $orderby, $order, $paged);

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
            $pageTitle = $this->logic->pageOrdering()->getPageTitleFromIDAndType($pageIDAndType, $redirectFinalDest);
        }
        $manualPickerHiddenClass = ($suggestion !== null && $isSimpleMode) ? ' abj404-hidden' : '';
        $redirectToInner = $this->buildRedirectToDropdownHtml($pageTitle, $pageIDAndType);
        $redirectToBody = $this->editFormPresenter()->buildManualPickerWrapperHtml($manualPickerHiddenClass, $redirectToInner);
        $formRows .= $this->editFormPresenter()->buildFieldRowHtml(
            'redirect_to_user_field',
            $this->editFormPresenter()->buildRequiredLabel(__('Redirect to', '404-solution')),
            $redirectToBody
        );

        // Capture the redirect-type button grid output and place it inside a form-table row.
        ob_start();
        $this->redirectTypeUI->echoRedirectTypeButtonGrid((string)$codeSelected);
        $typeGridHtml = (string)ob_get_clean();
        $formRows .= $this->editFormPresenter()->buildFieldRowHtml('code', esc_html__('Redirect Type', '404-solution'), $typeGridHtml);

        // Build advanced options (dates + conditions).
        $advancedOptions = $this->buildAdvancedOptionsHtml($startDate, $endDate);

        // Compose the page using the shell template.
        $cancelUrl = $this->editFormPresenter()->buildCancelUrl($source_page, $filter, $orderby, $order);

        echo $this->editFormPresenter()->buildShellHtml(array(
            '{title}' => esc_html($title),
            '{back_url}' => esc_url($backUrl),
            '{back_label}' => esc_html($backLabel),
            '{action_url}' => esc_attr($actionUrl),
            '{hidden_inputs}' => $hiddenInputs,
            '{pre_table_block}' => $preTableBlock,
            '{form_rows}' => $formRows,
            '{advanced_options}' => $advancedOptions,
            '{submit_label}' => esc_html__('Update Redirect', '404-solution'),
            '{cancel_url}' => esc_url($cancelUrl),
            '{cancel_label}' => esc_html__('Cancel', '404-solution'),
        ));
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

        $hiddenInputs = $this->editFormPresenter()->buildRedirectIdHiddenInput($redirectId);
        $formRows = $this->editFormPresenter()->buildUrlRowHtml($redirectUrl, $redirectEngine);
        $isRegexChecked = ($row !== null && $row->isRegex()) ? ' checked' : '';
        $formRows .= $this->editFormPresenter()->buildRegexRowHtml($isRegexChecked);

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

        return $this->editFormPresenter()->buildAdvancedOptionsHtml(
            $startDate,
            $endDate,
            $conditionsHtml,
            $openAttr !== ''
        );
    }
    
    /**
     * @param string $dest
     * @param array<int, object> $rows
     * @return string
     */
    function echoRedirectDestinationOptionsOthers($dest, $rows) {
        return $this->destinationOptionsPresenter()->buildPostOptions(
            (string)$dest,
            $rows,
            function(string $debugInfo): void {
                abj_service('request_context')->debug_info = $debugInfo;
            }
        );
    }

    /**
     * @param string $dest
     * @return string
     */
    function echoRedirectDestinationOptionsCatsTags($dest) {
        $cats = $this->contentRepository->getPublishedCategories();
        /** @var array<int, object{taxonomy: string, name?: string}> $cats */
        $customTagsEtc = $this->logic->pageOrdering()->getMapOfCustomCategories($cats);
        $tags = $this->contentRepository->getPublishedTags();
        return $this->destinationOptionsPresenter()->buildTaxonomyOptions((string)$dest, $cats, $tags, $customTagsEtc);
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
        return $this->engineLabeler()->humanize($rawName);
    }

    /**
     * Get the best suggested destination for a captured URL using the spell-checker.
     *
     * @param string $url The captured 404 URL.
     * @param array<string, mixed> $options Plugin options.
     * @return array{title: string, score: int, id_and_type: string, type_label: string}|null The best match, or null if none found.
     */
    public function getSuggestedDestination(string $url, array $options): ?array {
        return $this->suggestionService()->getSuggestedDestination($url, $options);
    }

}
