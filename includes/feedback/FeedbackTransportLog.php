<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared logging shim for the FeedbackTransport family of classes. Lives in
 * its own file so collaborators (FeedbackHttpClient, FeedbackPayloadSchemaGuard)
 * can emit warnings without depending on FeedbackTransport itself, which would
 * create a class-level dependency cycle (FeedbackTransport already depends on
 * all of them).
 *
 * Routes through the plugin's Logging service when the service container is
 * up, falls back to the centralized PHP error-log sink so messages aren't
 * lost in standalone tests or early-boot contexts.
 */
class ABJ_404_Solution_FeedbackTransportLog {

    /**
     * @param string $level 'info' | 'warn' | 'error'
     * @param string $message
     * @return void
     */
    public static function log(string $level, string $message): void {
        if (function_exists('abj_service_optional')) {
            try {
                $logger = abj_service_optional('logging');
                if (is_object($logger)) {
                    if ($level === 'info' && method_exists($logger, 'infoMessage')) {
                        $logger->infoMessage($message);
                        return;
                    }
                    if ($level === 'warn' && method_exists($logger, 'warn')) {
                        $logger->warn($message);
                        return;
                    }
                    if ($level === 'error' && method_exists($logger, 'errorMessage')) {
                        $logger->errorMessage($message);
                        return;
                    }
                }
            } catch (\Throwable $e) {
                abj404_logPhpFallback(
                    'transport-fallback',
                    'FeedbackTransport logger lookup failed (' . $e->getMessage() . '); falling back to PHP error log'
                );
            }
        }
        abj404_logPhpFallback('transport-fallback', $message);
    }
}
