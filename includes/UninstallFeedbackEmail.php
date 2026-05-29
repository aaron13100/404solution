<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composes and dispatches the deactivation feedback email.
 *
 * Called by FeedbackEmailFallback::sendNow() when the primary HTTP POST to
 * the feedback transport fails (cron-context fallback path). Builds a
 * plain-text email body from a FeedbackTransport payload plus a live
 * diagnostic snapshot from UninstallDiagnostics, and dispatches via wp_mail().
 *
 * @since 4.3.0
 */
class ABJ_404_Solution_UninstallFeedbackEmail {

    /**
     * Email-fallback for FeedbackTransport when the HTTP POST fails. Builds a
     * deactivation-feedback email body from a FeedbackTransport payload and
     * dispatches it via wp_mail(). Public because the cron-context fallback in
     * FeedbackTransport::sendNow() invokes this for type='uninstall'.
     *
     * @param array<string, mixed> $payload FeedbackTransport-built payload.
     * @return bool True if wp_mail() reported success, false otherwise.
     */
    public static function send(array $payload): bool {
        global $wp_version;

        $site_name = function_exists('get_bloginfo') ? (string)get_bloginfo('name') : '';
        $rawAdminEmail = function_exists('get_option') ? get_option('admin_email') : '';
        $admin_email = is_string($rawAdminEmail) ? $rawAdminEmail : '';

        $contactEmail = isset($payload['contact_email']) && is_string($payload['contact_email']) ? $payload['contact_email'] : '';
        $includeDiag = !empty($payload['include_diagnostics']);

        $subject = sprintf('[404 Solution] Deactivation Feedback from %s', $site_name);

        $body = "Deactivation feedback received:\n\n";
        $body .= "===============================================\n";
        $body .= "USER FEEDBACK\n";
        $body .= "===============================================\n\n";

        $uninstallReason = isset($payload['uninstall_reason']) && is_string($payload['uninstall_reason']) ? $payload['uninstall_reason'] : '';
        if ($uninstallReason !== '') {
            $body .= "Reason: " . ucfirst(str_replace('-', ' ', $uninstallReason)) . "\n\n";
        }

        $selectedIssues = isset($payload['selected_issues']) && is_string($payload['selected_issues']) ? $payload['selected_issues'] : '';
        if ($selectedIssues !== '') {
            $body .= "Specific Issues:\n";
            foreach (explode(',', $selectedIssues) as $issue) {
                $body .= "  [x] " . ucfirst(str_replace('-', ' ', $issue)) . "\n";
            }
            $body .= "\n";
        }

        $followup = isset($payload['followup_details']) && is_string($payload['followup_details']) ? $payload['followup_details'] : '';
        if ($followup !== '') {
            $body .= "Additional Details:\n" . $followup . "\n\n";
        }

        $betterPlugin = isset($payload['better_plugin_name']) && is_string($payload['better_plugin_name']) ? $payload['better_plugin_name'] : '';
        if ($betterPlugin !== '') {
            $body .= "Switching to: " . $betterPlugin . "\n\n";
        }

        $otherReason = isset($payload['other_reason_text']) && is_string($payload['other_reason_text']) ? $payload['other_reason_text'] : '';
        if ($otherReason !== '') {
            $body .= "Other Reason Details:\n" . $otherReason . "\n\n";
        }

        if ($contactEmail !== '') {
            $body .= "User Email: " . $contactEmail . "\n\n";
        }

        if ($includeDiag) {
            $plugin_stats = ABJ_404_Solution_UninstallDiagnostics::getPluginStatistics();
            $db_info = ABJ_404_Solution_UninstallDiagnostics::getDatabaseInfo();
            $content_counts = ABJ_404_Solution_UninstallDiagnostics::getContentCounts();
            $system_info = array(
                'WordPress Version' => $wp_version,
                'PHP Version'       => phpversion(),
                'Plugin Version'    => defined('ABJ404_VERSION') ? ABJ404_VERSION : 'Unknown',
                'MySQL Version'     => $db_info['version'],
                'DB Charset'        => $db_info['charset'],
                'DB Collation'      => $db_info['collation'],
                'Multisite'         => is_multisite() ? 'Yes' : 'No',
                'Active Plugins'    => ABJ_404_Solution_UninstallDiagnostics::getActivePluginsList(),
                'Category Count'    => $content_counts['categories'],
                'Tag Count'         => $content_counts['tags'],
                'Total Pages'       => $content_counts['pages'],
                'Total Posts'       => $content_counts['posts'],
                'Redirects (active)' => $plugin_stats['redirects']['all'],
                '  - Manual'        => $plugin_stats['redirects']['manual'],
                '  - Automatic'     => $plugin_stats['redirects']['auto'],
                '  - Regex'         => $plugin_stats['redirects']['regex'],
                '  - Trashed'       => $plugin_stats['redirects']['trash'],
                'Captured 404s (active)' => $plugin_stats['captured']['all'],
                '  - New'           => $plugin_stats['captured']['captured'],
                '  - Ignored'       => $plugin_stats['captured']['ignored'],
                '  - Later'         => $plugin_stats['captured']['later'],
                '  - Trash'         => $plugin_stats['captured']['trash'],
                'Log Entries in DB' => $plugin_stats['log_count'],
                'Log Table Size'    => $plugin_stats['log_table_size_mb'] . ' MB',
                'Debug File Size'   => $plugin_stats['debug_file_size_mb'] . ' MB',
            );

            $body .= "===============================================\n";
            $body .= "PLUGIN DEBUG LOG\n";
            $body .= "===============================================\n\n";
            $debugLog = isset($payload['debug_log']) && is_string($payload['debug_log']) ? $payload['debug_log'] : '';
            $body .= ($debugLog !== '' ? $debugLog : 'Log excerpt unavailable.') . "\n\n";

            $body .= "===============================================\n";
            $body .= "DATABASE COLLATIONS\n";
            $body .= "===============================================\n\n";
            $body .= ABJ_404_Solution_UninstallDiagnostics::getDatabaseCollationSnapshot() . "\n\n";

            $body .= "===============================================\n";
            $body .= "SYSTEM INFORMATION\n";
            $body .= "===============================================\n\n";
            foreach ($system_info as $label => $value) {
                $body .= sprintf("%-20s: %s\n", $label, $value);
            }
        }

        $body .= "\n===============================================\n";
        $body .= "This feedback was sent automatically when the user deactivated the plugin.\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        );
        if ($contactEmail !== '') {
            $headers[] = 'Reply-To: ' . $contactEmail;
        }

        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
        return (bool) wp_mail($to, $subject, $body, $headers);
    }
}
