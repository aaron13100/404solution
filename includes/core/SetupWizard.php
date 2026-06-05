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
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'abj404_setup_wizard')) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')), 403);
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', '404-solution')), 403);
        }

        // Mark setup as complete
        update_option(self::OPTION_NAME, gmdate('Y-m-d'));

        // Response is intentionally minimal; the UI uses a fire-and-forget request.
        wp_send_json_success(array('message' => ''));
    }

    /**
     * Check if setup wizard should be shown
     *
     * @return bool True if wizard should display
     */
    private static function shouldShowWizard() {
        // Only show if setup hasn't been completed
        // Existing users upgrading from <3.0.7 have this set via migration in PluginLogic.php
        $completed = get_option(self::OPTION_NAME, '');
        return empty($completed);
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
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
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

        // Verify user capabilities with error feedback (Bug #10 fix)
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to access this page.', '404-solution'),
                esc_html__('Error', '404-solution'),
                array('response' => 403, 'back_link' => true)
            );
        }

        $action = sanitize_text_field($_POST['abj404_setup_wizard_action']);

        // All actions mark setup as complete
        update_option(self::OPTION_NAME, gmdate('Y-m-d'));

        // If user clicked "Save & Get Started", apply their settings
        if ($action === 'save') {
            self::applySettings();
        }

        // Determine redirect destination based on logging choice
        $q2_answer = isset($_POST['abj404_setup_q2']) ? sanitize_text_field($_POST['abj404_setup_q2']) : 'yes';
        $redirect_url = 'options-general.php?page=abj404_solution&setup_complete=1';

        // If logging 404s, take them to Captured 404s tab; otherwise Page Redirects
        if ($q2_answer === 'yes') {
            $redirect_url .= '&subpage=abj404_captured';
        }

        wp_safe_redirect(admin_url($redirect_url));
        exit;
    }

    /** Allowed values for Q1 (Bug #13 fix)
     * @var array<int, string>
     */
    private static $allowedQ1Values = ['redirect', 'default'];

    /** Allowed values for Q2 (Bug #13 fix)
     * @var array<int, string>
     */
    private static $allowedQ2Values = ['yes', 'no'];

    /** Allowed values for Q3
     * @var array<int, string>
     */
    private static $allowedQ3Values = ['yes', 'no'];

    /**
     * Apply settings from wizard form
     * @return void
     */
    private static function applySettings(): void {
        $abj404logic = abj_service('plugin_logic');
        $options = abj_service('options_repository')->getOptions();

        // Question 1: What happens when page not found
        // Validate against whitelist (Bug #13 fix)
        $q1_answer = isset($_POST['abj404_setup_q1']) ? sanitize_text_field($_POST['abj404_setup_q1']) : 'redirect';
        if (!in_array($q1_answer, self::$allowedQ1Values, true)) {
            $q1_answer = 'redirect'; // Default to safe value
        }

        if ($q1_answer === 'redirect') {
            // Automatically redirect to similar page when a match is found
            $options['auto_redirects'] = '1';
            $options['auto_cats'] = '1';
            $options['auto_tags'] = '1';
        } else {
            // Just show the default 404 page - only use manual redirects
            $options['auto_redirects'] = '0';
            $options['auto_cats'] = '0';
            $options['auto_tags'] = '0';
        }
        $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;

        // Question 2: Log 404s
        // Validate against whitelist (Bug #13 fix)
        $q2_answer = isset($_POST['abj404_setup_q2']) ? sanitize_text_field($_POST['abj404_setup_q2']) : 'yes';
        if (!in_array($q2_answer, self::$allowedQ2Values, true)) {
            $q2_answer = 'yes'; // Default to safe value
        }

        $options['capture_404'] = ($q2_answer === 'yes') ? '1' : '0';

        // Question 3: Email alerts
        $q3_answer = isset($_POST['abj404_setup_q3']) ? sanitize_text_field($_POST['abj404_setup_q3']) : 'yes';
        if (!in_array($q3_answer, self::$allowedQ3Values, true)) {
            $q3_answer = 'yes';
        }

        if ($q3_answer === 'yes') {
            $options['admin_notification'] = '50';
            $options['admin_notification_frequency'] = 'weekly';
            $admin_email = get_option('admin_email');
            $options['admin_notification_email'] = is_string($admin_email) ? $admin_email : '';
        }

        // Save options
        abj_service('options_repository')->updateOptions($options);
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

        // Only for users who can manage options
        if (!current_user_can('manage_options')) {
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
        $cssPath = self::filteredAssetPath(
            'abj404_setup_wizard_stylesheet_path',
            dirname(__DIR__) . '/html/setupWizardStyles.css'
        );
        $wrapperPath = self::filteredAssetPath(
            'abj404_setup_wizard_styles_template_path',
            dirname(__DIR__) . '/html/setupWizardStyles.html'
        );

        echo self::fillTpl($wrapperPath, 'setup wizard style wrapper', array(
            'css' => self::readSetupWizardAsset($cssPath, 'setup wizard stylesheet'),
        ));
    }

    /**
     * Output the modal HTML structure
     * @return void
     */
    public static function outputModalHTML(): void {
        $templatePath = self::filteredAssetPath(
            'abj404_setup_wizard_modal_template_path',
            dirname(__DIR__) . '/html/setupWizardModal.html'
        );
        $answers = self::getDefaultAnswerValues();

        echo self::fillTpl($templatePath, 'setup wizard modal template', array(
            'nonce_field' => self::renderNonceField(),
            'welcome_heading' => esc_html__('Welcome to 404 Solution', '404-solution'),
            'close_label' => esc_attr__('Close', '404-solution'),
            'intro_primary' => esc_html__('404 Solution helps you automatically handle 404 errors and broken links on your site.', '404-solution'),
            'intro_secondary' => esc_html__("Let's configure how it handles missing pages. You can always change these settings later.", '404-solution'),
            'q1_heading' => esc_html__('When a page is not found, what should happen?', '404-solution'),
            'q1_redirect_checked' => self::checkedAttribute($answers['q1'], 'redirect'),
            'q1_redirect_label' => esc_html__('Automatically redirect to similar page (recommended)', '404-solution'),
            'q1_redirect_desc' => esc_html__('When a match is found, redirect visitors automatically', '404-solution'),
            'q1_default_checked' => self::checkedAttribute($answers['q1'], 'default'),
            'q1_default_label' => esc_html__('Just show the default 404 page', '404-solution'),
            'q1_default_desc' => esc_html__("Use WordPress's standard \"Page not found\" screen. Manual redirects still work.", '404-solution'),
            'q2_heading' => esc_html__('Log 404 errors for review?', '404-solution'),
            'q2_yes_checked' => self::checkedAttribute($answers['q2'], 'yes'),
            'q2_yes_label' => esc_html__('Yes, log 404 errors', '404-solution'),
            'q2_yes_desc' => esc_html__('Track missing pages so you can create redirects later', '404-solution'),
            'q2_no_checked' => self::checkedAttribute($answers['q2'], 'no'),
            'q2_no_label' => esc_html__("No, don't log 404s", '404-solution'),
            'q2_no_desc' => esc_html__('Only handle manually created redirects', '404-solution'),
            'q3_heading' => esc_html__('Get email alerts about 404 problems?', '404-solution'),
            'q3_yes_checked' => self::checkedAttribute($answers['q3'], 'yes'),
            'q3_yes_label' => esc_html__('Yes, email me a weekly summary (recommended)', '404-solution'),
            'q3_yes_desc' => esc_html__('Get notified when captured 404 URLs exceed 50', '404-solution'),
            'q3_no_checked' => self::checkedAttribute($answers['q3'], 'no'),
            'q3_no_label' => esc_html__("No, I'll check manually", '404-solution'),
            'q3_no_desc' => esc_html__('You can always enable email alerts later in Options', '404-solution'),
            'skip_label' => esc_html__('Skip Setup', '404-solution'),
            'save_label' => esc_html__('Save & Get Started', '404-solution'),
        ));
    }

    /**
     * Render a template with string placeholders.
     *
     * @param string $path Absolute template path.
     * @param string $assetLabel Human-readable asset label for diagnostics.
     * @param array<string,string> $vars Escaped placeholder values.
     * @return string Rendered template.
     */
    private static function fillTpl(string $path, string $assetLabel, array $vars): string {
        $template = self::readSetupWizardAsset($path, $assetLabel);
        $replacements = array();
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Read a setup wizard asset and preserve the failed path in diagnostics.
     *
     * @param string $path Absolute asset path.
     * @param string $assetLabel Human-readable asset label.
     * @return string Asset contents.
     */
    private static function readSetupWizardAsset(string $path, string $assetLabel): string {
        try {
            return ABJ_404_Solution_FileSystemService::readFileContents($path, false);
        } catch (Throwable $e) {
            throw new ABJ_404_Solution_SetupWizardAssetException(
                'Could not load ' . $assetLabel . ' from ' . $path . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Apply a string-valued asset path filter.
     *
     * @param non-empty-string $hook Filter hook name.
     * @param string $defaultPath Default absolute path.
     * @return string Filtered path when valid, otherwise the default.
     */
    private static function filteredAssetPath(string $hook, string $defaultPath): string {
        if (!function_exists('apply_filters')) {
            return $defaultPath;
        }

        $filtered = apply_filters($hook, $defaultPath);
        if (!is_string($filtered) || $filtered === '') {
            return $defaultPath;
        }

        return $filtered;
    }

    /**
     * Return validated default answers for the rendered wizard form.
     *
     * @return array{q1:string,q2:string,q3:string}
     */
    private static function getDefaultAnswerValues(): array {
        $answers = array(
            'q1' => 'redirect',
            'q2' => 'yes',
            'q3' => 'yes',
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_setup_wizard_default_answers', $answers);
            if (is_array($filtered)) {
                if (isset($filtered['q1']) && is_string($filtered['q1'])) {
                    $answers['q1'] = $filtered['q1'];
                }
                if (isset($filtered['q2']) && is_string($filtered['q2'])) {
                    $answers['q2'] = $filtered['q2'];
                }
                if (isset($filtered['q3']) && is_string($filtered['q3'])) {
                    $answers['q3'] = $filtered['q3'];
                }
            }
        }

        return array(
            'q1' => self::validAnswerValue($answers['q1'], self::$allowedQ1Values, 'redirect'),
            'q2' => self::validAnswerValue($answers['q2'], self::$allowedQ2Values, 'yes'),
            'q3' => self::validAnswerValue($answers['q3'], self::$allowedQ3Values, 'yes'),
        );
    }

    /**
     * Return an allowed answer or the safe default.
     *
     * @param string $value Candidate value.
     * @param array<int,string> $allowed Allowed values.
     * @param string $default Default value.
     * @return string Validated answer.
     */
    private static function validAnswerValue(string $value, array $allowed, string $default): string {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Return a leading-space checked attribute for selected radio options.
     *
     * @param string $current Current option value.
     * @param string $candidate Candidate option value.
     * @return string Attribute fragment.
     */
    private static function checkedAttribute(string $current, string $candidate): string {
        return $current === $candidate ? ' checked' : '';
    }

    /**
     * Capture WordPress nonce field output so it can be inserted into the template.
     *
     * @return string Nonce input HTML.
     */
    private static function renderNonceField(): string {
        ob_start();
        wp_nonce_field('abj404_setup_wizard', 'abj404_setup_wizard_nonce');
        return (string)ob_get_clean();
    }

    /**
     * Output JavaScript for dismiss and save functionality
     * @return void
     */
    public static function outputScript(): void {
        ?>
        <script>
            (function() {
                var overlay = document.getElementById('abj404-setup-wizard');
                var closeBtn = document.getElementById('abj404-setup-close');
                var skipBtn = document.getElementById('abj404-setup-skip');
                var saveBtn = document.querySelector('.abj404-setup-primary');
                var form = document.querySelector('#abj404-setup-wizard form');

                // Bug #9 fix: Null check for nonce element
                var nonceEl = document.getElementById('abj404_setup_wizard_nonce');
                var nonce = nonceEl ? nonceEl.value : '';

                // Bug #26 fix: Track dismiss state to prevent multiple calls
                var isDismissing = false;
                var isSubmitting = false;

                function dismissWizard() {
                    // Bug #26 fix: Prevent multiple rapid dismissals
                    if (isDismissing) {
                        return;
                    }
                    isDismissing = true;

                    // Disable buttons to prevent further clicks
                    if (closeBtn) closeBtn.disabled = true;
                    if (skipBtn) skipBtn.disabled = true;

                    // Remove modal immediately
                    if (overlay) {
                        overlay.remove();
                    }

                    // Bug #9 fix: Don't send AJAX if no nonce
                    if (!nonce) {
                        showToast(<?php echo wp_json_encode(__('Could not save settings - missing security token. The wizard may appear again on next visit.', '404-solution')); ?>);
                        return;
                    }

                    // Fire AJAX to mark as complete with error handling
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status !== 200) {
                            showToast(<?php echo wp_json_encode(__('Could not save dismissal. The wizard may appear again on next visit.', '404-solution')); ?>);
                            return;
                        }
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (!response.success) {
                                var msg = response.data && response.data.message ? response.data.message : '';
                                showToast(<?php echo wp_json_encode(__('Could not save dismissal: ', '404-solution')); ?> + msg + <?php echo wp_json_encode(__(' The wizard may appear again on next visit.', '404-solution')); ?>);
                            }
                        } catch (e) {
                            showToast(<?php echo wp_json_encode(__('Could not save dismissal. The wizard may appear again on next visit.', '404-solution')); ?>);
                        }
                    };
                    xhr.onerror = function() {
                        showToast(<?php echo wp_json_encode(__('Network error - could not save dismissal. The wizard may appear again on next visit.', '404-solution')); ?>);
                    };
                    xhr.send('action=abj404_dismiss_setup_wizard&nonce=' + encodeURIComponent(nonce));
                }

                function showToast(message) {
                    // Remove any existing toast
                    var existingToast = document.querySelector('.abj404-toast');
                    if (existingToast) {
                        existingToast.remove();
                    }

                    // Create toast element using DOM methods
                    var toast = document.createElement('div');
                    toast.className = 'abj404-toast';
                    toast.setAttribute('role', 'alert');

                    var closeBtn = document.createElement('button');
                    closeBtn.className = 'abj404-toast-close';
                    closeBtn.setAttribute('aria-label', <?php echo wp_json_encode(__('Close', '404-solution')); ?>);
                    closeBtn.textContent = '\u00D7';
                    closeBtn.onclick = function() { toast.remove(); };

                    var textNode = document.createTextNode(message);

                    toast.appendChild(closeBtn);
                    toast.appendChild(textNode);
                    document.body.appendChild(toast);

                    // Auto-remove after 10 seconds
                    setTimeout(function() {
                        if (toast && toast.parentNode) {
                            toast.remove();
                        }
                    }, 10000);
                }

                function showSavingOverlay() {
                    // Bug #26 fix: Prevent multiple submissions
                    if (isSubmitting) {
                        return false;
                    }
                    isSubmitting = true;

                    // Disable buttons
                    if (saveBtn) saveBtn.disabled = true;
                    if (skipBtn) skipBtn.disabled = true;
                    if (closeBtn) closeBtn.disabled = true;

                    // Bug #19 fix: Use DOM methods instead of innerHTML
                    var loadingOverlay = document.createElement('div');
                    loadingOverlay.className = 'abj404-setup-loading';

                    var spinner = document.createElement('div');
                    spinner.className = 'abj404-setup-spinner';
                    loadingOverlay.appendChild(spinner);

                    var loadingText = document.createElement('span');
                    loadingText.textContent = <?php echo wp_json_encode(__('Saving...', '404-solution')); ?>;
                    loadingOverlay.appendChild(loadingText);

                    var modal = overlay ? overlay.querySelector('.abj404-setup-modal') : null;
                    if (modal) {
                        modal.appendChild(loadingOverlay);
                    }
                }

                if (closeBtn) {
                    closeBtn.addEventListener('click', dismissWizard);
                }
                if (skipBtn) {
                    skipBtn.addEventListener('click', dismissWizard);
                }
                if (form) {
                    form.addEventListener('submit', showSavingOverlay);
                }
            })();
        </script>
        <?php
    }
}
