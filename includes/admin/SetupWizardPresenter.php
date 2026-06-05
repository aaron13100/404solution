<?php

// allow-no-test-found: covered by tests/SetupWizardTest.php through setup wizard admin render entry points.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders setup wizard admin presentation assets.
 */
class ABJ_404_Solution_SetupWizardPresenter {

    /**
     * Output modal CSS styles.
     *
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
     * Output the modal HTML structure.
     *
     * @return void
     */
    public static function outputModalHTML(): void {
        $templatePath = self::filteredAssetPath(
            'abj404_setup_wizard_modal_template_path',
            dirname(__DIR__) . '/html/setupWizardModal.html'
        );
        $answers = ABJ_404_Solution_SetupWizardAnswerPolicy::defaultAnswers();

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
     * Output JavaScript for dismiss and save functionality.
     *
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
