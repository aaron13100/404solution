<?php


if (!defined('ABSPATH')) {
    exit;
}

/* the glue that holds it together / everything else. */

require_once dirname(__FILE__) . '/PluginLogicUrlNormalization.php';
require_once dirname(__FILE__) . '/PluginLogicAdminActions.php';
require_once dirname(__FILE__) . '/PluginLogicImportExport.php';
require_once dirname(__FILE__) . '/PluginLogicSettingsUpdate.php';
require_once dirname(__FILE__) . '/PluginLogicPageOrdering.php';
require_once dirname(__FILE__) . '/PluginLogicLifecycle.php';
require_once dirname(__FILE__) . '/PluginLogicDefaults.php';
require_once dirname(__FILE__) . '/PluginLogicInterface.php';
require_once dirname(__FILE__) . '/StorageOptionContracts.php';

/**
 * @phpstan-type PageObject object{id: int, post_parent: int, depth: int, post_type: string, post_title: string}
 */
class ABJ_404_Solution_PluginLogic implements ABJ_404_Solution_PluginLogicInterface {

	/** @var ABJ_404_Solution_Functions */
	private $f = null;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao = null;

	/** @var ABJ_404_Solution_Logging */
	private $logger = null;

	/** @var ABJ_404_Solution_RedirectsRepositoryInterface */
	private $redirectsRepo;

	/** @var ABJ_404_Solution_LogsRepositoryInterface */
	private $logsRepo;

	/** @var ABJ_404_Solution_ViewBuildOrchestratorInterface */
	private $viewBuild;

	/** @var ABJ_404_Solution_ViewReadServiceInterface */
	private $viewRead;

	/** @var ABJ_404_Solution_ContentRepositoryInterface */
	private $contentRepo;

	/** @var ABJ_404_Solution_StatsRepositoryInterface */
	private $statsRepo;

	/** @var ABJ_404_Solution_DatabaseCoreInterface */
	private $dbCore;

	/** @var ABJ_404_Solution_ImportExportService|null */
	private $importExportService = null;

	/** @var string|null */
	private $urlHomeDirectory = null;

	/** @var int|null */
	private $urlHomeDirectoryLength = null;

	/** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $adminActions;

    /** @var ABJ_404_Solution_PluginLogicImportExport */
    private $importExport;

    /** @var ABJ_404_Solution_PluginLogicSettingsUpdate */
    private $settingsUpdate;

    /** @var ABJ_404_Solution_PluginLogicPageOrdering */
    private $pageOrdering;

    /** @return ABJ_404_Solution_PluginLogic The singleton instance of the class. */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $resolved = ABJ_404_Solution_ServiceContainer::safeGet('plugin_logic');
            if ($resolved instanceof self) {
                self::$instance = $resolved;
                return self::$instance;
            }
        }

    	self::$instance = new ABJ_404_Solution_PluginLogic();

    	// these filters allow non-admins to have admin access to the plugin.
    	add_filter( 'user_has_cap',
    		'ABJ_404_Solution_PluginAdminAccessPolicy::wpUserHasCapFilter', 10, 4 );

    	return self::$instance;
    }

    /**
     * Constructor with dependency injection.
     *
     * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
     * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     */
    function __construct($functions = null, $dataAccess = null, $logging = null) {
    	$this->f = $functions !== null ? $functions : abj_service('functions');
    	$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
    	$this->logger = $logging !== null ? $logging : abj_service('logging');

        if ($this->dao instanceof ABJ_404_Solution_DataAccess && get_class($this->dao) === ABJ_404_Solution_DataAccess::class) {
    	    $this->redirectsRepo = $this->dao->getRedirectsRepo();
    	    $this->logsRepo = $this->dao->getLogsRepo();
    	    $this->viewBuild = $this->dao->getViewBuildOrchestrator();
    	    $this->viewRead = $this->dao->getViewReadService();
    	    $this->contentRepo = $this->dao->getContentRepo();
    	    $this->statsRepo = $this->dao->getStatsRepo();
    	    $this->dbCore = $this->dao->getDbCore();
        } else {
            $this->resolveDaoAccessorsForTestMock();
        }

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

    	// Initialize standalone composition classes
    	$this->urlNormalization = new ABJ_404_Solution_PluginLogicUrlNormalization(
    	    $this->f, $this->urlHomeDirectory, $this->urlHomeDirectoryLength
    	);

    	$self = $this;
    	$this->importExport = new ABJ_404_Solution_PluginLogicImportExport(function() use ($self) {
    	    return $self->getImportExportService();
    	});

    	$this->settingsUpdate = new ABJ_404_Solution_PluginLogicSettingsUpdate(
    	    $this->f, $this->logger, $this->contentRepo, $this
    	);

    	$this->pageOrdering = new ABJ_404_Solution_PluginLogicPageOrdering(
    	    $this->f, $this->logger, $this->contentRepo, $this->statsRepo, $this->urlNormalization, $this
    	);

    	$this->adminActions = new ABJ_404_Solution_PluginLogicAdminActions(
    	    new ABJ_404_Solution_AdminActionsDependencies(
    	        $this->f, $this->logger, $this->redirectsRepo, $this->viewBuild, $this->viewRead,
    	        $this->contentRepo, $this->dbCore, $this->dao, $this->urlNormalization, $this
    	    )
    	);
    }

    /**
     * Polymorphic test-mock fallback: $this->dao may be a mock implementing the repo
     * getters, OR a mock that stands in for individual repos directly. Either shape is
     * accepted at runtime; PHPStan cannot follow this without per-assignment markers.
     * @return void
     */
    private function resolveDaoAccessorsForTestMock(): void {
        $accessors = [
            'redirectsRepo' => 'getRedirectsRepo',
            'logsRepo' => 'getLogsRepo',
            'viewBuild' => 'getViewBuildOrchestrator',
            'viewRead' => 'getViewReadService',
            'contentRepo' => 'getContentRepo',
            'statsRepo' => 'getStatsRepo',
            'dbCore' => 'getDbCore',
        ];
        foreach ($accessors as $property => $method) {
            $value = (is_object($this->dao) && method_exists($this->dao, $method))
                ? $this->dao->{$method}()
                : $this->dao;
            // @phpstan-ignore-next-line assign.propertyType
            $this->{$property} = $value;
        }
    }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    public function urlNormalization() {
        return $this->urlNormalization;
    }

    /** @return ABJ_404_Solution_PluginLogicAdminActions */
    public function adminActions() {
        return $this->adminActions;
    }

    /** @return ABJ_404_Solution_PluginLogicImportExport */
    public function importExport() {
        return $this->importExport;
    }

    /** @return ABJ_404_Solution_PluginLogicSettingsUpdate */
    public function settingsUpdate() {
        return $this->settingsUpdate;
    }

    /** @return ABJ_404_Solution_PluginLogicPageOrdering */
    public function pageOrdering() {
        return $this->pageOrdering;
    }

    /** @return ABJ_404_Solution_ImportExportService */
    private function getImportExportService() {
        if ($this->importExportService !== null) {
            return $this->importExportService;
        }

        if (!class_exists('ABJ_404_Solution_ImportExportService')) {
            require_once dirname(__FILE__) . '/ImportExportService.php';
        }

        $this->importExportService = new ABJ_404_Solution_ImportExportService(
            abj_service('view_read_service'),
            abj_service('redirects_repository'),
            abj_service('content_repository'),
            $this->logger
        );
        return $this->importExportService;
    }

    /** Instance counterpart used by the Data layer through PluginLogicInterface. */
    public function registerCrons(): void {
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }


    /** Forward to a real page for queries like ?p=10
     * @param array<string, mixed> $options
     * @return void
     */
    function tryNormalPostQuery(array $options): void {
        global $wp_query;

        $query = $wp_query->query;
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
                $redirect = $this->redirectsRepo->getExistingRedirectForURL($fromURL);
                $defaultRedirect = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
                if (!isset($redirect['id']) || $redirect['id'] == 0) {
                    $this->redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                        $fromURL, (string)ABJ404_STATUS_AUTO, (string)ABJ404_TYPE_POST,
                        (string)$pageid, $defaultRedirect, 0, 'page ID'
                    ));
                }
                $this->logsRepo->logRedirectHit($fromURL, $permalink, 'page ID');
                abj_service('not_found_response')->forceRedirect($permalink, (int)$defaultRedirect);
                exit;
            }
        }
    }

    /**
     * @param string $urlRequest the requested URL
     * @param string $urlSlugOnly only the slug
     * @return void
     */
    function initializeIgnoreValues(string $urlRequest, string $urlSlugOnly): void {
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $ignoreReasonDoNotProcess = null;
        $ignoreReasonDoProcess = null;
        $httpUserAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ?
                $this->f->strtolower($_SERVER['HTTP_USER_AGENT']) : '';

        $adminURLRaw = parse_url(admin_url(), PHP_URL_PATH);
        $adminURL = is_string($adminURLRaw) ? $adminURLRaw : '/wp-admin/';
        if (is_admin() || $this->f->substr($urlRequest, 0, $this->f->strlen($adminURL)) == $adminURL) {
            $this->logger->debugMessage("Ignoring admin URL: " . $urlRequest);
            $ignoreReasonDoNotProcess = 'Admin URL';
        }

        $ignoreDontProcess = is_string($options['ignore_dontprocess']) ? $options['ignore_dontprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDontProcess);

        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $this->logger->debugMessage("Ignoring user agent (do not redirect): " .
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoNotProcess = 'User agent (do not redirect): ' . esc_html($_SERVER['HTTP_USER_AGENT']);
            }
        }

        $patternsToIgnore = is_array($options['folders_files_ignore_usable']) ? $options['folders_files_ignore_usable'] : array();
        if (!empty($patternsToIgnore)) {
            foreach ($patternsToIgnore as $patternToIgnore) {
                $patternToIgnoreStr = is_string($patternToIgnore) ? $patternToIgnore : (string)$patternToIgnore;
                $patternToIgnoreNoSlashes = stripslashes($patternToIgnoreStr);
                abj_service('request_context')->debug_info = 'Applying regex pattern to ignore\"' .
                    $patternToIgnoreNoSlashes . '" to URL slug: ' . $urlSlugOnly;
                $matches = array();
                if ($this->f->regexMatch($patternToIgnoreNoSlashes, $urlSlugOnly, $matches)) {
                    $this->logger->debugMessage("Ignoring file/folder (do not redirect) for URL: " .
                            esc_html($urlSlugOnly) . ", pattern used: " . $patternToIgnoreNoSlashes);
                    $ignoreReasonDoNotProcess = 'Files and folders (do not redirect) pattern: ' .
                        esc_html($patternToIgnoreNoSlashes);
                }
                abj_service('request_context')->debug_info = 'Cleared after regex pattern to ignore.';
            }
        }
        abj_service('request_context')->ignore_donotprocess = is_string($ignoreReasonDoNotProcess) ? $ignoreReasonDoNotProcess : false;

        $ignoreDoProcess = is_string($options['ignore_doprocess']) ? $options['ignore_doprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDoProcess);

        foreach ($userAgents as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $this->logger->debugMessage("Ignoring user agent (process ok): " .
                        esc_html($_SERVER['HTTP_USER_AGENT']) . " for URL: " . esc_html($urlRequest));
                $ignoreReasonDoProcess = 'User agent (process ok): ' . $agentToIgnore;
            }
        }
        abj_service('request_context')->ignore_doprocess = is_string($ignoreReasonDoProcess) ? $ignoreReasonDoProcess : false;
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

    /** @return void */
    function setCookieWithPreviousRequest(): void {

        $requested_url_raw = $this->f->normalizeUrlString($_SERVER['REQUEST_URI']);

        $requested_url_cleaned = preg_replace('/\?.*$/', '', $requested_url_raw);
        $requested_url = is_string($requested_url_cleaned) ? $requested_url_cleaned : $requested_url_raw;

    	$cookieName = ABJ404_PP . '_REQUEST_URI';
    	$cookieNameShort = $cookieName . '_SHORT';
    	try {
    		setcookie($cookieName, $requested_url, time() + (60 * 4), "/");
    		setcookie($cookieNameShort, $requested_url, time() + (5), "/");

    		if (!isset($_COOKIE[$cookieName . '_UPDATE_URL']) ||
    				empty($_COOKIE[$cookieName . '_UPDATE_URL'])) {
    			$update_url_raw = $this->f->normalizeUrlString($_SERVER['REQUEST_URI']);
    			$update_url_cleaned = preg_replace('/\?.*$/', '', $update_url_raw);
    			$update_url = is_string($update_url_cleaned) ? $update_url_cleaned : $update_url_raw;
    			setcookie($cookieName . '_UPDATE_URL', $update_url,
    				time() + (60 * 4), "/");
    		}

    	} catch (Exception $e) {
    		$this->logger->debugMessage("There was an issue setting a cookie: " . $e->getMessage());
    		$expireTime = date("D, d M Y H:i:s T", time() + (60 * 4));
    		$c = "\n" . '<script>document.cookie = "' . $cookieName . '=' .
     		esc_js($requested_url) .
     		'; expires=' . $expireTime . '";</script>' . "\n";
     		echo $c;
    	}

    	abj_service('request_context')->requested_url = $requested_url;
    }

    /**
     * @param bool $skip_db_check
     * @return array<string, mixed>
     * @deprecated Use abj_service('options_repository')->getOptions() directly. This delegate exists only during the migration window for the OptionsRepository extraction and will be removed before the parent task closes.
     */
    function getOptions(bool $skip_db_check = false) {
        return abj_service('options_repository')->getOptions($skip_db_check);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     * @deprecated Use abj_service('options_repository')->updateOptions() directly. This delegate exists only during the migration window for the OptionsRepository extraction and will be removed before the parent task closes.
     */
    function updateOptions(array $options): void {
        abj_service('options_repository')->updateOptions($options);
    }

    /** @return string */
    function getDebugLogFileLink(): string {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

}
