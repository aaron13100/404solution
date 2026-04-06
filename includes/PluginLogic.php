<?php


if (!defined('ABSPATH')) {
    exit;
}

/* the glue that holds it together / everything else. */

require_once dirname(__FILE__) . '/PluginLogicTrait_UrlNormalization.php';
require_once dirname(__FILE__) . '/PluginLogicTrait_AdminActions.php';
require_once dirname(__FILE__) . '/PluginLogicTrait_ImportExport.php';
require_once dirname(__FILE__) . '/PluginLogicTrait_SettingsUpdate.php';
require_once dirname(__FILE__) . '/PluginLogicTrait_PageOrdering.php';

/**
 * @phpstan-type PageObject object{id: int, post_parent: int, depth: int, post_type: string, post_title: string}
 */
class ABJ_404_Solution_PluginLogic {

	use ABJ_404_Solution_PluginLogicTrait_UrlNormalization;
	use ABJ_404_Solution_PluginLogicTrait_AdminActions;
	use ABJ_404_Solution_PluginLogicTrait_ImportExport;
	use ABJ_404_Solution_PluginLogicTrait_SettingsUpdate;
	use ABJ_404_Solution_PluginLogicTrait_PageOrdering;

	/** @var ABJ_404_Solution_Functions */
	private $f = null;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao = null;

	/** @var ABJ_404_Solution_Logging */
	private $logger = null;

	/** @var ABJ_404_Solution_ImportExportService|null */
	private $importExportService = null;

	/** @var string|null */
	private $urlHomeDirectory = null;

	/** @var int|null */
	private $urlHomeDirectoryLength = null;

	/** @var array<string, mixed>|null */
	private $options = null;
	/** @var array<string, mixed>|null */
	private $resolvedOptionsSkipDbCheck = null;
	/** @var array<string, mixed>|null */
	private $resolvedOptionsWithDbCheck = null;

	/** @var self|null */
    private static $instance = null;

    /** @var string|null */
    private static $uniqID = null;

    /** Use this to avoid an infinite loop when checking if a user has admin access or not.
     * @var bool */
    private static $checkingIsAdmin = false;

    /** Allowed column names for orderby parameter.
     * @var array<int, string> */
    private static $allowedOrderbyColumns = [
        'url',
        'status',
        'type',
        'dest',
        'final_dest',
        'code',
        'score',
        'timestamp',
        'created',
        'lastused',
        'last_used',
        'logshits',
        'remote_host',
        'referrer',
        'action',
        'username'
    ];

    /** Allowed values for order parameter.
     * @var array<int, string> */
    private static $allowedOrderValues = ['ASC', 'DESC'];

    /** @return ABJ_404_Solution_PluginLogic The singleton instance of the class. */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('plugin_logic')) {
                    $resolved = $c->get('plugin_logic');
                    if ($resolved instanceof self) {
                        self::$instance = $resolved;
                        return self::$instance;
                    }
                }
            } catch (Throwable $e) {
                // fall back
            }
        }

    	self::$instance = new ABJ_404_Solution_PluginLogic();
    	self::$uniqID = uniqid("", true);

    	// these filters allow non-admins to have admin access to the plugin.
    	add_filter( 'user_has_cap',
    		'ABJ_404_Solution_PluginLogic::override_user_can_access_admin_page', 10, 4 );

    	return self::$instance;
    }

    /**
     * Constructor with dependency injection.
     * Dependencies are now explicit and visible.
     *
     * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
     * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     */
    function __construct($functions = null, $dataAccess = null, $logging = null) {
    	// Use injected dependencies or fall back to getInstance() for backward compatibility
    	$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
    	$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
    	$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();

        $urlPath = parse_url(get_home_url(), PHP_URL_PATH);
        // Fix MEDIUM #1 (5th review): Distinguish between parse failure (false) and no path (null)
        if ($urlPath === false) {
            $this->logger->warn("Malformed home URL detected: " . get_home_url());
            $urlPath = '';
        } else if ($urlPath === null) {
            $urlPath = '';
        }

	    	// Fix HIGH #2 (4th review): Decode subdirectory for consistency with runtime processing
	        $decodedPath = $this->f->normalizeUrlString(rtrim($urlPath, '/'));
	        if (!is_string($decodedPath)) {
	        	$decodedPath = '';
	        }
	    	// Fix HIGH #3 (4th review): Remove null bytes and control characters for security
	    	$cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $decodedPath);
    	$this->urlHomeDirectory = is_string($cleaned) ? $cleaned : $decodedPath;
    	$this->urlHomeDirectoryLength = $this->f->strlen($this->urlHomeDirectory);
    }

    /** @return ABJ_404_Solution_ImportExportService */
    private function getImportExportService() {
        if ($this->importExportService !== null) {
            return $this->importExportService;
        }

        if (!class_exists('ABJ_404_Solution_ImportExportService')) {
            require_once dirname(__FILE__) . '/ImportExportService.php';
        }

        $this->importExportService = new ABJ_404_Solution_ImportExportService($this->dao, $this->logger);
        return $this->importExportService;
    }

    /** This replaces the current_user_can('administrator') function.
     *
     * Use the following to add a filter.
     * // -------
     * add_filter( 'abj404_userIsPluginAdmin', 'my_custom_function' );
     * function my_custom_function( $value ) {
     * 	  // validate user can access the plugin here.
     * 	  return $value;
     * }
     * // -------
     *
     * @return bool true if $abj404logic->userIsPluginAdmin()
     */
    function userIsPluginAdmin() {
    	// avoid an infinite loop.
    	if (ABJ_404_Solution_PluginLogic::$checkingIsAdmin) {
    		return false;
    	}

    	ABJ_404_Solution_PluginLogic::$checkingIsAdmin = true;
    	try {
    		// Capability checks should not trigger DB upgrade checks (which can throw and lock users out).
    		$options = $this->getOptions(true);
    		$f = $this->f;
    		global $current_user;

    		// Baseline: admins have access. Prefer capability checks over role-name checks.
    		$isPluginAdmin = current_user_can('manage_options') || current_user_can('administrator');
    		if (function_exists('is_multisite') && is_multisite() && function_exists('is_super_admin') && is_super_admin()) {
    			$isPluginAdmin = true;
    		}

    		// check extra admins.
    		$extraAdmins = $options['plugin_admin_users'] ?? array();
    		$current_user_name = null;
    		if (isset($current_user)) {
    			$current_user_name = $current_user->user_login;
    		}
    		if ($current_user_name != null && $current_user_name != false) {
    			$check = false;
    			if (is_array($extraAdmins)) {
    				$extraAdmins = array_filter($extraAdmins,
    					array($f, 'removeEmptyCustom'));
    				$check = true;
    			} else if (is_string($extraAdmins)) {
    			    $extraAdmins = $this->f->explodeNewline($extraAdmins);
    				$check = true;
    			}
    			/** @var array<int|string, mixed> $extraAdmins */
    			if ($check && is_array($extraAdmins) && in_array($current_user_name, $extraAdmins)) {
    				$isPluginAdmin = true;
    			}
    		}

    		// do the filter in case someone wants to add one
    		return apply_filters('abj404_userIsPluginAdmin', $isPluginAdmin);
    	} finally {
    		ABJ_404_Solution_PluginLogic::$checkingIsAdmin = false;
    	}
    }

    /**
     * Verify a nonce for admin-link actions, without depending on the browser's Referer header.
     *
     * WordPress core's check_admin_referer() can fail in environments that strip referrers; in that
     * case we fall back to wp_verify_nonce() using the same nonce value.
     *
     * @param string $action Nonce action string used in wp_nonce_url()
     * @param string $queryArg Nonce query arg name (default '_wpnonce')
     * @return bool
     */
    private function verifyLinkNonce($action, $queryArg = '_wpnonce') {
        // Prefer check_admin_referer when available, but don't die on failure.
        if (function_exists('check_admin_referer')) {
            $ok = check_admin_referer($action, $queryArg);
            if ($ok) {
                return true;
            }
        }

        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        if (!isset($_REQUEST[$queryArg])) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_REQUEST[$queryArg]));
        if ($nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Get the current user's settings mode preference.
     * @return string 'simple' or 'advanced'
     */
    function getSettingsMode() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return 'simple';
        }
        $mode = get_user_meta($user_id, 'abj404_settings_mode', true);
        return ($mode === 'advanced') ? 'advanced' : 'simple';
    }

    /**
     * Set the current user's settings mode preference.
     * @param string $mode 'simple' or 'advanced'
     * @return bool|int Meta ID on success, false on failure
     */
    function setSettingsMode($mode) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $valid_mode = ($mode === 'advanced') ? 'advanced' : 'simple';
        return update_user_meta($user_id, 'abj404_settings_mode', $valid_mode);
    }

    /** Allow the user to be an admin for the plugin.
     * @param array<string, bool> $allcaps
     * @param array<int, string> $caps
     * @param array<int, mixed> $args
     * @param \WP_User $user
     * @return array<string, bool> an array of the capabilities
     */
    static function override_user_can_access_admin_page( $allcaps, $caps, $args, $user ) {
    	// if it's not an admin page then we don't change anything.
    	if (!is_admin()) {
    		return $allcaps;
    	}

    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

    	$isPluginAdmin = false;
    	$isViewing404AdminPage = false;

    	// is the user supposed to have access?
    	if ($abj404logic->userIsPluginAdmin()) {
    		$isPluginAdmin = true;
    	}

    	if ($isPluginAdmin) {
    		$userRequest = ABJ_404_Solution_UserRequest::getInstance();
    		$queryParts = $userRequest !== null ? $userRequest->getQueryString() : null;

    		// are we viewing a 404 plugin page?
    		if (is_string($queryParts) && strpos($queryParts, ABJ404_PP) !== false) {
    			$isViewing404AdminPage = true;
    		}
    	}

    	if ($isPluginAdmin && $isViewing404AdminPage) {
    		$allcaps['manage_options'] = true;
    	}

    	return $allcaps;
    }

    /** Forward to a real page for queries like ?p=10
     * @global type $wp_query
     * @param array<string, mixed> $options
     * @return void
     */
    function tryNormalPostQuery(array $options): void {
        global $wp_query;

        // this is for requests like website.com/?p=123
        $query = $wp_query->query;
        // if it's not set then don't use it.
        if (!isset($query['p'])) {
            return;
        }
        $pageid = $query['p'];
        if (!empty($pageid)) {
            $rawPermalink = get_permalink($pageid);
            $permalink = $this->f->normalizeUrlString($rawPermalink !== false ? $rawPermalink : null);
            $status = get_post_status($pageid);
            if (($permalink != false) &&
            	(in_array($status, array('publish', 'published')))) {
            	$homeURL = get_home_url();
            	if ($homeURL == null) {
            		$homeURL = '';
            	}
            	$urlHomeDirectory = parse_url($homeURL, PHP_URL_PATH);
            	if ($urlHomeDirectory == null) {
            		$urlHomeDirectory = '';
            	}
            	$urlHomeDirectory = rtrim($urlHomeDirectory, '/');
                $fromURL = $urlHomeDirectory . '/?p=' . $pageid;
                $redirect = $this->dao->getExistingRedirectForURL($fromURL);
                $defaultRedirect = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
                if (!isset($redirect['id']) || $redirect['id'] == 0) {
                    $this->dao->setupRedirect($fromURL, (string)ABJ404_STATUS_AUTO, (string)ABJ404_TYPE_POST,
                            (string)$pageid, $defaultRedirect, 0, 'page ID');
                }
                $this->dao->logRedirectHit($fromURL, $permalink, 'page ID');
                $this->forceRedirect($permalink, (int)$defaultRedirect);
                exit;
            }
        }
    }

    /**
     * @global type $abj404logging
     * @global type $abj404logic
     * @param string $urlRequest the requested URL. e.g. /404killer/aboutt
     * @param string $urlSlugOnly only the slug. e.g. /aboutt
     * @return void
     */
    function initializeIgnoreValues(string $urlRequest, string $urlSlugOnly): void {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        $options = $abj404logic->getOptions();
        $ignoreReasonDoNotProcess = null;
        $ignoreReasonDoProcess = null;
        $httpUserAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ?
                $this->f->strtolower($_SERVER['HTTP_USER_AGENT']) : '';

        // Note: is_admin() does not mean the user is an admin - it returns true when the user is on an admin screen.
        // ignore requests that are supposed to be for an admin.
        $adminURLRaw = parse_url(admin_url(), PHP_URL_PATH);
        $adminURL = is_string($adminURLRaw) ? $adminURLRaw : '/wp-admin/';
        if (is_admin() || $this->f->substr($urlRequest, 0, $this->f->strlen($adminURL)) == $adminURL) {
            $this->logger->debugMessage("Ignoring admin URL: " . $urlRequest);
            $ignoreReasonDoNotProcess = 'Admin URL';
        }

        // The user agent Zemanta Aggregator http://www.zemanta.com causes a lot of false positives on
        // posts that are still drafts and not actually published yet. It's from the plugin "WordPress Related Posts"
        // by https://www.sovrn.com/.
        $ignoreDontProcess = is_string($options['ignore_dontprocess']) ? $options['ignore_dontprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDontProcess);

        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $this->logger->debugMessage("Ignoring user agent (do not redirect): " .
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoNotProcess = 'User agent (do not redirect): ' . esc_html($_SERVER['HTTP_USER_AGENT']);
            }
        }

        // ----- ignore based on regex file path
        $patternsToIgnore = is_array($options['folders_files_ignore_usable']) ? $options['folders_files_ignore_usable'] : array();
        if (!empty($patternsToIgnore)) {
            foreach ($patternsToIgnore as $patternToIgnore) {
                $patternToIgnoreStr = is_string($patternToIgnore) ? $patternToIgnore : (string)$patternToIgnore;
                $patternToIgnoreNoSlashes = stripslashes($patternToIgnoreStr);
                ABJ_404_Solution_RequestContext::getInstance()->debug_info = 'Applying regex pattern to ignore\"' .
                    $patternToIgnoreNoSlashes . '" to URL slug: ' . $urlSlugOnly;
                $matches = array();
                if ($this->f->regexMatch($patternToIgnoreNoSlashes, $urlSlugOnly, $matches)) {
                    $this->logger->debugMessage("Ignoring file/folder (do not redirect) for URL: " .
                            esc_html($urlSlugOnly) . ", pattern used: " . $patternToIgnoreNoSlashes);
                    $ignoreReasonDoNotProcess = 'Files and folders (do not redirect) pattern: ' .
                        esc_html($patternToIgnoreNoSlashes);
                }
                ABJ_404_Solution_RequestContext::getInstance()->debug_info = 'Cleared after regex pattern to ignore.';
            }
        }
        ABJ_404_Solution_RequestContext::getInstance()->ignore_donotprocess = is_string($ignoreReasonDoNotProcess) ? $ignoreReasonDoNotProcess : false;

        // -----
        // ignore and process
        $ignoreDoProcess = is_string($options['ignore_doprocess']) ? $options['ignore_doprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDoProcess);

        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $this->logger->debugMessage("Ignoring user agent (process ok): " .
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoProcess = 'User agent (process ok): ' . $agentToIgnore;
            }
        }
        ABJ_404_Solution_RequestContext::getInstance()->ignore_doprocess = is_string($ignoreReasonDoProcess) ? $ignoreReasonDoProcess : false;
    }

    /** @return string */
    function readCookieWithPreviousRqeuestShort(): string {
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieNameShort = $cookieName . '_SHORT';

        if (array_key_exists($cookieNameShort, $_COOKIE) &&
            array_key_exists($cookieName, $_COOKIE)) {
    		return $_COOKIE[$cookieName];
    	}

    	return '';
    }

    /** Set a cookie with the requested URL (path only, no query string).
     * Security: Query strings may contain sensitive data (tokens, auth codes, etc.)
     * so we only store the path portion of the URL.
     * @return void
     */
    function setCookieWithPreviousRequest(): void {

        $requested_url_raw = $this->f->normalizeUrlString($_SERVER['REQUEST_URI']);

        // Security: Strip query string to avoid storing sensitive params (tokens, auth codes, etc.)
        $requested_url_cleaned = preg_replace('/\?.*$/', '', $requested_url_raw);
        $requested_url = is_string($requested_url_cleaned) ? $requested_url_cleaned : $requested_url_raw;

    	// this may be used later when displaying suggestions.
    	$cookieName = ABJ404_PP . '_REQUEST_URI';
    	$cookieNameShort = $cookieName . '_SHORT';
    	try {
    		setcookie($cookieName, $requested_url, time() + (60 * 4), "/");
    		setcookie($cookieNameShort, $requested_url, time() + (5), "/");

    		// only set the update_URL if it's not already set.
    		// this is because multiple redirects might happen and we want to store
    		// only the user's original requested page.
    		if (!isset($_COOKIE[$cookieName . '_UPDATE_URL']) ||
    				empty($_COOKIE[$cookieName . '_UPDATE_URL'])) {
    			// Also strip query string from UPDATE_URL for consistency
    			$update_url_raw = $this->f->normalizeUrlString($_SERVER['REQUEST_URI']);
    			$update_url_cleaned = preg_replace('/\?.*$/', '', $update_url_raw);
    			$update_url = is_string($update_url_cleaned) ? $update_url_cleaned : $update_url_raw;
    			setcookie($cookieName . '_UPDATE_URL', $update_url,
    				time() + (60 * 4), "/");
    		}

    	} catch (Exception $e) {
    		$this->logger->debugMessage("There was an issue setting a cookie: " . $e->getMessage());
    		// This javascript redirect will only appear if the header redirect did not work for some reason.
    		// document.cookie = "username=John Doe; expires=Thu, 18 Dec 2013 12:00:00 UTC";
    		$expireTime = date("D, d M Y H:i:s T", time() + (60 * 4));
    		$c = "\n" . '<script>document.cookie = "' . $cookieName . '=' .
     		esc_js($requested_url) .
     		'; expires=' . $expireTime . '";</script>' . "\n";
     		echo $c;
    	}

    	ABJ_404_Solution_RequestContext::getInstance()->requested_url = $requested_url;
    }

    /** The passed in reason will be appended to the automatically generated reason.
     * @param string $requestedURL
     * @param string $reason
     * @param bool $useUserSpecified404
     * @param array<string, mixed>|null $optionsOverride
     * @return void
     */
    function sendTo404Page(string $requestedURL, string $reason = '', bool $useUserSpecified404 = true, $optionsOverride = null): void {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        $options = (is_array($optionsOverride) ? $optionsOverride : $abj404logic->getOptions());

        // ---------------------------------------
        // Fallback detection: if behavior is 'suggest' but system page was deleted,
        // flip to theme_default before attempting to redirect.
        $behavior = isset($options['dest404_behavior']) ? $options['dest404_behavior'] : '';
        if ($behavior === 'suggest') {
            $systemPage = ABJ_404_Solution_SystemPage::getInstance();
            if (!$systemPage->systemPageExists()) {
                $systemPage->handleSystemPageDeleted();
                // Reload options after flip
                $options = $this->getOptions(true);
            }
        }

        // if there's a default 404 page specified then use that.
        $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
        $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);

        if ($useUserSpecified404 && $this->thereIsAUserSpecified404Page($dest404page)) {
           	// $idAndType OK on regular 404
           	$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0,
           		null, $options);

            // make sure the page exists
            if (!in_array($permalink['status'], array('publish', 'published'))) {
            	$msg = __("The user specified 404 page wasn't found. " .
            			"Please update the user-specified 404 page on the Options page.",
            			'404-solution');
            	$this->logger->infoMessage($msg);

            } else {
            	// dipslay the user specified 404 page.

	            // get the existing redirect before adding a new one.
	            $redirect = $this->dao->getExistingRedirectForURL($requestedURL);
	            $pType = is_scalar($permalink['type']) ? (string)$permalink['type'] : '';
	            $pId = is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
	            $pLink = is_scalar($permalink['link']) ? (string)$permalink['link'] : '';
	            $defRedir = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
	            if (!isset($redirect['id']) || $redirect['id'] == 0) {
	                $this->dao->setupRedirect($requestedURL, (string)ABJ404_STATUS_CAPTURED, $pType, $pId, $defRedir, 0);
	            }

	            $this->dao->logRedirectHit($requestedURL, $pLink, 'user specified 404 page. ' . $reason);

	            // set cookie here to remmeber to use a 404 status when displaying the 404 page
	            setcookie(ABJ404_PP . '_STATUS_404', 'true', time() + 20, "/");

	            // the 404 page...
	            $abj404logic->forceRedirect(esc_url($pLink),
	            	(int)$defRedir);
	            exit;
            }
        }

        // ---------------------------------------
        // give up. log the 404.
        if (@$options['capture_404'] == '1') {
            // get the existing redirect before adding a new one.
            $redirect = $this->dao->getExistingRedirectForURL($requestedURL);
            $defRedir2 = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
            if (!isset($redirect['id']) || $redirect['id'] == 0) {
                $this->dao->setupRedirect($requestedURL, (string)ABJ404_STATUS_CAPTURED, (string)ABJ404_TYPE_404_DISPLAYED, (string)ABJ404_TYPE_404_DISPLAYED, $defRedir2, 0);
            }
        } else {
            $optionsJson = json_encode($options);
            $this->logger->debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL .
                    " | Redirect: (none)" . " | is_single(): " . is_single() . " | " .
                    "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " .
                    is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses_post(is_string($optionsJson) ? $optionsJson : ''));
        }
    }

    /** Returns true if there is a custom 404 page.
     * @param string|null $dest404page
     * @return bool
     */
    function thereIsAUserSpecified404Page($dest404page): bool {
    	if ($dest404page == null) {
    		return false;
    	}
    	$check1 = ($dest404page !== (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED));
    	$check2 = ($dest404page !== (string)ABJ404_TYPE_404_DISPLAYED);
    	return $check1 && $check2;
    }

    /**
     * @param bool $skip_db_check
     * @return array<string, mixed>
     */
    function getOptions(bool $skip_db_check = false) {
        if (!$skip_db_check && is_array($this->resolvedOptionsWithDbCheck)) {
            return $this->resolvedOptionsWithDbCheck;
        }
        if ($skip_db_check) {
            if (is_array($this->resolvedOptionsSkipDbCheck)) {
                return $this->resolvedOptionsSkipDbCheck;
            }
            // A full checked set is safe to reuse for skip-db-check callers.
            if (is_array($this->resolvedOptionsWithDbCheck)) {
                return $this->resolvedOptionsWithDbCheck;
            }
        }

    	if ($this->options == null) {
        	$optionResult = get_option('abj404_settings');
        	$this->options = is_array($optionResult) ? $optionResult : null;
    	}
    	$options = $this->options;

        if (!is_array($options)) {
            add_option('abj404_settings', '', '', false);
            $options = array();
        }

        // Check to make sure we aren't missing any new options.
        $defaults = $this->getDefaultOptions();
        $missing = false;
        foreach ($defaults as $key => $value) {
            if (!isset($options[$key]) || $options[$key] === '') {
                $options[$key] = $value;
                $missing = true;
            }
        }

        if ($missing) {
            $this->updateOptions($options);
        }

        if ($skip_db_check == false) {
            if (!array_key_exists('DB_VERSION', $options) || $options['DB_VERSION'] != ABJ404_VERSION) {
                $options = $this->updateToNewVersion($options);
            }
        }

        // Normalize suggestion templates so malformed placeholder values never leak to frontend.
        if ($this->normalizeSuggestionTemplateOptions($options)) {
            $this->updateOptions($options);
        }

        if ($skip_db_check) {
            $this->resolvedOptionsSkipDbCheck = $options;
        } else {
            $this->resolvedOptionsWithDbCheck = $options;
        }

        return $options;
    }

    /** @param array<string, mixed> $options @return void */
    function updateOptions(array $options): void {
    	$old_options = $this->options;
    	update_option('abj404_settings', $options);
    	$this->options = $options;
        // The persistent options changed, so invalidate per-request resolved caches.
        $this->resolvedOptionsSkipDbCheck = null;
        $this->resolvedOptionsWithDbCheck = null;
    }

    /** Do any maintenance when upgrading to a new version.
     * @global type $abj404logging
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function updateToNewVersion(array $options) {
        $syncUtils = ABJ_404_Solution_SynchronizationUtils::getInstance();

        $synchronizedKeyFromUser = "update_db_version";
        $uniqueID = $syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

        if ($uniqueID == '' || $uniqueID == null) {
        	$this->logger->debugMessage("Avoiding infinite loop on database update.");
            return $options;
        }

        $returnValue = $options;

        // Fixed: Use finally block to ensure lock is ALWAYS released, even on fatal errors
        try {
            $returnValue = $this->updateToNewVersionAction($options);

        } catch (Throwable $e) {  // Fixed: Catch Throwable (Exception + Error) instead of just Exception
            $this->logger->errorMessage("Error updating to new version. ", $e instanceof \Exception ? $e : null);
            throw $e;  // Re-throw to propagate the error
        } finally {
            // This ALWAYS executes, even on fatal errors or exceptions
            $syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
        }

        // update the permalink cache because updating the plugin version may affect it.
        $permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
        $permalinkCache->updatePermalinkCache(1);

        return $returnValue;
    }

    /** Do any maintenance when upgrading to a new version.
     * @global type $abj404logic
     * @global type $abj404logging
     * @global type $wpdb
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function updateToNewVersionAction(array $options) {
    	global $wpdb;

        if (!is_array($options)) {
            $options = array();
        }
        // Ensure all expected keys exist even when called with partial settings (tests/migrations).
        $options = array_merge($this->getDefaultOptions(), $options);

        $currentDBVersion = "(unknown)";
        if (array_key_exists('DB_VERSION', $options) && is_string($options['DB_VERSION'])) {
            $currentDBVersion = $options['DB_VERSION'];
        }
        $this->logger->infoMessage(self::$uniqID . ": Updating database version from " .
        	$currentDBVersion . " to " . ABJ404_VERSION . " (begin).");

        // remove old log files. added in 2.28.0
        $fileUtils = ABJ_404_Solution_Functions::getInstance();
        $fileUtils->deleteDirectoryRecursively(ABJ404_PATH . 'temp/');

        // wp_abj404_logsv2 exists since 1.7.
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables(true);

        // abj404_duplicateCronAction is no longer needed as of 1.7.
        wp_clear_scheduled_hook('abj404_duplicateCronAction');

        ABJ_404_Solution_PluginLogic::doUnregisterCrons();
        // added in 1.8.2
        ABJ_404_Solution_PluginLogic::doRegisterCrons();

        // since 1.9.0. ignore_doprocess add SeznamBot, Pinterestbot, UptimeRobot and "Slurp" -> "Yahoo! Slurp"
        if (version_compare($currentDBVersion, '1.9.0') < 0) {
            $ignoreDoProcessStr = is_string($options['ignore_doprocess']) ? $options['ignore_doprocess'] : '';
            $userAgents = $this->f->explodeNewline($ignoreDoProcessStr);

            $uasForSearch = $this->f->explodeNewline($ignoreDoProcessStr);

            foreach ($userAgents as &$str) {
                if ($this->f->strtolower(trim($str)) == "slurp") {
                    $str = "Yahoo! Slurp";
                    $this->logger->infoMessage('Changed user agent "Slurp" to "Yahoo! Slurp" in the do not log list.');
                }
            }

            if (!in_array("seznambot", $uasForSearch)) {
                $userAgents[] = 'SeznamBot';
                $this->logger->infoMessage('Added user agent "SeznamBot" to do not log list."');
            }
            if (!in_array("pinterestbot", $uasForSearch)) {
                $userAgents[] = 'Pinterestbot';
                $this->logger->infoMessage('Added user agent "Pinterestbot" to do not log list."');
            }
            if (!in_array("uptimerobot", $uasForSearch)) {
                $userAgents[] = 'UptimeRobot';
                $this->logger->infoMessage('Added user agent "UptimeRobot" to do not log list."');
            }

            $options['ignore_doprocess'] = implode("\n",$userAgents);
            $this->updateOptions($options);
        }

        // move to the new log table
        if (version_compare($currentDBVersion, '1.8.0') < 0) {
            $query = "SHOW TABLES LIKE '{wp_abj404_logs}'";
            $result = $this->dao->queryAndGetResults($query);
            $rows = $result['rows'];

            // make sure empty() only sees a variable and not a function for older PHP versions, due to
            // https://stackoverflow.com/a/2173318 and
            // https://wordpress.org/support/topic/fatal-error-will-latest-release/
            $filteredRows = is_array($rows) ? array_filter($rows) : array();
            if (!empty($filteredRows)) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/migrateToNewLogsTable.sql");
                $query = $this->dao->doTableNameReplacements($query);
                $result = $this->dao->queryAndGetResults($query);

                // if anything was successfully imported then delete the old table.
                if ($result['rows_affected'] > 0) {
                    $this->logger->infoMessage($result['rows_affected'] .
                            ' log rows were migrated to the new table structre.');
                    // log the rows inserted/migrated.
                    $wpdb->query('drop table ' . $this->dao->getLowercasePrefix() . 'abj404_logs');
                }
            }
        }

        if (version_compare($currentDBVersion, '2.18.0') < 0) {
            // add .well-known/acme-challenge/*, wp-content/themes/*, wp-content/plugins/* to folders_files_ignore
            $foldersIgnoreStr = is_string($options['folders_files_ignore']) ? $options['folders_files_ignore'] : '';
            $originalItems = $this->f->explodeNewline($foldersIgnoreStr);

            $newItems = array("wp-content/plugins/*", "wp-content/themes/*", ".well-known/acme-challenge/*");
            foreach ($newItems as $newItem) {
                if (array_search($newItem, $originalItems) === false) {
                    $originalItems[] = $newItem;
                    $this->logger->infoMessage('Added ' . $newItem . ' to the list of folders to ignore."');
                }
            }

            $options['folders_files_ignore'] = implode("\n",$originalItems);
            $this->updateOptions($options);
        }

        // add the second part of the default destination page.
        $dest404page = is_string($options['dest404page']) ? $options['dest404page'] : '';
        if ($this->f->strpos($dest404page, '|') === false) {
            // not found
            if ($dest404page == '0') {
                $dest404page .= "|" . ABJ404_TYPE_404_DISPLAYED;
            } else {
                $dest404page .= '|' . ABJ404_TYPE_POST;
            }
            $options['dest404page'] = $dest404page;
            $this->updateOptions($options);
        }

        // Since 3.0.7: Mark existing users as having completed setup wizard
        // This prevents the wizard from showing to users upgrading from earlier versions
        // Important: Skip this for NEW installs (where DB_VERSION is 0.0.0) so they see the wizard
        if ($currentDBVersion !== '0.0.0' && version_compare($currentDBVersion, '3.0.7') < 0) {
            update_option('abj404_setup_completed', gmdate('Y-m-d'));
            $this->logger->infoMessage('Marked setup wizard as completed for existing user.');
        }

        // Since 3.0.9: Migrate suggest_minscore to suggest_minscore_enabled checkbox
        // If user had suggest_minscore set from an older version, enable the checkbox to preserve their behavior
        if (!isset($options['suggest_minscore_enabled'])) {
            if (isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) && intval($options['suggest_minscore']) >= 25) {
                $options['suggest_minscore_enabled'] = '1';
                $this->logger->infoMessage('Enabled minimum score filtering based on existing suggest_minscore setting.');
            } else {
                $options['suggest_minscore_enabled'] = '0';
            }
            $this->updateOptions($options);
        }

        // Since 4.1.0: Migrate dest404page to dest404_behavior tile setting.
        // Existing installs may have a custom page set. Map it to the new behavior.
        if (!isset($options['dest404_behavior']) || $options['dest404_behavior'] === 'theme_default') {
            $dest = is_string($options['dest404page']) ? $options['dest404page'] : '';
            if ($dest === '0|' . ABJ404_TYPE_404_DISPLAYED || $dest === (string)ABJ404_TYPE_404_DISPLAYED || $dest === '') {
                $options['dest404_behavior'] = 'theme_default';
            } else if ($dest === '0|' . ABJ404_TYPE_HOME) {
                $options['dest404_behavior'] = 'homepage';
            } else if ($dest !== '') {
                // Check if it's a system page (from a previous install of this feature)
                $parts = explode('|', $dest);
                $pageId = isset($parts[0]) ? (int)$parts[0] : 0;
                if ($pageId > 0 && ABJ_404_Solution_SystemPage::isSystemPage($pageId)) {
                    $options['dest404_behavior'] = 'suggest';
                } else {
                    $options['dest404_behavior'] = 'custom';
                }
            }
            $this->updateOptions($options);
        }

        $options = $this->doUpdateDBVersionOption($options);
        $this->logger->infoMessage(self::$uniqID . ": Updating database version to " .
        	ABJ404_VERSION . " (end).");

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    function getDefaultOptions() {
        $options = array(
            'default_redirect' => '301',
            'send_error_logs' => '0',
            'capture_404' => '1',
            'capture_deletion' => 1095,
            'manual_deletion' => '0',
            'log_deletion' => '365',
            'admin_notification' => '0',
            'remove_matches' => '1',
            'suggest_max' => '5',
            'suggest_title' => '<h3>{suggest_title_text}</h3>',
            'suggest_before' => '<ol>',
            'suggest_after' => '</ol>',
            'suggest_entrybefore' => '<li>',
            'suggest_entryafter' => '</li>',
            'suggest_noresults' => '<p>{suggest_noresults_text}</p>',
            'suggest_cats' => '1',
            'suggest_tags' => '1',
            'suggest_minscore' => '25',
            'suggest_minscore_enabled' => '0',
            'update_suggest_url' => '0',
            'auto_redirects' => '1',
            'auto_slugs' => '1',
            'auto_trash_redirect' => '0',
            'auto_score' => '90',
            'auto_score_title' => '',
            'auto_score_category_tag' => '',
            'auto_score_content' => '',
            'template_redirect_priority' => '9',
            'auto_deletion' => '1095',
            'auto_302_expiration_days' => '0',
            'auto_cats' => '1',
            'auto_tags' => '1',
            'dest404page' => '0|' . ABJ404_TYPE_404_DISPLAYED,
            'maximum_log_disk_usage' => '10',
            'ignore_dontprocess' => 'zemanta aggregator',
            'ignore_doprocess' => "Googlebot\nMediapartners-Google\nAdsBot-Google\ndevelopers.google.com\n"
            . "Bingbot\nYahoo! Slurp\nDuckDuckBot\nBaiduspider\nYandexBot\nwww.sogou.com\nSogou-Test-Spider\n"
            . "Exabot\nfacebot\nfacebookexternalhit\nia_archiver\nSeznamBot\nPinterestbot\nUptimeRobot\nMJ12bot",
            'recognized_post_types' => "page\npost\nproduct",
            'recognized_categories' => "",
            'folders_files_ignore' => implode("\n", array("wp-content/plugins/*", "wp-content/themes/*",
                ".well-known/acme-challenge/*")),
            'folders_files_ignore_usable' => "",
            'suggest_regex_exclusions' => "",
            'suggest_regex_exclusions_usable' => "",
        	'plugin_admin_users' => "",
        	'debug_mode' => 0,
            'days_wait_before_major_update' => 30,
            'DB_VERSION' => '0.0.0',
            'menuLocation' => 'underSettings',
            'admin_theme' => 'default',
            'plugin_language_override' => '',
            'disable_auto_dark_mode' => '0',
            'admin_notification_email' => '',
            'admin_notification_frequency' => 'instant',
            'admin_notification_digest_limit' => '10',
            'admin_notification_last_sent' => '0',
            'page_redirects_order_by' => 'url',
            'page_redirects_order' => 'ASC',
            'captured_order_by' => 'logshits',
            'captured_order' => 'DESC',
        	'excludePages[]' => '',
            'dest404_behavior' => 'theme_default',
        );

        return $options;
    }

    /**
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    function doUpdateDBVersionOption($options = null): array {
        if ($options == null) {
        	$options = $this->getOptions(true);
        }

        $options['DB_VERSION'] = ABJ404_VERSION;

        $this->updateOptions($options);

        return $options;
    }

    /** Remove cron jobs. @return void */
    static function doUnregisterCrons(): void {
        $crons = array('abj404_cleanupCronAction', 'abj404_duplicateCronAction', 'removeDuplicatesCron', 'deleteOldRedirectsCron');
        for ($i = 0; $i < count($crons); $i++) {
            $cron_name = $crons[$i];
            $timestamp1 = wp_next_scheduled($cron_name);
            while ($timestamp1 != False) {
                wp_unschedule_event($timestamp1, $cron_name);
                $timestamp1 = wp_next_scheduled($cron_name);
            }

            $timestamp2 = wp_next_scheduled($cron_name, array(''));
            while ($timestamp2 != False) {
                wp_unschedule_event($timestamp2, $cron_name, array(''));
                $timestamp2 = wp_next_scheduled($cron_name, array(''));
            }

            wp_clear_scheduled_hook($cron_name);
        }
    }

    /** Create database tables. Register crons. etc.
     * Handles both single-site and multisite activations.
     *
     * For network activations, sites are activated asynchronously in the background
     * to prevent timeouts on large networks.
     *
     * @param bool $network_wide Whether this is a network-wide activation
     * @global type $abj404logic
     * @global type $abj404dao
     * @return void
     */
    static function runOnPluginActivation(bool $network_wide = false): void {
        if (is_multisite() && $network_wide) {
            // Network activation: Schedule background activation to prevent timeouts
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            // Store list of pending site IDs in network option
            update_site_option('abj404_pending_network_activation', $sites);
            update_site_option('abj404_network_activation_total', count($sites));

            // Schedule first batch immediately
            wp_schedule_single_event(time(), 'abj404_network_activation_hook');

            // Show admin notice that activation is happening in background
            add_action('network_admin_notices', function() {
                $pendingRaw = get_site_option('abj404_pending_network_activation', array());
                $pending = is_array($pendingRaw) ? $pendingRaw : array();
                $totalRaw = get_site_option('abj404_network_activation_total', 0);
                $total = is_scalar($totalRaw) ? (int)$totalRaw : 0;
                $completed = $total - count($pending);

                if (!empty($pending)) {
                    echo '<div class="notice notice-info"><p><strong>404 Solution:</strong> Network activation in progress... ' .
                         esc_html((string)$completed) . ' of ' . esc_html((string)$total) . ' sites activated. ' .
                         'This will complete in the background.</p></div>';
                }
            });
        } else {
            // Single site activation (or individual subsite activation)
            self::activateSingleSite();
        }
    }

    /**
     * Activate plugin for a single site.
     * This contains the actual activation logic that was previously in runOnPluginActivation.
     *
     * @global type $abj404logic
     * @global type $abj404dao
     * @global type $abj404logging
     * @return void
     */
    private static function activateSingleSite(): void {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        add_option('abj404_settings', '', '', false);

        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables();

        ABJ_404_Solution_PluginLogic::doRegisterCrons();

        $abj404logic->doUpdateDBVersionOption();
    }

    /**
     * Background cron handler for network activation.
     * Processes one site at a time to prevent timeouts.
     * Reschedules itself if more sites remain.
     * @return void
     */
    static function networkActivationCronHandler(): void {
        // Get list of pending sites
        $pendingRaw = get_site_option('abj404_pending_network_activation', array());
        $pending = is_array($pendingRaw) ? $pendingRaw : array();

        if (empty($pending)) {
            // All done! Clean up network options
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
            return;
        }

        // Process one site
        $blog_id = array_shift($pending);
        $blog_id_int = is_scalar($blog_id) ? (int)$blog_id : 0;

        try {
            switch_to_blog($blog_id_int);
            self::activateSingleSite();
            restore_current_blog();
        } catch (Exception $e) {
            // Log error but continue with other sites
            error_log('404 Solution: Network activation failed for site ' . $blog_id_int . ': ' . $e->getMessage());
            restore_current_blog();
        }

        // Update pending list
        update_site_option('abj404_pending_network_activation', $pending);

        // Schedule next site (10 seconds delay to spread load)
        if (!empty($pending)) {
            wp_schedule_single_event(time() + 10, 'abj404_network_activation_hook');
        } else {
            // All done! Clean up network options
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress < 5.1).
     * This is triggered by the wpmu_new_blog action.
     *
     * @param int $blog_id Blog ID of the new blog
     * @param int $user_id User ID of the user creating the blog
     * @param string $domain Domain of the new blog
     * @param string $path Path of the new blog
     * @param int $site_id Site ID (network ID)
     * @param array<string, mixed> $meta Additional meta information
     * @return void
     */
    static function activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta): void {
        // Only activate if the plugin is network-activated
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            switch_to_blog($blog_id);
            self::activateSingleSite();
            restore_current_blog();
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress >= 5.1).
     * This is triggered by the wp_initialize_site action.
     *
     * @param WP_Site $site The site object for the new site
     * @param array<string, mixed> $args Additional arguments passed to the hook
     * @return void
     */
    static function activateNewSiteModern($site, $args): void {
        // Only activate if the plugin is network-activated
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            switch_to_blog((int)$site->blog_id);
            self::activateSingleSite();
            restore_current_blog();
        }
    }

    /**
     * Handle plugin deactivation for both single-site and multisite.
     *
     * @param bool $network_wide Whether this is a network-wide deactivation
     * @return void
     */
    static function runOnPluginDeactivation(bool $network_wide = false): void {
        if (is_multisite() && $network_wide) {
            // Network deactivation: deactivate for all sites
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            foreach ($sites as $blog_id) {
                switch_to_blog($blog_id);
                self::deactivateSingleSite();
                restore_current_blog();
            }
        } else {
            // Single site deactivation
            self::deactivateSingleSite();
        }
    }

    /**
     * Deactivate plugin for a single site.
     * Unregisters cron jobs.
     * @return void
     */
    private static function deactivateSingleSite(): void {
        self::doUnregisterCrons();
    }

    /**
     * Clean up when a blog is deleted in multisite.
     * This is triggered by the delete_blog action.
     *
     * @global wpdb $wpdb WordPress database object
     * @param int $blog_id Blog ID being deleted
     * @param bool $drop Whether to drop the tables (true) or just deactivate (false)
     * @return void
     */
    static function deleteBlogData($blog_id, $drop = false): void {
        if ($drop) {
            switch_to_blog($blog_id);

            global $wpdb;
            $dao = ABJ_404_Solution_DataAccess::getInstance();
            $prefix = $dao->getLowercasePrefix();

            // Remove ALL custom database tables via dynamic discovery.
            // SHOW TABLES is the source of truth — new tables are automatically included.
            $tables = $wpdb->get_results(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix . 'abj404_') . '%'),
                ARRAY_N
            );
            foreach ($tables as $tableRow) {
                $tblName = is_array($tableRow) && isset($tableRow[0]) ? $tableRow[0] : '';
                if (preg_match('/^[a-zA-Z0-9_]+$/', $tblName) && strpos($tblName, 'abj404') !== false) {
                    $wpdb->query("DROP TABLE IF EXISTS `{$tblName}`");
                }
            }

            // Remove ALL plugin options
            $plugin_options = array(
                'abj404_settings',
                'abj404_db_version',
                'abj404_migrated_to_relative_paths',
                'abj404_migration_results',
                'abj404_ngram_cache_initialized',
                'abj404_ngram_rebuild_offset',
                'abj404_ngram_usage_stats',
                'abj404_installed_time',
                'abj404_user_feedback',
                'abj404_uninstall_preferences'
            );

            foreach ($plugin_options as $option) {
                delete_option($option);
            }

            // Delete dynamic sync options (using LIKE pattern)
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('abj404_sync_') . '%'
                )
            );

            // Clear ALL scheduled cron jobs for this blog
            $cron_hooks = array(
                'abj404_cleanupCronAction',
                'abj404_updateLogsHitsTableAction',
                'abj404_updatePermalinkCacheAction',
                'abj404_rebuild_ngram_cache_hook'
            );

            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            // Also clear legacy cron hooks
            $legacy_hooks = array(
                'abj404_duplicateCronAction',
                'abj404_updatePermalinkCache',
                'abj404_cleanupCron'
            );

            foreach ($legacy_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            restore_current_blog();
        }
    }

    /** @return void */
    static function doRegisterCrons(): void {
        if (!wp_next_scheduled('abj404_cleanupCronAction')) {
            // we randomize this so that when the geo2ip file is downloaded, there aren't a whole
            // lot of users that request the file at the same time.
            $timeForEvent = '0' . random_int(0, 5) . ':' . random_int(10, 59) . ':' . random_int(10, 59);
            $eventTimestamp = strtotime($timeForEvent);
            if ($eventTimestamp !== false) {
                wp_schedule_event($eventTimestamp, 'daily', 'abj404_cleanupCronAction');
            }
        }
    }

    /** @return string */
    function getDebugLogFileLink(): string {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

    /** Get the "/commentpage" and the "?query=part" of the URL.
     * @return string */
    function getCommentPartAndQueryPartOfRequest() {
        // Fast path for common redirects: no query string and no comment-page segment.
        // This avoids UserRequest initialization/parsing for simple URLs.
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($requestUri !== '' &&
                strpos($requestUri, '?') === false &&
                strpos($requestUri, '/comment-page-') === false) {
            return '';
        }

    	$userRequest = ABJ_404_Solution_UserRequest::getInstance();
    	if ($userRequest === null) {
    		return '';
    	}
    	$queryString = $userRequest->getQueryString();
    	$queryParts = $this->f->removePageIDFromQueryString(is_string($queryString) ? $queryString : '');
    	$queryParts = ($queryParts == '') ? '' : '?' . $queryParts;
    	$commentPart = $userRequest->getCommentPagePart();
    	return (is_string($commentPart) ? $commentPart : '') . $queryParts;
    }

    /** First try a wp_redirect. Then try a redirect with JavaScript. The wp_redirect usually works, but doesn't
     * if some other plugin has already output any kind of data.
     * @param string $location
     * @param int $status
     * @param int|string $type only 0 for sending to a 404 page
     * @param string $requestedURL
     * @param bool $isCustom404
     * @return bool true if the user is sent to the default 404 page.
     */
    function forceRedirect(string $location, int $status = 302, $type = -1, string $requestedURL = '', bool $isCustom404 = false): bool {
        // 410 Gone: send status header then render the gone410.html template and exit.
        if ($status === 410) {
            status_header(410);
            $templatePath = __DIR__ . '/html/gone410.html';
            if (file_exists($templatePath)) {
                $siteName = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
                $siteUrl  = function_exists('home_url') ? home_url('/') : '/';
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{site_name}', '{site_url}', '{heading}', '{message}', '{back_home}'),
                        array(
                            esc_html($siteName),
                            esc_url($siteUrl),
                            esc_html__('This content has been permanently removed.', '404-solution'),
                            esc_html__('The page you requested no longer exists and has not been moved to a new location.', '404-solution'),
                            esc_html__('Back to home page', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        // 451 Unavailable For Legal Reasons: send status header then render the gone451.html template and exit.
        if ($status === 451) {
            status_header(451);
            $templatePath = __DIR__ . '/html/gone451.html';
            if (file_exists($templatePath)) {
                $siteName = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
                $siteUrl  = function_exists('home_url') ? home_url('/') : '/';
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{site_name}', '{site_url}', '{heading}', '{message}', '{back_home}'),
                        array(
                            esc_html($siteName),
                            esc_url($siteUrl),
                            esc_html__('451 Unavailable For Legal Reasons', '404-solution'),
                            esc_html__('This content is unavailable due to a legal demand.', '404-solution'),
                            esc_html__('Back to home page', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        // Meta Refresh: emit an HTML page with <meta http-equiv="refresh"> and exit.
        if ($status === 0 && $location !== '') {
            status_header(200);
            $templatePath = __DIR__ . '/html/metaRefresh.html';
            if (file_exists($templatePath)) {
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{url}', '{delay}', '{title}', '{message}'),
                        array(
                            esc_url($location),
                            '0',
                            esc_html__('Redirecting…', '404-solution'),
                            esc_html__('You are being redirected. Click the link if not redirected automatically.', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        $finalDestination = $this->buildFinalRedirectDestination($location, $requestedURL, $isCustom404);

    	$previousRequest = $this->readCookieWithPreviousRqeuestShort();
    	$schemePos = $this->f->strpos($finalDestination, '://');
    	$finalDestNoHome = ($schemePos !== false)
    		? $this->f->substr($finalDestination, $schemePos + 3) : $finalDestination;
    	$slashPos = $this->f->strpos($finalDestNoHome, '/');
    	$finalDestNoHome = ($slashPos !== false)
    		? $this->f->substr($finalDestNoHome, $slashPos) : '/';

    	$schemePos2 = $this->f->strpos($location, '://');
    	$locationNoHome = ($schemePos2 !== false)
    		? $this->f->substr($location, $schemePos2 + 3) : $location;
    	$slashPos2 = $this->f->strpos($locationNoHome, '/');
    	$locationNoHome = ($slashPos2 !== false)
    		? $this->f->substr($locationNoHome, $slashPos2) : '/';
    	// maybe avoid infinite redirects.
    	if (!empty($previousRequest)) {
    		if ($previousRequest == $finalDestNoHome && $previousRequest != $locationNoHome) {
    			$this->logger->infoMessage("Maybe avoided infite redirects to/from: " .
    				$previousRequest);
    			$finalDestination = $location;

    		} else if ($previousRequest == $finalDestination) {
    			$this->logger->infoMessage("Avoided infite redirects to/from: " .
    				$previousRequest);
    			return false;
    		}
    	}

    	// if the destination is the default 404 page then send the user there.
    	if ($type == ABJ404_TYPE_404_DISPLAYED) {
    		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
    		$abj404logic->sendTo404Page($requestedURL, '', false);

    		return true;
    	}

    	// try a normal redirect using a header.
    	$this->setCookieWithPreviousRequest();
        // If headers can be sent, do a normal header redirect and exit immediately.
        // Only fall back to JS redirect when headers are already sent.
        if (!headers_sent()) {
            if (function_exists('abj404_benchmark_emit_headers')) {
                abj404_benchmark_emit_headers();
            }
            // Prefer wp_safe_redirect for same-host redirects to avoid header-injection edge cases,
            // but allow external redirects (plugin supports external redirect destinations).
            $useSafe = false;
            if (function_exists('wp_safe_redirect')) {
                $destHost = parse_url($finalDestination, PHP_URL_HOST);
                if ($destHost === null || $destHost === false || $destHost === '') {
                    $useSafe = true; // relative URL
                } else {
                    $homeHost = parse_url(home_url(), PHP_URL_HOST);
                    if (is_string($homeHost) && $homeHost !== '' && strtolower($homeHost) === strtolower($destHost)) {
                        $useSafe = true;
                    }
                }
            }

            if ($useSafe) {
                wp_safe_redirect($finalDestination, $status, ABJ404_NAME);
            } else {
                wp_redirect($finalDestination, $status, ABJ404_NAME);
            }
            if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
                return false;
            }
            exit;
        }

        // JS fallback redirect for the rare case some other plugin/theme already output content.
        // Use wp_json_encode to safely encode URL for JavaScript to prevent XSS.
        if (function_exists('abj404_benchmark_emit_headers')) {
            abj404_benchmark_emit_headers();
        }
        $c = '<script>' . 'function doRedirect() {' . "\n" .
                '   window.location.replace(' . wp_json_encode($finalDestination) . ');' . "\n" .
                '}' . "\n" .
                'setTimeout(doRedirect, 1);' . "\n" .
                '</script>' . "\n" .
                'Page moved: <a href="' . esc_url($finalDestination) . '">' .
                    esc_html($finalDestination) . '</a>';
        echo $c;
        if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
            return false;
        }
        exit;
    }

}
