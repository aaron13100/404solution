<?php

/* the glue that holds it together / everything else. */

class ABJ_404_Solution_PluginLogic {

    /** 
     * @param type $skip_db_check
     * @return array
     */
    function getOptions($skip_db_check = "0") {
        global $abj404logic;
        $options = get_option('abj404_settings');

        if ($options == "") {
            add_option('abj404_settings', '', '', 'no');
        }

        // Check to make sure we aren't missing any new options.
        $defaults = $this->getDefaultOptions();
        $missing = false;
        foreach ($defaults as $key => $value) {
            if (!isset($options[$key]) || '' === $options[$key]) {
                $options[$key] = $value;
                $missing = true;
            }
        }

        if ($missing) {
            update_option('abj404_settings', $options);
        }

        if ($skip_db_check == "0") {
            if ($options['DB_VERSION'] != ABJ404_VERSION) {
                if (ABJ404_VERSION == "1.3.2") {
                    //Unregister all crons. Some were bad.
                    ABJ_404_Solution_PluginLogic::doUnregisterCrons();

                    //Register the good ones
                    $abj404logic->doRegisterCrons();
                }
                $options = $abj404logic->doUpdateDBVersionOption();
            }
        }

        return $options;
    }

    /** 
     * @return array
     */
    function getDefaultOptions() {
        $options = array(
            'default_redirect' => '301',
            'capture_404' => '1',
            'capture_deletion' => 1095,
            'manual_deletion' => '0',
            'admin_notification' => '200',
            'remove_matches' => '1',
            'display_suggest' => '1',
            'suggest_minscore' => '25',
            'suggest_max' => '5',
            'suggest_title' => '<h3>' . __('Suggested Alternatives', '404-solution') . '</h3>',
            'suggest_before' => '<ol>',
            'suggest_after' => '</ol>',
            'suggest_entrybefore' => '<li>',
            'suggest_entryafter' => '</li>',
            'suggest_noresults' => '<p>' . __('No Results To Display.', '404-solution') . '</p>',
            'suggest_cats' => '1',
            'suggest_tags' => '1',
            'auto_redirects' => '1',
            'auto_score' => '90',
            'auto_deletion' => '1095',
            'auto_cats' => '1',
            'auto_tags' => '1',
            'force_permalinks' => '1',
            'dest404page' => 'none',
        );
        return $options;
    }

    function doUpdateDBVersionOption() {
        global $abj404logic;

        $options = $abj404logic->getOptions(1);

        $options['DB_VERSION'] = ABJ404_VERSION;

        update_option('abj404_settings', $options);

        return $options;
    }

    /** Remove cron jobs. */
    static function doUnregisterCrons() {
        $crons = array('abj404_cleanupCronAction', 'abj404_duplicateCronAction', 'removeDuplicatesCron', 'deleteOldRedirectsCron');
        for ($i = 0; $i < count($crons); $i++) {
            $cron_name = $crons[$i];
            $timestamp1 = wp_next_scheduled($cron_name);
            while ($timestamp1 != False) {
                wp_unschedule_event($timestamp1, $cron_name);
                $timestamp1 = wp_next_scheduled($cron_name);
            }

            $timestamp2 = wp_next_scheduled($cron_name, '');
            while ($timestamp2 != False) {
                wp_unschedule_event($timestamp2, $cron_name, '');
                $timestamp2 = wp_next_scheduled($cron_name, '');
            }

            wp_clear_scheduled_hook($cron_name);
        }
    }

    /** Create database tables. Register crons. etc.
     * @global type $abj404dao */
    static function runOnPluginActivation() {
        global $abj404dao;
        global $abj404logic;
        add_option('abj404_settings', '', '', 'no');

        $abj404dao->createDatabaseTables();

        $abj404logic->doRegisterCrons();

        $abj404logic->doUpdateDBVersionOption();
    }

    function doRegisterCrons() {
        $timestampc = wp_next_scheduled('abj404_cleanupCronAction');
        if ($timestampc == False) {
            wp_schedule_event(current_time('timestamp') - 86400, 'daily', 'abj404_cleanupCronAction');
        }

        $timestampd = wp_next_scheduled('abj404_duplicateCronAction');
        if ($timestampd == False) {
            wp_schedule_event(current_time('timestamp') - 3600, 'hourly', 'abj404_duplicateCronAction');
        }
    }
    
    /** Do the passed in action and return the associated message. 
     * @global type $abj404logic
     * @param type $action
     * @param string $sub
     * @return type
     */
    function handlePluginAction($action, &$sub) {
        global $abj404logic;
        $message = "";
        
        if ($action == "updateOptions") {
            if (check_admin_referer('abj404UpdateOptions') && is_admin()) {
                $sub = "abj404_options";
                $message = $this->updateOptionsFromPOST();
                if ($message == "") {
                    $message = __('Options Saved Successfully!', '404-solution');
                } else {
                    $message .= __('Some options were not saved successfully.', '404-solution');
                }
            }
        } else if ($action == "addRedirect") {
            if (check_admin_referer('abj404addRedirect') && is_admin()) {
                $message = $this->addAdminRedirect();
                if ($message == "") {
                    $message = __('New Redirect Added Successfully!', '404-solution');
                } else {
                    $message .= __('Error: unable to add new redirect.', '404-solution');
                }
            }
        } else if ($action == "emptyRedirectTrash") {
            if (check_admin_referer('abj404_emptyRedirectTrash') && is_admin()) {
                $abj404logic->doEmptyTrash('redirects');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            }
        } else if ($action == "emptyCapturedTrash") {
            if (check_admin_referer('abj404_emptyCapturedTrash') && is_admin()) {
                $abj404logic->doEmptyTrash('captured');
                $message = __('All trashed URLs have been deleted!', '404-solution');
            }
        } else if ($action == "bulkignore" || $action == "bulkcaptured" || $action == "bulktrash") {
            if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
                $message = $abj404logic->doBulkAction($action, filter_input(INPUT_POST, "idnum", FILTER_SANITIZE_STRING));
            }
        } else if ($action == "purgeRedirects") {
            if (check_admin_referer('abj404_purgeRedirects') && is_admin()) {
                $message = $abj404dao->deleteSpecifiedRedirects();
            }
        }
        
        return $message;
    }

    /** Move redirects to trash. 
     * @return type
     */
    function hanldeTrashAction() {
        global $abj404dao;
        
        $message = "";
        // Handle Trash Functionality
        if (isset($_GET['trash'])) {
            if (check_admin_referer('abj404_trashRedirect') && is_admin()) {
                $trash = "";
                if ($_GET['trash'] == 0) {
                    $trash = 0;
                } else if ($_GET['trash'] == 1) {
                    $trash = 1;
                }
                if ($trash == 0 || $trash == 1) {
                    $message = $abj404dao->moveRedirectsToTrash(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT), $trash);
                    if ($message == "") {
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
        }
        
        return $message;
    }
    
    /** Delete redirects.
     * @global type $abj404dao
     * @return type
     */
    function handleDeleteAction() {
        global $abj404dao;
        $message = "";
        
        //Handle Delete Functionality
        if (isset($_GET['remove']) && $_GET['remove'] == 1) {
            if (check_admin_referer('abj404_removeRedirect') && is_admin()) {
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    $sanitize_id = absint(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT));
                    $abj404dao->deleteRedirect($sanitize_id);
                    $message = __('Redirect Removed Successfully!', '404-solution');
                }
            }
        }
        
        return $message;
    }
    
    /** Set a redirect as ignored.
     * @return type
     */
    function handleIgnoreAction() {
        global $abj404dao;
        $message = "";
        
        //Handle Ignore Functionality
        if (isset($_GET['ignore'])) {
            if (check_admin_referer('abj404_ignore404') && is_admin()) {
                if ($_GET['ignore'] == 0 || $_GET['ignore'] == 1) {
                    if (preg_match('/[0-9]+/', $_GET['id'])) {
                        if ($_GET['ignore'] == 1) {
                            $newstatus = ABJ404_IGNORED;
                        } else {
                            $newstatus = ABJ404_CAPTURED;
                        }
                        $message = $abj404dao->updateRedirectTypeStatus(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT), $newstatus);
                        if ($message == "") {
                            if ($newstatus == ABJ404_CAPTURED) {
                                $message = __('Removed 404 URL from ignored list successfully!', '404-solution');
                            } else {
                                $message = __('404 URL marked as ignored successfully!', '404-solution');
                            }
                        } else {
                            if ($newstatus == ABJ404_CAPTURED) {
                                $message = __('Error: unable to remove URL from ignored list', '404-solution');
                            } else {
                                $message = __('Error: unable to mark URL as ignored', '404-solution');
                            }
                        }
                    }
                }
            }
        }

        return $message;
    }
    
    /** Edit redirect data.
     * @return type
     */
    function handleEditAction(&$sub) {
        $message = "";
        
        //Handle edit posts
        if (isset($_POST['action']) && $_POST['action'] == "editRedirect") {
            if (isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id'])) {
                if (check_admin_referer('abj404editRedirect') && is_admin()) {
                    $message = $this->updateRedirectData();
                    if ($message == "") {
                        $message .= __('Redirect Information Updated Successfully!', '404-solution');
                        $sub = "redirects";
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
     * @param type $action
     * @param type $ids
     * @return string
     */
    function doBulkAction($action, $ids) {
        global $abj404dao;
        $message = "";

        // nonce already verified.

        if ($action == "bulkignore" || $action == "bulkcaptured") {
            if ($action == "bulkignore") {
                $status = ABJ404_IGNORED;
            } else if ($action == "bulkcaptured") {
                $status = ABJ404_CAPTURED;
            } else {
                ABJ_404_Solution_Functions::errorMessage("Unrecognized bulk action: " + esc_html($action));
                echo "Unrecognized bulk action: " + esc_html($action);
                return;
            }
            $count = 0;
            foreach ($ids as $id) {
                $s = $abj404dao->updateRedirectTypeStatus($id, $status);
                if ($s == "") {
                    $count++;
                }
            }
            if ($action == "bulkignore") {
                $message = $count . " " . __('URLs marked as ignored.', '404-solution');
            } else if ($action == "bulkcaptured") {
                $message = $count . " " . __('URLs marked as captured.', '404-solution');
            } else {
                ABJ_404_Solution_Functions::errorMessage("Unrecognized bulk action: " + esc_html($action));
            }

        } else if ($action == "bulktrash") {
            $count = 0;
            foreach ($ids as $id) {
                $s = $abj404dao->moveRedirectsToTrash($id, 1);
                if ($s == "") {
                    $count ++;
                }
            }
            $message = $count . " " . __('URLs moved to trash', '404-solution');

        } else {
            ABJ_404_Solution_Functions::errorMessage("Unrecognized bulk action: " + esc_html($action));
            echo "Unrecognized bulk action: " + esc_html($action);
        }
        return $message;
    }

    /** 
     * This is for both empty trash buttons (page redirects and captured 404 URLs).
     * @param type $sub
     */
    function doEmptyTrash($sub) {
        global $abj404dao;

        $tableOptions = $this->getTableOptions();

        $rows = $abj404dao->getRedirects($sub, $tableOptions, 0);

        // nonce already verified.

        foreach ($rows as $row) {
            $abj404dao->deleteRedirect($row['id']);
        }
    }
    
    function updateRedirectData() {
        global $abj404dao;
        $message = "";

        if ($_POST['url'] != "") {
            if (substr($_POST['url'], 0, 1) != "/") {
                $message .= __('Error: URL must start with /', '404-solution') . "<br>";
            }
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<br>";
        }

        if ($_POST['dest'] == "EXTERNAL") {
            if ($_POST['external'] == "") {
                $message .= __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<br>";
            } else {
                if (substr($_POST['external'], 0, 7) != "http://" && substr($_POST['external'], 0, 8) != "https://" && substr($_POST['external'], 0, 6) != "ftp://") {
                    $message .= __('Error: External URL\'s must start with http://, https://, or ftp://', '404-solution') . "<br>";
                }
            }
        }

        if ($message == "") {
            $type = "";
            $dest = "";
            if ($_POST['dest'] === "" . ABJ404_EXTERNAL) {
                $type = ABJ404_EXTERNAL;
                $dest = esc_sql(filter_input(INPUT_POST, "external", FILTER_SANITIZE_URL));
            } else {
                $info = explode("|", filter_input(INPUT_POST, "dest", FILTER_SANITIZE_STRING));
                if (count($info) == 2) {
                    $dest = $info[0];
                    if ($info[1] == ABJ404_POST) {
                        $type = ABJ404_POST;
                    } else if ($info[1] == ABJ404_CAT) {
                        $type = ABJ404_CAT;
                    } else if ($info[1] == ABJ404_TAG) {
                        $type = ABJ404_TAG;
                    }
                }
            }

            if ($type != "" && $dest != "") {
                $abj404dao->updateRedirect($type, $dest);

                $_POST['url'] = "";
                $_POST['code'] = "";
                $_POST['external'] = "";
                $_POST['dest'] = "";
            } else {
                $message .= __('Error: Data not formatted properly.', '404-solution') . "<br>";
            }
        }

        return $message;
    }
    
    function addAdminRedirect() {
        global $abj404dao;
        $message = "";

        if ($_POST['url'] != "") {
            if (substr($_POST['url'], 0, 1) != "/") {
                $message .= __('Error: URL must start with /', '404-solution') . "<br>";
            }
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<br>";
        }

        if ($_POST['dest'] == "EXTERNAL") {
            if ($_POST['external'] == "") {
                $message .= __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<br>";
            } else {
                if (substr($_POST['external'], 0, 7) != "http://" && substr($_POST['external'], 0, 8) != "https://" && substr($_POST['external'], 0, 6) != "ftp://") {
                    $message .= __('Error: External URL\'s must start with http://, https://, or ftp://', '404-solution') . "<br>";
                }
            }
        }

        if ($message == "") {
            $type = "";
            $dest = "";
            if ($_POST['dest'] == "EXTERNAL") {
                $type = ABJ404_EXTERNAL;
                $dest = esc_sql(filter_input(INPUT_POST, "external", FILTER_SANITIZE_URL));
                ;
            } else {
                $info = explode("|", filter_input(INPUT_POST, "dest", FILTER_SANITIZE_STRING));
                if (count($info) == 2) {
                    $dest = $info[0];
                    if ($info[1] == "POST") {
                        $type = ABJ404_POST;
                    } else if ($info[1] == "CAT") {
                        $type = ABJ404_CAT;
                    } else if ($info[1] == "TAG") {
                        $type = ABJ404_TAG;
                    }
                }
            }
            if ($type != "" && $dest != "") {

                // nonce already verified.

                $abj404dao->setupRedirect(esc_sql(filter_input(INPUT_POST, "url", FILTER_SANITIZE_URL)), ABJ404_MANUAL, $type, $dest, esc_sql(filter_input(INPUT_POST, "code", FILTER_SANITIZE_STRING)), 0);
                $_POST['url'] = "";
                $_POST['code'] = "";
                $_POST['external'] = "";
                $_POST['dest'] = "";
            } else {
                $message .= __('Error: Data not formatted properly.', '404-solution') . "<br>";
            }
        }

        return $message;
    }

    /** 
     * @global type $abj404dao
     * @return 
     */
    function getTableOptions() {
        global $abj404dao;
        $tableOptions = array();

        $tableOptions['filter'] = $abj404dao->getPostOrGetSanitize("filter", "");
        if ($tableOptions['filter'] == "") {
            if (isset($_GET['subpage']) && $_GET['subpage'] == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        }

        if (isset($_GET['orderby'])) {
            $tableOptions['orderby'] = filter_input(INPUT_GET, "orderby", FILTER_SANITIZE_STRING);
        } else {
            if (isset($_GET['subpage']) && $_GET['subpage'] == "abj404_logs") {
                $tableOptions['orderby'] = "timestamp";
            } else {
                $tableOptions['orderby'] = "url";
            }
        }

        if (isset($_GET['order'])) {
            $tableOptions['order'] = filter_input(INPUT_GET, "order", FILTER_SANITIZE_STRING);
        } else {
            if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
                $tableOptions['order'] = "DESC";
            } else {
                $tableOptions['order'] = "ASC";
            }
        }

        $tableOptions['paged'] = $abj404dao->getPostOrGetSanitize("paged", 1);

        $tableOptions['perpage'] = $abj404dao->getPostOrGetSanitize("perpage", ABJ404_OPTION_DEFAULT_PERPAGE);

        if (isset($_GET['subpage']) && $_GET['subpage'] == "abj404_logs") {
            if (isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {
                $tableOptions['logsid'] = filter_input(INPUT_GET, "id", FILTER_SANITIZE_STRING);
            } else {
                $tableOptions['logsid'] = 0;
            }
        }

        // sanitize all values.
        foreach ($tableOptions as &$value) {
            $value = esc_sql(sanitize_text_field($value));
        }
        unset($value);

        return $tableOptions;
    }
    
    /** 
     * @global type $abj404logic
     * @return string
     */
    function updateOptionsFromPOST() {
        global $abj404logic;

        $message = "";
        $options = $abj404logic->getOptions();
        if ($_POST['default_redirect'] == "301" || $_POST['default_redirect'] == "302") {
            $options['default_redirect'] = filter_input(INPUT_POST, "default_redirect", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Error: Invalid value specified for default redirect type', '404-solution') . ".<br>";
        }

        if ($_POST['capture_404'] == "1") {
            $options['capture_404'] = '1';
        } else {
            $options['capture_404'] = '0';
        }

        if (preg_match('/^[0-9]+$/', $_POST['admin_notification']) == 1) {
            $options['admin_notification'] = filter_input(INPUT_POST, "admin_notification", FILTER_SANITIZE_STRING);
        }

        if (preg_match('/^[0-9]+$/', $_POST['capture_deletion']) == 1 && $_POST['capture_deletion'] >= 0) {
            $options['capture_deletion'] = filter_input(INPUT_POST, "capture_deletion", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Collected URL deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        if (preg_match('/^[0-9]+$/', $_POST['manual_deletion']) == 1 && $_POST['manual_deletion'] >= 0) {
            $options['manual_deletion'] = filter_input(INPUT_POST, "manual_deletion", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Manual redirect deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        if ($_POST['remove_matches'] == "1") {
            $options['remove_matches'] = '1';
        } else {
            $options['remove_matches'] = '0';
        }

        if (isset($_POST['debug_mode']) && $_POST['debug_mode'] == "1") {
            $options['debug_mode'] = '1';
        } else {
            $options['debug_mode'] = '0';
        }

        if ($_POST['display_suggest'] == "1") {
            $options['display_suggest'] = '1';
        } else {
            $options['display_suggest'] = '0';
        }

        if ($_POST['suggest_cats'] == "1") {
            $options['suggest_cats'] = '1';
        } else {
            $options['suggest_cats'] = '0';
        }

        if ($_POST['suggest_tags'] == "1") {
            $options['suggest_tags'] = '1';
        } else {
            $options['suggest_tags'] = '0';
        }

        if (preg_match('/^[0-9]+$/', $_POST['suggest_minscore']) == 1 && $_POST['suggest_minscore'] >= 0 && $_POST['suggest_minscore'] <= 99) {
            $options['suggest_minscore'] = filter_input(INPUT_POST, "suggest_minscore", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Suggestion minimum score value must be a number between 1 and 99', '404-solution') . ".<br>";
        }

        if (preg_match('/^[0-9]+$/', $_POST['suggest_max']) == 1 && $_POST['suggest_max'] >= 1) {
            $options['suggest_max'] = filter_input(INPUT_POST, "suggest_max", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Maximum number of suggest value must be a number greater or equal to 1', '404-solution') . ".<br>";
        }

        // the suggest_.* options have html in them.
        $options['suggest_title'] = wp_kses_post($_POST['suggest_title']);
        $options['suggest_before'] = wp_kses_post($_POST['suggest_before']);
        $options['suggest_after'] = wp_kses_post($_POST['suggest_after']);
        $options['suggest_entrybefore'] = wp_kses_post($_POST['suggest_entrybefore']);
        $options['suggest_entryafter'] = wp_kses_post($_POST['suggest_entryafter']);
        $options['suggest_noresults'] = wp_kses_post($_POST['suggest_noresults']);

        if (isset($_POST['dest404page'])) {
            $options['dest404page'] = filter_input(INPUT_POST, "dest404page", FILTER_SANITIZE_STRING);
        } else {
            $options['dest404page'] = 'none';
        }

        if ($_POST['auto_redirects'] == "1") {
            $options['auto_redirects'] = '1';
        } else {
            $options['auto_redirects'] = '0';
        }

        if (isset($_POST['auto_cats']) && $_POST['auto_cats'] == "1") {
            $options['auto_cats'] = '1';
        } else {
            $options['auto_cats'] = '0';
        }

        if (isset($_POST['auto_tags']) && $_POST['auto_tags'] == "1") {
            $options['auto_tags'] = '1';
        } else {
            $options['auto_tags'] = '0';
        }

        if (preg_match('/^[0-9]+$/', $_POST['auto_score']) == 1 && $_POST['auto_score'] >= 0 && $_POST['auto_score'] <= 99) {
            $options['auto_score'] = filter_input(INPUT_POST, "auto_score", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Auto match score value must be a number between 0 and 99', '404-solution') . ".<br>";
        }


        if (preg_match('/^[0-9]+$/', $_POST['auto_deletion']) == 1 && $_POST['auto_deletion'] >= 0) {
            $options['auto_deletion'] = filter_input(INPUT_POST, "auto_deletion", FILTER_SANITIZE_STRING);
        } else {
            $message .= __('Auto redirect deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        if ($_POST['force_permalinks'] == "1") {
            $options['force_permalinks'] = '1';
        } else {
            $options['force_permalinks'] = '0';
        }

        /** Sanitize all data. */
        foreach ($options as $key => $option) {
            $new_key = wp_kses_post($key);
            $new_option = wp_kses_post($option);
            $new_options[$new_key] = $new_option;
        }

        update_option('abj404_settings', $new_options);

        return $message;
    }
}
