<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress AJAX action registrar for the seven admin-table endpoints. Each
 * action name maps to a dedicated handler class (Ajax_GetPaginationLinks,
 * Ajax_WarmTableCache, Ajax_RefreshStatsDashboard, Ajax_RefreshHealthBar,
 * Ajax_FetchInflightStage, Ajax_AdvanceViewBuild, Ajax_RefreshAdminNonces),
 * each of which owns the logic for its own endpoint. This class no longer
 * implements any endpoint itself.
 *
 * The thin per-endpoint instance methods (e.g. `getPaginationLinks()`)
 * exist solely so that the historical
 * `ABJ_404_Solution_ViewUpdater::getInstance()->X()` test-suite call shape
 * keeps working as a dispatch surface; each is a 1-line delegation to the
 * handler class. The static cross-cutting helpers
 * (`buildAjaxErrorResponse`, `sendJsonResponseAndExit`, `isFatalErrorType`,
 * `markInflightStage`, `adminNonceActions`, and the reflected
 * `extractViewQueryDiagnostics`) forward to
     * ABJ_404_Solution_Ajax_AdminEndpointSupport so external callers
     * (`ErrorHandler`, tests, legacy AJAX code) keep the same surface.
 */
class ABJ_404_Solution_ViewUpdater {

	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ViewUpdater();
		}

		return self::$instance;
	}

    /** @return void */
    static function init() {
        $me = ABJ_404_Solution_ViewUpdater::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks',
                array($me, 'getPaginationLinks'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxWarmTableCache',
                array($me, 'warmTableCache'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshStatsDashboard',
                array($me, 'refreshStatsDashboard'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshHealthBar',
                array($me, 'refreshHealthBar'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxFetchInflightStage',
                array($me, 'fetchInflightStage'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxAdvanceViewBuild',
                array($me, 'advanceViewBuild'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshAdminNonces',
                array($me, 'refreshAdminNonces'));
        // wp_ajax_nopriv_ is for normal users
    }

    /**
     * Admin nonce action verbs JS call sites consume. Forwards to
     * Ajax_AdminEndpointSupport; the canonical list lives there now.
     * @return string[]
     */
    public static function adminNonceActions(): array {
        return ABJ_404_Solution_Ajax_AdminEndpointSupport::adminNonceActions();
    }

    /** @return void */
    function refreshAdminNonces() {
        (new ABJ_404_Solution_Ajax_RefreshAdminNonces())->handle();
    }

    /** @return void */
    function getPaginationLinks() {
        (new ABJ_404_Solution_Ajax_GetPaginationLinks())->handle();
    }

    /** @return void */
    function warmTableCache() {
        (new ABJ_404_Solution_Ajax_WarmTableCache())->handle();
    }

    /** @return void */
    function refreshStatsDashboard() {
        (new ABJ_404_Solution_Ajax_RefreshStatsDashboard())->handle();
    }

    /** @return void */
    function refreshHealthBar() {
        (new ABJ_404_Solution_Ajax_RefreshHealthBar())->handle();
    }

    /** @return void */
    function fetchInflightStage() {
        (new ABJ_404_Solution_Ajax_FetchInflightStage())->handle();
    }

    /** @return void */
    function advanceViewBuild() {
        (new ABJ_404_Solution_Ajax_AdvanceViewBuild())->handle();
    }

    /**
     * @param string $stage
     * @return void
     */
    public static function markInflightStage($stage) {
        ABJ_404_Solution_Ajax_AdminEndpointSupport::markInflightStage($stage);
    }

    /**
     * @param int $type
     * @return bool
     */
    public static function isFatalErrorType($type) {
        return ABJ_404_Solution_Ajax_AdminEndpointSupport::isFatalErrorType($type);
    }

    /**
     * @param string $message
     * @param array<string, mixed>|null $details
     * @param bool $isPluginAdmin
     * @return array<string, mixed>
     */
    public static function buildAjaxErrorResponse($message, $details, $isPluginAdmin) {
        return ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse($message, $details, $isPluginAdmin);
    }

    /**
     * @param mixed $payload
     * @param int $httpStatus
     * @return void
     */
    public static function sendJsonResponseAndExit($payload, $httpStatus = 200) {
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, $httpStatus);
    }

}
