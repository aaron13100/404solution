<?php


if (!defined('ABSPATH')) {
    exit;
}

/* the glue that holds it together / everything else. */

/**
 * @phpstan-type PageObject object{id: int, post_parent: int, depth: int, post_type: string, post_title: string}
 */
class ABJ_404_Solution_PluginLogic {

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
    
    /** If a page's URL is /blogName/pageName then this returns /pageName.
     * @param string|null $urlRequest
     * @return string
     */
    function removeHomeDirectory($urlRequest): string {
    	if ($urlRequest === null) {
    		return '';
    	}
    	$f = $this->f;
    	$urlHomeDirectory = $this->urlHomeDirectory;
    	$homeLen = $this->urlHomeDirectoryLength !== null ? $this->urlHomeDirectoryLength : 0;

    	// Fix CRITICAL #1 (5th review): Skip processing for root installations
    	// When WordPress is at domain root, urlHomeDirectoryLength is 0
    	// Without this check, substr($url, 0, 0) == '' is always TRUE, incorrectly stripping leading slash
    	if ($homeLen === 0) {
    		return $urlRequest;
    	}

    	// Fix CRITICAL #1 (2nd review): Check path boundary to prevent false positives
    	// e.g., /blog should match /blog/page but NOT /blogpost or /blog-archive
    	if ($this->f->substr($urlRequest, 0, $homeLen) == $urlHomeDirectory) {
    		// Verify path boundary: next character must be '/', '?', '#', or end of string
    		$nextChar = $this->f->substr($urlRequest, $homeLen, 1);
    		if ($nextChar === '/' || $nextChar === '?' || $nextChar === '#' || $nextChar === '') {
    			// Fix CRITICAL #2 (3rd review): Don't strip query/fragment markers
    			if ($nextChar === '/' || $nextChar === '') {
    				// Strip subdirectory + slash for paths: /blog/page → /page
    				$urlRequest = $this->f->substr($urlRequest, ($homeLen + 1));
    			} else {
    				// Fix HIGH #1 (4th review): Add leading slash for query/fragment
    				// Strip subdirectory, add leading slash: /blog?q=1 → /?q=1
    				$urlRequest = '/' . $this->f->substr($urlRequest, $homeLen);
    			}
    		}
    		// else: false positive (e.g., /blogpost when subdirectory is /blog) - don't strip
    	}

        return $urlRequest;
    }

    /**
     * Normalize URL to relative path by removing WordPress subdirectory.
     * This ensures URLs are stored/matched independently of subdirectory changes.
     * Fixes Issue #24: Redirects now survive WordPress subdirectory changes.
     *
     * @param string|null $url Full URL or path
     * @return string Relative path without subdirectory
     */
    function normalizeToRelativePath($url): string {
        // Fix Issue #5: Handle empty URLs explicitly
        if ($url === '') {
            return '/';
        }

        // Fix HIGH #2: Trim whitespace
        if ($url === null) {
            return '/';
        }
        $url = trim($url);

        // Fix CRITICAL #2 (4th review): REMOVED rawurldecode() - URLs already decoded by UserRequest
        // Subdirectory decoding is now handled in constructor for consistency

        // Fix HIGH #2: If full URL, extract path only
        if (preg_match('#^https?://#i', $url)) {
            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['path'])) {
                return '/';
            }
            $url = $parsed['path'];
            // Preserve query and fragment
            if (!empty($parsed['query'])) {
                $url .= '?' . $parsed['query'];
            }
            if (!empty($parsed['fragment'])) {
                $url .= '#' . $parsed['fragment'];
            }
        }

        // Fix HIGH #2: Handle protocol-relative URLs (//example.com/path)
        if (strpos($url, '//') === 0) {
            $parsed = parse_url('http:' . $url);
            if ($parsed !== false && isset($parsed['path'])) {
                $url = $parsed['path'];
                if (!empty($parsed['query'])) {
                    $url .= '?' . $parsed['query'];
                }
                if (!empty($parsed['fragment'])) {
                    $url .= '#' . $parsed['fragment'];
                }
            } else {
                return '/';
            }
        }

        // Remove home directory if present
        $relativePath = $this->removeHomeDirectory($url);

        // Fix Issue #5: Check if removeHomeDirectory() returned empty unexpectedly
        if ($relativePath === '') {
            // Return root path for empty results
            return '/';
        }

        // Fix HIGH #2: Normalize multiple slashes to single slash
        $relativePathCleaned = preg_replace('#/+#', '/', $relativePath);
        $relativePath = is_string($relativePathCleaned) ? $relativePathCleaned : $relativePath;

        // Ensure consistent leading slash (but not multiple)
        $relativePath = '/' . ltrim($relativePath, '/');

        return $relativePath;
    }

    /**
     * Normalize a user-provided path for storage/matching.
     * Decodes percent-encoded octets and strips invalid UTF-8/control bytes.
     *
     * @param string|null $url
     * @return string
     */
    private function normalizeUserProvidedPath($url) {
        $url = $this->f->normalizeUrlString($url);
        if ($url === '') {
            return '';
        }

        return $this->normalizeToRelativePath($url);
    }

    /**
     * Normalize an external destination URL for storage.
     * Decodes percent-encoded octets and strips invalid UTF-8/control bytes.
     *
     * @param string|null $url
     * @return string
     */
    private function normalizeExternalDestinationUrl($url) {
        return $this->f->normalizeUrlString($url);
    }

    /**
     * Generate normalized lookup variants for URL matching.
     * Includes decoded form and a legacy encoded fallback.
     *
     * @param string|null $url
     * @return array<int, string>
     */
    function getNormalizedUrlCandidates($url) {
        $decoded = $this->normalizeUserProvidedPath($url);
        if ($decoded === '') {
            return array();
        }

        $candidates = array($decoded);

        // Legacy fallback for stored percent-encoded slugs.
        $encoded = $this->normalizeToRelativePath($this->f->encodeUrlForLegacyMatch($decoded));
        if ($encoded !== $decoded) {
            $candidates[] = $encoded;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Translate a redirect destination URL to the current language when possible.
     *
     * @param string $location Full URL or path to redirect to.
     * @param string $requestedURL Original requested path/URL that triggered the 404.
     * @return string URL to use for redirect.
     */
    function maybeTranslateRedirectUrl($location, $requestedURL = '') {
        if (!is_string($location) || $location === '') {
            return $location;
        }

        $translated = $this->translatePressRedirectUrl($location, $requestedURL);
        if ($translated !== null && $translated !== '') {
            $location = $translated;
        }

        if ($translated === null || $translated === '') {
            $translated = $this->wpmlRedirectUrl($location, $requestedURL);
            if ($translated !== null && $translated !== '') {
                $location = $translated;
            }
        }

        if ($translated === null || $translated === '') {
            $translated = $this->polylangRedirectUrl($location, $requestedURL);
            if ($translated !== null && $translated !== '') {
                $location = $translated;
            }
        }

        // Allow other multilingual plugins/themes to override redirect destinations.
        return apply_filters('abj404_translate_redirect_url', $location, $requestedURL);
    }

    /** @return string|null */
    private function translatePressRedirectUrl(string $location, string $requestedURL) {
        if (!$this->translatePressIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getTranslatePressLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->translatePressTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    /** @return bool */
    private function translatePressIntegrationAvailable(): bool {
        return function_exists('trp_get_language_from_url') ||
            function_exists('trp_get_current_language') ||
            function_exists('trp_get_url_for_language') ||
            function_exists('trp_translate_url') ||
            has_filter('trp_translate_url');
    }

    /** @return mixed */
    private function translatePressTranslateUrl(string $url, string $language) {
        if (function_exists('trp_get_url_for_language')) {
            return trp_get_url_for_language($language, $url);
        }

        if (function_exists('trp_translate_url')) {
            return trp_translate_url($url, $language);
        }

        return apply_filters('trp_translate_url', $url, $language);
    }

    private function getTranslatePressLanguageFromRequest(string $requestedURL): string {
        $fullRequestedUrl = $this->buildFullUrlFromRequest($requestedURL);

        if (function_exists('trp_get_language_from_url')) {
            $language = trp_get_language_from_url($fullRequestedUrl);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (function_exists('trp_get_current_language')) {
            $language = trp_get_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    /** @return string|null */
    private function wpmlRedirectUrl(string $location, string $requestedURL) {
        if (!$this->wpmlIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getWpmlLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->wpmlTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    private function wpmlIntegrationAvailable(): bool {
        return function_exists('wpml_current_language') ||
            has_filter('wpml_current_language') ||
            has_filter('wpml_language_from_url') ||
            has_filter('wpml_permalink');
    }

    /** @return mixed */
    private function wpmlTranslateUrl(string $url, string $language) {
        if (has_filter('wpml_permalink')) {
            return apply_filters('wpml_permalink', $url, $language);
        }

        return null;
    }

    private function getWpmlLanguageFromRequest(string $requestedURL): string {
        $fullRequestedUrl = $this->buildFullUrlFromRequest($requestedURL);

        if (has_filter('wpml_language_from_url')) {
            $language = apply_filters('wpml_language_from_url', '', $fullRequestedUrl);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (function_exists('wpml_current_language')) {
            $language = wpml_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (has_filter('wpml_current_language')) {
            $language = apply_filters('wpml_current_language', null);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    /** @return string|null */
    private function polylangRedirectUrl(string $location, string $requestedURL) {
        if (!$this->polylangIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getPolylangLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->polylangTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    private function polylangIntegrationAvailable(): bool {
        return function_exists('pll_current_language') ||
            function_exists('pll_translate_url');
    }

    /** @return mixed */
    private function polylangTranslateUrl(string $url, string $language) {
        if (function_exists('pll_translate_url')) {
            return pll_translate_url($url, $language);
        }

        return null;
    }

    private function getPolylangLanguageFromRequest(string $requestedURL): string {
        if (function_exists('pll_current_language')) {
            $language = pll_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    private function buildFullUrlFromRequest(string $requestedURL): string {
        $path = $requestedURL;
        if ($path === '') {
            $userRequest = ABJ_404_Solution_UserRequest::getInstance();
            if ($userRequest !== null) {
                $path = $userRequest->getPathWithSortedQueryString();
            }
        }

        if ($path === '') {
            return home_url('/');
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return home_url($path);
    }

    private function isLocalUrl(string $url): bool {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parsedUrl = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parsedUrl) || !isset($parsedUrl['host'])) {
            // Relative URLs are treated as local.
            return true;
        }

        $siteUrl = home_url();
        $parsedSite = function_exists('wp_parse_url') ? wp_parse_url($siteUrl) : parse_url($siteUrl);
        $siteHost = is_array($parsedSite) && isset($parsedSite['host']) ? strtolower($parsedSite['host']) : '';

        return $siteHost !== '' && strtolower($parsedUrl['host']) === $siteHost;
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
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying regex pattern to ignore\"' . 
                    $patternToIgnoreNoSlashes . '" to URL slug: ' . $urlSlugOnly;
                $matches = array();
                if ($this->f->regexMatch($patternToIgnoreNoSlashes, $urlSlugOnly, $matches)) {
                    $this->logger->debugMessage("Ignoring file/folder (do not redirect) for URL: " . 
                            esc_html($urlSlugOnly) . ", pattern used: " . $patternToIgnoreNoSlashes);
                    $ignoreReasonDoNotProcess = 'Files and folders (do not redirect) pattern: ' . 
                        esc_html($patternToIgnoreNoSlashes);
                }
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex pattern to ignore.';
            }
        }
        $_REQUEST[ABJ404_PP]['ignore_donotprocess'] = $ignoreReasonDoNotProcess;
        
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
        $_REQUEST[ABJ404_PP]['ignore_doprocess'] = $ignoreReasonDoProcess;
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

    	$_REQUEST[ABJ404_PP][$cookieName] = $requested_url;
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
            if (!isset($options[$key]) || '' == $options[$key]) {
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
            'auto_score' => '90',
            'auto_score_title' => '',
            'auto_score_category_tag' => '',
            'auto_score_content' => '',
            'template_redirect_priority' => '9',
            'auto_deletion' => '1095',
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
            'page_redirects_order_by' => 'url',
            'page_redirects_order' => 'ASC',
            'captured_order_by' => 'logshits',
            'captured_order' => 'DESC',
        	'excludePages[]' => '',
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
                $wpdb->query("DROP TABLE IF EXISTS {$tableRow[0]}");
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
            $timeForEvent = '0' . rand(0, 5) . ':' . rand(10, 59) . ':' . rand(10, 59);
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

    /** Do the passed in action and return the associated message. 
     * @global type $abj404logic
     * @param string $action
     * @param string $sub
     * @return string
     */
    function handlePluginAction($action, &$sub) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        $message = "";
        $message = array_key_exists('display-this-message', $_POST) ? 
        	sanitize_text_field($_POST['display-this-message']) : '';
        
        if ($action == "updateOptions") {
        	if (wp_verify_nonce($_POST['nonce'], 'abj404UpdateOptions') && is_admin()) {
                // delete the debug file and lose all changes, or
                if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile']) {
                    $filepath = $this->logger->getDebugFilePath();
                    if (!file_exists($filepath)) {
                        $message = sprintf(__("Debug file not found. (%s)", '404-solution'), $filepath);
                    } else if ($this->logger->deleteDebugFile()) {
                        $message = sprintf(__("Debug file(s) deleted. (%s)", '404-solution'), $filepath);
                    } else {
                        $message = sprintf(__("Issue deleting debug file. (%s)", '404-solution'), $filepath);
                    }
                    return $message;
                }
                
                // save all changes. saveOptions, saveSettings
                $sub = "abj404_options";
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "addRedirect") {
            if (check_admin_referer('abj404addRedirect') && is_admin()) {
                $message = $this->addAdminRedirect();
                if ($message == "") {
                    $message = __('New Redirect Added Successfully!', '404-solution');
                } else {
                    $message .= __('Error: unable to add new redirect.', '404-solution');
                }
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "emptyRedirectTrash") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                $abj404logic->doEmptyTrash('abj404_redirects');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "emptyCapturedTrash") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                $abj404logic->doEmptyTrash('abj404_captured');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "purgeRedirects") {
            if (check_admin_referer('abj404_purgeRedirects') && is_admin()) {
                $message = $this->dao->deleteSpecifiedRedirects();
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "runMaintenance") {
            if (check_admin_referer('abj404_runMaintenance') && is_admin()) {
                $message = $this->dao->deleteOldRedirectsCron();
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "rebuildNgramCache") {
            if (check_admin_referer('abj404_rebuildNgramCache') && is_admin()) {
                // Server-side request deduplication to prevent race conditions
                $userId = get_current_user_id();
                $transientKey = 'abj404_ngram_rebuild_request_' . $userId;
                $recentRequest = get_transient($transientKey);

                if ($recentRequest) {
                    // Duplicate request within 10 seconds - likely from rapid button clicks
                    $message = __('N-gram cache rebuild is already scheduled or in progress. Please wait for it to complete.', '404-solution');
                } else {
                    // Set transient to prevent duplicate requests for 10 seconds
                    set_transient($transientKey, time(), 10);

                    $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();

                    // Use async rebuild to avoid timeouts on large sites
                    $scheduled = $dbUpgrades->scheduleNGramCacheRebuild();

                    if ($scheduled) {
                        $message = __('N-gram cache rebuild has been scheduled and will run in the background. This may take several minutes on large sites. You can continue using the plugin normally.', '404-solution');
                    } else {
                        // Check if already running
                        $nextScheduled = wp_next_scheduled('abj404_rebuild_ngram_cache_hook');
                        if ($nextScheduled) {
                            $message = __('N-gram cache rebuild is already scheduled or in progress. Please wait for it to complete.', '404-solution');
                        } else {
                            $message = __('Failed to schedule N-gram cache rebuild. Please try again or check your WordPress cron configuration.', '404-solution');
                        }
                    }
                }
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "clearSpellingCache") {
            if (check_admin_referer('abj404_clearSpellingCache') && is_admin()) {
                $this->dao->deleteSpellingCache();
                $message = __('Spelling cache cleared successfully.', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($this->f->substr($action . '', 0, 4) == "bulk") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                if (!isset($_POST['idnum'])) {
                    $this->logger->debugMessage("No ID(s) specified for bulk action: " . esc_html($action));
                    echo sprintf(__("Error: No ID(s) specified for bulk action. (%s)", '404-solution'),
                        esc_html($action));
                    return '';
                }
                $message = $abj404logic->doBulkAction($action, array_map('absint', $_POST['idnum']));
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " . 
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        }
                
        return $message;
    }

    /** Move redirects to trash. 
     * @return string
     */
    function hanldeTrashAction() {
        
        $message = "";
        // Handle Trash Functionality
        if (isset($_GET['trash'])) {
            if (is_admin() && $this->verifyLinkNonce('abj404_trashRedirect')) {
                $trash = "";
                if ($_GET['trash'] == 0) {
                    $trash = 0;
                } else if ($_GET['trash'] == 1) {
                    $trash = 1;
                } else {
                    $this->logger->errorMessage("Unexpected trash operation: " . 
                            esc_html($_GET['trash']));
                    $message = __('Error: Bad trash operation specified.', '404-solution');
                    return $message;
                }
                
                $id = absint($_GET['id']);
                $message = $this->dao->moveRedirectsToTrash($id, $trash);
                if ($message == "") {
                    // Captured URLs: restoring from the Captured->Trash view should return to Captured (not Ignored/Later).
                    $subpage = isset($_GET['subpage']) ? sanitize_text_field(wp_unslash($_GET['subpage'])) : '';
                    $filter = isset($_GET['filter']) ? intval($_GET['filter']) : 0;
                    if ($trash == 0 && $subpage === 'abj404_captured' && $filter === ABJ404_TRASH_FILTER) {
                        $this->dao->updateRedirectTypeStatus($id, (string)ABJ404_STATUS_CAPTURED);
                    }
                    if ($trash == 1) {
                        $message = __('Redirect moved to trash successfully!', '404-solution');
                    } else {
                        $message = __('Redirect restored from trash successfully!', '404-solution');
                    }
                } else {
                    if ($trash == 1) {
                        $message = __('Error: Unable to move redirect to trash.', '404-solution');
                    } else {
                        $message = __('Error: Unable to move redirect from trash.', '404-solution');
                    }
                }
                
            }
        }
        
        return $message;
    }
    
    /** @return void */
    function handleActionChangeItemsPerRow(): void {

        if ($this->dao->getPostOrGetSanitize('action') == 'changeItemsPerRow' && $this->userIsPluginAdmin()) {
            check_admin_referer('abj404_changeItemsPerRow'); // verify nonce for CSRF protection
            $this->updatePerPageOption(absint($this->dao->getPostOrGetSanitize('perpage')));
        }
    }
    
    /** @return void */
    function handleActionExport(): void {
        
        if (($this->dao->getPostOrGetSanitize('action') == 'exportRedirects') && $this->userIsPluginAdmin()) {
            check_admin_referer('abj404_exportRedirects'); // this verifies the nonce
            $this->doExport();
        }
    }
    
    /** @return string|null */
    function handleActionImportFile() {

        if (($this->dao->getPostOrGetSanitize('action') == 'importRedirectsFile') && $this->userIsPluginAdmin()) {
            check_admin_referer('abj404_importRedirectsFile'); // this verifies the nonce (must match View.php form nonce)
            $result = $this->doImportFile();
            return $result;
        }

        return null;
    }

    /** @return string */
    function getExportFilename(string $format = 'native'): string {
        return $this->getImportExportService()->getExportFilename($format);
    }
    
    /** @return void */
    function doExport(): void {
        $this->getImportExportService()->doExport();
    }

    /**
     * Convert native export format to a Redirection-compatible CSV shape.
     *
     * @param string $sourceFile Native export file path.
     * @param string $destinationFile Output file path.
     * @return string Empty string on success, error message otherwise.
     */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        return $this->getImportExportService()->convertExportCsvToRedirectionFormat($sourceFile, $destinationFile);
    }
    
    /** Expected formats are 
     * from_url,status,type,to_url,wp_type
     * from_url,to_url 
     */
    /** @return string */
    function doImportFile(): string {
        return $this->getImportExportService()->doImportFile();
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @return array<int, string>
     */
    function loadDataArrayFromFile(array $dataArray, bool $dryRun = false): array {
        return $this->getImportExportService()->loadDataArrayFromFile($dataArray, $dryRun);
    }
    
	    /** @return array<string, string> */
	    function splitCsvLine(string $line): array {
	        return $this->getImportExportService()->splitCsvLine($line);
	    }

    /**
     * Detect whether this row appears to be a compatible competitor header row.
     *
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow(array $columns): bool {
        return $this->getImportExportService()->isCompatibleImportHeaderRow($columns);
    }

    /**
     * Normalize import headers for matching.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    function normalizeImportHeaders(array $columns): array {
        $result = $this->getImportExportService()->normalizeImportHeaders($columns);
        return array_map(function ($v) {
            return is_string($v) ? $v : '';
        }, $result);
    }

    /**
     * Map CSV row values into from_url/to_url using known competitor headers.
     *
     * @param array<int, string> $row
     * @param array<int, string> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders(array $row, array $normalizedHeaders): array {
        return $this->getImportExportService()->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * Detect import format by CSV header row.
     *
     * @param array<int, string> $columns
     * @return string
     */
    function detectImportFormatFromHeaders(array $columns): string {
        return $this->getImportExportService()->detectImportFormatFromHeaders($columns);
    }
    
    /** @return void */
    function updatePerPageOption(int $rows): void {
        $showRows = max($rows, ABJ404_OPTION_MIN_PERPAGE);
        $showRows = min($showRows, ABJ404_OPTION_MAX_PERPAGE);

        $options = $this->getOptions();
        $options['perpage'] = $showRows;
        $this->updateOptions($options);
    }
    
    /** 
     * 
     * @global type $abj404dao
     * @global type $abj404logging
     * @return string
     */
    function handleActionImportRedirects() {
        $message = "";
        
        
        if ($this->dao->getPostOrGetSanitize('action') == 'importRedirects') {
            if ($this->dao->getPostOrGetSanitize('sanity_404redirected') != '1') {
                $message = __("Error: You didn't check the I understand checkbox. No importing for you!", '404-solution');
                return $message;
            }

            check_admin_referer('abj404_importRedirects');
            
            try {
                $result = $this->dao->importDataFromPluginRedirectioner();
                if ($result['last_error'] != '') {
                    $lastErrorJson = json_encode($result['last_error']);
                    $message = sprintf(__("Error: No records were imported. SQL result: %s", '404-solution'),
                            wp_kses_post(is_string($lastErrorJson) ? $lastErrorJson : ''));
                } else {
                    $rowsAffected = is_scalar($result['rows_affected']) ? (string)$result['rows_affected'] : '0';
                    $message = sprintf(__("Records imported: %s", '404-solution'), esc_html($rowsAffected));
                }
                
            } catch (Exception $e) {
                $message = "Error: Importing failed. Message: " . $e->getMessage();
                $this->logger->errorMessage('Error importing redirects.', $e);
            }
        }
        
        return $message;
    }
    
    /** Delete redirects.
     * @global type $abj404dao
     * @return string
     */
    function handleDeleteAction() {
        $message = "";
        
        //Handle Delete Functionality
        if (array_key_exists('remove', $_GET) && @$_GET['remove'] == 1) {
            if (is_admin() && $this->verifyLinkNonce('abj404_removeRedirect')) {
                if ($this->f->regexMatch('[0-9]+', $_GET['id'])) {
                    $this->dao->deleteRedirect(absint($_GET['id']));
                    $message = __('Redirect Removed Successfully!', '404-solution');
                }
            }
        }
        
        return $message;
    }
    
    /**
     * Generic handler for updating redirect status based on URL parameters.
     * Eliminates duplication between handleIgnoreAction and handleLaterAction.
     *
     * @param string $paramName The $_GET parameter name ('ignore' or 'later')
     * @param string $nonceAction The nonce action name for security verification
     * @param int $activeStatus The status constant to use when action=1
     * @param string $errorActionName Action name for error messages ('ignore' or 'organize later')
     * @param string $successActionName Action name for success messages ('ignored' or 'organize later')
     * @return string Success/error message or empty string
     */
    private function handleStatusUpdate($paramName, $nonceAction, $activeStatus, $errorActionName, $successActionName) {
        $message = "";

        if (isset($_GET[$paramName])) {
            if (is_admin() && $this->verifyLinkNonce($nonceAction)) {
                if ($_GET[$paramName] != 0 && $_GET[$paramName] != 1) {
                    $this->logger->debugMessage("Unexpected {$errorActionName} operation: " .
                            esc_html($_GET[$paramName]));
                    $message = sprintf(__('Error: Bad %s operation specified.', '404-solution'), $errorActionName);
                    return $message;
                }

                $id = $_GET['id'] ?? '';
                if ($id !== '' && $this->f->regexMatch('[0-9]+', $id)) {
                    if ($_GET[$paramName] == 1) {
                        $newstatus = $activeStatus;
                    } else {
                        $newstatus = ABJ404_STATUS_CAPTURED;
                    }

                    $message = $this->dao->updateRedirectTypeStatus(absint($id), (string)$newstatus);
                    if ($message == "") {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = sprintf(__('Removed 404 URL from %s list successfully!', '404-solution'), $successActionName);
                        } else {
                            $message = sprintf(__('404 URL marked as %s successfully!', '404-solution'), $successActionName);
                        }
                    } else {
                        if ($newstatus == ABJ404_STATUS_CAPTURED) {
                            $message = sprintf(__('Error: unable to remove URL from %s list', '404-solution'), $successActionName);
                        } else {
                            $message = sprintf(__('Error: unable to mark URL as %s', '404-solution'), $successActionName);
                        }
                    }
                }
            }
        }

        return $message;
    }

    /** Set a redirect as ignored.
     * @return string
     */
    function handleIgnoreAction() {
        return $this->handleStatusUpdate('ignore', 'abj404_ignore404', ABJ404_STATUS_IGNORED, 'ignore', 'ignored');
    }

    /** Set a redirect as "organize later".
     * @return string
     */
    function handleLaterAction() {
        return $this->handleStatusUpdate('later', 'abj404_organizeLater', ABJ404_STATUS_LATER, 'organize later', 'organize later');
    }

    /** Edit redirect data.
     * @global type $abj404dao
     * @param string $sub
     * @param string $action
     * @return string
     */
    function handleActionEdit(&$sub, &$action) {
        $message = "";
        
        //Handle edit posts
        if (array_key_exists('action', $_POST) && $_POST['action'] == "editRedirect") {
            $id = $this->dao->getPostOrGetSanitize('id');
            $ids = $this->dao->getPostOrGetSanitize('ids_multiple');
            if (!($id === '' && $ids === '') && ($this->f->regexMatch('[0-9]+', '' . $id) || $this->f->regexMatch('[0-9]+', '' . $ids))) {
                if (is_admin() && $this->verifyLinkNonce('abj404editRedirect')) {
                    $message = $this->updateRedirectData();
                    if ($message == "") {
                        // Return user to the page they came from instead of always going to redirects page
                        $source_page = $this->dao->getPostOrGetSanitize('source_page');

                        // Validate source_page is a known tab
                        $valid_tabs = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
                                          'abj404_stats', 'abj404_tools', 'abj404_options');
                        if ($source_page === '' || !in_array($source_page, $valid_tabs)) {
                            // Default to redirects page if source_page is missing or invalid
                            $source_page = 'abj404_redirects';
                        }

                        // Build redirect URL with source page and preserved table options
                        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page;
                        $redirect_url .= "&updated=1"; // Add flag to show success message

                        // Preserve table options
                        $source_filter = $this->dao->getPostOrGetSanitize('source_filter', '');
                        if ($source_filter !== '' && $source_filter !== '0') {
                            $redirect_url .= "&filter=" . urlencode($source_filter);
                        }

                        $source_orderby = $this->dao->getPostOrGetSanitize('source_orderby', '');
                        $source_order = $this->dao->getPostOrGetSanitize('source_order', '');
                        if ($source_orderby !== '' && $source_order !== '') {
                            if (!($source_orderby === "url" && $source_order === "ASC")) {
                                $redirect_url .= "&orderby=" . urlencode($source_orderby);
                                $redirect_url .= "&order=" . urlencode($source_order);
                            }
                        }

                        $source_paged = $this->dao->getPostOrGetSanitize('source_paged', '');
                        if ($source_paged !== '' && (int)$source_paged > 1) {
                            $redirect_url .= "&paged=" . urlencode($source_paged);
                        }

                        // Perform redirect using Post/Redirect/Get pattern
                        wp_safe_redirect(admin_url('admin.php' . $redirect_url));
                        // Note: Intentionally not calling exit() to allow for testability
                        // WordPress will handle the redirect on next page load
                        return "";
                    } else {
                        $message .= __('Error: Unable to update redirect data.', '404-solution');
                    }
                }
            }
        }

        return $message;
    }
    
    /**
     * @global type $abj404dao
     * @param string $action
     * @param array<int, int> $ids
     * @return string
     */
    function doBulkAction(string $action, array $ids): string {
        $message = "";

        // nonce already verified.

        $this->logger->debugMessage("In doBulkAction. Action: " .
                esc_html($action == '' ? '(none)' : $action) . ", ids: " . wp_kses_post((string)json_encode($ids)));

        if ($action == "bulkignore" || $action == "bulkcaptured" || $action == "bulklater" ||
                $action == "bulk_trash_restore") {

            $status = 0;
            if ($action == "bulkignore") {
                $status = ABJ404_STATUS_IGNORED;

            } else if ($action == "bulkcaptured") {
                $status = ABJ404_STATUS_CAPTURED;

            } else if ($action == "bulklater") {
                $status = ABJ404_STATUS_LATER;
            }
            // else: bulk_trash_restore - don't change the status.

            $count = 0;
            foreach ($ids as $id) {
                $s = $this->dao->moveRedirectsToTrash($id, 0);
                if ($action != "bulk_trash_restore") {
                    $s = $this->dao->updateRedirectTypeStatus($id, (string)$status);
                }
                if ($s == "") {
                    $count++;
                }
            }
            if ($action == "bulkignore") {
                $message = $count . " " . __('URL(s) marked as Ignored.', '404-solution');
            } else if ($action == "bulkcaptured") {
                $message = $count . " " . __('URL(s) marked as Captured.', '404-solution');
            } else if ($action == "bulklater") {
                $message = $count . " " . __('URL(s) marked as Later.', '404-solution');
            } else {
                // bulk_trash_restore
                $message = $count . " " . __('URL(s) restored.', '404-solution');
            }
            
        } else if ($action == "bulk_trash_delete_permanently") {
            $count = 0;
            foreach ($ids as $id) {
                $this->dao->deleteRedirect(absint($id));
                $count ++;
            }
            $message = $count . " " . __('URL(s) deleted', '404-solution');

        } else if ($action == "bulktrash") {
            $count = 0;
            foreach ($ids as $id) {
                $s = $this->dao->moveRedirectsToTrash($id, 1);
                if ($s == "") {
                    $count ++;
                }
            }
            $message = $count . " " . __('URL(s) moved to trash', '404-solution');

        } else {
            $this->logger->errorMessage("Unrecognized bulk action: " . esc_html($action));
            echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
        }
        return $message;
    }

    /**
     * This is for both empty trash buttons (page redirects and captured 404 URLs).
     * @param string $sub
     * @return void
     */
    function doEmptyTrash(string $sub): void {
        global $wpdb;
        global $abj404_redirect_types;
        global $abj404_captured_types;
        
        $query = "";
        if ($sub == "abj404_captured") {
            $query = "delete FROM {wp_abj404_redirects} \n" .
                    "where disabled = 1 \n" .
                    "      and status in (" . implode(", ", $abj404_captured_types) . ")";
            
        } else if ($sub == "abj404_redirects") {
            $query = "delete FROM {wp_abj404_redirects} \n" .
                    "where disabled = 1 \n" .
                    "      and status in (" . implode(", ", $abj404_redirect_types) . ")";
            
        } else {
            $this->logger->errorMessage("Unrecognized type in doEmptyTrash(" . $sub . ")");
        }

        $result = $this->dao->queryAndGetResults($query);
        $this->logger->debugMessage("doEmptyTrash deleted " . $result['rows_affected'] . " rows total. (" . $sub . ")");

        // Invalidate status counts cache after bulk delete
        $this->dao->invalidateStatusCountsCache();

        $this->dao->queryAndGetResults("optimize table {wp_abj404_redirects}");
    }
    
    /** 
     * @global type $abj404dao
     * @return string
     */
    function updateRedirectData() {
        $message = "";
        $fromURL = "";
        $ids_multiple = "";
        
        if (
        	(!array_key_exists('url', $_POST) || $_POST['url'] == "") && 
        	(array_key_exists('ids_multiple', $_POST) && $_POST['ids_multiple'] != "")) {
            $ids_multiple = array_map('absint', explode(',', $_POST['ids_multiple']));
            
        } else if (array_key_exists('url', $_POST) && $_POST['url'] != "" && 
        	(!array_key_exists('ids_multiple', $_POST) || $_POST['ids_multiple'] == "")) {
        		
        	$fromURL = stripslashes($_POST['url']);
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
        }

        if ($fromURL != "" && $this->f->substr($_POST['url'], 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
        }

        $typeAndDest = $this->getRedirectTypeAndDest();

        $typeAndDestMessage = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($typeAndDestMessage != "") {
            return $typeAndDestMessage;
        }

        $tdType = is_scalar($typeAndDest['type']) ? (int)$typeAndDest['type'] : 0;
        $tdDest = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        if ($tdType != 0 && $tdDest !== "") {
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            // decide whether we're updating one or multiple redirects.
            if ($fromURL != "") {
                $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int)$_POST['id'] : 0;
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                $this->dao->updateRedirect($tdType, $tdDest,
                        $fromURL, $id, $code, (string)$statusType);

            } else if ($ids_multiple != "") {
                // get the redirect data for each ID.
                $redirects_multiple = $this->dao->getRedirectsByIDs($ids_multiple);
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                foreach ($redirects_multiple as $redirect) {
                    $redirectUrl = is_string($redirect['url']) ? $redirect['url'] : '';
                    $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                    $this->dao->updateRedirect($tdType, $tdDest,
                            $redirectUrl, $redirectId, $code, (string)$statusType);
                }

            } else {
                $this->logger->errorMessage("Issue determining which redirect(s) to update. " .
                    "fromURL: " . $fromURL . ", ids_multiple: " . $ids_multiple);
            }

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $this->logger->errorMessage("Update redirect data issue. Type: " . esc_html((string)$tdType) .
                    ", dest: " . esc_html($tdDest));
        }

        return $message;
    }
    
    /**
     * @return array<string, mixed>
     */
    function getRedirectTypeAndDest(): array {

        $response = array();
        $response['type'] = "";
        $response['dest'] = "";
        $response['message'] = "";
        $userEnteredURL = '';

        if (!isset($_POST['redirect_to_data_field_id']) || $_POST['redirect_to_data_field_id'] === '') {
            $response['message'] = __('Error: Redirect destination is required.', '404-solution') . "<BR/>";
            return $response;
        }

        if ($_POST['redirect_to_data_field_id'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $rawEnteredURLResult = $this->dao->getPostOrGetSanitizeUrl('redirect_to_user_field');
            $rawEnteredURL = is_string($rawEnteredURLResult) ? $rawEnteredURLResult : null;
            $userEnteredURL = $this->normalizeExternalDestinationUrl($rawEnteredURL);
            $userEnteredURL = esc_url($userEnteredURL, array('http', 'https'));
            if ($userEnteredURL == "") {
                $response['message'] = __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>";

            } else if ($this->f->strlen($userEnteredURL) < 8) {
                $response['message'] = __('Error: External URL is too short.', '404-solution') . "<BR/>";

            } else if ($this->f->strpos($userEnteredURL, "://") === false) {
                $response['message'] = __("Error: External URL doesn't contain ://", '404-solution') . "<BR/>";

            } else {
                // Validate that URL uses safe protocol (http/https only)
                $parsed_url = parse_url($userEnteredURL);
                if (!is_array($parsed_url) || !isset($parsed_url['scheme']) || !in_array(strtolower($parsed_url['scheme']), array('http', 'https'))) {
                    $response['message'] = __('Error: External URL must use http:// or https:// protocol only.', '404-solution') . "<BR/>";
                }

                // Allow filtering of external redirect URLs for additional validation
                // Usage: add_filter('abj404_validate_external_redirect', function($url) { /* validation */ return $url; });
                $validated_url = apply_filters('abj404_validate_external_redirect', $userEnteredURL);
                if ($validated_url === false) {
                    $response['message'] = __('Error: External redirect URL failed validation.', '404-solution') . "<BR/>";
                } else {
                    $userEnteredURL = $validated_url;
                }
            }
        }

        if ($response['message'] != "") {
            return $response;
        }
        $info = explode("|", sanitize_text_field($_POST['redirect_to_data_field_id']));

        if ($_POST['redirect_to_data_field_id'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $response['type'] = ABJ404_TYPE_EXTERNAL;
            // Use the sanitized $userEnteredURL (created at line 1932) instead of raw POST
            $response['dest'] = $userEnteredURL;
        } else {
            if (count($info) == 2) {
                $response['dest'] = absint($info[0]);
                $response['type'] = $info[1];
            } else {
                $infoJson = json_encode($info);
                $this->logger->errorMessage("Unexpected info while updating redirect: " .
                        wp_kses_post(is_string($infoJson) ? $infoJson : ''));
            }
        }
        
        return $response;
    }
    
    /**
     * @global type $abj404dao
     * @return string
     */
    function addAdminRedirect() {
        $message = "";

        if (!isset($_POST['manual_redirect_url']) || $_POST['manual_redirect_url'] == "") {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
            return $message;
        }

        $manualURL = isset($_POST['manual_redirect_url']) ? wp_unslash($_POST['manual_redirect_url']) : '';
        $manualURL = $this->normalizeUserProvidedPath($manualURL);
        if ($this->f->substr($manualURL, 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
            return $message;
        }

        $typeAndDest = $this->getRedirectTypeAndDest();

        $tdMsg = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($tdMsg != "") {
            return $tdMsg;
        }

        $tdType2 = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdDest2 = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        if ($tdType2 != "" && $tdDest2 !== "") {
            // url match type. regex or normal exact match.
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            $code = isset($_POST['code']) && !empty($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : (string)ABJ404_STATUS_MANUAL;

            $this->dao->setupRedirect($manualURL, (string)$statusType,
                    $tdType2, $tdDest2,
                    sanitize_text_field($code), 0);

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $this->logger->errorMessage("Add redirect data issue. Type: " . esc_html($tdType2) . ", dest: " .
                    esc_html($tdDest2));
        }

        return $message;
    }

    /**
     * @param string $pageBeingViewed
     * @return array<string, mixed>
     */
    function getTableOptions(string $pageBeingViewed): array {
        $tableOptions = array();
        $options = $this->getOptions(true);

        $translationArray = array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Man', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}' => __('Auto', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}' => __('RegEx', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}' => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}' => __('Tag', '404-solution'),
       		'{ABJ404_TYPE_HOME_text}' => __('Home Page', '404-solution'),
       		'{ABJ404_TYPE_404_DISPLAYED_text}' => __('(Default 404 Page)', '404-solution'),
       		'{ABJ404_TYPE_SPECIAL_text}' => __('(Special)', '404-solution'),
        );
        
        $tableOptions['translations'] = $translationArray;
        
        $tableOptions['filter'] = intval($this->dao->getPostOrGetSanitize("filter", ""));
        if ($tableOptions['filter'] == "") {
            if ($this->dao->getPostOrGetSanitize('subpage') == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_STATUS_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        }
        
        $tableOptions['filterText'] = trim($this->dao->getPostOrGetSanitize("filterText", ""));
        // Remove comment markers early to prevent filterText from breaking SQL comments.
        $tableOptions['filterText'] = $this->f->str_replace(array('*', '/', '$'), '', $tableOptions['filterText']);

        $orderbyInput = $this->dao->getPostOrGetSanitize('orderby', "");
        if ($orderbyInput != "" && in_array($orderbyInput, self::$allowedOrderbyColumns, true)) {
            $tableOptions['orderby'] = $orderbyInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order_by'] = $tableOptions['orderby'];
                $this->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order_by'] = $tableOptions['orderby'];
                $this->updateOptions($options);
            }

        } else if ($pageBeingViewed == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
        } else if ($pageBeingViewed == 'abj404_redirects') {
            $tableOptions['orderby'] = $options['page_redirects_order_by'];
        } else if ($pageBeingViewed == 'abj404_captured') {
            $tableOptions['orderby'] = $options['captured_order_by'];
        } else {
            $tableOptions['orderby'] = 'url';
        }

        $orderInput = strtoupper($this->dao->getPostOrGetSanitize('order', ''));
        if ($orderInput != '' && in_array($orderInput, self::$allowedOrderValues, true)) {
            $tableOptions['order'] = $orderInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order'] = $tableOptions['order'];
                $this->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order'] = $tableOptions['order'];
                $this->updateOptions($options);
            }

        } else if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";

        } else if ($pageBeingViewed == 'abj404_redirects') {
            $tableOptions['order'] = $options['page_redirects_order'];

        } else if ($pageBeingViewed == 'abj404_captured') {
            $tableOptions['order'] = $options['captured_order'];

        } else {
            $tableOptions['order'] = "ASC";
        }

        $tableOptions['paged'] = $this->dao->getPostOrGetSanitize("paged", '1');

        $perPageOption = ABJ404_OPTION_DEFAULT_PERPAGE;
        if (isset($options['perpage'])) {
            $perPageOption = max(absint(is_scalar($options['perpage']) ? $options['perpage'] : 0), ABJ404_OPTION_MIN_PERPAGE);
        }
        $tableOptions['perpage'] = $this->dao->getPostOrGetSanitize("perpage", (string)$perPageOption);

        $tableOptions['logsid'] = 0;
        if ($this->dao->getPostOrGetSanitize('subpage') == "abj404_logs") {
            $logId = (string)$this->dao->getPostOrGetSanitize('id', '');
            if ($this->f->regexMatch('[0-9]+', $logId)) {
                $tableOptions['logsid'] = absint($logId);

            } else {
                $redirectToDataFieldId = (string)$this->dao->getPostOrGetSanitize('redirect_to_data_field_id', '');
                if ($this->f->regexMatch('[0-9]+', $redirectToDataFieldId)) {
                    $tableOptions['logsid'] = absint($redirectToDataFieldId);
                }
            }
        }

        // sanitize all values.
        $sanitizedTableOptions = $this->sanitizePostData($tableOptions);

        return $sanitizedTableOptions;
    }
    
    /**
     * @param array<string, mixed> $postData
     * @param bool $restoreNewlines
     * @return array<string, mixed>
     */
    function sanitizePostData(array $postData, bool $restoreNewlines = false): array {
        $newData = array();
        foreach ($postData as $key => $value) {
            $key = wp_kses_post($key);
            if (is_array($value)) {
                $newData[$key] = $this->sanitizePostData($value, $restoreNewlines);
            } else {
                // Handle null values (PHP 8.1+ deprecation fix)
                if ($value === null) {
                    $newData[$key] = '';
                } else {
                    $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
                    $newData[$key] = wp_kses_post($valueStr);
                    $newData[$key] = esc_sql($newData[$key]);
                    if ($restoreNewlines) {
                        $newData[$key] = str_replace('\n', "\n", $newData[$key]);
                    }
                }
            }
        }
        return $newData;
    }
    
    /** Remove non a-zA-Z0-9 or _ characters. 
     * @param string $str
     * @return string
     */
    function sanitizeForSQL($str) {
        if ($str == null || $str == '') {
            return '';
        }
        $re = '/[^\w_]/';

        $result = preg_replace($re, '', $str);
        return is_string($result) ? $result : $str;
    }
    
    /**
     * @return array<string, mixed>
     */
    function updateOptionsFromPOST() {
        $message = "";
        $options = $this->getOptions();

        // to return after handling the ajax call.
        $returnData = array();
        $returnData['newURL'] = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';

        // get the submitted settings
        if (!isset($_POST['encodedData'])) {
            $this->logger->errorMessage('Missing encodedData in POST');
            return array(
                'success' => false,
                'status' => 400,
                'message' => 'Missing form data',
            );
        }

        $encodedData = $_POST['encodedData'];
        $postData = $this->f->decodeComplicatedData($encodedData);
        if (!is_array($postData)) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST');
            return array(
                'success' => false,
                'status' => 400,
                'message' => 'Missing form data',
            );
        }

        // verify nonce (defense-in-depth; Ajax_Php already verifies for admin-ajax calls)
        $nonce = isset($postData['nonce']) ? $postData['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'abj404UpdateOptions') || !is_admin()) {
            return array(
                'success' => false,
                'status' => 403,
                'message' => 'Invalid security token',
            );
        }

        $_POST = $postData;

        // delete the debug file if requested.
        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile'] == true) {
            $sub = '';
            $returnData['error'] = '';
            $returnData['message'] = $this->handlePluginAction('updateOptions', $sub);

        } else {
            // save all options - grouped by related functionality
            $message .= $this->updateRedirectSettings($options, $_POST);
            $message .= $this->updateWordPressSettings($options, $_POST);
            $message .= $this->updateNotificationSettings($options, $_POST);
            $message .= $this->updateDeletionSettings($options, $_POST);
            $message .= $this->updateSuggestionSettings($options, $_POST);
            $message .= $this->updateBooleanToggles($options, $_POST);
            $message .= $this->updateSuggestionHTMLOptions($options, $_POST);
            $message .= $this->updateRegexPatternSettings($options, $_POST);
            $message .= $this->updateAdminUsers($options, $_POST);
            $message .= $this->updateExcludedPages($options, $_POST);

            // save this for later to sanitize it ourselves.
            $excludedPages = $options['excludePages[]'];

            /** Sanitize all data. */
            $new_options = array();
            // when sanitizing data we keep the newlines (\n) because some data
            // is entered that way and it shouldn't allow any kind of sql
            // injection or any other security issues that I foresee at this point.
            $new_options = $this->sanitizePostData($options, true);

            // only some characters in the string.
            $excludedPages = $excludedPages == null ? '' : trim($excludedPages);
            $excludedPages = preg_replace('/[^\[\",\]a-zA-Z\d\|\\\\ ]/', '', $excludedPages);
            $new_options['excludePages[]'] = $excludedPages;

            $this->updateOptions($new_options);

            // update the permalink cache because the post types included may have changed.
            $permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
            $permalinkCache->updatePermalinkCache(2);

            $returnData['error'] = $message;
            if ($message == "") {
                $returnData['message'] = __('Options Saved Successfully!', '404-solution');
            } else {
                $returnData['message'] = __('Some options were not saved successfully.', '404-solution') .
                    '		' . $message;
            }
        }

        return array(
            'success' => true,
            'status' => 200,
            'data' => $returnData,
        );
    }

    /** Update redirect-related settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateRedirectSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['default_redirect'])) {
            if ($postData['default_redirect'] == "301" || $postData['default_redirect'] == "302") {
                $options['default_redirect'] = is_scalar($postData['default_redirect']) ? intval($postData['default_redirect']) : 301;
            } else {
                $message .= __('Error: Invalid value specified for default redirect type', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['redirect_to_data_field_id'])) {
            $options['dest404page'] = sanitize_text_field(is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : '');
        }
        if (isset($postData['redirect_to_data_field_title'])) {
            $options['dest404pageURL'] = sanitize_text_field(is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : '');
            if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            	$options['dest404page'] = $options['dest404pageURL'] . '|' . ABJ404_TYPE_EXTERNAL;
            }
        }

        if (isset($postData['template_redirect_priority'])) {
            if (is_numeric($postData['template_redirect_priority']) && $postData['template_redirect_priority'] >= 0 && $postData['template_redirect_priority'] <= 999) {
                $options['template_redirect_priority'] = absint($postData['template_redirect_priority']);
            } else {
                $message .= __('Error: Template redirect priority value must be a number between 0 and 999', '404-solution') . ".<BR/>";
            }
        }

        return $message;
    }

    /** Update WordPress-specific settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateWordPressSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['ignore_dontprocess'])) {
        	$options['ignore_dontprocess'] = wp_kses_post(is_string($postData['ignore_dontprocess']) ? $postData['ignore_dontprocess'] : '');
        }
        if (isset($postData['ignore_doprocess'])) {
        	$options['ignore_doprocess'] = wp_kses_post(is_string($postData['ignore_doprocess']) ? $postData['ignore_doprocess'] : '');
        }
        if (isset($postData['recognized_post_types'])) {
        	$options['recognized_post_types'] = wp_kses_post(is_string($postData['recognized_post_types']) ? $postData['recognized_post_types'] : '');
        }
        if (isset($postData['recognized_categories'])) {
        	$options['recognized_categories'] = wp_kses_post(is_string($postData['recognized_categories']) ? $postData['recognized_categories'] : '');
        }
        if (isset($postData['menuLocation'])) {
        	$options['menuLocation'] = wp_kses_post(is_string($postData['menuLocation']) ? $postData['menuLocation'] : '');
        }

        if (isset($postData['admin_theme'])) {
            // Only allow specific theme values
            $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
            $theme = sanitize_text_field(is_string($postData['admin_theme']) ? $postData['admin_theme'] : '');
            if (in_array($theme, $allowed_themes)) {
                $options['admin_theme'] = $theme;
            } else {
                $message .= __('Error: Invalid theme selected', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['plugin_language_override'])) {
            // Only allow specific locale values
            $allowed_locales = array('', 'en_US', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'pt_BR', 'nl_NL', 'ru_RU', 'ja', 'zh_CN', 'id_ID', 'sv_SE');
            $locale = sanitize_text_field(is_string($postData['plugin_language_override']) ? $postData['plugin_language_override'] : '');
            if (in_array($locale, $allowed_locales)) {
                $options['plugin_language_override'] = $locale;
            } else {
                $message .= __('Error: Invalid language selected', '404-solution') . ".<BR/>";
            }
        }

        // Handle disable_auto_dark_mode checkbox (unchecked = not in postData)
        if (isset($postData['disable_auto_dark_mode']) && $postData['disable_auto_dark_mode'] == '1') {
            $options['disable_auto_dark_mode'] = '1';
        } else {
            $options['disable_auto_dark_mode'] = '0';
        }

        if (isset($postData['days_wait_before_major_update'])) {
            if (is_numeric($postData['days_wait_before_major_update'])) {
                $options['days_wait_before_major_update'] = absint($postData['days_wait_before_major_update']);
            } else {
                $message .= sprintf(__('Error: The time to wait before an automatic update must be a number between 0 and something around %d.', '404-solution'), PHP_INT_MAX) . "<BR/>";
            }
        }

        return $message;
    }

    /** Update notification settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateNotificationSettings(&$options, $postData) {
        $message = "";

        if (isset($postData['admin_notification'])) {
            if (is_numeric($postData['admin_notification'])) {
                $options['admin_notification'] = absint($postData['admin_notification']);
            }
        }

        if (isset($postData['admin_notification_email'])) {
            $options['admin_notification_email'] = trim(wp_kses_post(is_string($postData['admin_notification_email']) ? $postData['admin_notification_email'] : ''));
        }

        return $message;
    }

    /**
     * Validate and set a numeric field value from POST data.
     * Eliminates duplication in settings update methods.
     *
     * @param array<string, mixed> $options Reference to options array to update
     * @param array<string, mixed> $postData POST data containing field value
     * @param string $fieldName Name of the field to validate
     * @param string $errorMessage Error message to display on validation failure
     * @param int $minValue Minimum allowed value (default: 0)
     * @param bool $useAbsintForCheck Whether to use absint() before comparison (default: false)
     * @return string Error message if validation fails, empty string otherwise
     */
    private function validateAndSetNumericField(array &$options, array $postData, string $fieldName, string $errorMessage, int $minValue = 0, bool $useAbsintForCheck = false): string {
        if (isset($postData[$fieldName])) {
            $value = $postData[$fieldName];
            $scalarValue = is_scalar($value) ? $value : 0;
            $passesValidation = false;

            if ($useAbsintForCheck) {
                // For maximum_log_disk_usage: check absint(value) > minValue
                $passesValidation = is_numeric($value) && absint($scalarValue) > $minValue;
            } else {
                // For other fields: check value >= minValue
                $passesValidation = is_numeric($value) && $value >= $minValue;
            }

            if ($passesValidation) {
                $options[$fieldName] = absint($scalarValue);
                return "";
            } else {
                return __($errorMessage, '404-solution') . ".<BR/>";
            }
        }
        return "";
    }

    /** Update deletion-related settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateDeletionSettings(array &$options, array $postData): string {
        $message = "";

        $message .= $this->validateAndSetNumericField($options, $postData, 'capture_deletion',
            'Error: Collected URL deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'manual_deletion',
            'Error: Manual redirect deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'log_deletion',
            'Error: Log deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'auto_deletion',
            'Error: Auto redirect deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'maximum_log_disk_usage',
            'Error: Maximum log disk usage must be a number greater than zero', 0, true);

        return $message;
    }

    /** Update suggestion/spelling settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateSuggestionSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['suggest_max'])) {
            if (is_numeric($postData['suggest_max']) && $postData['suggest_max'] >= 1) {
                if ($options['suggest_max'] != absint($postData['suggest_max'])) {
                    $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                            ": Truncating spelling cache because the max suggestions # changed from " .
                            $options['suggest_max'] . ' to ' . absint($postData['suggest_max']));

                    // the spelling cache only stores up to X entries. X is based on suggest_max
                    // so the spelling cache has to be reset when this number changes.
                    $this->dao->deleteSpellingCache();
                }

                $options['suggest_max'] = absint($postData['suggest_max']);
            } else {
                $message .= __('Error: Maximum number of suggest value must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['auto_score'])) {
            if (is_numeric($postData['auto_score']) && $postData['auto_score'] >= 0 && $postData['auto_score'] <= 99) {
                $options['auto_score'] = absint($postData['auto_score']);
            } else {
                $message .= __('Error: Auto match score value must be a number between 0 and 99', '404-solution') . ".<BR/>";
            }
        }

        // Per-engine score overrides: accept empty string (use global) or numeric 0–99
        $engineScoreKeys = ['auto_score_title', 'auto_score_category_tag', 'auto_score_content'];
        foreach ($engineScoreKeys as $key) {
            if (isset($postData[$key])) {
                $raw = $postData[$key];
                $val = is_string($raw) ? trim($raw) : (is_numeric($raw) ? trim(strval($raw)) : '');
                if ($val === '') {
                    $options[$key] = '';
                } elseif (is_numeric($val) && $val >= 0 && $val <= 99) {
                    $options[$key] = absint($val);
                } else {
                    $message .= __('Error: Per-engine score override must be empty or a number between 0 and 99', '404-solution') . ".<BR/>";
                }
            }
        }

        return $message;
    }

    /** Update boolean toggle options (checkboxes).
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateBooleanToggles(array &$options, array $postData): string {
        $message = "";

        // Check if we're in simple or advanced settings mode
        $settingsMode = $this->getSettingsMode();

        // All boolean options that could be in forms
        $allBooleanOptions = array('remove_matches', 'debug_mode', 'suggest_cats', 'suggest_tags',
            'auto_redirects', 'auto_slugs', 'auto_cats', 'auto_tags', 'capture_404', 'send_error_logs', 'log_raw_ips',
        	'redirect_all_requests', 'update_suggest_url', 'suggest_minscore_enabled'
        );

        // Options that appear in Simple Mode form
        $simpleModeOptions = array('auto_redirects', 'capture_404');

        // Determine which options to process from POST data
        if ($settingsMode === 'simple') {
            // Simple mode: only process options that are actually in the form
            $optionsToProcess = $simpleModeOptions;
        } else {
            // Advanced mode: process all options (existing behavior)
            $optionsToProcess = $allBooleanOptions;
        }

        foreach ($optionsToProcess as $optionName) {
        	$newVal = (array_key_exists($optionName, $postData) && $postData[$optionName] == "1") ? 1 : 0;

        	// in case the suggest_cats or suggest_tags is changed.
        	if (!array_key_exists($optionName, $options) ||
        		$options[$optionName] != $newVal) {

        		$this->dao->deleteSpellingCache();
        	}
            $options[$optionName] = $newVal;
        }

        // In Simple Mode, sync auto_cats and auto_tags with auto_redirects
        if ($settingsMode === 'simple') {
            $autoRedirectsValue = isset($options['auto_redirects']) ? $options['auto_redirects'] : 0;
            $options['auto_cats'] = $autoRedirectsValue;
            $options['auto_tags'] = $autoRedirectsValue;
        }

        return $message;
    }

    /** Update suggestion HTML display options.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateSuggestionHTMLOptions(array &$options, array $postData): string {
        $message = "";

        // the suggest_.* options have html in them.
        $optionsListSuggest = array('suggest_title', 'suggest_before', 'suggest_after', 'suggest_entrybefore',
            'suggest_entryafter', 'suggest_noresults');
        foreach ($optionsListSuggest as $optionName) {
            // Only update if the option was posted (Simple Mode doesn't include these)
            if (isset($postData[$optionName])) {
                $options[$optionName] = wp_kses_post(is_string($postData[$optionName]) ? $postData[$optionName] : '');
            }
        }

        return $message;
    }

    /** Update regex pattern settings for ignoring files/folders and suggestion exclusions.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateRegexPatternSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['folders_files_ignore'])) {
            $foldersFilesVal = is_string($postData['folders_files_ignore']) ? $postData['folders_files_ignore'] : '';
            $options['folders_files_ignore'] = wp_unslash(wp_kses_post($foldersFilesVal));

            // make the regular expressions usable.
            $patternsToIgnore = $this->f->explodeNewline($options['folders_files_ignore']);
            $usableFilePatterns = array();
            foreach ($patternsToIgnore as $patternToIgnore) {
                $newPattern = '^' . preg_quote(trim($patternToIgnore), '/') . '$';
                $newPattern = $this->f->str_replace("\*",".*", $newPattern);
                $usableFilePatterns[] = $newPattern;
            }
            $options['folders_files_ignore_usable'] = $usableFilePatterns;
        }

        if ( isset( $postData['suggest_regex_exclusions'] ) ) {
            // 1. Sanitize the raw input using the appropriate function for multi-line text without HTML.
            $suggestRegexRaw = is_string($postData['suggest_regex_exclusions']) ? $postData['suggest_regex_exclusions'] : '';
            $sanitized_exclusions = sanitize_textarea_field( wp_unslash( $suggestRegexRaw ) );
            $options['suggest_regex_exclusions'] = $sanitized_exclusions;

            // 2. Generate the usable regex patterns *from the sanitized input*.
            $patternsToIgnore = $this->f->explodeNewline( $sanitized_exclusions );
            $usableFilePatterns = array();
            foreach ( $patternsToIgnore as $patternToIgnore ) {
                $trimmedPattern = trim( $patternToIgnore );
                // Only process non-empty lines
                if ( ! empty( $trimmedPattern ) ) {
                    // Escape regex special characters, then convert literal '*' into '.*' for wildcard matching.
                    $newPattern = '^' . preg_quote( $trimmedPattern, '/' ) . '$';
                    // Use standard str_replace; $this->f->str_replace is likely unnecessary here unless it provides specific multibyte handling not needed for '\*'.
                    $newPattern = str_replace( '\*', '.*', $newPattern );
                    $usableFilePatterns[] = $newPattern;
                }
            }
            $options['suggest_regex_exclusions_usable'] = $usableFilePatterns;
        }

        return $message;
    }

    /** Update plugin admin users list.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateAdminUsers(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['plugin_admin_users'])) {
        	$pluginAdminUsers = $postData['plugin_admin_users'];
        	if (is_array($pluginAdminUsers)) {
        		$pluginAdminUsers = array_filter($pluginAdminUsers,
        			array($this->f, 'removeEmptyCustom'));
        	}

        	$options['plugin_admin_users'] = $pluginAdminUsers;
        }

        return $message;
    }

    /** Update excluded pages list.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateExcludedPages(array &$options, array $postData): string {
        $message = "";

        if (is_array($options['excludePages[]'])) {
            $this->logger->warn("Exclude pages settings lost.");
            $options['excludePages[]'] = '';
        }
        if (isset($postData['excludePages[]'])) {
        	$excludePagesStr = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        	$oldExcludePages = json_decode($excludePagesStr);
        	if (!is_array($postData['excludePages[]'])) {
        		$postData['excludePages[]'] = array($postData['excludePages[]']);
        	}
        	$encodedPages = json_encode($postData['excludePages[]']);
        	$options['excludePages[]'] = is_string($encodedPages) ? $encodedPages : '';
        	$newExcludePages = json_decode($options['excludePages[]']);
        	if ($newExcludePages !== $oldExcludePages) {
        		// if any excluded pages changed or if the number of excluded pages changed
        		// then the spelling cache has to be reset.
        		$this->dao->deleteSpellingCache();
        	}
        } else {
        	$excludePagesStr2 = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        	$oldExcludePages = json_decode($excludePagesStr2);
        	if (null !== $oldExcludePages) {
        		// if any excluded pages changed or if the number of excluded pages changed
        		// then the spelling cache has to be reset.
        		$this->dao->deleteSpellingCache();
        	}
        	$options['excludePages[]'] = null;
        }

        return $message;
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

    /**
     * Build the final redirect destination URL.
     *
     * This is separated for testability and to avoid mixing HTML escaping with redirect URL construction.
     *
     * @param string $location Base redirect destination.
     * @param string $requestedURL Original requested URL (used for custom 404 ref tracking).
     * @param bool $isCustom404 Whether we are redirecting to a custom 404 page.
     * @return string Redirect destination suitable for wp_redirect().
     */
    public function buildFinalRedirectDestination($location, $requestedURL = '', $isCustom404 = false) {
        // Translate redirect destination for multilingual sites (TranslatePress, etc.)
        $location = $this->maybeTranslateRedirectUrl($location, $requestedURL);

        // Preserve comment pagination and query string from the original request.
        $commentPartAndQueryPart = (string)$this->getCommentPartAndQueryPartOfRequest();
        $finalDestination = (string)$location . $commentPartAndQueryPart;

        // Append _ref LAST for custom 404 redirects (prevents user override via query string).
        // This is a fallback for when cookies don't survive 301 redirects.
        if ($isCustom404 && is_string($requestedURL) && $requestedURL !== '') {
            $refUrlResult = preg_replace('/\?.*/', '', $requestedURL); // Strip query string from ref
            $refUrl = is_string($refUrlResult) ? $refUrlResult : $requestedURL;
            $refParam = ABJ404_PP . '_ref';
            if (function_exists('remove_query_arg')) {
                $finalDestination = remove_query_arg($refParam, $finalDestination);
            }
            if (function_exists('add_query_arg')) {
                $finalDestination = add_query_arg($refParam, rawurlencode($refUrl), $finalDestination);
            } else {
                $separator = (strpos($finalDestination, '?') === false) ? '?' : '&';
                $finalDestination .= $separator . $refParam . '=' . rawurlencode($refUrl);
            }
        }

        // Sanitize for redirect header context (NOT HTML context).
        // Harden against CRLF header injection even when WP helpers are not available.
        $finalDestCleaned = preg_replace("/[\\r\\n]+/", '', (string)$finalDestination);
        $finalDestination = is_string($finalDestCleaned) ? $finalDestCleaned : (string)$finalDestination;

        if (function_exists('wp_sanitize_redirect')) {
            $finalDestination = wp_sanitize_redirect($finalDestination);
        } elseif (function_exists('esc_url_raw')) {
            $finalDestination = esc_url_raw($finalDestination);
        }

        return (string)$finalDestination;
    }

    /** Order pages and set the page depth for child pages.
     * Move the children to be underneath the parents.
     * @param array<int, object> $pages
     * @param bool $includeMissingParentPages
     * @return array<int, object>
     */
    function orderPageResults(array $pages, bool $includeMissingParentPages = false): array {

        // sort by type then title.
        usort($pages, function (object $a, object $b): int {
            return $this->sortByTypeThenTitle($a, $b);
        });
        // run this to see if there are any child pages left.
        $orderedPages = $this->setDepthAndAddChildren($pages);

        // The pages are now sorted. We now apply the depth AND we make sure the child pages
        // always immediately follow the parent pages.

        // -------------
        if ($includeMissingParentPages && (count($orderedPages) != count($pages))) {
            $iterations = 0;

            do {
                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);
                $pageCountBefore = count($pages);
                $iterations = $iterations + 1;

                // get the parents of the unused pages.
                foreach ($idsOfMissingParentPages as $pageID) {
                    $postParent = get_post(is_scalar($pageID) ? (int)$pageID : 0);
                    if ($postParent == null) {
                        continue;
                    }
                    $parentPageSlug = $postParent->post_name;
                    $parentPage = $this->dao->getPublishedPagesAndPostsIDs($parentPageSlug);
                    if (count($parentPage) != 0) {
                        $pages[] = $parentPage[0];
                    }
                }

                if ($iterations > 30) {
                    break;
                }

                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);

                // loop until we can't find any more parents. This may happen if a sub-page is published
                // and the parent page is not published.
            } while ($pageCountBefore != count($pages));

            // sort everything again
            usort($pages, function (object $a, object $b): int {
                return $this->sortByTypeThenTitle($a, $b);
            });
            $orderedPages = $this->setDepthAndAddChildren($pages);
        }

        // if there are child pages left over then there's an issue. it means there's a child page that was
        // returned but the parent for that child was not returned. so we don't have any place to display
        // the child page. this could be because the parent page is not "published"
        if (count($orderedPages) != count($pages)) {
            $unusedPages = array_udiff($pages, $orderedPages, function (object $a, object $b): int {
                return $this->compareByID($a, $b);
            });
            $this->logger->debugMessage("There was an issue finding the parent pages for some child pages. " .
                    "These pages' parents may not have a 'published' status. Pages: " . 
                    wp_kses_post(json_encode($unusedPages) ?: ''));
        }
        
        return $orderedPages;
    }
    
    /** For custom categories we create a Map<String, List> where the key is the name
     * of the taxonomy and the list holds the rows that have the category info.
     * @param array<int, object{taxonomy: string, name?: string}> $categoryRows
     * @return array<string, array<int, object{taxonomy: string, name?: string}>>
     */
    function getMapOfCustomCategories(array $categoryRows): array {
        $customTagsEtc = array();

        foreach ($categoryRows as $cat) {
            $taxonomy = $cat->taxonomy;
            if ($taxonomy == 'category') {
                continue;
            }
            // for custom categories we create a Map<String, List> where the key is the name
            // of the taxonomy and the list holds the rows that have the category info.
            if (!array_key_exists($taxonomy, $customTagsEtc) || $customTagsEtc[$taxonomy] == null) {
                $customTagsEtc[$taxonomy] = array($cat);
            } else {
                array_push($customTagsEtc[$taxonomy], $cat);
            }
            
        }
        return $customTagsEtc;
    }
    
    /** Returns a list of parent IDs that can't be found in the passed in pages.
     * @param array<int, object> $pages
     * @return array<int, mixed>
     */
    function getMissingParentPageIDs(array $pages): array {
        $listOfIDs = array();
        $missingParentPageIDs = array();

        foreach ($pages as $page) {
            /** @var PageObject $page */
            $listOfIDs[] = $page->id;
        }

        foreach ($pages as $page) {
            /** @var PageObject $page */
            if ($page->post_parent == 0) {
                continue;
            }
            if (in_array($page->post_parent, $listOfIDs)) {
                continue;
            }
            
            $missingParentPageIDs[] = $page->post_parent;
        }

        $missingParentPageIDs = array_merge(
        	array_unique($missingParentPageIDs, SORT_REGULAR), array());
        return $missingParentPageIDs;
    }

    /**
     * Compare pages based on their ID.
     * @param object $a
     * @param object $b
     * @return int
     */
    function compareByID(object $a, object $b): int {
        /** @var PageObject $a */
        /** @var PageObject $b */
        if ($a->id < $b->id) {
            return -1;
        }
        if ($b->id < $a->id) {
            return 1;
        }
        return 0;
    }
    
    /** Set the depth of each page and add pages under their parents by rebuilding the list
     * every time we iterate through it and adding the child pages at the right moment every time
     * the list is built.
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function setDepthAndAddChildren(array $pages): array {
        // find all child pages (pages that have parents).
        $childPages = $this->findChildPages($pages);
        
        // find all pages with no parents.
        $mainPages = $this->findAllMainPages($pages);
        
        $oldChildPageCount = -1;
        
        // this do{} loop is here because some child pages have children.
        do {
            // add every page to a new list, while looking for parents.
            $orderedPages = array();
            foreach ($mainPages as $page) {
                /** @var PageObject $page */
                // always add the main page.
                $orderedPages[] = $page;

                // if this page is the parent of any children then add the children.
                $removeThese = array();
                foreach ($childPages as $child) {
                    /** @var PageObject $child */
                    if ($child->post_parent == $page->id) {
                        // set the page depth based on the parent's page depth.
                        $parentDepth = $page->depth;
                        /** @var \stdClass $childMut */
                        $childMut = $child;
                        $childMut->depth = $parentDepth + 1;

                        $removeThese[] = $child;
                        $orderedPages[] = $child;
                    }
                }
                
                // remove any child pages that have been placed already
                $childPages = $this->removeUsedChildPages($childPages, $removeThese);
            }
            
            // the new list becomes the list that we will iterate over next time. 
            // this prepares us for the next iteration and for child pages with a depth greater than 1.
            // (for child pages that have children).
            $mainPages = $orderedPages;
            
            // if the count has not changed then there's no point in looping again.
            if (count($childPages) == $oldChildPageCount) {
                break;
            }
            $oldChildPageCount = count($childPages);
            // stop the loop once there are no more children to add.
        } while (count($childPages) > 0);
        
        return $orderedPages;
    }
    
    /**
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function findAllMainPages(array $pages): array {
        $mainPages = array();
        foreach ($pages as $page) {
            /** @var PageObject $page */
            // if there's no parent then just add the page.
            if ($page->post_parent == 0) {
                $mainPages[] = $page;
            }
        }
        
        return $mainPages;
    }
    
    /**
     * @param array<int, object> $childPages
     * @param array<int, object> $removeThese
     * @return array<int, object>
     */
    function removeUsedChildPages(array $childPages, array $removeThese): array {
        // if any children were added then remove them from the list.
        foreach ($removeThese as $removeThis) {
            $key = array_search($removeThis, $childPages);
            if ($key !== false) {
                unset($childPages[$key]);
            }
        }
        
        return $childPages;
    }
    
    /** Return pages that have a non-0 parent.
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function findChildPages(array $pages): array {
        $childPages = array();
        foreach ($pages as $page) {
            /** @var PageObject $page */
            if ($page->post_parent != 0) {
                $childPages[] = $page;
            }
        }
        return $childPages;
    }

    /**
     * @param object $a
     * @param object $b
     * @return int
     */
    function sortByTypeThenTitle(object $a, object $b): int {
        /** @var PageObject $a */
        /** @var PageObject $b */
        // first sort by type
        $result = strcmp($a->post_type, $b->post_type);
        if ($result != 0) {
            return $result;
        }

        // then by title.
        return strcmp($a->post_title, $b->post_title);
    }

    /** Send an email if a notification should be displayed. Return true if an email is sent, or false otherwise.
     * @return string
     */
    function emailCaptured404Notification() {
        
        $options = $this->getOptions(true);
        
        $captured404Count = $this->dao->getCapturedCountForNotification();
        if (!$this->shouldNotifyAboutCaptured404s($captured404Count)) {
            return "Not enough 404s found to send an admin notification email (" . $captured404Count . ").";
        }
        
        $captured404URLSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_captured';
        $generalSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';
        $to = is_string($options['admin_notification_email']) ? $options['admin_notification_email'] : '';
        $subject = '404 Solution: Captured 404 Notification';
        $body = "There are currently " . $captured404Count . " captured 404s to look at. <BR/><BR/>\n\n";
        $body .= 'Visit <a href="' . $captured404URLSettings . '">' . $captured404URLSettings .
                '</a> to see them.<BR/><BR/>' . "\n";
        $body .= 'To stop getting these emails, update the settings at <a href="' . $generalSettings . '">' .
                $generalSettings . '</a>, or contact the site administrator.' . "<BR/>\n";
        $body .= "<BR/><BR/>\n\nSent " . date('Y/m/d h:i:s T') . "<BR/>\n" . "PHP version: " . PHP_VERSION .
                ", <BR/>\nPlugin version: " . ABJ404_VERSION;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $adminEmail = get_option('admin_email');
        $adminEmailStr = is_string($adminEmail) ? $adminEmail : '';
        $headers[] = 'From: ' . $adminEmailStr . '<' . $adminEmailStr . '>';

        // send the email
        $this->logger->debugMessage("Sending captured 404 notification email to: " . $to);
        wp_mail($to, $subject, $body, $headers);
        $this->logger->debugMessage("Captured 404 notification email sent.");
        return "Captured 404 notification email sent to: " . trim($to);
    }
    
    /** Return true if a notification should be displayed, or false otherwise.
     * @global type $abj404dao
     * @param number $captured404Count the number of captured 404s
     * @return boolean
     */
    function shouldNotifyAboutCaptured404s($captured404Count) {
        $options = $this->getOptions(true);
        
        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            if ($captured404Count >= $options['admin_notification']) {
                return true;
            }
        }
        
        return false;
    }
    
    /** 0|0 => "(Default 404 Page)"
     * 5|5 => "(Home Page)"
     * 10|1 => "About"
     * @param string $idAndType
     * @param string $externalLinkURL
     * @return string
     */
    function getPageTitleFromIDAndType($idAndType, $externalLinkURL) {
        
        if ($idAndType == '') {
            return '';
        }

        $meta = explode("|", $idAndType);
        $id = $meta[0];
        // Handle malformed data that doesn't contain a pipe separator
        $type = isset($meta[1]) ? $meta[1] : '';

        // Use strict comparison to avoid null/false == 0 issues with type coercion
        // Cast to int for comparison since ABJ404_TYPE_* constants are integers
        $typeInt = is_numeric($type) ? (int)$type : -1;

        if ($idAndType == ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED) {
            return __('(Default 404 Page)', '404-solution');
        } else if ($idAndType == ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME) {
            return __('(Home Page)', '404-solution');
        } else if ($typeInt === ABJ404_TYPE_EXTERNAL) {
            return $externalLinkURL;
        }

        $idInt = (int)$id;
        if ($typeInt === ABJ404_TYPE_POST) {
            return get_the_title($idInt);

        } else if ($typeInt === ABJ404_TYPE_CAT) {
            $rows = $this->dao->getPublishedCategories($idInt);
            if (empty($rows)) {
                $this->logger->debugMessage('No TERM (category) found with ID: ' . $id);
                return '';
            }
            $firstRow = $rows[0];
            return property_exists($firstRow, 'name') ? (string)$firstRow->name : '';

        } else if ($typeInt === ABJ404_TYPE_TAG) {
            $tag = get_tag($idInt);
            if (is_object($tag) && property_exists($tag, 'name')) {
                return (string)$tag->name;
            }
            return '';
        }

        $this->logger->errorMessage("Couldn't get page title. No matching type found for type: " . esc_html($type));
        return '';
    }
}
