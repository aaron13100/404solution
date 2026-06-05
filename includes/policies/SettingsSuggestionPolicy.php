<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies suggestion scoring/count and suggestion-template settings.
 */
class ABJ_404_Solution_SettingsSuggestionPolicy {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /**
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     */
    public function __construct($logger, $contentRepo) {
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    public function applyScoringOptions(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['suggest_max'])) {
            $message .= $this->applySuggestMax($options, $postData['suggest_max']);
        }
        if (isset($postData['auto_score'])) {
            $message .= $this->applyAutoScore($options, $postData['auto_score']);
        }
        foreach (array('auto_score_title', 'auto_score_category_tag', 'auto_score_content') as $key) {
            if (isset($postData[$key])) {
                $message .= $this->applyEngineScore($options, $key, $postData[$key]);
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Empty string; kept for section-updater message contract.
     */
    public function applyTemplateOptions(array &$options, array $postData): string {
        foreach (array(
            'suggest_title',
            'suggest_before',
            'suggest_after',
            'suggest_entrybefore',
            'suggest_entryafter',
            'suggest_noresults',
        ) as $optionName) {
            if (isset($postData[$optionName])) {
                $options[$optionName] = wp_kses_post(is_string($postData[$optionName]) ? $postData[$optionName] : '');
            }
        }

        $this->normalizeTemplateOptions($options);
        return "";
    }

    /**
     * Repair malformed suggestion template options.
     *
     * @param array<string, mixed> $options
     * @return bool True when any option was changed.
     */
    public function normalizeTemplateOptions(array &$options): bool {
        $changed = false;
        $defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();

        if ($this->repairTemplateOption(
            $options,
            $defaults,
            'suggest_title',
            'suggest_title_text',
            '<h3>{suggest_title_text}</h3>'
        )) {
            $changed = true;
        }
        if ($this->repairTemplateOption(
            $options,
            $defaults,
            'suggest_noresults',
            'suggest_noresults_text',
            '<p>{suggest_noresults_text}</p>'
        )) {
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawSuggestMax
     */
    private function applySuggestMax(array &$options, $rawSuggestMax): string {
        if (!is_numeric($rawSuggestMax) || $rawSuggestMax < 1) {
            return __('Error: Maximum number of suggest value must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
        }

        if ($options['suggest_max'] != absint($rawSuggestMax)) {
            $oldSuggestMax = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ?
                (string)$options['suggest_max'] : '';
            $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Truncating spelling cache because the max suggestions # changed from " .
                $oldSuggestMax . ' to ' . absint($rawSuggestMax));
            $this->contentRepo->deleteSpellingCache();
        }
        $options['suggest_max'] = absint($rawSuggestMax);
        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawAutoScore
     */
    private function applyAutoScore(array &$options, $rawAutoScore): string {
        if (is_numeric($rawAutoScore) && $rawAutoScore >= 0 && $rawAutoScore <= 99) {
            $options['auto_score'] = absint($rawAutoScore);
            return "";
        }

        return __('Error: Auto match score value must be a number between 0 and 99', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawScore
     */
    private function applyEngineScore(array &$options, string $key, $rawScore): string {
        $val = is_string($rawScore) ? trim($rawScore) : (is_numeric($rawScore) ? trim(strval($rawScore)) : '');
        if ($val === '') {
            $options[$key] = '';
            return "";
        }
        if (is_numeric($val) && $val >= 0 && $val <= 99) {
            $options[$key] = absint($val);
            return "";
        }

        return __('Error: Per-engine score override must be empty or a number between 0 and 99', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $defaults
     */
    private function repairTemplateOption(
        array &$options,
        array $defaults,
        string $optionName,
        string $tokenName,
        string $fallbackDefault
    ): bool {
        $default = isset($defaults[$optionName]) && is_string($defaults[$optionName]) ?
            $defaults[$optionName] : $fallbackDefault;
        $value = isset($options[$optionName]) && is_scalar($options[$optionName]) ? (string)$options[$optionName] : '';
        $lower = strtolower(trim($value));
        $wrappedToken = '{' . $tokenName . '}';
        $hasBareBrokenToken = (strpos($value, $tokenName) !== false && strpos($value, $wrappedToken) === false);

        if ($value === '' || in_array($lower, array($tokenName, $wrappedToken), true) || $hasBareBrokenToken) {
            if ($value !== $default) {
                $options[$optionName] = $default;
                return true;
            }
        }

        return false;
    }
}
