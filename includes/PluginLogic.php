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


    /** @return string */
    function getDebugLogFileLink(): string {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

}
