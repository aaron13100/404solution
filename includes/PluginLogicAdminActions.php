<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin action dispatcher + thin facade.
 *
 * Two roles:
 *
 *   1. Action-name -> handler-class registry. handlePluginAction() routes
 *      POST verbs (addRedirect, updateOptions, emptyRedirectTrash, bulk*, ...)
 *      to the matching class in includes/admin/actions/, centralizing the
 *      nonce + is_admin() guard so it cannot be forgotten when a new action
 *      is added.
 *
 *   2. Backward-compatible facade for non-dispatcher entry points
 *      (link-style $_GET actions called from View.php, plus methods kept for
 *      tests that already pin the existing API). Each facade method here is
 *      a one-line delegator to the real implementation in
 *      includes/admin/actions/*Handler.php; this class does not hold the
 *      action logic itself.
 *
 * Per-action implementations live in:
 *   - TrashLinkActionHandler   (link-style $_GET['trash'])
 *   - EditRedirectHandler      (POST editRedirect, plus updateRedirectData)
 *   - AddRedirectHandler       (POST addRedirect)
 *   - BulkActionHandler        (POST bulk*)
 *   - RedirectFormResolver     (shared form parsing + regex auto-promote)
 *   - UpdateOptionsHandler, EmptyRedirectTrashHandler, EmptyCapturedTrashHandler,
 *     PurgeRedirectsHandler, RunMaintenanceHandler, RebuildNgramCacheHandler,
 *     ClearSpellingCacheHandler, SaveGscSettingsHandler, ImportFromPluginHandler,
 *     UndoRegexAutoPromoteHandler
 *
 * Standalone class extracted from PluginLogicTrait_AdminActions.
 */
class ABJ_404_Solution_PluginLogicAdminActions {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|ABJ_404_Solution_DataAccess */
    private $viewBuild;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewRead;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;

    /** @var ABJ_404_Solution_AdminActionsDependencies */
    private $deps;

    /** @var ABJ_404_Solution_RedirectFormResolver|null lazy. */
    private $formResolver;

    /** @var ABJ_404_Solution_TrashLinkActionHandler|null lazy. */
    private $trashLinkHandler;

    /** @var ABJ_404_Solution_EditRedirectHandler|null lazy. */
    private $editRedirectHandler;

    /** @var ABJ_404_Solution_AddRedirectHandler|null lazy. */
    private $addRedirectHandler;

    /** @var ABJ_404_Solution_BulkActionHandler|null lazy. */
    private $bulkActionHandler;

    /**
     * Construct with a single typed dependency bundle. Replaces a
     * 10-positional-parameter signature (audit source design-audit-2026-05-29.md,
     * criterion 220 Interface Size). Internal field layout is unchanged; only
     * the constructor interface narrows.
     *
     * @param ABJ_404_Solution_AdminActionsDependencies $deps
     */
    function __construct(ABJ_404_Solution_AdminActionsDependencies $deps) {
        $this->f = $deps->getFunctions();
        $this->logger = $deps->getLogger();
        $this->redirectsRepo = $deps->getRedirectsRepo();
        $this->viewBuild = $deps->getViewBuild();
        $this->viewRead = $deps->getViewRead();
        $this->contentRepo = $deps->getContentRepo();
        $this->dbCore = $deps->getDbCore();
        $this->dao = $deps->getDao();
        $this->urlNormalization = $deps->getUrlNormalization();
        $this->pluginLogic = $deps->getPluginLogic();
        $this->deps = $deps;
    }

    /**
     * Accessors used by ABJ_404_Solution_AdminActionHandlerInterface implementations
     * under includes/admin/actions/. The dispatcher passes $this to each handler
     * (constructor injection) so handlers can reach shared collaborators without
     * each handler getting its own 10-argument constructor.
     *
     * @return ABJ_404_Solution_Functions
     */
    public function getFunctions() {
        return $this->f;
    }

    /** @return ABJ_404_Solution_Logging */
    public function getLogger() {
        return $this->logger;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface|ABJ_404_Solution_DataAccess */
    public function getViewBuild() {
        return $this->viewBuild;
    }

    /** @return ABJ_404_Solution_RedirectsRepositoryInterface */
    public function getRedirectsRepo() {
        return $this->redirectsRepo;
    }

    /** @return ABJ_404_Solution_ContentRepositoryInterface */
    public function getContentRepo() {
        return $this->contentRepo;
    }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    public function getUrlNormalization() {
        return $this->urlNormalization;
    }

    /**
     * Shared by Add and Edit redirect handlers. Lazy so construction stays
     * cheap when admin actions never fire on this request.
     *
     * @return ABJ_404_Solution_RedirectFormResolver
     */
    public function redirectFormResolver(): ABJ_404_Solution_RedirectFormResolver {
        if ($this->formResolver === null) {
            $this->formResolver = new ABJ_404_Solution_RedirectFormResolver(
                $this->f, $this->logger, $this->urlNormalization
            );
        }
        return $this->formResolver;
    }

    /**
     * Verify a nonce for admin-link actions, without depending on the browser's Referer header.
     * Public so handlers under includes/admin/actions/ that respond to GET-link
     * actions (TrashLinkActionHandler, EditRedirectHandler) can reuse the
     * same nonce primitive used by the legacy methods on this class.
     *
     * @param string $action Nonce action string used in wp_nonce_url()
     * @param string $queryArg Nonce query arg name (default '_wpnonce')
     * @return bool
     */
    public function verifyLinkNonce($action, $queryArg = '_wpnonce') {
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
     * Exact-match action -> handler-class registry. Built lazily once per
     * request. Each handler class implements
     * ABJ_404_Solution_AdminActionHandlerInterface. See includes/admin/actions/.
     *
     * To add a new admin action: create a Handler class in includes/admin/actions/,
     * add a classmap entry, and add it here. The nonce + is_admin() guard is
     * centralized in handlePluginAction() so a new handler cannot ship without it.
     *
     * @return array<string, class-string<ABJ_404_Solution_AdminActionHandlerInterface>>
     */
    private function actionHandlerMap(): array {
        return array(
            'updateOptions'        => 'ABJ_404_Solution_UpdateOptionsHandler',
            'addRedirect'          => 'ABJ_404_Solution_AddRedirectHandler',
            'emptyRedirectTrash'   => 'ABJ_404_Solution_EmptyRedirectTrashHandler',
            'emptyCapturedTrash'   => 'ABJ_404_Solution_EmptyCapturedTrashHandler',
            'purgeRedirects'       => 'ABJ_404_Solution_PurgeRedirectsHandler',
            'runMaintenance'       => 'ABJ_404_Solution_RunMaintenanceHandler',
            'rebuildNgramCache'    => 'ABJ_404_Solution_RebuildNgramCacheHandler',
            'clearSpellingCache'   => 'ABJ_404_Solution_ClearSpellingCacheHandler',
            'saveGscSettings'      => 'ABJ_404_Solution_SaveGscSettingsHandler',
            'importFromPlugin'     => 'ABJ_404_Solution_ImportFromPluginHandler',
            'undoRegexAutoPromote' => 'ABJ_404_Solution_UndoRegexAutoPromoteHandler',
        );
    }

    /**
     * Prefix-match registry. Used after exact-match misses. Currently only
     * 'bulk' is registered, matching the historical
     * `substr($action, 0, 4) == "bulk"` branch (bulktrash, bulkignore, etc.).
     *
     * @return array<string, class-string<ABJ_404_Solution_AdminActionHandlerInterface>>
     */
    private function actionHandlerPrefixMap(): array {
        return array(
            'bulk' => 'ABJ_404_Solution_BulkActionHandler',
        );
    }

    /**
     * Resolve an action string to a handler instance, or null if no handler
     * matches. Checks exact-match registry first, then prefix-match.
     *
     * @param string $action
     * @return ABJ_404_Solution_AdminActionHandlerInterface|null
     */
    private function resolveActionHandler(string $action) {
        $exact = $this->actionHandlerMap();
        if (isset($exact[$action])) {
            $cls = $exact[$action];
            return $this->instantiateHandler($cls);
        }
        foreach ($this->actionHandlerPrefixMap() as $prefix => $cls) {
            if ($action !== '' && strpos($action, $prefix) === 0) {
                return $this->instantiateHandler($cls);
            }
        }
        return null;
    }

    /**
     * Instantiate a handler. Handlers that need shared collaborators declare
     * a constructor that takes ABJ_404_Solution_PluginLogicAdminActions
     * (this dispatcher) as their only argument; handlers that need nothing
     * declare no constructor. Reflection inspects which case applies.
     *
     * @param class-string<ABJ_404_Solution_AdminActionHandlerInterface> $cls
     * @return ABJ_404_Solution_AdminActionHandlerInterface
     */
    private function instantiateHandler(string $cls): ABJ_404_Solution_AdminActionHandlerInterface {
        $reflect = new ReflectionClass($cls);
        $ctor = $reflect->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            $instance = $reflect->newInstance();
        } else {
            $instance = $reflect->newInstance($this);
        }
        if (!$instance instanceof ABJ_404_Solution_AdminActionHandlerInterface) {
            throw new RuntimeException("Handler class {$cls} does not implement AdminActionHandlerInterface.");
        }
        return $instance;
    }

    /** Do the passed in action and return the associated message.
     *
     * Dispatches to a handler in includes/admin/actions/ via the registry above.
     * Nonce verification + is_admin() guard are centralized here so they cannot
     * be forgotten when a new action is added. Unknown actions are a no-op that
     * returns the pre-populated display-this-message (preserving pre-refactor
     * behavior of the original 12-branch if/else chain).
     *
     * @param string $action
     * @param string $sub
     * @return string
     */
    function handlePluginAction($action, &$sub) {
        $message = array_key_exists('display-this-message', $_POST) ?
            sanitize_text_field($_POST['display-this-message']) : '';

        $handler = $this->resolveActionHandler((string)$action);
        if ($handler === null) {
            return $message;
        }

        if (!$this->verifyHandlerNonce($handler) || !is_admin()) {
            $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                    is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            return $message;
        }

        return $handler->handle((string)$action, $sub);
    }

    /**
     * Run the handler's declared nonce check. Most handlers use
     * check_admin_referer($action, $arg); the legacy 'updateOptions' branch
     * uses wp_verify_nonce($_POST[$arg], $action) directly. Behavior is
     * preserved verbatim per-handler so nonce semantics do not change.
     *
     * @param ABJ_404_Solution_AdminActionHandlerInterface $handler
     * @return bool
     */
    private function verifyHandlerNonce(ABJ_404_Solution_AdminActionHandlerInterface $handler): bool {
        $action = $handler->nonceAction();
        $arg = $handler->nonceArg();
        if ($handler->useCheckAdminReferer()) {
            return (bool)check_admin_referer($action, $arg);
        }
        if (!isset($_POST[$arg]) || !is_scalar($_POST[$arg])) {
            return false;
        }
        return (bool)wp_verify_nonce((string)$_POST[$arg], $action);
    }

    /**
     * Backward-compatible facade for the trash link action. The real
     * implementation lives in ABJ_404_Solution_TrashLinkActionHandler.
     *
     * The legacy misspelling (hanldeTrashAction) is preserved as an alias
     * so existing call sites (View.php) and test stubs do not break; new
     * code should call handleTrashAction().
     *
     * @return string
     */
    function handleTrashAction() {
        if ($this->trashLinkHandler === null) {
            $this->trashLinkHandler = new ABJ_404_Solution_TrashLinkActionHandler($this);
        }
        return $this->trashLinkHandler->handle();
    }

    /** @return string Legacy misspelled alias. Use handleTrashAction(). */
    function hanldeTrashAction() {
        return $this->handleTrashAction();
    }

    /** @return void */
    function handleActionChangeItemsPerRow(): void {

        $userIsPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
        if ($this->f->getPostOrGetSanitize('action') == 'changeItemsPerRow' && $userIsPluginAdmin) {
            check_admin_referer('abj404_changeItemsPerRow');
            $this->updatePerPageOption(absint($this->f->getPostOrGetSanitize('perpage')));
        }
    }

    /** @return void */
    function handleActionExport(): void {

        if (($this->f->getPostOrGetSanitize('action') == 'exportRedirects') && abj_service('admin_access_policy')->isPluginAdmin()) {
            check_admin_referer('abj404_exportRedirects');
            $this->pluginLogic->importExport()->doExport();
        }
    }

    /** @return string|null */
    function handleActionImportFile() {

        if (($this->f->getPostOrGetSanitize('action') == 'importRedirectsFile') && abj_service('admin_access_policy')->isPluginAdmin()) {
            check_admin_referer('abj404_importRedirectsFile');
            $result = $this->pluginLogic->importExport()->doImportFile();
            $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
            return $result;
        }

        return null;
    }

    /** @return void */
    function updatePerPageOption(int $rows): void {
        $showRows = max($rows, ABJ404_OPTION_MIN_PERPAGE);
        $showRows = min($showRows, ABJ404_OPTION_MAX_PERPAGE);

        $options = abj_service('options_repository')->getOptions();
        $options['perpage'] = $showRows;
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @return string
     */
    function handleActionImportRedirects() {
        $message = "";


        if ($this->f->getPostOrGetSanitize('action') == 'importRedirects') {
            if ($this->f->getPostOrGetSanitize('sanity_404redirected') != '1') {
                $message = __("Error: You didn't check the I understand checkbox. No importing for you!", '404-solution');
                return $message;
            }

            check_admin_referer('abj404_importRedirects');

            try {
                $result = $this->deps->getPluginUpdateRepo()->importDataFromPluginRedirectioner();
                if ($result['last_error'] != '') {
                    $lastErrorJson = json_encode($result['last_error']);
                    $message = sprintf(__("Error: No records were imported. SQL result: %s", '404-solution'),
                            wp_kses_post(is_string($lastErrorJson) ? $lastErrorJson : ''));
                } else {
                    $rowsAffected = is_scalar($result['rows_affected']) ? (string)$result['rows_affected'] : '0';
                    $message = sprintf(__("Records imported: %s", '404-solution'), esc_html($rowsAffected));
                    $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
                }

            } catch (Exception $e) {
                $message = "Error: Importing failed. Message: " . $e->getMessage();
                $this->logger->errorMessage('Error importing redirects.', $e);
            }
        }

        return $message;
    }

    /** Delete redirects.
     * @return string
     */
    function handleDeleteAction() {
        $message = "";

        if (array_key_exists('remove', $_GET) && @$_GET['remove'] == 1) {
            if (is_admin() && $this->verifyLinkNonce('abj404_removeRedirect')) {
                if ($this->f->regexMatch('[0-9]+', $_GET['id'])) {
                    $this->redirectsRepo->deleteRedirect(absint($_GET['id']));
                    $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
                    $message = __('Redirect Removed Successfully!', '404-solution');
                }
            }
        }

        return $message;
    }

    /**
     * @param string $paramName The $_GET parameter name
     * @param string $nonceAction The nonce action name
     * @param int $activeStatus The status constant to use when action=1
     * @param string $errorActionName Action name for error messages
     * @param string $successActionName Action name for success messages
     * @return string
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

                    $message = $this->redirectsRepo->updateRedirectTypeStatus(absint($id), (string)$newstatus);
                    if ($message == "") {
                        $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
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

    /** @return string */
    function handleIgnoreAction() {
        return $this->handleStatusUpdate('ignore', 'abj404_ignore404', ABJ404_STATUS_IGNORED, 'ignore', 'ignored');
    }

    /** @return string */
    function handleLaterAction() {
        return $this->handleStatusUpdate('later', 'abj404_organizeLater', ABJ404_STATUS_LATER, 'organize later', 'organize later');
    }

    /** Edit redirect data.
     * Facade: real implementation lives in ABJ_404_Solution_EditRedirectHandler.
     *
     * @param string $sub
     * @param string $action
     * @return string
     */
    function handleActionEdit(&$sub, &$action) {
        if ($this->editRedirectHandler === null) {
            $this->editRedirectHandler = new ABJ_404_Solution_EditRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->editRedirectHandler->handle($sub, $action);
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_BulkActionHandler.
     *
     * @param string $action
     * @param array<int, int> $ids
     * @return string
     */
    function doBulkAction(string $action, array $ids): string {
        if ($this->bulkActionHandler === null) {
            $this->bulkActionHandler = new ABJ_404_Solution_BulkActionHandler($this);
        }
        return $this->bulkActionHandler->doBulkAction($action, $ids);
    }

    /**
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
            return;
        }

        $result = $this->dbCore->queryAndGetResults($query);
        $this->logger->debugMessage("doEmptyTrash deleted " . $result['rows_affected'] . " rows total. (" . $sub . ")");

        $this->viewRead->invalidateStatusCountsCache();

        $this->dbCore->queryAndGetResults("optimize table {wp_abj404_redirects}");
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_EditRedirectHandler.
     *
     * @return string
     */
    function updateRedirectData() {
        if ($this->editRedirectHandler === null) {
            $this->editRedirectHandler = new ABJ_404_Solution_EditRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->editRedirectHandler->updateRedirectData();
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_RedirectFormResolver.
     *
     * @return array<string, mixed>
     */
    function getRedirectTypeAndDest(): array {
        return $this->redirectFormResolver()->getRedirectTypeAndDest();
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_AddRedirectHandler.
     *
     * @return string
     */
    function addAdminRedirect() {
        if ($this->addRedirectHandler === null) {
            $this->addRedirectHandler = new ABJ_404_Solution_AddRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->addRedirectHandler->addAdminRedirect();
    }

    /**
     * @return string Human-readable result message.
     */
    function handleActionUndoRegexAutoPromote() {
        $notice = ABJ_404_Solution_RegexAutoPromote::readNotice();
        if ($notice === null || $notice['redirect_id'] <= 0) {
            return __('Error: No regex auto-promotion to undo.', '404-solution');
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $sql = "UPDATE `" . $redirectsTable . "` SET `url` = %s, `status` = %d WHERE `id` = %d";
        $this->dbCore->queryAndGetResults($sql, array('query_params' => array(
            $notice['original_url'],
            (int)ABJ404_STATUS_MANUAL,
            (int)$notice['redirect_id'],
        )));
        $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
        ABJ_404_Solution_RegexAutoPromote::clearNotice();
        return sprintf(
            /* translators: %s = the original from_url string that was restored */
            __('Regex auto-promotion undone. Restored "%s" with status Manual.', '404-solution'),
            $notice['original_url']
        );
    }

    /**
     * @return string Human-readable result message.
     */
    public function handleActionImportFromPlugin(): string {
        $source = isset($_POST['import_source']) && is_string($_POST['import_source'])
            ? sanitize_text_field($_POST['import_source'])
            : '';

        if ($source === '') {
            return __('Error: No source plugin specified.', '404-solution');
        }

        $allowedSources = array('rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection');
        if (!in_array($source, $allowedSources, true)) {
            return sprintf(
                /* translators: %s = unknown source identifier */
                __('Error: Unknown source plugin "%s".', '404-solution'),
                esc_html($source)
            );
        }

        $importer = new ABJ_404_Solution_CrossPluginImporter($this->dao, $this->logger);
        $count    = $importer->importFrom($source);

        if ($count > 0) {
            $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
        }

        return sprintf(
            /* translators: %d = number of redirects imported */
            _n(
                '%d redirect imported successfully.',
                '%d redirects imported successfully.',
                $count,
                '404-solution'
            ),
            $count
        );
    }

}
