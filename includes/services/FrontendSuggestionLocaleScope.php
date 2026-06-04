<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies visitor-locale-first rendering for frontend suggestion output.
 */
class ABJ_404_Solution_FrontendSuggestionLocaleScope {

    /**
     * Switch to the best frontend suggestion locale when WordPress supports it.
     *
     * @return bool True when a locale switch was accepted.
     */
    public function switchToFrontendLocale(): bool {
        if (!function_exists('switch_to_locale') || !function_exists('restore_previous_locale')) {
            return false;
        }

        $targetLocale = $this->resolveFrontendSuggestionLocale();
        if ($targetLocale === '') {
            return false;
        }

        return switch_to_locale($targetLocale);
    }

    /**
     * Restore the previous locale when this scope switched it.
     *
     * @param bool $didSwitch
     * @return void
     */
    public function restore(bool $didSwitch): void {
        if ($didSwitch && function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
    }

    /**
     * Resolve frontend locale with visitor-locale-first behavior.
     *
     * @return string
     */
    private function resolveFrontendSuggestionLocale(): string {
        $polyLangLocale = $this->resolvePolylangLocale();
        if ($polyLangLocale !== '') {
            return $polyLangLocale;
        }

        $wpmlLocale = $this->resolveWpmlLocale();
        if ($wpmlLocale !== '') {
            return $wpmlLocale;
        }

        return $this->resolveWordPressLocale();
    }

    /**
     * @return string
     */
    private function resolvePolylangLocale(): string {
        if (function_exists('pll_current_language')) {
            $pllLocale = pll_current_language('locale');
            if (is_string($pllLocale) && $pllLocale !== '') {
                return $pllLocale;
            }
        }
        return '';
    }

    /**
     * @return string
     */
    private function resolveWpmlLocale(): string {
        if (function_exists('apply_filters') && function_exists('has_filter') && has_filter('wpml_current_language')) {
            $langCode = apply_filters('wpml_current_language', null);
            if (is_string($langCode) && $langCode !== '' && has_filter('wpml_active_languages')) {
                $active = apply_filters('wpml_active_languages', null, 'skip_missing=0');
                if (is_array($active) && isset($active[$langCode]) && is_array($active[$langCode])) {
                    $entry = $active[$langCode];
                    if (!empty($entry['default_locale']) && is_string($entry['default_locale'])) {
                        return $entry['default_locale'];
                    }
                    if (!empty($entry['locale']) && is_string($entry['locale'])) {
                        return $entry['locale'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * @return string
     */
    private function resolveWordPressLocale(): string {
        if (function_exists('determine_locale')) {
            $locale = determine_locale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return '';
    }
}
