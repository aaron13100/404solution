<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents frontend shortcode suggestion lists and loading placeholders.
 */
class ABJ_404_Solution_ShortcodeSuggestionsPresenter {

    /**
     * Render suggestions HTML from a pre-computed suggestions packet.
     *
     * @param array<int, mixed> $suggestionsPacket
     * @param string $requestedURL
     * @param array<string, mixed>|null $options
     * @param bool $adminDebugModal
     * @return string
     */
    public function renderSuggestionsHTML(array $suggestionsPacket, string $requestedURL = '',
            ?array $options = null, bool $adminDebugModal = false): string {
        $options = $options ?? abj_service('options_repository')->getOptions(true);

        $permalinkSuggestions = isset($suggestionsPacket[0]) ? (array)$suggestionsPacket[0] : [];
        $rowType = isset($suggestionsPacket[1]) && is_string($suggestionsPacket[1])
            ? $suggestionsPacket[1] : 'pages';

        $showExtraAdminData = $this->shouldShowAdminData();
        $adminDebugPresenter = new ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter();
        $extraDataById = ($showExtraAdminData && $adminDebugModal)
            ? $adminDebugPresenter->collectExtraData($permalinkSuggestions, abj_service('view_read_service'), abj_service('functions'))
            : [];
        $adminDebugData = [];

        $rowResult = $this->renderSuggestionRows($permalinkSuggestions, $rowType, $options,
            $showExtraAdminData, $adminDebugModal, $adminDebugPresenter, $extraDataById, $adminDebugData);
        $body = $rowResult['body'] . $this->renderResultFooter($rowResult['displayed'], $options)
            . $this->renderAdminNote($showExtraAdminData, $rowResult, $requestedURL, $options);

        $content = $this->fillTemplate('shortcodeSuggestionsContainer.html', array(
            'title' => wp_kses_post(
                $this->replaceSuggestionTemplateToken($this->optionString($options, 'suggest_title'),
                    'suggest_title_text', __('Here are some other great pages', '404-solution'))
            ),
            'body' => $body,
        ));

        if ($showExtraAdminData && $adminDebugModal && !empty($adminDebugData)) {
            $content .= $adminDebugPresenter->renderScript($adminDebugData);
        }

        return $content;
    }

    /**
     * @param array<int|string, mixed> $permalinkSuggestions
     * @param string $rowType
     * @param array<string, mixed> $options
     * @param bool $showExtraAdminData
     * @param bool $adminDebugModal
     * @param ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter $adminDebugPresenter
     * @param array<string, array<string, mixed>> $extraDataById
     * @param array<int, array<string, mixed>> $adminDebugData
     * @return array{body: string, displayed: int, bestScore: float}
     */
    private function renderSuggestionRows(array $permalinkSuggestions, string $rowType, array $options,
            bool $showExtraAdminData, bool $adminDebugModal,
            ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter $adminDebugPresenter,
            array $extraDataById, array &$adminDebugData): array {
        $body = '';
        $displayed = 0;
        $bestScore = 0.0;
        $currentSlug = $this->currentSlug(abj_service('plugin_logic'), abj_service('functions'));
        $commentPartRaw = abj_service('not_found_response')->getCommentPartAndQueryPartOfRequest();
        $commentPartAndQueryPart = is_string($commentPartRaw) ? $commentPartRaw : '';

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $row = $this->renderSuggestionRow((string)$idAndType, $linkScore, $rowType, $options,
                $currentSlug, $commentPartAndQueryPart, $showExtraAdminData, $adminDebugModal,
                $adminDebugPresenter, $extraDataById);
            if ($row === null) {
                continue;
            }
            if ($displayed == 0) {
                $body .= wp_kses_post($this->optionString($options, 'suggest_before'));
            }
            $body .= $row['html'];
            if ($row['debugData'] !== null) {
                $adminDebugData[] = $row['debugData'];
            }
            if ($displayed == 0 || $row['score'] > $bestScore) {
                $bestScore = $row['score'];
            }
            $displayed++;
            if ($displayed >= $this->suggestionLimit($options)) {
                break;
            }
        }

        return array('body' => $body, 'displayed' => $displayed, 'bestScore' => $bestScore);
    }

    /**
     * @param string $idAndTypeStr
     * @param mixed $linkScore
     * @param string $rowType
     * @param array<string, mixed> $options
     * @param string $currentSlug
     * @param string $commentPartAndQueryPart
     * @param bool $showExtraAdminData
     * @param bool $adminDebugModal
     * @param ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter $adminDebugPresenter
     * @param array<string, array<string, mixed>> $extraDataById
     * @return array{html: string, debugData: array<string, mixed>|null, score: float}|null
     */
    private function renderSuggestionRow(string $idAndTypeStr, $linkScore, string $rowType, array $options,
            string $currentSlug, string $commentPartAndQueryPart, bool $showExtraAdminData,
            bool $adminDebugModal, ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter $adminDebugPresenter,
            array $extraDataById): ?array {
        if ($this->isExcludedSuggestion($idAndTypeStr)) {
            return null;
        }

        $linkScoreFloat = is_scalar($linkScore) ? (float)$linkScore : 0.0;
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray(
            $idAndTypeStr, $linkScoreFloat, $rowType, $options);
        $permLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
        $permTitle = isset($permalink['title']) && is_string($permalink['title']) ? $permalink['title'] : '';
        $permScore = isset($permalink['score']) && is_numeric($permalink['score']) ? (float)$permalink['score'] : 0.0;

        if ($this->shouldSkipPermalink($permLink, $permScore, $currentSlug, $options)) {
            return null;
        }

        $debugData = null;
        $scoreHtml = $this->scoreHtml($showExtraAdminData, $adminDebugModal, $permScore, $adminDebugPresenter);
        if ($showExtraAdminData && $adminDebugModal) {
            $debugData = $adminDebugPresenter->buildItemData($idAndTypeStr, $permTitle, $permScore, $permLink, $extraDataById);
        }

        return array('html' => $this->fillTemplate('shortcodeSuggestionRow.html', array(
            'entry_before' => wp_kses_post($this->optionString($options, 'suggest_entrybefore')),
            'href' => esc_url($permLink . $commentPartAndQueryPart),
            'title_attr' => esc_attr($permTitle),
            'title_text' => esc_attr($permTitle),
            'score_html' => $scoreHtml,
            'entry_after' => wp_kses_post($this->optionString($options, 'suggest_entryafter')),
        )) . "\n", 'debugData' => $debugData, 'score' => $permScore);
    }

    /**
     * @param string $permLink
     * @param float $permScore
     * @param string $currentSlug
     * @param array<string, mixed> $options
     * @return bool
     */
    private function shouldSkipPermalink(string $permLink, float $permScore, string $currentSlug, array $options): bool {
        if ($currentSlug !== '' && basename($permLink) == $currentSlug) {
            return true;
        }
        $minScoreEnabled = isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1';
        $suggestMinscoreRaw = isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) ? $options['suggest_minscore'] : 25;
        return $minScoreEnabled && $permScore < intval($suggestMinscoreRaw);
    }

    /**
     * @param bool $showExtraAdminData
     * @param bool $adminDebugModal
     * @param float $permScore
     * @return string
     */
    private function scoreHtml(bool $showExtraAdminData, bool $adminDebugModal, float $permScore,
            ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter $adminDebugPresenter): string {
        if (!$showExtraAdminData) {
            return '';
        }
        if ($adminDebugModal) {
            return $adminDebugPresenter->renderScoreLink($permScore);
        }
        return ' (' . number_format($permScore, 4) . ')';
    }

    /**
     * @param int $displayed
     * @param array<string, mixed> $options
     * @return string
     */
    private function renderResultFooter(int $displayed, array $options): string {
        if ($displayed >= 1) {
            return wp_kses_post($this->optionString($options, 'suggest_after')) . "\n";
        }
        return wp_kses_post(
            $this->replaceSuggestionTemplateToken($this->optionString($options, 'suggest_noresults'),
                'suggest_noresults_text', __('No suggestions. :/ ', '404-solution'))
        );
    }

    /**
     * The admin-only block that explains the scores above it: what the best
     * match scored, what an automatic redirect has to clear, and the two ways
     * to act on that. Empty string for everyone else, so a logged-out visitor
     * sees nothing new.
     *
     * Sits below the whole list rather than beside each row on purpose: one
     * block reads as a note, one sentence per suggestion reads as noise.
     *
     * @param bool $showExtraAdminData
     * @param array{body: string, displayed: int, bestScore: float} $rowResult
     * @param string $requestedURL
     * @param array<string, mixed> $options
     * @return string
     */
    private function renderAdminNote(bool $showExtraAdminData, array $rowResult, string $requestedURL,
            array $options): string {
        if (!$showExtraAdminData) {
            return '';
        }
        $notePresenter = new ABJ_404_Solution_ShortcodeSuggestionsAdminNotePresenter();
        return $notePresenter->render($rowResult['displayed'] >= 1, $rowResult['bestScore'],
            $requestedURL, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    private function suggestionLimit(array $options): int {
        return isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? (int)$options['suggest_max'] : 5;
    }

    /**
     * Render a loading placeholder for async suggestions.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $options
     * @return string
     */
    public function renderAsyncPlaceholder(string $requestedURL, array $options): string {
        $suggestMaxVal = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
        $suggestMax = intval($suggestMaxVal);

        $skeletons = '';
        for ($i = 0; $i < $suggestMax; $i++) {
            $skeletons .= $this->fillTemplate('shortcodeSuggestionSkeleton.html', array()) . "\n";
        }

        return $this->fillTemplate('shortcodeSuggestionsPlaceholder.html', array(
            'requested_url' => esc_attr($requestedURL),
            'title' => wp_kses_post(
                $this->replaceSuggestionTemplateToken($this->optionString($options, 'suggest_title'),
                    'suggest_title_text', __('Here are some other great pages', '404-solution'))
            ),
            'before' => wp_kses_post($this->optionString($options, 'suggest_before')),
            'loading_text' => esc_html__('Loading page suggestions...', '404-solution'),
            'skeletons' => $skeletons,
            'after' => wp_kses_post($this->optionString($options, 'suggest_after')),
        ));
    }

    /**
     * @return bool
     */
    private function shouldShowAdminData(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $policy = abj_service('admin_access_policy');
        return is_object($policy)
            && method_exists($policy, 'isPluginAdmin')
            && (bool)$policy->isPluginAdmin();
    }

    /**
     * @param string $idAndTypeStr
     * @return bool
     */
    private function isExcludedSuggestion(string $idAndTypeStr): bool {
        $idTypeParts = explode('|', $idAndTypeStr, 2);
        $idInt = isset($idTypeParts[0]) && is_numeric($idTypeParts[0]) ? (int)$idTypeParts[0] : 0;
        $typeInt = isset($idTypeParts[1]) && is_numeric($idTypeParts[1]) ? (int)$idTypeParts[1] : 0;
        if ($idInt <= 0) {
            return false;
        }

        $typePost = (int)ABJ404_TYPE_POST;
        $typeCat  = (int)ABJ404_TYPE_CAT;
        $typeTag  = (int)ABJ404_TYPE_TAG;
        if ($typeInt === $typePost) {
            return function_exists('get_post_meta') && get_post_meta($idInt, '_abj404_exclude', true) === '1';
        }
        if ($typeInt === $typeCat || $typeInt === $typeTag) {
            return function_exists('get_term_meta') && get_term_meta($idInt, '_abj404_exclude', true) === '1';
        }
        return false;
    }

    /**
     * @param object|null $abj404logic
     * @param object|null $functions
     * @return string
     */
    private function currentSlug($abj404logic, $functions): string {
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI']) ||
                !is_object($abj404logic) || !is_object($functions) ||
                !method_exists($abj404logic, 'urlNormalization') || !method_exists($functions, 'regexReplace')) {
            return '';
        }
        $normalizer = $abj404logic->urlNormalization();
        if (!is_object($normalizer) || !method_exists($normalizer, 'removeHomeDirectory')) {
            return '';
        }
        $requestUri = abj_service('sanitizer')->normalizeUrlString($_SERVER['REQUEST_URI']);
        $slugWithHome = $functions->regexReplace('\?.*', '', $requestUri);
        return is_string($slugWithHome) ? $normalizer->removeHomeDirectory($slugWithHome) : '';
    }

    /**
     * @param array<string, mixed> $options
     * @param string $key
     * @return string
     */
    private function optionString(array $options, string $key): string {
        return isset($options[$key]) && is_string($options[$key]) ? $options[$key] : '';
    }

    /**
     * Replace both placeholder and legacy bare token forms.
     *
     * @param string $template
     * @param string $tokenNameWithoutBraces
     * @param string $replacement
     * @return string
     */
    private function replaceSuggestionTemplateToken(string $template, string $tokenNameWithoutBraces, string $replacement): string {
        return str_replace(
            array('{' . $tokenNameWithoutBraces . '}', $tokenNameWithoutBraces),
            $replacement,
            $template
        );
    }

    /**
     * @param string $name
     * @param array<string, string> $vars
     * @return string
     */
    private function fillTemplate(string $name, array $vars): string {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        return str_replace($search, $replace, $template);
    }
}
