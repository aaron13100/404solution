<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin action handlers: trash, delete, ignore, later, edit, bulk actions, empty trash.
 * Used by ABJ_404_Solution_PluginLogic via `use`.
 */
trait ABJ_404_Solution_PluginLogicTrait_AdminActions {

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
        } else if ($action == "saveGscSettings") {
            if (check_admin_referer('abj404_gsc_save', '_wpnonce_gsc') && is_admin()) {
                $logger = ABJ_404_Solution_Logging::getInstance();
                $gsc = new ABJ_404_Solution_GoogleSearchConsole($logger);
                $error = $gsc->saveSettings($_POST);
                $message = ($error === '') ? __('Google Search Console credentials saved.', '404-solution') : $error;
            } else {
                $this->logger->debugMessage("saveGscSettings security check failed. is_admin: " . is_admin());
            }
        } else if ($action == "importFromPlugin") {
            if (check_admin_referer('abj404_importFromPlugin') && is_admin()) {
                $message = $this->handleActionImportFromPlugin();
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
            return;
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

            // Parse scheduled redirect dates from POST data
            $startDateRaw = isset($_POST['redirect_start_date']) && is_string($_POST['redirect_start_date']) ? trim($_POST['redirect_start_date']) : '';
            $endDateRaw = isset($_POST['redirect_end_date']) && is_string($_POST['redirect_end_date']) ? trim($_POST['redirect_end_date']) : '';
            $startTs = ($startDateRaw !== '') ? strtotime($startDateRaw . ' 00:00:00') : null;
            $endTs = ($endDateRaw !== '') ? strtotime($endDateRaw . ' 23:59:59') : null;
            // Treat strtotime failures as null
            if ($startTs === false) { $startTs = null; }
            if ($endTs === false) { $endTs = null; }

            // Sanitize and collect conditions from POST data.
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

            // decide whether we're updating one or multiple redirects.
            if ($fromURL != "") {
                $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int)$_POST['id'] : 0;
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                $this->dao->updateRedirect($tdType, $tdDest,
                        $fromURL, $id, $code, (string)$statusType, $startTs, $endTs);

                // Save conditions only for single-redirect edits (bulk edit has no conditions UI).
                if ($id > 0) {
                    $this->dao->saveRedirectConditions($id, $sanitizedConditions);
                }

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

        // 410 Gone and 451 Unavailable For Legal Reasons have no destination URL — bypass destination validation.
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
            // Use the sanitized $userEnteredURL instead of raw POST
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
        $postedCodeForCheck2 = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        $code410 = $postedCodeForCheck2 === '410' || $postedCodeForCheck2 === '451';
        if ($tdType2 != "" && ($tdDest2 !== "" || $code410)) {
            // url match type. regex or normal exact match.
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            // Note: use !== '' instead of !empty() because empty('0') is true in PHP,
            // which would incorrectly discard code=0 (Meta Refresh).
            $code = isset($_POST['code']) && is_scalar($_POST['code']) && (string)$_POST['code'] !== '' ? (string)$_POST['code'] : '301';

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
     * Handle the importFromPlugin POST action.
     * Reads the selected source plugin from $_POST['import_source'] and delegates
     * to CrossPluginImporter::importFrom().
     *
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
