<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX adapter for loading the deferred Google Search Console admin section.
 */
class ABJ_404_Solution_Ajax_LoadGscSection {

    /** @return void */
    public static function loadGscSection() {
        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_gsc_deferred');

        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('load_gsc_section', 30, 60)) {
            wp_send_json_error(array('message' => __('Rate limit exceeded. Please try again later.', '404-solution')), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        try {
            $gscLogger = abj_service('logging');
            $gsc = new ABJ_404_Solution_GoogleSearchConsole($gscLogger);

            $html = $gsc->renderer()->renderAdminSection();

            $refreshScheduled = false;
            if ($gsc->oauthStore()->getState() === 'connected' && $gsc->searchAnalytics()->isRefreshNeeded()) {
                $gsc->searchAnalytics()->scheduleBackgroundRefresh();
                $refreshScheduled = true;
            }

            wp_send_json_success(array('html' => $html, 'refresh_scheduled' => $refreshScheduled), 200);
            return; // @phpstan-ignore deadCode.unreachable
        } catch (Throwable $e) {
            $logger = abj_service('logging');
            if (is_object($logger) && method_exists($logger, 'errorMessage')) {
                $logger->errorMessage('Error loading deferred GSC section: ' . $e->getMessage());
            }
            $detail = (string)$e->getMessage();
            $framing = __('Unable to load Google Search Console section.', '404-solution');
            $message = $detail !== '' ? $framing . ' (' . $detail . ')' : $framing;
            wp_send_json_error(array('message' => $message), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }
    }
}
