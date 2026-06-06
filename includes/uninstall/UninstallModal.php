<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the deactivation modal popup display.
 *
 * Owns the bootstrap/enqueue path (registers WP hooks, queues modal JS/CSS
 * on plugins.php, renders the modal markup via the external HTML template).
 * AJAX persistence is delegated to ABJ_404_Solution_Ajax_UninstallPrefs,
 * diagnostic snapshot collection to ABJ_404_Solution_UninstallDiagnostics,
 * and email-fallback dispatch to ABJ_404_Solution_UninstallFeedbackEmail.
 *
 * @since 2.36.11
 */
class ABJ_404_Solution_UninstallModal {

    /**
     * Initialize the deactivation modal functionality.
     * @return void
     */
    public static function init(): void {
        // Enqueue assets only on plugins.php page
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));

        // Register AJAX handler for saving preferences
        add_action('wp_ajax_abj404_save_uninstall_prefs', array(__CLASS__, 'handleAjaxSavePreferences'));
    }

    /**
     * Enqueue modal assets (JavaScript, CSS) on plugins.php page.
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public static function enqueueAssets(string $hook): void {
        // Only load on plugins.php page
        if ($hook !== 'plugins.php') {
            return;
        }

        // Only for administrators who can manage plugins
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Enqueue jQuery UI Dialog (WordPress core)
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Enqueue custom JavaScript
        wp_enqueue_script(
            'abj404-uninstall-modal',
            plugin_dir_url(ABJ404_FILE) . 'includes/js/uninstall-modal.js',
            array('jquery', 'jquery-ui-dialog'),
            '1.0.0',
            true
        );

        // Get redirect count for display in modal
        $redirectCount = ABJ_404_Solution_UninstallDiagnostics::getRedirectCount();

        // Pass data to JavaScript
        wp_localize_script('abj404-uninstall-modal', 'abj404UninstallModal', array(
            'nonce' => wp_create_nonce('abj404_uninstall_nonce'),
            'pluginSlug' => self::getPluginSlug(),
            'redirectCount' => $redirectCount,
            'i18n' => array(
                'dialogTitle' => __('404 Solution - Deactivation Options', '404-solution'),
                'btnCancel' => __('Cancel', '404-solution'),
                'btnSkipFeedback' => __('Deactivate without feedback', '404-solution'),
                'btnDeactivate' => __('Email Feedback & Deactivate', '404-solution'),
                'btnSaving' => __('Processing...', '404-solution'),
                'btnDeactivating' => __('Deactivating', '404-solution'),
            )
        ));

        // Enqueue modal-specific stylesheet (extracted from inline CSS).
        wp_enqueue_style(
            'abj404-uninstall-modal',
            plugin_dir_url(ABJ404_FILE) . 'includes/css/uninstall-modal.css',
            array('wp-jquery-ui-dialog'),
            '1.0.0'
        );

        // Output modal HTML in footer
        add_action('admin_footer', array(__CLASS__, 'outputModalHTML'));
    }

    /**
     * Output the modal HTML structure by loading the external template
     * and substituting i18n placeholders.
     *
     * @return void
     */
    public static function outputModalHTML(): void {
        $redirectCount = ABJ_404_Solution_UninstallDiagnostics::getRedirectCount();

        $templatePath = dirname(__DIR__) . '/html/uninstallModal.html';
        if (class_exists('ABJ_404_Solution_Functions')) {
            $html = ABJ_404_Solution_FileSystemService::readFileContents($templatePath);
        } else {
            $html = is_readable($templatePath) ? (string) file_get_contents($templatePath) : '';
        }

        $replacements = array(
            '{{LABEL_BEFORE_DEACTIVATING}}'        => __('Before deactivating, choose what happens to your data:', '404-solution'),
            '{{LABEL_KEEP_REDIRECTS}}'             => sprintf(_n('Keep my redirect (%d)', 'Keep my redirects (%d)', $redirectCount, '404-solution'), $redirectCount),
            '{{LABEL_KEEP_REDIRECTS_DESC}}'        => __('— saves them for later if you reinstall', '404-solution'),
            '{{LABEL_KEEP_LOGS}}'                  => __('Keep 404 logs', '404-solution'),
            '{{LABEL_KEEP_LOGS_DESC}}'             => __('— historical data preserved', '404-solution'),
            '{{LABEL_CACHE_NOTE}}'                 => __('Cache tables are always deleted (can be rebuilt)', '404-solution'),
            '{{LABEL_HELP_IMPROVE}}'               => __('Help us improve (Optional)', '404-solution'),
            '{{REASON_TEMPORARY}}'                 => __('Temporary deactivation for debugging', '404-solution'),
            '{{REASON_NOT_WORKING}}'               => __('The plugin is not working as expected', '404-solution'),
            '{{REASON_FOUND_BETTER}}'              => __('I found a better plugin', '404-solution'),
            '{{REASON_NO_LONGER_NEEDED}}'          => __('I no longer need this functionality', '404-solution'),
            '{{REASON_TOO_COMPLICATED}}'           => __('Too complicated to configure', '404-solution'),
            '{{REASON_PERFORMANCE}}'               => __('Performance issues', '404-solution'),
            '{{REASON_OTHER}}'                     => __('Other reason', '404-solution'),
            '{{LABEL_NOT_WORKING_HEADER}}'         => __('What specifically isn\'t working?', '404-solution'),
            '{{ISSUE_REDIRECTS_NOT_TRIGGERING}}'   => __('Redirects not triggering/working', '404-solution'),
            '{{ISSUE_SETTINGS_NOT_SAVING}}'        => __('Settings not saving', '404-solution'),
            '{{ISSUE_ADMIN_ERRORS}}'               => __('Admin pages showing errors', '404-solution'),
            '{{ISSUE_SUGGESTIONS_NOT_APPEARING}}'  => __('Suggestions not appearing', '404-solution'),
            '{{ISSUE_PLUGIN_CONFLICTS}}'           => __('Conflicts with other plugins', '404-solution'),
            '{{ISSUE_OTHER_ISSUE}}'                => __('Other issue (please specify below)', '404-solution'),
            '{{LABEL_PERFORMANCE_HEADER}}'         => __('What type of performance issue?', '404-solution'),
            '{{ISSUE_SLOW_ADMIN}}'                 => __('Slow admin dashboard', '404-solution'),
            '{{ISSUE_SLOW_FRONTEND}}'              => __('Slow frontend page loads', '404-solution'),
            '{{ISSUE_HIGH_DATABASE}}'              => __('High database usage', '404-solution'),
            '{{ISSUE_MEMORY_ISSUES}}'              => __('Memory issues', '404-solution'),
            '{{ISSUE_OTHER_PERFORMANCE}}'          => __('Other (please specify below)', '404-solution'),
            '{{LABEL_CONFUSING_HEADER}}'           => __('What was confusing?', '404-solution'),
            '{{ISSUE_SETTINGS_CONFUSING}}'         => __('Settings are confusing', '404-solution'),
            '{{ISSUE_TOO_MANY_OPTIONS}}'           => __('Too many options', '404-solution'),
            '{{ISSUE_UNCLEAR_DOCS}}'               => __('Unclear documentation', '404-solution'),
            '{{ISSUE_OTHER_CONFUSION}}'            => __('Other (please specify below)', '404-solution'),
            '{{LABEL_BETTER_PLUGIN_PROMPT}}'       => __('Which plugin are you switching to?', '404-solution'),
            '{{PLACEHOLDER_BETTER_PLUGIN}}'        => esc_attr(__('Plugin name (optional)', '404-solution')),
            '{{LABEL_OTHER_REASON_PROMPT}}'        => __('Please tell us more (optional):', '404-solution'),
            '{{PLACEHOLDER_OTHER_REASON}}'         => esc_attr(__('What\'s your reason for deactivating?', '404-solution')),
            '{{LABEL_ADDITIONAL_DETAILS}}'         => __('Additional details (optional):', '404-solution'),
            '{{PLACEHOLDER_ADDITIONAL_DETAILS}}'   => esc_attr(__('Any other information about the issue...', '404-solution')),
            '{{LABEL_YOUR_EMAIL}}'                 => __('Your email (optional):', '404-solution'),
            '{{PLACEHOLDER_FEEDBACK_EMAIL}}'       => esc_attr(__('For follow-up if needed', '404-solution')),
            '{{LABEL_INCLUDE_DIAGNOSTICS}}'        => __('Include technical details (site URL, system info, plugin counts, and a sanitized log excerpt) to help diagnose the issue', '404-solution'),
            /* translators: %s is a literal relative file path to the plugin's privacy policy stub, rendered as <code>. */
            '{{PRIVACY_NOTE}}'                     => sprintf(
                esc_html__('Privacy details (retention, erasure path, processing region): %s', '404-solution'),
                '<code>docs/privacy.md</code>'
            ),
        );

        echo strtr($html, $replacements);
    }

    /**
     * Back-compat entry point: delegates to Ajax_UninstallPrefs.
     * Production hook (wp_ajax_abj404_save_uninstall_prefs) and existing
     * tests reference this static method directly.
     *
     * @return void
     */
    public static function handleAjaxSavePreferences(): void {
        ABJ_404_Solution_Ajax_UninstallPrefs::handle();
    }

    /**
     * Back-compat entry point: delegates to UninstallFeedbackEmail::send().
     * FeedbackEmailFallback::sendNow() calls this for type='uninstall'.
     *
     * @param array<string, mixed> $payload FeedbackTransport-built payload.
     * @return bool True if wp_mail() reported success, false otherwise.
     */
    public static function sendFeedbackEmail(array $payload): bool {
        return ABJ_404_Solution_UninstallFeedbackEmail::send($payload);
    }

    /**
     * Get the plugin slug for JavaScript.
     *
     * @return string Plugin directory slug
     */
    private static function getPluginSlug(): string {
        $pluginPath = plugin_basename(ABJ404_FILE);
        $parts = explode('/', $pluginPath);
        return $parts[0];
    }
}
