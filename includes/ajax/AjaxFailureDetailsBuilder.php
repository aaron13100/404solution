<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Builds rich admin-only details for a caught pagination failure. */
final class ABJ_404_Solution_AjaxFailureDetailsBuilder {

    /**
     * @param Throwable $error
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function pagination(Throwable $error, array $context): array {
        $details = array(
            'exception' => array(
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ),
            'context' => $context,
        );
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $lastQuery = $GLOBALS['wpdb']->last_query ?? '';
            $details['wpdb'] = array(
                'last_error' => $GLOBALS['wpdb']->last_error ?? '',
                'last_query_redacted' =>
                    ABJ_404_Solution_AjaxAdminEndpointSupport::redactSqlShape($lastQuery),
                'last_query_length' => is_string($lastQuery) ? strlen($lastQuery) : 0,
            );
        }
        $viewQueryDiagnostics =
            ABJ_404_Solution_AjaxAdminEndpointSupport::extractViewQueryDiagnostics($error);
        if ($viewQueryDiagnostics !== null) {
            $details['view_query_diagnostics'] = $viewQueryDiagnostics;
        }
        return $details;
    }
}
