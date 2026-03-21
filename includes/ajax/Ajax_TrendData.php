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
        $nonce = isset($_GET['nonce']) ? (string)$_GET['nonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'abj404_trendData')) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Verify user has appropriate capabilities.
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        if (!$abj404logic->userIsPluginAdmin()) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Rate limiting: 60 requests per minute.
        if (ABJ_404_Solution_Ajax_Php::checkRateLimit('trend_data', 60, 60)) {
            wp_send_json_error(array('message' => __('Rate limit exceeded. Please try again later.', '404-solution')), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $daysRaw = isset($_GET['days']) ? intval($_GET['days']) : 30;
        // Clamp to 1–90.
        $days = max(1, min(90, $daysRaw));

        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $data = $abj404dao->getDailyActivityTrend($days);

        wp_send_json_success($data, 200);
    }

}
