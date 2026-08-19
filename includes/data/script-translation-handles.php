<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registered script handles whose JavaScript calls wp.i18n.__() and therefore
 * needs a wp_set_script_translations() registration plus a per-locale JED JSON
 * file in languages/.
 *
 * This is DATA, not logic. It is the single source of truth shared by three
 * consumers, which is what keeps them from drifting apart:
 *
 *   - ABJ_404_Solution_WPUtils::registerScriptTranslations() reads it at
 *     admin_enqueue_scripts time and registers every handle that the current
 *     screen actually enqueued (wp_set_script_translations() is a no-op for a
 *     handle that was never registered, so one call covers every screen).
 *   - scripts/build-script-translations.php reads it to know which JS file
 *     supplies the msgids for each handle's JSON files.
 *   - ScriptTranslationsTest asserts that every JS file under includes/ that
 *     contains a translation call appears here, so adding a new translated
 *     script without wiring it up fails the suite instead of silently shipping
 *     an English-only string.
 *
 * Keys are wp_enqueue_script() handles; values are the JS source path relative
 * to the plugin root.
 *
 * @return array<string, string>
 */
// allow-no-test-found: static data file (returns the handle-to-source map, no logic); the map is verified against the real on-disk JS sources in ScriptTranslationsTest.
return array(
	'abj404-stats-confidence-chart' => 'includes/js/statsConfidenceChart.js',
	'abj404-support-request-modal-view' => 'includes/js/support-request-modal-view.js',
	'abj404-tools-migrate-plugin' => 'includes/js/toolsMigratePlugin.js',
);
