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
        $userIsPluginAdmin = (bool)abj_service('admin_access_policy')->isPluginAdmin();
        if (!$userIsPluginAdmin) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $nonceRaw = isset($_POST['nonce']) && is_string($_POST['nonce']) ? $_POST['nonce'] : '';
        if ($nonceRaw === '' || !wp_verify_nonce($nonceRaw, 'abj404_gsc_deferred')) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('load_gsc_section', 30, 60)) {
            wp_send_json_error(array('message' => __('Rate limit exceeded. Please try again later.', '404-solution')), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        try {
            $gscLogger = abj_service('logging');
            $gsc = new ABJ_404_Solution_GoogleSearchConsole($gscLogger);

            $html = $gsc->renderAdminSection();

            $refreshScheduled = false;
            if ($gsc->getState() === 'connected' && $gsc->isRefreshNeeded()) {
                $gsc->scheduleBackgroundRefresh();
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
