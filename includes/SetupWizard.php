<?php

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
     */
    public static function init() {
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
     */
    public static function handleAjaxDismiss() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'abj404_setup_wizard')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Mark setup as complete
        update_option(self::OPTION_NAME, gmdate('Y-m-d'));

        wp_send_json_success();
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
     */
    public static function handleFormSubmission() {
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

    /** Allowed values for Q1 (Bug #13 fix) */
    private static $allowedQ1Values = ['redirect', 'default'];

    /** Allowed values for Q2 (Bug #13 fix) */
    private static $allowedQ2Values = ['yes', 'no'];

    /**
     * Apply settings from wizard form
     */
    private static function applySettings() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions();

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

        // Save options
        $abj404logic->updateOptions($options);
    }

    /**
     * Enqueue assets on 404 Solution admin pages
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueueAssets($hook) {
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
     */
    public static function outputStyles() {
        ?>
        <style>
            /* Overlay covers the entire plugin content area including fixed tabs */
            .abj404-setup-overlay {
                position: fixed;
                top: var(--admin-bar-height, 32px);
                left: 160px; /* WordPress admin menu width */
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.85);
                z-index: 200;
                display: flex;
                align-items: flex-start;
                justify-content: center;
                padding-top: 50px;
            }

            /* Adjust for folded menu */
            @media screen and (max-width: 960px) {
                .abj404-setup-overlay {
                    left: 36px;
                }
            }

            /* Adjust for mobile */
            @media screen and (max-width: 782px) {
                .abj404-setup-overlay {
                    left: 0;
                    top: 46px; /* Mobile admin bar height */
                }
            }

            .abj404-setup-modal {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                max-width: 500px;
                width: 95%;
                max-height: calc(100vh - 150px);
                overflow-y: auto;
                position: relative;
                border: 1px solid #c3c4c7;
            }

            .abj404-setup-header {
                background: #2271b1;
                color: #fff;
                padding: 16px 20px;
                border-radius: 8px 8px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .abj404-setup-header h2 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: #fff;
            }

            .abj404-setup-close {
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.3);
                border-radius: 4px;
                color: #fff;
                font-size: 20px;
                cursor: pointer;
                padding: 2px 8px;
                line-height: 1;
                opacity: 0.9;
            }

            .abj404-setup-close:hover {
                opacity: 1;
                background: rgba(255, 255, 255, 0.2);
            }

            .abj404-setup-close:focus {
                outline: 2px solid rgba(255, 255, 255, 0.5);
                outline-offset: 1px;
            }

            .abj404-setup-content {
                padding: 16px 24px 10px 24px;
            }

            .abj404-setup-intro {
                margin-bottom: 24px;
                color: #50575e;
                font-size: 14px;
                line-height: 1.5;
            }

            .abj404-setup-question {
                margin-bottom: 24px;
            }

            .abj404-setup-question h3 {
                margin: 0 0 12px 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }

            .abj404-setup-options {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .abj404-setup-option {
                display: flex;
                align-items: flex-start;
                padding: 10px 12px;
                background: #f6f7f7;
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.2s;
            }

            .abj404-setup-option:hover {
                background: #eef0f0;
            }

            .abj404-setup-option:has(input:checked) {
                background: #e6f2ff;
                border: 1px solid #2271b1;
                margin: -1px;
            }

            .abj404-setup-option:focus-within {
                outline: 2px solid #2271b1;
                outline-offset: 1px;
            }

            .abj404-setup-option input[type="radio"] {
                margin: 2px 10px 0 0;
                flex-shrink: 0;
                accent-color: #2271b1;
            }

            .abj404-setup-option input[type="radio"],
            .abj404-setup-option input[type="radio"]:focus,
            .abj404-setup-option input[type="radio"]:checked,
            .abj404-setup-option input[type="radio"]:checked:focus {
                outline: none !important;
                box-shadow: none !important;
                border-color: #2271b1 !important;
            }

            .abj404-setup-option-text {
                flex: 1;
            }

            .abj404-setup-option-label {
                display: block;
                font-weight: 500;
                color: #1d2327;
                margin-bottom: 2px;
            }

            .abj404-setup-option-desc {
                display: block;
                font-size: 12px;
                color: #646970;
            }

            .abj404-setup-footer {
                padding: 16px 24px;
                background: #f6f7f7;
                border-radius: 0 0 8px 8px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
            }

            .abj404-setup-footer .button {
                padding: 6px 16px;
            }

            .abj404-setup-skip {
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                color: #50575e;
                padding: 6px 16px;
                font-size: 13px;
                cursor: pointer;
                text-decoration: none;
                line-height: 1.5;
            }

            .abj404-setup-skip:hover {
                background: #f0f0f1;
                border-color: #8c8f94;
                color: #1d2327;
            }

            .abj404-setup-skip:focus {
                outline: 2px solid #2271b1;
                outline-offset: 1px;
            }

            .abj404-setup-primary {
                background: #2271b1 !important;
                border-color: #2271b1 !important;
                color: #fff !important;
            }

            .abj404-setup-primary:hover {
                background: #135e96 !important;
                border-color: #135e96 !important;
            }

            /* Loading overlay */
            .abj404-setup-loading {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.9);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                border-radius: 8px;
                z-index: 10;
            }

            /* Toast notification for AJAX errors */
            .abj404-toast {
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: #d63638;
                color: #fff;
                padding: 12px 16px;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
                z-index: 9999;
                max-width: 350px;
                font-size: 13px;
                line-height: 1.4;
                animation: abj404-toast-slide 0.3s ease-out;
            }

            @keyframes abj404-toast-slide {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .abj404-toast-close {
                background: none;
                border: none;
                color: #fff;
                font-size: 16px;
                cursor: pointer;
                float: right;
                margin: -4px -4px 0 8px;
                padding: 0 4px;
                opacity: 0.8;
            }

            .abj404-toast-close:hover {
                opacity: 1;
            }

            body.abj404-dark-mode .abj404-toast {
                background: #dc3545;
            }

            .abj404-setup-loading span {
                color: #1d2327;
                font-size: 14px;
            }

            .abj404-setup-spinner {
                width: 24px;
                height: 24px;
                border: 3px solid #c3c4c7;
                border-top-color: #2271b1;
                border-radius: 50%;
                animation: abj404-setup-spin 0.8s linear infinite;
            }

            @keyframes abj404-setup-spin {
                to { transform: rotate(360deg); }
            }

            /* Dark mode support */
            body.abj404-dark-mode .abj404-setup-overlay {
                background: rgba(0, 0, 0, 0.75);
            }

            body.abj404-dark-mode .abj404-setup-modal {
                background: #1e1e1e;
                border-color: #3d3d3d;
            }

            body.abj404-dark-mode .abj404-setup-content {
                color: #e0e0e0;
            }

            body.abj404-dark-mode .abj404-setup-intro {
                color: #b0b0b0;
            }

            body.abj404-dark-mode .abj404-setup-question h3 {
                color: #e0e0e0;
            }

            body.abj404-dark-mode .abj404-setup-option {
                background: #2d2d2d;
            }

            body.abj404-dark-mode .abj404-setup-option:hover {
                background: #3d3d3d;
            }

            body.abj404-dark-mode .abj404-setup-option:has(input:checked) {
                background: #1a3a5c;
                border-color: #5aa2ff;
            }

            body.abj404-dark-mode .abj404-setup-option:focus-within {
                outline-color: #5aa2ff;
            }

            body.abj404-dark-mode .abj404-setup-option input[type="radio"],
            body.abj404-dark-mode .abj404-setup-option input[type="radio"]:focus,
            body.abj404-dark-mode .abj404-setup-option input[type="radio"]:checked,
            body.abj404-dark-mode .abj404-setup-option input[type="radio"]:checked:focus {
                accent-color: #5aa2ff;
                border-color: #5aa2ff !important;
            }

            body.abj404-dark-mode .abj404-setup-option-label {
                color: #e0e0e0;
            }

            body.abj404-dark-mode .abj404-setup-option-desc {
                color: #a0a0a0;
            }

            body.abj404-dark-mode .abj404-setup-footer {
                background: #2d2d2d;
            }

            body.abj404-dark-mode .abj404-setup-skip {
                background: #3d3d3d;
                border-color: #505050;
                color: #b0b0b0;
            }

            body.abj404-dark-mode .abj404-setup-skip:hover {
                background: #4d4d4d;
                border-color: #606060;
                color: #e0e0e0;
            }

            body.abj404-dark-mode .abj404-setup-loading {
                background: rgba(30, 30, 30, 0.9);
            }

            body.abj404-dark-mode .abj404-setup-loading span {
                color: #e0e0e0;
            }

            body.abj404-dark-mode .abj404-setup-spinner {
                border-color: #505050;
                border-top-color: #5aa2ff;
            }
        </style>
        <?php
    }

    /**
     * Output the modal HTML structure
     */
    public static function outputModalHTML() {
        ?>
        <div id="abj404-setup-wizard" class="abj404-setup-overlay">
            <div class="abj404-setup-modal">
                <form method="post" action="">
                    <?php wp_nonce_field('abj404_setup_wizard', 'abj404_setup_wizard_nonce'); ?>

                    <div class="abj404-setup-header">
                        <h2><?php esc_html_e('Welcome to 404 Solution', '404-solution'); ?></h2>
                        <button type="button" id="abj404-setup-close" class="abj404-setup-close" title="<?php esc_attr_e('Close', '404-solution'); ?>">&times;</button>
                    </div>

                    <div class="abj404-setup-content">
                        <p class="abj404-setup-intro">
                            <?php esc_html_e('404 Solution helps you automatically handle 404 errors and broken links on your site.', '404-solution'); ?>
                            <?php esc_html_e("Let's configure how it handles missing pages. You can always change these settings later.", '404-solution'); ?>
                        </p>

                        <!-- Question 1: What happens when page not found -->
                        <div class="abj404-setup-question">
                            <h3><?php esc_html_e('When a page is not found, what should happen?', '404-solution'); ?></h3>
                            <div class="abj404-setup-options">
                                <label class="abj404-setup-option">
                                    <input type="radio" name="abj404_setup_q1" value="redirect" checked>
                                    <span class="abj404-setup-option-text">
                                        <span class="abj404-setup-option-label"><?php esc_html_e('Automatically redirect to similar page (recommended)', '404-solution'); ?></span>
                                        <span class="abj404-setup-option-desc"><?php esc_html_e('When a match is found, redirect visitors automatically', '404-solution'); ?></span>
                                    </span>
                                </label>
                                <label class="abj404-setup-option">
                                    <input type="radio" name="abj404_setup_q1" value="default">
                                    <span class="abj404-setup-option-text">
                                        <span class="abj404-setup-option-label"><?php esc_html_e('Just show the default 404 page', '404-solution'); ?></span>
                                        <span class="abj404-setup-option-desc"><?php esc_html_e("Use WordPress's standard \"Page not found\" screen. Manual redirects still work.", '404-solution'); ?></span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Question 2: Log 404s -->
                        <div class="abj404-setup-question">
                            <h3><?php esc_html_e('Log 404 errors for review?', '404-solution'); ?></h3>
                            <div class="abj404-setup-options">
                                <label class="abj404-setup-option">
                                    <input type="radio" name="abj404_setup_q2" value="yes" checked>
                                    <span class="abj404-setup-option-text">
                                        <span class="abj404-setup-option-label"><?php esc_html_e('Yes, log 404 errors', '404-solution'); ?></span>
                                        <span class="abj404-setup-option-desc"><?php esc_html_e('Track missing pages so you can create redirects later', '404-solution'); ?></span>
                                    </span>
                                </label>
                                <label class="abj404-setup-option">
                                    <input type="radio" name="abj404_setup_q2" value="no">
                                    <span class="abj404-setup-option-text">
                                        <span class="abj404-setup-option-label"><?php esc_html_e("No, don't log 404s", '404-solution'); ?></span>
                                        <span class="abj404-setup-option-desc"><?php esc_html_e('Only handle manually created redirects', '404-solution'); ?></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="abj404-setup-footer">
                        <button type="button" id="abj404-setup-skip" class="abj404-setup-skip">
                            <?php esc_html_e('Skip Setup', '404-solution'); ?>
                        </button>
                        <!-- Hidden input ensures action is sent even if button is disabled during submit -->
                        <input type="hidden" name="abj404_setup_wizard_action" value="save">
                        <button type="submit" class="button abj404-setup-primary">
                            <?php esc_html_e('Save & Get Started', '404-solution'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Output JavaScript for dismiss and save functionality
     */
    public static function outputScript() {
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
