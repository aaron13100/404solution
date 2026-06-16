<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Locale override filter and frontend option helpers.
 *
 * abj404_override_plugin_locale() lets a user run the plugin UI in a language
 * different from the site/user locale. abj404_is_redirect_all_requests_enabled()
 * is a small testable parser for the redirect_all_requests setting.
 *
 * The add_filter('plugin_locale', ...) registration stays in 404-solution.php;
 * this file only defines the functions.
 */
// allow-no-test-found: boot-time global helpers wired via add_filter in 404-solution.php; no same-named unit file. abj404_is_redirect_all_requests_enabled (the option parser) is exercised in LoaderLazyLoadTest.

if (!function_exists('abj404_is_redirect_all_requests_enabled')) {
	/**
	 * Small helper for testability and to keep option-parsing logic consistent.
	 *
	 * @param mixed $options Value returned by get_option('abj404_settings')
	 * @return bool
	 */
	function abj404_is_redirect_all_requests_enabled($options) {
		return is_array($options) &&
			array_key_exists('redirect_all_requests', $options) &&
			(string)$options['redirect_all_requests'] === '1';
	}
}

	/**
	 * Override the locale for this plugin if user has configured a language override.
	 * This allows users to use a different language for the 404 Solution plugin
 * than their WordPress site language or user language preference.
 *
 * @param string $locale The current locale.
 * @param string $domain The text domain.
 * @return string The locale to use for translation loading.
 */
if (!function_exists('abj404_override_plugin_locale')) {
/**
 * @param string $locale
 * @param string $domain
 * @return string
 */
function abj404_override_plugin_locale($locale, $domain) {
	// Only override for our plugin's text domain.
	// Use the value cached in $GLOBALS at plugin boot to avoid a redundant get_option() call.
	if ($domain === '404-solution') {
		$override = isset($GLOBALS['abj404_plugin_language_override']) && is_string($GLOBALS['abj404_plugin_language_override']) ? $GLOBALS['abj404_plugin_language_override'] : '';
		if ($override !== '') {
			return $override;
		}
	}
	return $locale;
}
}
