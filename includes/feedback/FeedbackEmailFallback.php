<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Last-resort wp_mail() transport for feedback reports. Used when the HTTP
 * POST in FeedbackHttpClient fails. Routes to type-specific email body
 * builders so the email and HTTP transports share a single source of truth
 * for what the developer receives.
 *
 *   - 'uninstall'        delegates to UninstallModal::sendFeedbackEmail
 *   - 'error'/'heartbeat' delegates to Logging::emailLogFileToDeveloper
 *   - 'support_request'  built inline (user message + reply email + standard
 *                         diagnostic block + log excerpt)
 *
 * Also owns the development-environment detection that FeedbackPayloadBuilder
 * uses to tag local reports.
 */
class ABJ_404_Solution_FeedbackEmailFallback {

    /**
     * Send the payload via wp_mail(), routing to the type-specific body
     * builder. Returns true on send success.
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return bool
     */
    public static function send(array $payload, string $type): bool {
        if (!function_exists('wp_mail')) {
            return false;
        }
        if ($type === 'uninstall' && class_exists('ABJ_404_Solution_UninstallModal')) {
            return ABJ_404_Solution_UninstallModal::sendFeedbackEmail($payload);
        }
        if ($type === 'support_request') {
            return self::supportRequest($payload);
        }
        if (($type === 'error' || $type === 'heartbeat') && function_exists('abj_service_optional')) {
            try {
                $logger = abj_service_optional('logging');
                if (is_object($logger) && method_exists($logger, 'emailLogFileToDeveloper')) {
                    return (bool) $logger->emailLogFileToDeveloper($payload);
                }
            } catch (\Throwable $e) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackEmailFallback delegate (' . $type . ') failed: ' . $e->getMessage());
            }
        }
        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
        $version = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $subject = sprintf('[404 Solution] %s report (HTTP fallback) v%s', $type, $version);
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_PRETTY_PRINT) : json_encode($payload, JSON_PRETTY_PRINT);
        $body = is_string($json) ? $json : '';
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $result = wp_mail($to, $subject, $body, $headers);
        return (bool)$result;
    }

    /**
     * Build the wp_mail() body for type='support_request' fallbacks. The
     * user-facing fields (their message and reply address) lead the body so
     * a reviewer can act on the request without scrolling past the
     * diagnostic block. The standard payload dump and any captured log
     * excerpt follow as appendices.
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    private static function supportRequest(array $payload): bool {
        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
        $version = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $subject = sprintf('[404 Solution] Support request v%s', $version);

        $userMessage = isset($payload['user_message']) && is_scalar($payload['user_message'])
            ? (string)$payload['user_message'] : '';
        $replyEmail = isset($payload['reply_email']) && is_scalar($payload['reply_email'])
            ? (string)$payload['reply_email'] : '';
        $triggeredFrom = isset($payload['triggered_from']) && is_scalar($payload['triggered_from'])
            ? (string)$payload['triggered_from'] : '';
        $logExcerpt = isset($payload['debug_log_excerpt']) && is_scalar($payload['debug_log_excerpt'])
            ? (string)$payload['debug_log_excerpt'] : '';

        $bodyLines = array();
        $bodyLines[] = '=== USER SUPPORT REQUEST ===';
        $bodyLines[] = '';
        $bodyLines[] = 'Reply-To: ' . ($replyEmail !== '' ? $replyEmail : '(not provided)');
        $bodyLines[] = 'Triggered from: ' . ($triggeredFrom !== '' ? $triggeredFrom : '(unknown)');
        $bodyLines[] = '';
        $bodyLines[] = '--- User message ---';
        $bodyLines[] = $userMessage !== '' ? $userMessage : '(no message provided)';
        $bodyLines[] = '';
        $bodyLines[] = '=== DIAGNOSTICS ===';
        $bodyLines[] = '';
        $scalar = static function ($v, string $fallback = ''): string {
            return is_scalar($v) ? (string)$v : $fallback;
        };
        $bodyLines[] = 'Plugin version: ' . $scalar($payload['plugin_version'] ?? null);
        $bodyLines[] = 'PHP version: ' . $scalar($payload['php_version'] ?? null, PHP_VERSION);
        $bodyLines[] = 'WP version: ' . $scalar($payload['wp_version'] ?? null);
        $bodyLines[] = 'DB: ' . $scalar($payload['db_type'] ?? null) . ' ' . $scalar($payload['db_version'] ?? null);
        $bodyLines[] = 'Site URL: ' . $scalar($payload['site_url'] ?? null);
        $bodyLines[] = 'Multisite: ' . (!empty($payload['is_multisite']) ? 'yes' : 'no');
        $bodyLines[] = '';
        if ($logExcerpt !== '') {
            $bodyLines[] = '=== DEBUG LOG EXCERPT ===';
            $bodyLines[] = '';
            $bodyLines[] = $logExcerpt;
            $bodyLines[] = '';
        }
        $bodyLines[] = '=== FULL PAYLOAD (JSON) ===';
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_PRETTY_PRINT) : json_encode($payload, JSON_PRETTY_PRINT);
        $bodyLines[] = is_string($json) ? $json : '';

        $body = implode("\n", $bodyLines);
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        if ($replyEmail !== '') {
            $headers[] = 'Reply-To: ' . $replyEmail;
        }
        $result = wp_mail($to, $subject, $body, $headers);
        return (bool)$result;
    }
}
