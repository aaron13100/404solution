<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies regex-backed settings from the settings form payload.
 *
 * The stored contract has two parts for each textarea setting: the raw
 * sanitized textarea value and the derived list of anchored, wildcard-aware
 * regular expressions used at runtime.
 */
class ABJ_404_Solution_SettingsRegexPatternPolicy {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->functions = $functions;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Empty string; kept for the section-updater message contract.
     */
    public function apply(array &$options, array $postData): string {
        if (isset($postData['folders_files_ignore'])) {
            $foldersFilesVal = is_string($postData['folders_files_ignore']) ? $postData['folders_files_ignore'] : '';
            $options['folders_files_ignore'] = wp_unslash(wp_kses_post($foldersFilesVal));
            $options['folders_files_ignore_usable'] = $this->patternsFromLines(
                $this->functions->explodeNewline($options['folders_files_ignore']),
                true
            );
        }

        if (isset($postData['suggest_regex_exclusions'])) {
            $suggestRegexRaw = is_string($postData['suggest_regex_exclusions']) ? $postData['suggest_regex_exclusions'] : '';
            $sanitizedExclusions = sanitize_textarea_field(wp_unslash($suggestRegexRaw));
            $options['suggest_regex_exclusions'] = $sanitizedExclusions;
            $options['suggest_regex_exclusions_usable'] = $this->patternsFromLines(
                $this->functions->explodeNewline($sanitizedExclusions),
                false
            );
        }

        return "";
    }

    /**
     * @param array<int, string> $lines
     * @param bool $includeEmpty Existing folders_files_ignore behavior stores
     *                           the anchored empty pattern when the splitter
     *                           returns one; suggest exclusions skip empties.
     * @return array<int, string>
     */
    private function patternsFromLines(array $lines, bool $includeEmpty): array {
        $usablePatterns = array();
        foreach ($lines as $line) {
            $trimmedPattern = trim($line);
            if (!$includeEmpty && empty($trimmedPattern)) {
                continue;
            }
            $newPattern = '^' . preg_quote($trimmedPattern, '/') . '$';
            $newPattern = str_replace('\*', '.*', $newPattern);
            $usablePatterns[] = $newPattern;
        }
        return $usablePatterns;
    }
}
