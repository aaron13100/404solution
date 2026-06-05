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
        $body = self::appendUserFeedbackSections($body, $payload, $contactEmail);

        if ($includeDiag) {
            $body = self::appendDiagnosticSections($body, $payload, is_scalar($wp_version) ? (string)$wp_version : '');
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

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private static function appendDiagnosticSections(string $body, array $payload, string $wpVersion): string {
        $body .= self::formatDebugLogSection($payload);
        $body .= "===============================================\n";
        $body .= "DATABASE COLLATIONS\n";
        $body .= "===============================================\n\n";
        $body .= ABJ_404_Solution_UninstallDiagnostics::getDatabaseCollationSnapshot() . "\n\n";
        $body .= "===============================================\n";
        $body .= "SYSTEM INFORMATION\n";
        $body .= "===============================================\n\n";

        foreach (self::buildSystemInfo($wpVersion) as $label => $value) {
            $body .= sprintf("%-20s: %s\n", $label, $value);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private static function appendUserFeedbackSections(string $body, array $payload, string $contactEmail): string {
        $body = self::appendNamedPayloadSection($body, $payload, 'uninstall_reason', 'Reason', true);
        $body .= self::formatSelectedIssues($payload);
        $body = self::appendNamedPayloadSection($body, $payload, 'followup_details', 'Additional Details', false);
        $body = self::appendNamedPayloadSection($body, $payload, 'better_plugin_name', 'Switching to', false);
        $body = self::appendNamedPayloadSection($body, $payload, 'other_reason_text', 'Other Reason Details', false);

        if ($contactEmail !== '') {
            $body .= "User Email: " . $contactEmail . "\n\n";
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private static function appendNamedPayloadSection(string $body, array $payload, string $key, string $label, bool $titleCase): string {
        $value = isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : '';
        if ($value === '') {
            return $body;
        }

        $displayValue = $titleCase ? ucfirst(str_replace('-', ' ', $value)) : $value;
        return $body . $label . ": " . $displayValue . "\n\n";
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private static function formatSelectedIssues(array $payload): string {
        $selectedIssues = isset($payload['selected_issues']) && is_string($payload['selected_issues']) ? $payload['selected_issues'] : '';
        if ($selectedIssues === '') {
            return '';
        }

        $body = "Specific Issues:\n";
        foreach (explode(',', $selectedIssues) as $issue) {
            $body .= "  [x] " . ucfirst(str_replace('-', ' ', $issue)) . "\n";
        }
        return $body . "\n";
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private static function formatDebugLogSection(array $payload): string {
        $debugLog = isset($payload['debug_log']) && is_string($payload['debug_log']) ? $payload['debug_log'] : '';
        $body = "===============================================\n";
        $body .= "PLUGIN DEBUG LOG\n";
        $body .= "===============================================\n\n";
        return $body . ($debugLog !== '' ? $debugLog : 'Log excerpt unavailable.') . "\n\n";
    }

    /** @return array<string, string|int|float> */
    private static function buildSystemInfo(string $wpVersion): array {
        $pluginStats = ABJ_404_Solution_UninstallDiagnostics::getPluginStatistics();
        $dbInfo = ABJ_404_Solution_UninstallDiagnostics::getDatabaseInfo();
        $contentCounts = ABJ_404_Solution_UninstallDiagnostics::getContentCounts();

        return array(
            'WordPress Version' => $wpVersion,
            'PHP Version'       => phpversion(),
            'Plugin Version'    => defined('ABJ404_VERSION') ? ABJ404_VERSION : 'Unknown',
            'MySQL Version'     => $dbInfo['version'],
            'DB Charset'        => $dbInfo['charset'],
            'DB Collation'      => $dbInfo['collation'],
            'Multisite'         => is_multisite() ? 'Yes' : 'No',
            'Active Plugins'    => ABJ_404_Solution_UninstallDiagnostics::getActivePluginsList(),
            'Category Count'    => $contentCounts['categories'],
            'Tag Count'         => $contentCounts['tags'],
            'Total Pages'       => $contentCounts['pages'],
            'Total Posts'       => $contentCounts['posts'],
            'Redirects (active)' => $pluginStats['redirects']['all'],
            '  - Manual'        => $pluginStats['redirects']['manual'],
            '  - Automatic'     => $pluginStats['redirects']['auto'],
            '  - Regex'         => $pluginStats['redirects']['regex'],
            '  - Trashed'       => $pluginStats['redirects']['trash'],
            'Captured 404s (active)' => $pluginStats['captured']['all'],
            '  - New'           => $pluginStats['captured']['captured'],
            '  - Ignored'       => $pluginStats['captured']['ignored'],
            '  - Later'         => $pluginStats['captured']['later'],
            '  - Trash'         => $pluginStats['captured']['trash'],
            'Log Entries in DB' => $pluginStats['log_count'],
            'Log Table Size'    => $pluginStats['log_table_size_mb'] . ' MB',
            'Debug File Size'   => $pluginStats['debug_file_size_mb'] . ' MB',
        );
    }
}
