<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin action handlers: trash, delete, ignore, later, edit, bulk actions, empty trash.
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

    /** @var ABJ_404_Solution_ViewReadServiceInterface|ABJ_404_Solution_DataAccess */
    private $viewRead;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_DatabaseCoreInterface|ABJ_404_Solution_DataAccess */
    private $dbCore;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;

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
    }

    /**
     * Accessors used by ABJ_404_Solution_AdminActionHandlerInterface implementations
     * under includes/admin/actions/. The dispatcher passes $this to each handler
     * (constructor injection) so handlers can reach shared collaborators without
     * each handler getting its own 10-argument constructor.
     *
     * @return ABJ_404_Solution_Logging
     */
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

    /**
     * Verify a nonce for admin-link actions, without depending on the browser's Referer header.
     *
     * @param string $action Nonce action string used in wp_nonce_url()
     * @param string $queryArg Nonce query arg name (default '_wpnonce')
     * @return bool
     */
    private function verifyLinkNonce($action, $queryArg = '_wpnonce') {
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

    /** Move redirects to trash.
     * @return string
     */
    function hanldeTrashAction() {

        $message = "";
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
                $message = $this->redirectsRepo->moveRedirectsToTrash($id, $trash);
                if ($message == "") {
                    $subpage = isset($_GET['subpage']) ? sanitize_text_field(wp_unslash($_GET['subpage'])) : '';
                    $filter = isset($_GET['filter']) ? intval($_GET['filter']) : 0;
                    if ($trash == 0 && $subpage === 'abj404_captured' && $filter === ABJ404_TRASH_FILTER) {
                        $this->redirectsRepo->updateRedirectTypeStatus($id, (string)ABJ404_STATUS_CAPTURED);
                    }
                    $this->viewBuild->invalidateViewDoneAndScheduleRebuild();
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

        if ($this->f->getPostOrGetSanitize('action') == 'changeItemsPerRow' && abj_service('admin_access_policy')->isPluginAdmin()) {
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
                $result = $this->dao->importDataFromPluginRedirectioner();
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

    /**
     * The parent admin script under which the plugin's menu page is registered.
     * Default install registers as a submenu under Settings (options-general.php);
     * users who set menuLocation=settingsLevel get a top-level menu (admin.php).
     * Using the wrong parent script in admin_url() produces a URL that doesn't
     * match the registered page, which historically landed users on the wrong
     * page after a redirect.
     *
     * @return string
     */
    /**
     * Build the post-edit redirect querystring (also used to determine the source
     * page the in-request render should target). Extracted from handleActionEdit
     * to keep that method's cyclomatic complexity within project limits.
     *
     * @return array{source_page: string, redirect_url: string}
     */
    private function buildPostEditRedirect(): array {
        $valid_tabs = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
                          'abj404_stats', 'abj404_tools', 'abj404_options');
        $source_page = $this->f->getPostOrGetSanitize('source_page');
        if ($source_page === '' || !in_array($source_page, $valid_tabs)) {
            $source_page = 'abj404_redirects';
        }

        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page . "&updated=1";

        $source_filter = $this->f->getPostOrGetSanitize('source_filter', '');
        if ($source_filter !== '' && $source_filter !== '0') {
            $redirect_url .= "&filter=" . urlencode($source_filter);
        }

        $source_orderby = $this->f->getPostOrGetSanitize('source_orderby', '');
        $source_order = $this->f->getPostOrGetSanitize('source_order', '');
        if ($source_orderby !== '' && $source_order !== ''
                && !($source_orderby === "url" && $source_order === "ASC")) {
            $redirect_url .= "&orderby=" . urlencode($source_orderby);
            $redirect_url .= "&order=" . urlencode($source_order);
        }

        $source_paged = $this->f->getPostOrGetSanitize('source_paged', '');
        if ($source_paged !== '' && (int)$source_paged > 1) {
            $redirect_url .= "&paged=" . urlencode($source_paged);
        }

        return array('source_page' => $source_page, 'redirect_url' => $redirect_url);
    }

    private function getMenuParentScript(): string {
        $options = abj_service('options_repository')->getOptions();
        $menuLocation = 'underSettings';
        if (is_array($options) && isset($options['menuLocation']) && is_string($options['menuLocation'])) {
            $menuLocation = $options['menuLocation'];
        }
        return $menuLocation === 'settingsLevel' ? 'admin.php' : 'options-general.php';
    }

    /** Edit redirect data.
     * @param string $sub
     * @param string $action
     * @return string
     */
    function handleActionEdit(&$sub, &$action) {
        $message = "";

        if (array_key_exists('action', $_POST) && $_POST['action'] == "editRedirect") {
            $id = $this->f->getPostOrGetSanitize('id');
            $ids = $this->f->getPostOrGetSanitize('ids_multiple');
            if (!($id === '' && $ids === '') && ($this->f->regexMatch('[0-9]+', '' . $id) || $this->f->regexMatch('[0-9]+', '' . $ids))) {
                if (is_admin() && $this->verifyLinkNonce('abj404editRedirect')) {
                    $message = $this->updateRedirectData();
                    if ($message == "") {
                        $redirect = $this->buildPostEditRedirect();

                        // PRG attempt: only works when called early enough that
                        // no output has been flushed yet (e.g. on admin_init).
                        // When called from the menu-page callback, admin-header.php
                        // has already streamed the admin chrome and headers_sent()
                        // is true, so the Location header is silently dropped.
                        if (!headers_sent()) {
                            wp_safe_redirect(admin_url($this->getMenuParentScript() . $redirect['redirect_url']));
                        }

                        // Defense in depth: even if PRG didn't fire, route the
                        // in-request render to the source page (redirects table
                        // by default) instead of re-rendering the edit form.
                        // Re-rendering echoAdminEditRedirectPage post-update
                        // surfaced "Error: No ID(s) found for edit request."
                        // for users whose admin chrome already flushed headers
                        // (Chad/lonesync, 2026-05-26).
                        $sub = $redirect['source_page'];
                        $action = '';
                        return __('Redirect Information Updated Successfully!', '404-solution');
                    } else {
                        $message .= __('Error: Unable to update redirect data.', '404-solution');
                    }
                }
            }
        }

        return $message;
    }

    /**
     * @param string $action
     * @param array<int, int> $ids
     * @return string
     */
    function doBulkAction(string $action, array $ids): string {
        $message = "";

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

            $count = 0;
            foreach ($ids as $id) {
                $s = $this->redirectsRepo->moveRedirectsToTrash($id, 0);
                if ($action != "bulk_trash_restore") {
                    $s = $this->redirectsRepo->updateRedirectTypeStatus($id, (string)$status);
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
                $message = $count . " " . __('URL(s) restored.', '404-solution');
            }

        } else if ($action == "bulk_trash_delete_permanently") {
            $count = 0;
            foreach ($ids as $id) {
                $this->redirectsRepo->deleteRedirect(absint($id));
                $count ++;
            }
            $message = $count . " " . __('URL(s) deleted', '404-solution');

        } else if ($action == "bulktrash") {
            $count = 0;
            foreach ($ids as $id) {
                $s = $this->redirectsRepo->moveRedirectsToTrash($id, 1);
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

        $tdTypeRaw = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdType = ($tdTypeRaw !== '') ? (int)$tdTypeRaw : -1;
        $tdDest = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        $postedCodeForCheck = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        $isCode410 = $postedCodeForCheck === '410' || $postedCodeForCheck === '451';
        if ($tdTypeRaw !== '' && ($tdDest !== "" || $isCode410)) {
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            $startDateRaw = isset($_POST['redirect_start_date']) && is_string($_POST['redirect_start_date']) ? trim($_POST['redirect_start_date']) : '';
            $endDateRaw = isset($_POST['redirect_end_date']) && is_string($_POST['redirect_end_date']) ? trim($_POST['redirect_end_date']) : '';
            $startTs = ($startDateRaw !== '') ? strtotime($startDateRaw . ' 00:00:00') : null;
            $endTs = ($endDateRaw !== '') ? strtotime($endDateRaw . ' 23:59:59') : null;
            if ($startTs === false) { $startTs = null; }
            if ($endTs === false) { $endTs = null; }

            $rawConditions = (isset($_POST['conditions']) && is_array($_POST['conditions']))
                ? $_POST['conditions'] : [];
            $sanitizedConditions = [];
            $allowedConditionTypes = [
                'login_status', 'user_role', 'referrer',
                'user_agent', 'ip_range', 'http_header',
            ];
            $allowedOperators = [
                'equals', 'not_equals', 'contains',
                'not_contains', 'regex', 'cidr',
            ];
            foreach ($rawConditions as $rawCond) {
                if (!is_array($rawCond)) {
                    continue;
                }
                $condType = isset($rawCond['condition_type']) && is_string($rawCond['condition_type'])
                    ? sanitize_text_field($rawCond['condition_type']) : '';
                if (!in_array($condType, $allowedConditionTypes, true)) {
                    continue;
                }
                $condLogic = (isset($rawCond['logic']) && strtoupper((string)$rawCond['logic']) === 'OR') ? 'OR' : 'AND';
                $condOperator = isset($rawCond['operator']) && is_string($rawCond['operator'])
                    ? sanitize_text_field($rawCond['operator']) : 'equals';
                if (!in_array($condOperator, $allowedOperators, true)) {
                    $condOperator = 'equals';
                }
                $condValue = isset($rawCond['value']) && is_string($rawCond['value'])
                    ? sanitize_text_field(wp_unslash($rawCond['value'])) : '';
                $condSortOrder = isset($rawCond['sort_order']) ? absint($rawCond['sort_order']) : 0;

                $sanitizedConditions[] = [
                    'logic'          => $condLogic,
                    'condition_type' => $condType,
                    'operator'       => $condOperator,
                    'value'          => $condValue,
                    'sort_order'     => $condSortOrder,
                ];
            }

            if ($fromURL != "") {
                $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int)$_POST['id'] : 0;
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                $originalFromURL = $fromURL;
                $autoPromote = $this->maybeAutoPromoteRegex($statusType, $fromURL);
                $statusType = $autoPromote['statusType'];
                $fromURL = $autoPromote['url'];
                $this->redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::create(
                    $id,
                    (int)$tdType,
                    (string)$fromURL,
                    (string)$tdDest,
                    (string)$code,
                    (string)$statusType,
                    $startTs,
                    $endTs
                ));
                if ($autoPromote['autoPromoted']) {
                    $this->saveRegexAutoPromoteNotice($id, $originalFromURL, $fromURL, $autoPromote['urlRewritten']);
                }

                if ($id > 0) {
                    $this->redirectsRepo->saveRedirectConditions($id, $sanitizedConditions);
                }
                $this->viewBuild->invalidateViewDoneAndScheduleRebuild();

            } else if ($ids_multiple != "") {
                $redirects_multiple = $this->redirectsRepo->getRedirectsByIDs($ids_multiple);
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                foreach ($redirects_multiple as $redirect) {
                    $redirectUrl = is_string($redirect['url']) ? $redirect['url'] : '';
                    $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                    $this->redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::create(
                        $redirectId,
                        (int)$tdType,
                        (string)$redirectUrl,
                        (string)$tdDest,
                        (string)$code,
                        (string)$statusType
                    ));
                }
                $this->viewBuild->invalidateViewDoneAndScheduleRebuild();

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

        $postedCode = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        if ($postedCode === '410' || $postedCode === '451') {
            $response['type'] = (string)ABJ404_TYPE_HOME;
            $response['dest'] = '';
            return $response;
        }

        if (!isset($_POST['redirect_to_data_field_id']) || $_POST['redirect_to_data_field_id'] === '') {
            $response['message'] = __('Error: Redirect destination is required.', '404-solution') . "<BR/>";
            return $response;
        }

        if ($_POST['redirect_to_data_field_id'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $rawEnteredURLResult = $this->f->getPostOrGetSanitizeUrl('redirect_to_user_field');
            $rawEnteredURL = is_string($rawEnteredURLResult) ? $rawEnteredURLResult : null;
            $userEnteredURL = $this->urlNormalization->normalizeExternalDestinationUrl($rawEnteredURL);
            $userEnteredURL = esc_url($userEnteredURL, array('http', 'https'));
            if ($userEnteredURL == "") {
                $response['message'] = __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>";

            } else if ($this->f->strlen($userEnteredURL) < 8) {
                $response['message'] = __('Error: External URL is too short.', '404-solution') . "<BR/>";

            } else if ($this->f->strpos($userEnteredURL, "://") === false) {
                $response['message'] = __("Error: External URL doesn't contain ://", '404-solution') . "<BR/>";

            } else {
                $parsed_url = parse_url($userEnteredURL);
                if (!is_array($parsed_url) || !isset($parsed_url['scheme']) || !in_array(strtolower($parsed_url['scheme']), array('http', 'https'))) {
                    $response['message'] = __('Error: External URL must use http:// or https:// protocol only.', '404-solution') . "<BR/>";
                }

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
     * @return string
     */
    function addAdminRedirect() {
        $message = "";

        if (!isset($_POST['manual_redirect_url']) || $_POST['manual_redirect_url'] == "") {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
            return $message;
        }

        $manualURL = isset($_POST['manual_redirect_url']) ? wp_unslash($_POST['manual_redirect_url']) : '';
        $manualURL = $this->urlNormalization->normalizeUserProvidedPath($manualURL);
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
        $postedCodeForCheck2 = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        $code410 = $postedCodeForCheck2 === '410' || $postedCodeForCheck2 === '451';
        if ($tdType2 != "" && ($tdDest2 !== "" || $code410)) {
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            $code = isset($_POST['code']) && is_scalar($_POST['code']) && (string)$_POST['code'] !== '' ? (string)$_POST['code'] : '301';

            $originalManualURL = $manualURL;
            $autoPromoteAdd = $this->maybeAutoPromoteRegex($statusType, $manualURL);
            $statusType = $autoPromoteAdd['statusType'];
            $manualURL = $autoPromoteAdd['url'];

            $newRedirectId = $this->redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                    $manualURL, (string)$statusType,
                    $tdType2, $tdDest2,
                    sanitize_text_field($code), 0
            ));
            if ($autoPromoteAdd['autoPromoted']) {
                $this->saveRegexAutoPromoteNotice((int)$newRedirectId, $originalManualURL, $manualURL, $autoPromoteAdd['urlRewritten']);
            }
            $this->viewBuild->invalidateViewDoneAndScheduleRebuild();

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $this->logger->errorMessage("Add redirect data issue. Type: " . esc_html($tdType2) . ", dest: " .
                    esc_html($tdDest2));
        }

        return $message;
    }

    /**
     * @param int $statusTypeIn
     * @param string $fromURL
     * @return array{statusType: int, url: string, autoPromoted: bool, urlRewritten: bool}
     */
    private function maybeAutoPromoteRegex($statusTypeIn, $fromURL) {
        $result = array(
            'statusType' => (int)$statusTypeIn,
            'url' => is_string($fromURL) ? $fromURL : '',
            'autoPromoted' => false,
            'urlRewritten' => false,
        );

        if ((int)$statusTypeIn === ABJ404_STATUS_REGEX) {
            return $result;
        }
        if (!ABJ_404_Solution_RegexAutoPromote::looksLikeUnambiguousRegex($result['url'])) {
            return $result;
        }

        $result['statusType'] = ABJ404_STATUS_REGEX;
        $result['autoPromoted'] = true;
        $glob = ABJ_404_Solution_RegexAutoPromote::applyGlobFixup($result['url']);
        $result['url'] = $glob['url'];
        $result['urlRewritten'] = $glob['changed'];

        return $result;
    }

    /**
     * @param int $redirectId
     * @param string $originalURL
     * @param string $newURL
     * @param bool $urlRewritten
     * @return void
     */
    private function saveRegexAutoPromoteNotice($redirectId, $originalURL, $newURL, $urlRewritten) {
        ABJ_404_Solution_RegexAutoPromote::saveNotice($redirectId, $originalURL, $newURL, $urlRewritten);
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
