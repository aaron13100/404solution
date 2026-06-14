<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captures runtime exceptions thrown from admin-page WordPress hooks and
 * renders a one-time admin notice instead of letting the page blank out.
 *
 * Admin hook callbacks (admin_head, asset enqueue, etc.) wrap their bodies in
 * try/catch and forward any Throwable here via {@see reportAdminRuntimeError()}.
 * The summary is buffered in static state for the current request and also
 * persisted to a short-lived transient so an error raised during one admin
 * request can still surface on the next page load. {@see echoAdminRuntimeErrorNotice()}
 * drains both sources and prints the notice.
 *
 * This is presentation/infrastructure for admin error visibility only: it makes
 * no business decisions and owns no domain data.
 */
class ABJ_404_Solution_AdminRuntimeErrorNotice {

    /** @var array<int, string> */
    private static $adminRuntimeErrors = array();

    /**
     * Persist and queue an admin runtime error so users see a notice instead of a blank page.
     *
     * @param string $hookName
     * @param Throwable $e
     * @return void
     */
    public static function reportAdminRuntimeError(string $hookName, Throwable $e): void {
        $summary = sprintf('[%s] %s', $hookName, $e->getMessage());
        self::$adminRuntimeErrors[] = $summary;

        try {
            $logger = abj_service('logging');
            $logger->errorMessage('Admin runtime exception in ' . $hookName . ': ' . $e->getMessage());
        } catch (Throwable $ignored) {
            abj404_logPhpFallback(
                'service-resolution-fallback',
                'admin runtime exception in ' . $hookName . ': ' . $e->getMessage()
            );
        }

        if (function_exists('set_transient')) {
            // allow-cache-empty: runtime-error notice summary is generated locally and intentionally persisted as-is.
            set_transient('abj404_admin_runtime_error', $summary, 300);
        }
    }

    /**
     * Echo one-time admin runtime errors captured from earlier hooks in this request (or previous request).
     *
     * @return void
     */
    public static function echoAdminRuntimeErrorNotice(): void {
        $errors = self::$adminRuntimeErrors;
        self::$adminRuntimeErrors = array();

        if (function_exists('get_transient')) {
            $saved = get_transient('abj404_admin_runtime_error');
            if (is_string($saved) && $saved !== '') {
                $errors[] = $saved;
                delete_transient('abj404_admin_runtime_error');
            }
        }

        if (empty($errors)) {
            return;
        }

        $message = implode("\n", array_unique(array_filter($errors)));
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/adminRuntimeErrorNotice.html',
            false
        );
        $replacements = array(
            '{message}' => esc_html__('An internal error occurred while loading this admin page.', '404-solution'),
            '{summary}' => esc_html__('Show details', '404-solution'),
            '{details}' => esc_html($message),
        );
        echo str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
