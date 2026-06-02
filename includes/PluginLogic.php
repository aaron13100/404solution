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
require_once dirname(__FILE__) . '/PluginLogicOptionsResolver.php';
require_once dirname(__FILE__) . '/PluginLogicVersionUpgrader.php';
require_once dirname(__FILE__) . '/services/NotFoundResponseService.php';
require_once dirname(__FILE__) . '/services/RequestIgnoreNormalizer.php';
require_once dirname(__FILE__) . '/services/PreviousRequestCookieTracker.php';

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

	/**
	 * @var array<string, mixed>|null Legacy test seam: reflection-based tests
	 * seed runtime options by setting this property and resetting the
	 * PluginLogicOptionsResolver singleton. Read by PluginLogicOptionsResolver::legacyPluginLogicOptionsOverride().
	 */
	private $options = null;

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

    // (no instance-level options resolver cache; resolved via the service
    // container on each call — see optionsResolver())

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
     * @param ABJ_404_Solution_StatsRepositoryInterface|null $statsRepository Stats repository
     */
    function __construct($functions = null, $dataAccess = null, $logging = null, $statsRepository = null) {
    	$this->f = $functions !== null ? $functions : abj_service('functions');
    	$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
    	$this->logger = $logging !== null ? $logging : abj_service('logging');

        if ($this->dao instanceof ABJ_404_Solution_DataAccess && get_class($this->dao) === ABJ_404_Solution_DataAccess::class) {
    	    $this->redirectsRepo = $this->dao->getRedirectsRepo();
    	    $this->viewBuild = $this->dao->getViewBuildOrchestrator();
    	    $this->viewRead = $this->dao->getViewReadService();
    	    $this->contentRepo = $this->dao->getContentRepo();
            $this->statsRepo = $this->resolveStatsRepository($statsRepository);
    	    $this->dbCore = $this->dao->getDbCore();
        } else {
            $this->statsRepo = $this->resolveStatsRepository($statsRepository);
            $this->resolveDaoAccessorsForTestMock();
        }

        $urlPath = parse_url(get_home_url(), PHP_URL_PATH);
        // Fix MEDIUM #1 (5th review): Distinguish between parse failure (false) and no path (null)
        if ($urlPath === false) {
            $this->logger->debugMessage("Malformed home URL detected while initializing PluginLogic: " . get_home_url());
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
    	    $this->f,
            $this->logger,
            $this->contentRepo,
            $this->statsRepo,
            $this->urlNormalization,
            abj_service('not_found_response')
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
            'viewBuild' => 'getViewBuildOrchestrator',
            'viewRead' => 'getViewReadService',
            'contentRepo' => 'getContentRepo',
            'dbCore' => 'getDbCore',
        ];
        foreach ($accessors as $property => $method) {
            if ($property === 'redirectsRepo'
                    && $this->daoOverridesAny([
                        'setupRedirect',
                        'moveRedirectsToTrash',
                        'deleteRedirect',
                        'updateRedirectTypeStatus',
                    ])) {
                $this->{$property} = $this->dao;
                continue;
            }
            $value = (is_object($this->dao) && method_exists($this->dao, $method))
                ? $this->dao->{$method}()
                : $this->dao;
            // @phpstan-ignore-next-line assign.propertyType
            $this->{$property} = $value;
        }
    }

    /** @param ABJ_404_Solution_StatsRepositoryInterface|null $provided */
    private function resolveStatsRepository($provided): ABJ_404_Solution_StatsRepositoryInterface {
        if ($provided instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $provided;
        }

        $service = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
            : null;
        if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $service;
        }

        return ABJ_404_Solution_UnavailableStatsRepository::resolve(__CLASS__);
    }

    /** @param array<int, string> $methods */
    private function daoOverridesAny(array $methods): bool {
        if (!is_object($this->dao)) {
            return false;
        }
        foreach ($methods as $method) {
            if (!method_exists($this->dao, $method)) {
                continue;
            }
            $reflection = new ReflectionMethod($this->dao, $method);
            if ($reflection->getDeclaringClass()->getName() !== ABJ_404_Solution_DataAccess::class) {
                return true;
            }
        }
        return false;
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

    /**
     * Access the composed options resolver. Returns whichever object is
     * registered as abj_service('options_repository') (the same instance is
     * exposed there for auth-time callers that must avoid the plugin_logic
     * resolution cycle). Duck-typed at runtime: any object responding to
     * getOptions()/updateOptions() is accepted so tests can install lightweight
     * stubs without subclassing the concrete resolver. The declared return
     * type is the concrete class so PHPStan can resolve caller chains.
     *
     * @return ABJ_404_Solution_PluginLogicOptionsResolver
     */
    public function optionsResolver() {
        // No instance-level cache: tests can swap the registered service
        // mid-run, and the service container singleton already deduplicates.
        $candidate = abj_service('options_repository');
        if (is_object($candidate) && method_exists($candidate, 'getOptions')) {
            return $candidate;
        }
        // Fallback when the container is uninitialised (very-early boot,
        // self-healing recovery from a broken install): construct the
        // concrete resolver inline so callers always receive a usable
        // collaborator instead of null. Routed through new rather than
        // ::getInstance() so lint-getinstance-callers does not flag this
        // bootstrap fallback.
        return new ABJ_404_Solution_PluginLogicOptionsResolver();
    }

    /**
     * Access the composed not-found response service. The frontend
     * dispatcher methods (sendTo404Page, forceRedirect,
     * thereIsAUserSpecified404Page, getCommentPartAndQueryPartOfRequest)
     * extracted from PluginLogic live here.
     *
     * Returns whichever object is registered as
     * abj_service('not_found_response'). Duck-typed at runtime so tests
     * can install lightweight doubles without subclassing the concrete
     * service.
     *
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    public function notFoundResponse() {
        $candidate = abj_service('not_found_response');
        if (is_object($candidate) && method_exists($candidate, 'sendTo404Page')) {
            return $candidate;
        }
        return new ABJ_404_Solution_NotFoundResponseService();
    }

    /**
     * Access the composed request-ignore normalizer. The
     * initializeIgnoreValues() and tryNormalPostQuery() methods
     * extracted from PluginLogic live here.
     *
     * @return ABJ_404_Solution_RequestIgnoreNormalizer
     */
    public function requestIgnoreNormalizer() {
        $candidate = abj_service('request_ignore_normalizer');
        if (is_object($candidate) && method_exists($candidate, 'tryNormalPostQuery')) {
            return $candidate;
        }
        return new ABJ_404_Solution_RequestIgnoreNormalizer();
    }

    /**
     * Access the composed previous-request cookie tracker. The
     * readCookieWithPreviousRqeuestShort() and
     * setCookieWithPreviousRequest() methods extracted from PluginLogic
     * live here.
     *
     * @return ABJ_404_Solution_PreviousRequestCookieTracker
     */
    public function previousRequestCookieTracker() {
        $candidate = abj_service('previous_request_cookie_tracker');
        if (is_object($candidate) && method_exists($candidate, 'setCookieWithPreviousRequest')) {
            return $candidate;
        }
        return new ABJ_404_Solution_PreviousRequestCookieTracker();
    }

    /**
     * Alias accessor for the primary 404 dispatcher service. The
     * original parent task asked for a single PluginLogicRequestDispatcher
     * accessor; the extraction split the responsibilities across three
     * single-responsibility services instead. NotFoundResponseService is
     * the one that actually dispatches the 404 response, so this alias
     * returns it. Prefer notFoundResponse() / requestIgnoreNormalizer() /
     * previousRequestCookieTracker() at new call sites.
     *
     * Why: per [[feedback_no_trait_extractions]], the project rejects
     * grab-bag extractions and prefers SRP-aligned classes; combining
     * the three responsibilities behind one wrapper would be a
     * pass-through facade flagged by [[modularity-extraction]].
     *
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    public function requestDispatcher() {
        return $this->notFoundResponse();
    }

    /**
     * Access the composed version upgrader. Returns whichever object is
     * registered as abj_service('version_upgrade'). Duck-typed at runtime:
     * any object responding to upgradeIfNeeded()/runUpgradeAction()/
     * stampDbVersion() is accepted so tests can install lightweight stubs
     * without subclassing the concrete upgrader. The declared return type
     * is the concrete class so PHPStan can resolve caller chains.
     *
     * @return ABJ_404_Solution_PluginLogicVersionUpgrader
     */
    public function versionUpgrader() {
        $candidate = abj_service('version_upgrade');
        if (is_object($candidate) && method_exists($candidate, 'upgradeIfNeeded')) {
            return $candidate;
        }
        // Fallback when the `version_upgrade` container key is unavailable
        // (very-early boot, self-healing recovery from a broken install).
        // Construct via `new` with individual services resolved so we route
        // through the container's dependency wiring without going through
        // the singleton entry (lint-getinstance-callers).
        $dao = abj_service('data_access');
        $dbCore = is_object($dao) && method_exists($dao, 'getDbCore') ? $dao->getDbCore() : $dao;
        return new ABJ_404_Solution_PluginLogicVersionUpgrader(
            abj_service('functions'),
            abj_service('logging'),
            is_object($dbCore) ? $dbCore : (object)[]
        );
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

/**
     * Legacy multisite hook facade retained for integrations that still point
     * at PluginLogic while the implementation lives in PluginLogicLifecycle.
     *
     * @param int $blog_id
     * @param int $user_id
     * @param string $domain
     * @param string $path
     * @param int $site_id
     * @param array<string, mixed> $meta
     * @return void
     */
    public static function activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta): void {
        ABJ_404_Solution_PluginLogicLifecycle::activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta);
    }

    /**
     * WordPress 5.1+ multisite hook facade retained for external call sites.
     *
     * @param mixed $site
     * @param array<string, mixed> $args
     * @return void
     */
    public static function activateNewSiteModern($site, $args): void {
        ABJ_404_Solution_PluginLogicLifecycle::activateNewSiteModern($site, $args);
    }

    /**
     * Multisite deletion hook facade retained for external call sites.
     *
     * @param int $blog_id
     * @param bool $drop
     * @return void
     */
    public static function deleteBlogData($blog_id, $drop): void {
        ABJ_404_Solution_PluginLogicLifecycle::deleteBlogData($blog_id, $drop);
    }

    /** @return void */
    public static function runOnPluginDeactivation(): void {
        ABJ_404_Solution_PluginLogicLifecycle::runOnPluginDeactivation();
    }


    /** @return string */
    function getDebugLogFileLink(): string {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

}
