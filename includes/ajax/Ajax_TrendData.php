<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for the Trend Analytics endpoint.
 *
 * Returns daily 404/redirect activity for Chart.js charts on the Stats page.
 */
class ABJ_404_Solution_Ajax_TrendData {

    /** @return void */
    public static function echoTrendData(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-trend-data')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_trendData');

        // Rate limiting: 60 requests per minute.
        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('trend_data', 60, 60)) {
            wp_send_json_error(array('message' => __('Rate limit exceeded. Please try again later.', '404-solution')), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $daysRaw = isset($_GET['days']) ? intval($_GET['days']) : 30;
        // Clamp to 1-90.
        $days = max(1, min(90, $daysRaw));

        /** @var ABJ_404_Solution_LogsRepositoryInterface $logsRepository */
        $logsRepository = abj_service('logs_repository');
        $data = $logsRepository->getDailyActivityTrend($days);

        wp_send_json_success($data, 200);
    }

}
