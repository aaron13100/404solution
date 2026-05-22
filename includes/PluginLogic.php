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
    	self::$uniqID = uniqid("", true);

    	// these filters allow non-admins to have admin access to the plugin.
    	add_filter( 'user_has_cap',
    		'ABJ_404_Solution_PluginLogic::override_user_can_access_admin_page', 10, 4 );

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
            $this->redirectsRepo = $this->dao;
            $this->logsRepo = $this->dao;
            $this->viewBuild = $this->dao;
            $this->viewRead = $this->dao;
            $this->contentRepo = $this->dao;
            $this->statsRepo = $this->dao;
            $this->dbCore = $this->dao;
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
    	    $this->f, $this->logger, $this->redirectsRepo, $this->viewBuild, $this->viewRead,
    	    $this->contentRepo, $this->dbCore, $this->dao, $this->urlNormalization, $this
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

    // =========================================================================
    // Delegation: UrlNormalization
    // =========================================================================

    /** @param string|null $urlRequest @return string */
    function removeHomeDirectory($urlRequest): string {
        if (!$this->urlNormalization instanceof ABJ_404_Solution_PluginLogicUrlNormalization) {
            $this->urlNormalization = new ABJ_404_Solution_PluginLogicUrlNormalization(
                $this->f !== null ? $this->f : abj_service('functions'),
                $this->urlHomeDirectory !== null ? $this->urlHomeDirectory : '',
                $this->urlHomeDirectoryLength !== null ? $this->urlHomeDirectoryLength : 0
            );
        }
        return $this->urlNormalization->removeHomeDirectory($urlRequest);
    }

    /** @param string|null $url @return string */
    function normalizeToRelativePath($url): string {
        if (!$this->urlNormalization instanceof ABJ_404_Solution_PluginLogicUrlNormalization) {
            $this->urlNormalization = new ABJ_404_Solution_PluginLogicUrlNormalization(
                $this->f !== null ? $this->f : abj_service('functions'),
                $this->urlHomeDirectory !== null ? $this->urlHomeDirectory : '',
                $this->urlHomeDirectoryLength !== null ? $this->urlHomeDirectoryLength : 0
            );
        }
        return $this->urlNormalization->normalizeToRelativePath($url);
    }

    /** @param string|null $url @return array<int, string> */
    function getNormalizedUrlCandidates($url) {
        if (!$this->urlNormalization instanceof ABJ_404_Solution_PluginLogicUrlNormalization) {
            $decoded = $this->normalizeToRelativePath($url);
            if ($decoded === '') {
                return array();
            }
            $candidates = array($decoded);
            $lower = function_exists('mb_strtolower') ? mb_strtolower($decoded, 'UTF-8') : strtolower($decoded);
            if ($lower !== $decoded) {
                $candidates[] = $lower;
            }
            $rawDecoded = is_string($url) ? rawurldecode($url) : '';
            if ($rawDecoded !== '' && $rawDecoded !== $decoded) {
                $candidates[] = $this->normalizeToRelativePath($rawDecoded);
            }
            return array_values(array_unique($candidates));
        }
        return $this->urlNormalization->getNormalizedUrlCandidates($url);
    }

    /** @param string $location @param string $requestedURL @return string */
    function maybeTranslateRedirectUrl($location, $requestedURL = '') {
        return $this->urlNormalization->maybeTranslateRedirectUrl($location, $requestedURL);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateWordPressSettings(array &$options, array $postData): string {
        return $this->settingsUpdate->updateWordPressSettings($options, $postData);
    }

    public function updateDeletionSettings(array &$options, array $postData): string {
        return $this->settingsUpdate->updateDeletionSettings($options, $postData);
    }

    public function updateSuggestionSettings(array &$options, array $postData): string {
        return $this->settingsUpdate->updateSuggestionSettings($options, $postData);
    }

    public function updateBooleanToggles(array &$options, array $postData): string {
        return $this->settingsUpdate->updateBooleanToggles($options, $postData);
    }

    public function translatePressIntegrationAvailable(): bool {
        return $this->urlNormalization->translatePressIntegrationAvailable();
    }

    public function translatePressRedirectUrl(string $location, string $requestedURL) {
        return $this->urlNormalization->translatePressRedirectUrl($location, $requestedURL);
    }

    public function getTranslatePressLanguageFromRequest(string $requestedURL): string {
        return $this->urlNormalization->getTranslatePressLanguageFromRequest($requestedURL);
    }

    public function translatePressTranslateUrl(string $url, string $language) {
        return $this->urlNormalization->translatePressTranslateUrl($url, $language);
    }

    public function buildFullUrlFromRequest(string $requestedURL): string {
        return $this->urlNormalization->buildFullUrlFromRequest($requestedURL);
    }

    public function isLocalUrl(string $url): bool {
        return $this->urlNormalization->isLocalUrl($url);
    }

    // =========================================================================
    // Delegation: Lifecycle (static forwarding)
    // =========================================================================

    /** @return void */
    static function doUnregisterCrons(): void {
        ABJ_404_Solution_PluginLogicLifecycle::doUnregisterCrons();
    }

    /** @param bool $network_wide @return void */
    static function runOnPluginActivation(bool $network_wide = false): void {
        ABJ_404_Solution_PluginLogicLifecycle::runOnPluginActivation($network_wide);
    }

    /** @return void */
    static function networkActivationCronHandler(): void {
        ABJ_404_Solution_PluginLogicLifecycle::networkActivationCronHandler();
    }

    /**
     * @param int $blog_id
     * @param int $user_id
     * @param string $domain
     * @param string $path
     * @param int $site_id
     * @param array<string, mixed> $meta
     * @return void
     */
    static function activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta): void {
        ABJ_404_Solution_PluginLogicLifecycle::activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta);
    }

    /** @param mixed $site @param array<string, mixed> $args @return void */
    static function activateNewSiteModern($site, $args): void {
        ABJ_404_Solution_PluginLogicLifecycle::activateNewSiteModern($site, $args);
    }

    /** @param bool $network_wide @return void */
    static function runOnPluginDeactivation(bool $network_wide = false): void {
        ABJ_404_Solution_PluginLogicLifecycle::runOnPluginDeactivation($network_wide);
    }

    /** @param int $blog_id @param bool $drop @return void */
    static function deleteBlogData($blog_id, $drop = false): void {
        ABJ_404_Solution_PluginLogicLifecycle::deleteBlogData($blog_id, $drop);
    }

    /** @return void */
    static function doRegisterCrons(): void {
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }

    // =========================================================================
    // Delegation: ImportExport
    // =========================================================================

    /** @return string */
    function getExportFilename(string $format = 'native'): string {
        return $this->importExport->getExportFilename($format);
    }

    /** @return void */
    function doExport(): void {
        $this->importExport->doExport();
    }

    /** @param string $sourceFile @param string $destinationFile @return string */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        return $this->importExport->convertExportCsvToRedirectionFormat($sourceFile, $destinationFile);
    }

    /** @return string */
    function doImportFile(): string {
        return $this->importExport->doImportFile();
    }

    /** @param array<string, mixed> $dataArray @param bool $dryRun @return array<int, string> */
    function loadDataArrayFromFile(array $dataArray, bool $dryRun = false): array {
        return $this->importExport->loadDataArrayFromFile($dataArray, $dryRun);
    }

    /** @return array<string, string> */
    function splitCsvLine(string $line): array {
        return $this->importExport->splitCsvLine($line);
    }

    /** @param array<int, string> $columns @return bool */
    function isCompatibleImportHeaderRow(array $columns): bool {
        return $this->importExport->isCompatibleImportHeaderRow($columns);
    }

    /** @param array<int, string> $columns @return array<int, string> */
    function normalizeImportHeaders(array $columns): array {
        return $this->importExport->normalizeImportHeaders($columns);
    }

    /** @param array<int, string> $row @param array<int, string> $normalizedHeaders @return array<string, string> */
    function mapImportRowByHeaders(array $row, array $normalizedHeaders): array {
        return $this->importExport->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /** @param array<int, string> $columns @return string */
    function detectImportFormatFromHeaders(array $columns): string {
        return $this->importExport->detectImportFormatFromHeaders($columns);
    }

    // =========================================================================
    // Delegation: AdminActions
    // =========================================================================

    /** @param string $action @param string $sub @return string */
    function handlePluginAction($action, &$sub) {
        return $this->adminActions->handlePluginAction($action, $sub);
    }

    /** @return string */
    function hanldeTrashAction() {
        return $this->adminActions->hanldeTrashAction();
    }

    /** @return void */
    function handleActionChangeItemsPerRow(): void {
        $this->adminActions->handleActionChangeItemsPerRow();
    }

    /** @return void */
    function handleActionExport(): void {
        $this->adminActions->handleActionExport();
    }

    /** @return string|null */
    function handleActionImportFile() {
        return $this->adminActions->handleActionImportFile();
    }

    /** @return void */
    function updatePerPageOption(int $rows): void {
        $this->adminActions->updatePerPageOption($rows);
    }

    /** @return string */
    function handleActionImportRedirects() {
        return $this->adminActions->handleActionImportRedirects();
    }

    /** @return string */
    function handleDeleteAction() {
        return $this->adminActions->handleDeleteAction();
    }

    /** @return string */
    function handleIgnoreAction() {
        return $this->adminActions->handleIgnoreAction();
    }

    /** @return string */
    function handleLaterAction() {
        return $this->adminActions->handleLaterAction();
    }

    /** @param string $sub @param string $action @return string */
    function handleActionEdit(&$sub, &$action) {
        return $this->adminActions->handleActionEdit($sub, $action);
    }

    /** @param string $action @param array<int, int> $ids @return string */
    function doBulkAction(string $action, array $ids): string {
        return $this->adminActions->doBulkAction($action, $ids);
    }

    /** @param string $sub @return void */
    function doEmptyTrash(string $sub): void {
        $this->adminActions->doEmptyTrash($sub);
    }

    /** @return string */
    function updateRedirectData() {
        return $this->adminActions->updateRedirectData();
    }

    /** @return array<string, mixed> */
    function getRedirectTypeAndDest(): array {
        return $this->adminActions->getRedirectTypeAndDest();
    }

    /** @return string */
    function addAdminRedirect() {
        return $this->adminActions->addAdminRedirect();
    }

    /** @return string */
    function handleActionUndoRegexAutoPromote() {
        return $this->adminActions->handleActionUndoRegexAutoPromote();
    }

    // =========================================================================
    // Delegation: SettingsUpdate
    // =========================================================================

    /** @param string $pageBeingViewed @return array<string, mixed> */
    function getTableOptions(string $pageBeingViewed): array {
        return $this->settingsUpdate->getTableOptions($pageBeingViewed);
    }

    /** @param array<string, mixed> $postData @param bool $restoreNewlines @return array<string, mixed> */
    function sanitizePostData(array $postData, bool $restoreNewlines = false): array {
        return $this->settingsUpdate->sanitizePostData($postData, $restoreNewlines);
    }

    /** @param string $str @return string */
    function sanitizeForSQL($str) {
        return $this->settingsUpdate->sanitizeForSQL($str);
    }

    /** @return array<string, mixed> */
    function updateOptionsFromPOST() {
        return $this->settingsUpdate->updateOptionsFromPOST();
    }

    /** @param array<string, mixed> $options @return bool */
    function normalizeSuggestionTemplateOptions(array &$options): bool {
        return $this->settingsUpdate->normalizeSuggestionTemplateOptions($options);
    }

    // =========================================================================
    // Delegation: PageOrdering
    // =========================================================================

    /** @param string $location @param string $requestedURL @param bool $isCustom404 @return string */
    public function buildFinalRedirectDestination($location, $requestedURL = '', $isCustom404 = false) {
        return $this->pageOrdering->buildFinalRedirectDestination($location, $requestedURL, $isCustom404);
    }

    /** @param array<int, object> $pages @param bool $includeMissingParentPages @return array<int, object> */
    function orderPageResults(array $pages, bool $includeMissingParentPages = false): array {
        return $this->pageOrdering->orderPageResults($pages, $includeMissingParentPages);
    }

    /** @param array<int, object{taxonomy: string, name?: string}> $categoryRows @return array<string, array<int, object{taxonomy: string, name?: string}>> */
    function getMapOfCustomCategories(array $categoryRows): array {
        return $this->pageOrdering->getMapOfCustomCategories($categoryRows);
    }

    /** @param array<int, object> $pages @return array<int, mixed> */
    function getMissingParentPageIDs(array $pages): array {
        return $this->pageOrdering->getMissingParentPageIDs($pages);
    }

    /** @param object $a @param object $b @return int */
    function compareByID(object $a, object $b): int {
        return $this->pageOrdering->compareByID($a, $b);
    }

    /** @param array<int, object> $pages @return array<int, object> */
    function setDepthAndAddChildren(array $pages): array {
        return $this->pageOrdering->setDepthAndAddChildren($pages);
    }

    /** @param array<int, object> $pages @return array<int, object> */
    function findAllMainPages(array $pages): array {
        return $this->pageOrdering->findAllMainPages($pages);
    }

    /** @param array<int, object> $childPages @param array<int, object> $removeThese @return array<int, object> */
    function removeUsedChildPages(array $childPages, array $removeThese): array {
        return $this->pageOrdering->removeUsedChildPages($childPages, $removeThese);
    }

    /** @param array<int, object> $pages @return array<int, object> */
    function findChildPages(array $pages): array {
        return $this->pageOrdering->findChildPages($pages);
    }

    /** @param object $a @param object $b @return int */
    function sortByTypeThenTitle(object $a, object $b): int {
        return $this->pageOrdering->sortByTypeThenTitle($a, $b);
    }

    /** @return string */
    function emailCaptured404Notification() {
        return $this->pageOrdering->emailCaptured404Notification();
    }

    /** @param number $captured404Count @return boolean */
    function shouldNotifyAboutCaptured404s($captured404Count) {
        return $this->pageOrdering->shouldNotifyAboutCaptured404s($captured404Count);
    }

    /** @param string $idAndType @param string $externalLinkURL @return string */
    function getPageTitleFromIDAndType($idAndType, $externalLinkURL) {
        return $this->pageOrdering->getPageTitleFromIDAndType($idAndType, $externalLinkURL);
    }

    // =========================================================================
    // Methods that remain on PluginLogic (not from traits)
    // =========================================================================

    /** This replaces the current_user_can('administrator') function.
     * @return bool true if $abj404logic->userIsPluginAdmin()
     */
    function userIsPluginAdmin() {
    	if (ABJ_404_Solution_PluginLogic::$checkingIsAdmin) {
    		return false;
    	}

    	ABJ_404_Solution_PluginLogic::$checkingIsAdmin = true;
    	try {
    		$options = $this->getOptions(true);
    		$f = $this->f;
    		global $current_user;

    		$isPluginAdmin = current_user_can('manage_options') || current_user_can('administrator');
    		if (function_exists('is_multisite') && is_multisite() && function_exists('is_super_admin') && is_super_admin()) {
    			$isPluginAdmin = true;
    		}

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

    		$filtered = apply_filters('abj404_userIsPluginAdmin', $isPluginAdmin);

    		if (!$filtered || ($filtered !== $isPluginAdmin)) {
    			$extraAdminsSummary = '';
    			$rawExtra = $options['plugin_admin_users'] ?? array();
    			if (is_array($rawExtra)) {
    				$extraAdminsSummary = implode(', ', array_filter($rawExtra));
    			} else if (is_string($rawExtra)) {
    				$extraAdminsSummary = $rawExtra;
    			}

    			$this->logger->debugMessage(
    				"userIsPluginAdmin detail: result=" . ($filtered ? 'true' : 'false') .
    				", pre-filter=" . ($isPluginAdmin ? 'true' : 'false') .
    				", manage_options=" . (current_user_can('manage_options') ? 'yes' : 'no') .
    				", user=" . ($current_user_name ?? '(none)') .
    				", plugin_admin_users=[" . esc_html($extraAdminsSummary) . "]" .
    				($filtered !== $isPluginAdmin ? ", NOTE: abj404_userIsPluginAdmin filter changed the result" : "")
    			);
    		}

    		return $filtered;
    	} finally {
    		ABJ_404_Solution_PluginLogic::$checkingIsAdmin = false;
    	}
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
    	if (!is_admin()) {
    		return $allcaps;
    	}

    	$abj404logic = abj_service('plugin_logic');

    	$isPluginAdmin = false;
    	$isViewing404AdminPage = false;

    	if ($abj404logic->userIsPluginAdmin()) {
    		$isPluginAdmin = true;
    	}

    	if ($isPluginAdmin) {
    		$userRequest = ABJ_404_Solution_UserRequest::getInstance();
    		$queryParts = $userRequest !== null ? $userRequest->getQueryString() : null;

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
                    $this->redirectsRepo->setupRedirect($fromURL, (string)ABJ404_STATUS_AUTO, (string)ABJ404_TYPE_POST,
                            (string)$pageid, $defaultRedirect, 0, 'page ID');
                }
                $this->logsRepo->logRedirectHit($fromURL, $permalink, 'page ID');
                $this->forceRedirect($permalink, (int)$defaultRedirect);
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
     * @param string $requestedURL
     * @param string $reason
     * @param bool $useUserSpecified404
     * @param array<string, mixed>|null $optionsOverride
     * @return void
     */
    function sendTo404Page(string $requestedURL, string $reason = '', bool $useUserSpecified404 = true, $optionsOverride = null): void {
        $abj404logic = abj_service('plugin_logic');

        $options = (is_array($optionsOverride) ? $optionsOverride : $abj404logic->getOptions());

        $behavior = isset($options['dest404_behavior']) ? $options['dest404_behavior'] : '';
        if ($behavior === 'suggest') {
            $systemPage = ABJ_404_Solution_SystemPage::getInstance();
            if (!$systemPage->systemPageExists()) {
                $systemPage->handleSystemPageDeleted();
                $options = $this->getOptions(true);
            }
        }

        $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
        $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);

        if ($useUserSpecified404 && $this->thereIsAUserSpecified404Page($dest404page)) {
           	$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0,
           		null, $options);

            if (!in_array($permalink['status'], array('publish', 'published'))) {
            	$msg = __("The user specified 404 page wasn't found. " .
            			"Please update the user-specified 404 page on the Options page.",
            			'404-solution');
            	$this->logger->infoMessage($msg);

            } else {
	            $redirect = $this->redirectsRepo->getExistingRedirectForURL($requestedURL);
	            $pType = is_scalar($permalink['type']) ? (string)$permalink['type'] : '';
	            $pId = is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
	            $pLink = is_scalar($permalink['link']) ? (string)$permalink['link'] : '';
	            $defRedir = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
	            if (!isset($redirect['id']) || $redirect['id'] == 0) {
	                $this->redirectsRepo->setupRedirect($requestedURL, (string)ABJ404_STATUS_CAPTURED, $pType, $pId, $defRedir, 0);
	            }

	            $this->logsRepo->logRedirectHit($requestedURL, $pLink, 'user specified 404 page. ' . $reason);

	            setcookie(ABJ404_PP . '_STATUS_404', 'true', time() + 20, "/");

	            $abj404logic->forceRedirect(esc_url($pLink),
	            	(int)$defRedir);
	            exit;
            }
        }

        if (@$options['capture_404'] == '1') {
            $redirect = $this->redirectsRepo->getExistingRedirectForURL($requestedURL);
            $defRedir2 = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
            if (!isset($redirect['id']) || $redirect['id'] == 0) {
                $this->redirectsRepo->setupRedirect($requestedURL, (string)ABJ404_STATUS_CAPTURED, (string)ABJ404_TYPE_404_DISPLAYED, (string)ABJ404_TYPE_404_DISPLAYED, $defRedir2, 0);
            }
        } else {
            $optionsJson = json_encode($options);
            $this->logger->debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL .
                    " | Redirect: (none)" . " | is_single(): " . is_single() . " | " .
                    "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " .
                    is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses_post(is_string($optionsJson) ? $optionsJson : ''));
        }
    }

    /** @param string|null $dest404page @return bool */
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

        if ($this->settingsUpdate->normalizeSuggestionTemplateOptions($options)) {
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
        $this->resolvedOptionsSkipDbCheck = null;
        $this->resolvedOptionsWithDbCheck = null;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    function updateToNewVersion(array $options) {
        self::invalidateOpcacheForCriticalFiles();

        $syncUtils = abj_service('sync_utils');

        $synchronizedKeyFromUser = "update_db_version";
        $uniqueID = $syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

        if ($uniqueID == '' || $uniqueID == null) {
        	$this->logger->debugMessage("Avoiding infinite loop on database update.");
            return $options;
        }

        $returnValue = $options;

        try {
            $returnValue = $this->updateToNewVersionAction($options);

        } catch (Throwable $e) {
            $this->logger->errorMessage("Error updating to new version. ", $e instanceof \Exception ? $e : null);
            throw $e;
        } finally {
            $syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
        }

        $permalinkCache = abj_service('permalink_cache');
        $permalinkCache->updatePermalinkCache(1);

        return $returnValue;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    function updateToNewVersionAction(array $options) {
    	global $wpdb;

        if (!is_array($options)) {
            $options = array();
        }
        $options = array_merge($this->getDefaultOptions(), $options);

        $currentDBVersion = "(unknown)";
        if (array_key_exists('DB_VERSION', $options) && is_string($options['DB_VERSION'])) {
            $currentDBVersion = $options['DB_VERSION'];
        }
        $this->logger->infoMessage(self::$uniqID . ": Updating database version from " .
        	$currentDBVersion . " to " . ABJ404_VERSION . " (begin).");

        $fileUtils = abj_service('functions');
        $fileUtils->deleteDirectoryRecursively(ABJ404_PATH . 'temp/');

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->runSelfHealPrologue();
        $upgradesEtc->createDatabaseTables(true);

        wp_clear_scheduled_hook('abj404_duplicateCronAction');

        ABJ_404_Solution_PluginLogic::doUnregisterCrons();
        ABJ_404_Solution_PluginLogic::doRegisterCrons();

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

        if (version_compare($currentDBVersion, '1.8.0') < 0) {
            $query = "SHOW TABLES LIKE '{wp_abj404_logs}'";
            $result = $this->dbCore->queryAndGetResults($query);
            $rows = $result['rows'];

            $filteredRows = is_array($rows) ? array_filter($rows) : array();
            if (!empty($filteredRows)) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/migrateToNewLogsTable.sql");
                $query = $this->dbCore->doTableNameReplacements($query);
                $result = $this->dbCore->queryAndGetResults($query);

                if ($result['rows_affected'] > 0) {
                    $this->logger->infoMessage($result['rows_affected'] .
                            ' log rows were migrated to the new table structre.');
                    $this->dbCore->queryAndGetResults('drop table ' . $this->dbCore->getLowercasePrefix() . 'abj404_logs');
                }
            }
        }

        if (version_compare($currentDBVersion, '2.18.0') < 0) {
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

        $dest404page = is_string($options['dest404page']) ? $options['dest404page'] : '';
        if ($this->f->strpos($dest404page, '|') === false) {
            if ($dest404page == '0') {
                $dest404page .= "|" . ABJ404_TYPE_404_DISPLAYED;
            } else {
                $dest404page .= '|' . ABJ404_TYPE_POST;
            }
            $options['dest404page'] = $dest404page;
            $this->updateOptions($options);
        }

        // @cache-write-audit: opt-out — stores a setup-completion date marker, not a query result
        if ($currentDBVersion !== '0.0.0' && version_compare($currentDBVersion, '3.0.7') < 0) {
            update_option('abj404_setup_completed', gmdate('Y-m-d'));
            $this->logger->infoMessage('Marked setup wizard as completed for existing user.');
        }

        if (!isset($options['suggest_minscore_enabled'])) {
            if (isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) && intval($options['suggest_minscore']) >= 25) {
                $options['suggest_minscore_enabled'] = '1';
                $this->logger->infoMessage('Enabled minimum score filtering based on existing suggest_minscore setting.');
            } else {
                $options['suggest_minscore_enabled'] = '0';
            }
            $this->updateOptions($options);
        }

        if (!isset($options['dest404_behavior']) || $options['dest404_behavior'] === 'theme_default') {
            $dest = is_string($options['dest404page']) ? $options['dest404page'] : '';
            if ($dest === '0|' . ABJ404_TYPE_404_DISPLAYED || $dest === (string)ABJ404_TYPE_404_DISPLAYED || $dest === '') {
                $options['dest404_behavior'] = 'theme_default';
            } else if ($dest === '0|' . ABJ404_TYPE_HOME) {
                $options['dest404_behavior'] = 'homepage';
            } else if ($dest !== '') {
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

    /** @return array<string, mixed> */
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
            'auto_trash_junk_urls' => '1',
            'auto_trash_junk_patterns' => implode("\n", array(
                '.env', '.git/', '.aws/', '.svn/', '.hg/',
                'xmlrpc.php', 'wlwmanifest.xml',
                'wp-config', 'config.php', 'config.json', 'config.bak',
                'phpinfo', 'phpmyadmin', 'phpMyAdmin', 'adminer',
                'sqladmin', 'dbadmin', 'mysqladmin',
                'id_rsa', '.bash_history', '.bashrc', '.DS_Store',
                'nginx.conf', 'httpd.conf', 'Dockerfile', 'docker-compose',
                '.sql', '.tar.gz', 'db_backup', 'database_backup',
                'setup-config.php',
                '/vendor/', '/node_modules/', '/tmp/',
                '/_profiler/', '/_debugbar/', '/debug/', '/debugbar/',
                '/META-INF/', '/WEB-INF/',
                'magento_version', 'alfa-rex.php', 'bypass.php',
            )),
        );

        return $options;
    }

    /** @param array<string, mixed>|null $options @return array<string, mixed> */
    function doUpdateDBVersionOption($options = null): array {
        if ($options == null) {
        	$options = $this->getOptions(true);
        }

        $options['DB_VERSION'] = ABJ404_VERSION;

        $this->updateOptions($options);

        return $options;
    }

    /** @return string[] File paths that were successfully invalidated. */
    static function invalidateOpcacheForCriticalFiles(): array {
        if (!function_exists('opcache_invalidate')) {
            return [];
        }

        $files = [
            ABJ404_PATH . 'includes/Functions.php',
            ABJ404_PATH . 'includes/php/FunctionsMBString.php',
            ABJ404_PATH . 'includes/php/FunctionsPreg.php',
        ];

        $invalidated = [];
        foreach ($files as $file) {
            if (is_file($file) && @opcache_invalidate($file, true)) {
                $invalidated[] = $file;
            }
        }

        return $invalidated;
    }


    /** @return string */
    function getDebugLogFileLink(): string {
        return "?page=" . ABJ404_PP . "&subpage=abj404_debugfile";
    }

    /** @return string */
    function getCommentPartAndQueryPartOfRequest() {
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

    /**
     * @param string $location
     * @param int $status
     * @param int|string $type only 0 for sending to a 404 page
     * @param string $requestedURL
     * @param bool $isCustom404
     * @return bool true if the user is sent to the default 404 page.
     */
    function forceRedirect(string $location, int $status = 302, $type = -1, string $requestedURL = '', bool $isCustom404 = false): bool {
        // 410 Gone
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

        // 451 Unavailable For Legal Reasons
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

        // Meta Refresh
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
                            esc_html__('Redirecting...', '404-solution'),
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

    	if ($type == ABJ404_TYPE_404_DISPLAYED) {
    		$abj404logic = abj_service('plugin_logic');
    		$abj404logic->sendTo404Page($requestedURL, '', false);

    		return true;
    	}

    	$this->setCookieWithPreviousRequest();
        if (!headers_sent()) {
            if (function_exists('abj404_benchmark_emit_headers')) {
                abj404_benchmark_emit_headers();
            }
            $useSafe = false;
            if (function_exists('wp_safe_redirect')) {
                $destHost = parse_url($finalDestination, PHP_URL_HOST);
                if ($destHost === null || $destHost === false || $destHost === '') {
                    $useSafe = true;
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
            if (!apply_filters('abj404_should_exit', true, array('source' => 'forceRedirect_header'))) {
                return false;
            }
            exit;
        }

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
        if (!apply_filters('abj404_should_exit', true, array('source' => 'forceRedirect_js'))) {
            return false;
        }
        exit;
    }

}
