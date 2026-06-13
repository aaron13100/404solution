<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raised when a setup wizard presentation asset cannot be loaded.
 */
class ABJ_404_Solution_SetupWizardAssetException extends RuntimeException {
}

/**
 * Setup Wizard for first-time plugin configuration
 * Shows a welcome modal on first visit to 404 Solution admin pages
 *
 * @since 3.0.5
 */
class ABJ_404_Solution_SetupWizard {

    /**
     * Option name for storing setup completion date
     */
    const OPTION_NAME = 'abj404_setup_completed';

    /**
     * Initialize the setup wizard functionality
     * @return void
     */
    public static function init(): void {
        // Handle form submission immediately (must run before any output)
        // This is called early during plugin load, so we check and handle here
        if (is_admin() && isset($_POST['abj404_setup_wizard_action'])) {
            // Use admin_init to ensure WordPress is fully loaded for nonce verification
            add_action('admin_init', array(__CLASS__, 'handleFormSubmission'), 1);
        }

        // AJAX handler for skip/close (no page reload needed)
        add_action('wp_ajax_abj404_dismiss_setup_wizard', array(__CLASS__, 'handleAjaxDismiss'));

        // Enqueue assets and output modal on 404 Solution pages
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));
    }

    /**
     * Handle AJAX dismiss (skip/close) - no settings changed, just mark complete
     * @return void
     */
    public static function handleAjaxDismiss(): void {
        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_setup_wizard');

        ABJ_404_Solution_SetupWizardOptionStore::markCompleteToday();

        // Response is intentionally minimal; the UI uses a fire-and-forget request.
        wp_send_json_success(array('message' => ''));
    }

    /**
     * Check if setup wizard should be shown
     *
     * @return bool True if wizard should display
     */
    private static function shouldShowWizard() {
        return !ABJ_404_Solution_SetupWizardOptionStore::isComplete();
    }

    /**
     * Check if current page is a 404 Solution admin page
     *
     * @return bool True if on 404 Solution page
     */
    private static function isPluginPage() {
        if (!is_admin()) {
            return false;
        }

        // Check for the plugin's page parameter
        $rawPage = $_GET['page'] ?? '';
        $page = is_scalar($rawPage) ? sanitize_text_field((string)$rawPage) : '';
        return $page === 'abj404_solution';
    }

    /**
     * Handle form submission for setup wizard
     * @return void
     */
    public static function handleFormSubmission(): void {
        // Check if this is our form submission
        if (!isset($_POST['abj404_setup_wizard_action'])) {
            return;
        }

        // Verify nonce with error feedback (Bug #10 fix)
        if (!isset($_POST['abj404_setup_wizard_nonce']) ||
            !wp_verify_nonce($_POST['abj404_setup_wizard_nonce'], 'abj404_setup_wizard')) {
            wp_die(
                esc_html__('Security check failed. Please try again.', '404-solution'),
                esc_html__('Error', '404-solution'),
                array('response' => 403, 'back_link' => true)
            );
        }

        // Verify plugin-admin access with error feedback (Bug #10 fix)
        if (!ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin()) {
            wp_die(
                esc_html__('You do not have permission to access this page.', '404-solution'),
                esc_html__('Error', '404-solution'),
                array('response' => 403, 'back_link' => true)
            );
        }

        $action = self::submittedAction($_POST);
        $answers = ABJ_404_Solution_SetupWizardAnswerPolicy::answersFromRequest($_POST);

        // All actions mark setup as complete
        ABJ_404_Solution_SetupWizardOptionStore::markCompleteToday();

        // If user clicked "Save & Get Started", apply their settings
        if ($action === 'save') {
            ABJ_404_Solution_SetupWizardOptionStore::saveAnswers($answers);
        }

        $redirect_url = ABJ_404_Solution_SetupWizardAnswerPolicy::redirectPath($answers);
        wp_safe_redirect(admin_url($redirect_url));
        exit;
    }

    /**
     * Return a sanitized setup action or an empty non-save action for malformed input.
     *
     * @param array<string,mixed> $post Raw POST payload.
     * @return string
     */
    private static function submittedAction(array $post): string {
        $rawAction = $post['abj404_setup_wizard_action'] ?? '';
        if (!is_scalar($rawAction)) {
            return '';
        }

        return sanitize_text_field((string)$rawAction);
    }

    /**
     * Enqueue assets on 404 Solution admin pages
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public static function enqueueAssets(string $hook): void {
        // Only load on 404 Solution pages
        if (!self::isPluginPage()) {
            return;
        }

        // Only load if wizard should be shown
        if (!self::shouldShowWizard()) {
            return;
        }

        // Only for users authorized for this plugin admin surface.
        if (!ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin()) {
            return;
        }

        // Add inline styles for the modal
        add_action('admin_head', array(__CLASS__, 'outputStyles'));

        // Output modal HTML in footer
        add_action('admin_footer', array(__CLASS__, 'outputModalHTML'));

        // Output JavaScript for dismiss functionality
        add_action('admin_footer', array(__CLASS__, 'outputScript'), 20);
    }

    /**
     * Output modal CSS styles
     * @return void
     */
    public static function outputStyles(): void {
        ABJ_404_Solution_SetupWizardPresenter::outputStyles();
    }

    /**
     * Output the modal HTML structure
     * @return void
     */
    public static function outputModalHTML(): void {
        ABJ_404_Solution_SetupWizardPresenter::outputModalHTML();
    }

    /**
     * Output JavaScript for dismiss and save functionality
     * @return void
     */
    public static function outputScript(): void {
        ABJ_404_Solution_SetupWizardPresenter::outputScript();
    }
}
