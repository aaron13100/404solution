<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for saving deactivation/uninstall preferences from the
 * deactivation modal. Persists user choices (keep redirects/logs,
 * deactivation reason, follow-up details) and queues the feedback payload
 * for asynchronous transport.
 *
 * @since 4.3.0
 */
class ABJ_404_Solution_Ajax_UninstallPrefs {

    /**
     * Handle AJAX request to save uninstall preferences.
     * Wired to wp_ajax_abj404_save_uninstall_prefs in UninstallModal::init().
     *
     * @return void
     */
    public static function handle(): void { // @phpstan-ignore abj404.cyclomaticComplexity
        $auth = abj_service('ajax_security_gate')->authorizeAdminWithNonce(
            'abj404_uninstall_nonce',
            array('nonce_param' => 'nonce', 'capability' => 'activate_plugins')
        );
        // Explicit-return form of requireAdminWithNonce( for handlers whose
        // tests use non-exiting wp_send_json_error() stubs.
        if (!$auth['ok']) {
            self::sendJsonError(array('message' => $auth['message']), $auth['status']);
            return;
        }

        // Get preferences from AJAX request
        // Use filter_var to properly handle boolean values sent from JavaScript
        $preferences = array(
            'delete_redirects' => isset($_POST['delete_redirects']) ? filter_var($_POST['delete_redirects'], FILTER_VALIDATE_BOOLEAN) : false,
            'delete_logs' => isset($_POST['delete_logs']) ? filter_var($_POST['delete_logs'], FILTER_VALIDATE_BOOLEAN) : false,
            'delete_cache' => true, // Always delete cache tables
            'send_feedback' => isset($_POST['send_feedback']) ? filter_var($_POST['send_feedback'], FILTER_VALIDATE_BOOLEAN) : false,
            'uninstall_reason' => is_string($_POST['uninstall_reason'] ?? null) ? sanitize_text_field($_POST['uninstall_reason']) : '',
            'selected_issues' => is_string($_POST['selected_issues'] ?? null) ? sanitize_text_field($_POST['selected_issues']) : '',
            'followup_details' => is_string($_POST['followup_details'] ?? null) ? sanitize_textarea_field($_POST['followup_details']) : '',
            // Back-compat for older tests/UI that used a single text field.
            'feedback_details' => is_string($_POST['followup_details'] ?? null) ? sanitize_textarea_field($_POST['followup_details']) : '',
            'better_plugin_name' => is_string($_POST['better_plugin_name'] ?? null) ? sanitize_text_field($_POST['better_plugin_name']) : '',
            'other_reason_text' => is_string($_POST['other_reason_text'] ?? null) ? sanitize_textarea_field($_POST['other_reason_text']) : '',
            'feedback_email' => is_string($_POST['feedback_email'] ?? null) ? sanitize_email($_POST['feedback_email']) : '',
            'include_diagnostics' => isset($_POST['include_diagnostics']) ? filter_var($_POST['include_diagnostics'], FILTER_VALIDATE_BOOLEAN) : false
        );

        // Debug logging without dumping raw preference payloads or contact fields.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = abj_service('logging');
            if (is_object($logger) && method_exists($logger, 'debugMessage')) {
                $logger->debugMessage('Uninstall preferences AJAX received: send_feedback=' .
                    ($preferences['send_feedback'] ? 'true' : 'false') .
                    ', include_diagnostics=' . ($preferences['include_diagnostics'] ? 'true' : 'false') .
                    ', delete_redirects=' . ($preferences['delete_redirects'] ? 'true' : 'false') .
                    ', delete_logs=' . ($preferences['delete_logs'] ? 'true' : 'false'));
            }
        }

        // Save preferences using site options for multisite compatibility
        // In multisite, use site_option for network-activated plugins, regular option for single-site
        $option_name = 'abj404_uninstall_preferences';
        $preferences = ABJ_404_Solution_StorageOptionContracts::prepareForWrite($option_name, $preferences);

        // Capture return value to verify save success
        $save_result = false;
        if (is_multisite() && self::isNetworkActivated()) {
            // Network-activated: Use site option (accessible across all sites)
            $save_result = update_site_option($option_name, $preferences);
        } else {
            // Single-site or site-specific activation: Use regular option
            $save_result = update_option($option_name, $preferences, false); // autoload=false
        }

        // Verify the save was successful (false could mean unchanged OR failure)
        if ($save_result === false) {
            // Read back the option to verify it was actually saved
            $saved_value = is_multisite() && self::isNetworkActivated()
                ? get_site_option($option_name)
                : get_option($option_name);

            // If the saved value doesn't match what we tried to save, it's a real failure
            if ($saved_value !== $preferences) {
                $logger = abj_service('logging');
                if ($logger !== null) {
                    $logger->warn('UninstallModal preference save failed: option ' . $option_name .
                        ' did not round-trip after update_option/update_site_option (multisite=' .
                        (is_multisite() ? '1' : '0') .
                        '). Returning HTTP 500 to AJAX caller.');
                }
                self::sendJsonError(array(
                    'message' => __('Could not save preferences. Your choices may not be preserved.', '404-solution')
                ), 500);
                return;
            }
            // If values match, the false return was just because value was unchanged (which is OK)
        }

        // Queue feedback for asynchronous send only if user explicitly opted in.
        // The actual HTTP POST + email-fallback runs out-of-band on the next
        // page load via wp_schedule_single_event(), so this AJAX call never
        // blocks on the network, even on slow SMTP / WAN paths.
        if ($preferences['send_feedback']) {
            $includeDiagnostics = !empty($preferences['include_diagnostics']);
            $debugLog = '';
            // Only fetch the log excerpt when the user opted into diagnostics.
            // Missing logging is an optional degraded path; failures inside
            // the excerpt reader are logged and omitted below.
            if ($includeDiagnostics && function_exists('abj_service_optional')) {
                $logger = abj_service_optional('logging');
                if (is_object($logger) && method_exists($logger, 'getSanitizedLogExcerptForSupport')) {
                    try {
                        $excerpt = $logger->getSanitizedLogExcerptForSupport();
                        if (is_string($excerpt)) {
                            $debugLog = $excerpt;
                        }
                    } catch (\Throwable $e) {
                        ABJ_404_Solution_FeedbackTransportLog::log(
                            'warn',
                            'Uninstall diagnostics debug-log excerpt unavailable: ' . $e->getMessage()
                        );
                    }
                }
            }

            $extras = array(
                'uninstall_reason'    => $preferences['uninstall_reason'],
                'selected_issues'     => $preferences['selected_issues'],
                'followup_details'    => $preferences['followup_details'],
                'better_plugin_name'  => $preferences['better_plugin_name'],
                'other_reason_text'   => $preferences['other_reason_text'],
                'contact_email'       => $preferences['feedback_email'],
                'include_diagnostics' => $includeDiagnostics,
                'debug_log'           => $debugLog,
            );
            // F1 (docs/diagnostic-catalog.md): the "Include technical details"
            // checkbox is the modal's diagnostic opt-in. When unchecked, we
            // must NOT collect or ship site_url, environment_extras, counts,
            // server_software, active_plugins, or any other diagnostic /
            // site-identifying field. The minimal-payload builder keeps the
            // payload schema-valid (server still accepts the feedback) while
            // suppressing every diagnostic row.
            // The preferences above are already saved at this point; a
            // failure building/queuing the feedback report must not turn a
            // successful deactivation save into an error response.
            try {
                $payload = $includeDiagnostics
                    ? ABJ_404_Solution_FeedbackTransport::buildPayload('uninstall', $extras)
                    : ABJ_404_Solution_FeedbackTransport::buildMinimalPayload('uninstall', $extras);
                ABJ_404_Solution_FeedbackTransport::queue($payload, 'uninstall');
            } catch (\Throwable $e) {
                ABJ_404_Solution_FeedbackTransportLog::log(
                    'warn',
                    'Uninstall feedback report build/queue failed: ' . $e->getMessage()
                );
            }

            $message = __('Thanks for the feedback!', '404-solution');
        } else {
            // User skipped feedback. Minimal message (won't be shown anyway due to instant redirect).
            $message = '';
        }

        // Return success (failures are already handled above)
        wp_send_json_success(array('message' => $message));
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $status
     * @return void
     */
    private static function sendJsonError(array $payload, int $status): void {
        wp_send_json_error($payload, $status);
    }

    /**
     * Check if plugin is network-activated.
     *
     * @return bool True if network-activated, false otherwise
     */
    private static function isNetworkActivated(): bool {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }
}
