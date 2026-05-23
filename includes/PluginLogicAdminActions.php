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

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface */
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

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuild
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewRead
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_DatabaseCoreInterface $dbCore
     * @param ABJ_404_Solution_DataAccess $dao
     * @param ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     */
    function __construct($f, $logger, $redirectsRepo, $viewBuild, $viewRead, $contentRepo, $dbCore, $dao, $urlNormalization, $pluginLogic) {
        $this->f = $f;
        $this->logger = $logger;
        $this->redirectsRepo = $redirectsRepo;
        $this->viewBuild = $viewBuild;
        $this->viewRead = $viewRead;
        $this->contentRepo = $contentRepo;
        $this->dbCore = $dbCore;
        $this->dao = $dao;
        $this->urlNormalization = $urlNormalization;
        $this->pluginLogic = $pluginLogic;
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

    /** Do the passed in action and return the associated message.
     * @param string $action
     * @param string $sub
     * @return string
     */
    function handlePluginAction($action, &$sub) {
        $message = "";
        $message = array_key_exists('display-this-message', $_POST) ?
        	sanitize_text_field($_POST['display-this-message']) : '';

        if ($action == "updateOptions") {
        	if (wp_verify_nonce($_POST['nonce'], 'abj404UpdateOptions') && is_admin()) {
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
                $this->doEmptyTrash('abj404_redirects');
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "emptyCapturedTrash") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                $this->doEmptyTrash('abj404_captured');
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
                $message = __('All trashed URLs have been deleted!', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "purgeRedirects") {
            if (check_admin_referer('abj404_purgeRedirects') && is_admin()) {
                $message = $this->redirectsRepo->deleteSpecifiedRedirects();
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "runMaintenance") {
            if (check_admin_referer('abj404_runMaintenance') && is_admin()) {
                $message = $this->redirectsRepo->deleteOldRedirectsCron();
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "rebuildNgramCache") {
            if (check_admin_referer('abj404_rebuildNgramCache') && is_admin()) {
                $userId = get_current_user_id();
                $transientKey = 'abj404_ngram_rebuild_request_' . $userId;
                $recentRequest = get_transient($transientKey);

                if ($recentRequest) {
                    $message = __('N-gram cache rebuild is already scheduled or in progress. Please wait for it to complete.', '404-solution');
                } else {
                    set_transient($transientKey, time(), 10);

                    $dbUpgrades = abj_service('database_upgrades');

                    $scheduled = $dbUpgrades->scheduleNGramCacheRebuild();

                    if ($scheduled) {
                        $message = __('N-gram cache rebuild has been scheduled and will run in the background. This may take several minutes on large sites. You can continue using the plugin normally.', '404-solution');
                    } else {
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
                $this->contentRepo->deleteSpellingCache();
                $message = __('Spelling cache cleared successfully.', '404-solution');
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "saveGscSettings") {
            if (check_admin_referer('abj404_gsc_save', '_wpnonce_gsc') && is_admin()) {
                $logger = abj_service('logging');
                $gsc = new ABJ_404_Solution_GoogleSearchConsole($logger);
                $error = $gsc->saveSettings($_POST);
                $message = ($error === '') ? __('Google Search Console credentials saved.', '404-solution') : $error;
            } else {
                $this->logger->debugMessage("saveGscSettings security check failed. is_admin: " . is_admin());
            }
        } else if ($action == "importFromPlugin") {
            if (check_admin_referer('abj404_importFromPlugin') && is_admin()) {
                $message = $this->handleActionImportFromPlugin();
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
            } else {
                $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                        is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            }
        } else if ($action == "undoRegexAutoPromote") {
            if (check_admin_referer('abj404undoRegexAutoPromote') && is_admin()) {
                $message = $this->handleActionUndoRegexAutoPromote();
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
                $message = $this->doBulkAction($action, array_map('absint', $_POST['idnum']));
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
                    $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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

        if ($this->f->getPostOrGetSanitize('action') == 'changeItemsPerRow' && $this->pluginLogic->userIsPluginAdmin()) {
            check_admin_referer('abj404_changeItemsPerRow');
            $this->updatePerPageOption(absint($this->f->getPostOrGetSanitize('perpage')));
        }
    }

    /** @return void */
    function handleActionExport(): void {

        if (($this->f->getPostOrGetSanitize('action') == 'exportRedirects') && $this->pluginLogic->userIsPluginAdmin()) {
            check_admin_referer('abj404_exportRedirects');
            $this->pluginLogic->doExport();
        }
    }

    /** @return string|null */
    function handleActionImportFile() {

        if (($this->f->getPostOrGetSanitize('action') == 'importRedirectsFile') && $this->pluginLogic->userIsPluginAdmin()) {
            check_admin_referer('abj404_importRedirectsFile');
            $result = $this->pluginLogic->doImportFile();
            $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
            return $result;
        }

        return null;
    }

    /** @return void */
    function updatePerPageOption(int $rows): void {
        $showRows = max($rows, ABJ404_OPTION_MIN_PERPAGE);
        $showRows = min($showRows, ABJ404_OPTION_MAX_PERPAGE);

        $options = $this->pluginLogic->getOptions();
        $options['perpage'] = $showRows;
        $this->pluginLogic->updateOptions($options);
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
                    $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
                    $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
                        $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
                    $message = $this->pluginLogic->updateRedirectData();
                    if ($message == "") {
                        $source_page = $this->f->getPostOrGetSanitize('source_page');

                        $valid_tabs = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
                                          'abj404_stats', 'abj404_tools', 'abj404_options');
                        if ($source_page === '' || !in_array($source_page, $valid_tabs)) {
                            $source_page = 'abj404_redirects';
                        }

                        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page;
                        $redirect_url .= "&updated=1";

                        $source_filter = $this->f->getPostOrGetSanitize('source_filter', '');
                        if ($source_filter !== '' && $source_filter !== '0') {
                            $redirect_url .= "&filter=" . urlencode($source_filter);
                        }

                        $source_orderby = $this->f->getPostOrGetSanitize('source_orderby', '');
                        $source_order = $this->f->getPostOrGetSanitize('source_order', '');
                        if ($source_orderby !== '' && $source_order !== '') {
                            if (!($source_orderby === "url" && $source_order === "ASC")) {
                                $redirect_url .= "&orderby=" . urlencode($source_orderby);
                                $redirect_url .= "&order=" . urlencode($source_order);
                            }
                        }

                        $source_paged = $this->f->getPostOrGetSanitize('source_paged', '');
                        if ($source_paged !== '' && (int)$source_paged > 1) {
                            $redirect_url .= "&paged=" . urlencode($source_paged);
                        }

                        wp_safe_redirect(admin_url('admin.php' . $redirect_url));
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
                $this->redirectsRepo->updateRedirect($tdType, $tdDest,
                        $fromURL, $id, $code, (string)$statusType, $startTs, $endTs);
                if ($autoPromote['autoPromoted']) {
                    $this->saveRegexAutoPromoteNotice($id, $originalFromURL, $fromURL, $autoPromote['urlRewritten']);
                }

                if ($id > 0) {
                    $this->redirectsRepo->saveRedirectConditions($id, $sanitizedConditions);
                }
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();

            } else if ($ids_multiple != "") {
                $redirects_multiple = $this->redirectsRepo->getRedirectsByIDs($ids_multiple);
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                foreach ($redirects_multiple as $redirect) {
                    $redirectUrl = is_string($redirect['url']) ? $redirect['url'] : '';
                    $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                    $this->redirectsRepo->updateRedirect($tdType, $tdDest,
                            $redirectUrl, $redirectId, $code, (string)$statusType);
                }
                $this->viewBuild->markViewDoneInvalidatedByAdminMutation();

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

            $newRedirectId = $this->redirectsRepo->setupRedirect($manualURL, (string)$statusType,
                    $tdType2, $tdDest2,
                    sanitize_text_field($code), 0);
            if ($autoPromoteAdd['autoPromoted']) {
                $this->saveRegexAutoPromoteNotice((int)$newRedirectId, $originalManualURL, $manualURL, $autoPromoteAdd['urlRewritten']);
            }
            $this->viewBuild->markViewDoneInvalidatedByAdminMutation();

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
        $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
    private function handleActionImportFromPlugin(): string {
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
            $this->viewBuild->markViewDoneInvalidatedByAdminMutation();
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
