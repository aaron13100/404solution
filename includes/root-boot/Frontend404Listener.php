<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend template_redirect listener.
 *
 * abj404_404listener() is the plugin's frontend entry point. On a genuine 404 it
 * lazily loads the plugin and dispatches to the WordPress connector; on non-404
 * requests it only does the minimal work required for the redirect-all and
 * update-suggest-URL features, bailing immediately when neither is active so the
 * full plugin is not loaded on every page view.
 *
 * The add_action('template_redirect', 'abj404_404listener', $priority)
 * registration stays in 404-solution.php (the priority is computed there from
 * settings); this file only defines the callback.
 */
// allow-no-test-found: boot-time frontend entry point (abj404_404listener) wired via add_action('template_redirect') in 404-solution.php; no same-named unit file. The full 404 dispatch path is exercised end-to-end in FrontendRedirect404PipelineEndToEndTest (which references abj404_404listener).

if (!function_exists('abj404_404listener')) {
/** @return void */
function abj404_404listener() {
	if (!$GLOBALS['abj404_boot_ok']) {
		return;
	}
	$runtimeFlags = isset($GLOBALS['abj404_frontend_runtime_flags']) && is_array($GLOBALS['abj404_frontend_runtime_flags'])
		? $GLOBALS['abj404_frontend_runtime_flags'] : array();
	$is404 = is_404();
	if (!$is404) {
        // Performance: do NOT load the whole plugin on every frontend request unless we must.
    	if (!empty($runtimeFlags['redirect_all_requests'])) {
    		require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
    		try {
    			$connector = ABJ_404_Solution_WordPress_Connector::getInstance();
    			$connector->processRedirectAllRequests();
    		} catch (\Throwable $e) {
    			// This runs on every front-end request when "redirect all
    			// requests" is enabled. A transient failure resolving the
    			// plugin's services (the VRMU incident: a class momentarily
    			// missing during a plugin self-update racing a live request)
    			// must degrade to "serve this request normally" rather than
    			// a fatal error page for a real visitor.
    			if (function_exists('abj404_logRuntimeWarning')) {
    				abj404_logRuntimeWarning('abj404_404listener: processRedirectAllRequests failed', $e);
    			}
    		}
    		return;
    	}

		$updateSuggestEnabled = !empty($runtimeFlags['update_suggest_url']);
		$cookieName404 = ABJ404_PP . '_STATUS_404';
		$has404StatusCookie = (isset($_COOKIE[$cookieName404]) && $_COOKIE[$cookieName404] == 'true');

		// Fast path: if none of the non-404 features are active, bail immediately.
		if (!$updateSuggestEnabled && !$has404StatusCookie) {
			return;
		}

    	/** If we're currently redirecting to a custom 404 page and we are about to show page
    	 * suggestions then update the URL displayed to the user. */
    	$cookieName = ABJ404_PP . '_REQUEST_URI_UPDATE_URL';
    	$queryParamName = ABJ404_PP . '_ref';

    	$hasUpdateCookie = !empty($_COOKIE[$cookieName]);
    	$hasUpdateParam = !empty($_GET[$queryParamName]);

    	// Fast path: nothing pending from prior plugin-driven redirects.
    	if (!$hasUpdateCookie && !$hasUpdateParam && !$has404StatusCookie) {
    		return;
    	}

    	if ($has404StatusCookie) {
   			// clear the cookie
			setcookie($cookieName404, 'false', abj404_now() - 5, "/");
    		// we're going to a custom 404 page so set the status to 404.
	    	status_header(404);
    	}

    	if (!$updateSuggestEnabled) {
    		return;
    	}

    	// Check cookie first, then query param fallback (for 301 redirects where cookies don't survive)
    	$originalURL = null;
    	if ($hasUpdateCookie) {
    		$originalURL = $_COOKIE[$cookieName];
    	} elseif ($hasUpdateParam) {
    		$refParam = $_GET[$queryParamName];
    		$originalURL = urldecode(is_string($refParam) ? $refParam : '');
    	}

    	if ($originalURL !== null) {
			// clear the cookie - sanitize before writing to $_REQUEST
            $sanitizedOriginal = sanitize_text_field(is_string($originalURL) ? $originalURL : '');
			$_REQUEST[ABJ404_PP . '_REQUEST_URI'] = $sanitizedOriginal;
            $_REQUEST[ABJ404_PP . '_REQUEST_URI_UPDATE_URL'] = $sanitizedOriginal;
			setcookie($cookieName, '', abj404_now() - 5, "/");

			require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
			add_action('wp_head', 'ABJ_404_Solution_ShortCode::updateURLbarIfNecessary');
    	}
		return;
    }

	// ignore admin screens and login requests on 404 processing path.
	// $_SERVER['SCRIPT_NAME'] is not guaranteed (CLI, some test runners, some proxies).
	// Use a direct script-name check to avoid invoking wp_login_url() filters.
	$scriptNameRaw = $_SERVER['SCRIPT_NAME'] ?? '';
	$scriptName = is_string($scriptNameRaw) ? $scriptNameRaw : '';
	$requestUriRaw = $_SERVER['REQUEST_URI'] ?? '';
	$requestUri = is_string($requestUriRaw) ? $requestUriRaw : '';
	$isLoginScreen = (
		($scriptName !== '' && stripos($scriptName, 'wp-login.php') !== false) ||
		($requestUri !== '' && stripos($requestUri, 'wp-login.php') !== false)
	);
	if (is_admin() || $isLoginScreen) {
		return;
	}

    require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
    try {
        $connector = ABJ_404_Solution_WordPress_Connector::getInstance();
        $connector->process404();
    } catch (\Throwable $e) {
        // This is the plugin's core entry point, hit on every genuine 404 a
        // real visitor triggers. A transient failure resolving the plugin's
        // services (the VRMU incident: a class momentarily missing during a
        // plugin self-update racing a live request) must degrade to "let
        // WordPress show its normal 404 page" rather than a fatal error
        // page for that visitor.
        if (function_exists('abj404_logRuntimeWarning')) {
            abj404_logRuntimeWarning('abj404_404listener: process404 failed', $e);
        }
    }
}
}
