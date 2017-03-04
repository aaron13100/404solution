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
            if (!isset( $options[$key]) || '' == $options[$key]) {
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
            'dest404page' => '0',
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
        global $abj404dao;
        
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
                $message = $abj404logic->doBulkAction($action, array_map('absint', $_POST['idnum']));
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
                } else {
                    ABJ_404_Solution_Functions::debugMessage("Unexpected trash operation: " . 
                            esc_html($_GET['trash']));
                    $message = __('Error: Bad trash operation specified.', '404-solution');
                    return $message;
                }
                
                $message = $abj404dao->moveRedirectsToTrash(absint($_GET['id']), $trash);
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
        if (@$_GET['remove'] == 1) {
            if (check_admin_referer('abj404_removeRedirect') && is_admin()) {
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    $abj404dao->deleteRedirect(absint($_GET['id']));
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
                if ($_GET['ignore'] != 0 && $_GET['ignore'] != 1) {
                    ABJ_404_Solution_Functions::debugMessage("Unexpected ignore operation: " . 
                            esc_html($_GET['ignore']));
                    $message = __('Error: Bad ignore operation specified.', '404-solution');
                    return $message;                    
                }
                
                if (preg_match('/[0-9]+/', $_GET['id'])) {
                    if ($_GET['ignore'] == 1) {
                        $newstatus = ABJ404_IGNORED;
                    } else {
                        $newstatus = ABJ404_CAPTURED;
                    }
                    
                    $message = $abj404dao->updateRedirectTypeStatus(absint($_GET['id']), $newstatus);
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

        return $message;
    }
    
    /** Edit redirect data.
     * @return type
     */
    function handleEditAction(&$sub) {
        $message = "";
        
        //Handle edit posts
        if (@$_POST['action'] == "editRedirect") {
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
                echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
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
                echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
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
            echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
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
                $dest = esc_url($_POST['external']);
            } else {
                $info = explode("|", sanitize_text_field($_POST['dest']));
                if (count($info) == 2) {
                    $dest = absint($info[0]);
                    if ($info[1] == ABJ404_POST) {
                        $type = ABJ404_POST;
                    } else if ($info[1] == ABJ404_CAT) {
                        $type = ABJ404_CAT;
                    } else if ($info[1] == ABJ404_TAG) {
                        $type = ABJ404_TAG;
                    } else {
                        ABJ_404_Solution_Functions::errorMessage("Unrecognized type while updating redirect: " . 
                                esc_html($type));
                    }
                } else {
                    ABJ_404_Solution_Functions::errorMessage("Unexpected info while updating redirect: " . 
                            wp_kses_post(json_encode($info)));
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

        if ($_POST['url'] == "") {
            $message .= __('Error: URL is a required field.', '404-solution') . "<br>";
            return $message;
        }
            
        if (substr($_POST['url'], 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<br>";
            return $message;
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
                $dest = esc_url($_POST['external']);
                
            } else {
                $info = explode("|", sanitize_text_field($_POST['dest']));
                if (count($info) == 2) {
                    $dest = absint($info[0]);
                    if ($info[1] == "POST") {
                        $type = ABJ404_POST;
                    } else if ($info[1] == "CAT") {
                        $type = ABJ404_CAT;
                    } else if ($info[1] == "TAG") {
                        $type = ABJ404_TAG;
                    } else {
                        ABJ_404_Solution_Functions::debugMessage("Unrecognized redirect type requested: " . 
                                esc_html($info[1]));
                    }
                }
            }
            if ($type != "" && $dest != "") {

                // nonce already verified.

                $abj404dao->setupRedirect(esc_url($_POST['url']), ABJ404_MANUAL, $type, $dest, sanitize_text_field($_POST['code']), 0);
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
            if (@$_GET['subpage'] == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        }

        if (isset($_GET['orderby'])) {
            $tableOptions['orderby'] = esc_sql($_GET['orderby']);
        } else if (@$_GET['subpage'] == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
        } else {
            $tableOptions['orderby'] = "url";
        }

        if (isset($_GET['order'])) {
            $tableOptions['order'] = esc_sql($_GET['order']);
        } else if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";
        } else {
            $tableOptions['order'] = "ASC";
        }

        $tableOptions['paged'] = $abj404dao->getPostOrGetSanitize("paged", 1);

        $tableOptions['perpage'] = $abj404dao->getPostOrGetSanitize("perpage", ABJ404_OPTION_DEFAULT_PERPAGE);

        if (@$_GET['subpage'] == "abj404_logs") {
            if (isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {                
                $tableOptions['logsid'] = absint($_GET['id']);
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
            $options['default_redirect'] = intval($_POST['default_redirect']);
        } else {
            $message .= __('Error: Invalid value specified for default redirect type', '404-solution') . ".<br>";
        }

        if ($_POST['capture_404'] == "1") {
            $options['capture_404'] = '1';
        } else {
            $options['capture_404'] = '0';
        }

        if (preg_match('/^[0-9]+$/', $_POST['admin_notification']) == 1) {
            $options['admin_notification'] = absint($_POST['admin_notification']);
        }

        if (preg_match('/^[0-9]+$/', $_POST['capture_deletion']) == 1 && $_POST['capture_deletion'] >= 0) {
            $options['capture_deletion'] = absint($_POST['capture_deletion']);
        } else {
            $message .= __('Collected URL deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        if (preg_match('/^[0-9]+$/', $_POST['manual_deletion']) == 1 && $_POST['manual_deletion'] >= 0) {
            $options['manual_deletion'] = absint($_POST['manual_deletion']);
        } else {
            $message .= __('Manual redirect deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        $options['remove_matches'] = ($_POST['remove_matches'] == "1") ? 1 : 0;
        $options['debug_mode'] = (@$_POST['debug_mode'] == "1") ? 1 : 0;
        $options['display_suggest'] = ($_POST['display_suggest'] == "1") ? 1 : 0;
        $options['suggest_cats'] = ($_POST['suggest_cats'] == "1") ? 1 : 0;
        $options['suggest_tags'] = ($_POST['suggest_tags'] == "1") ? 1 : 0;

        if (preg_match('/^[0-9]+$/', $_POST['suggest_minscore']) == 1 && $_POST['suggest_minscore'] >= 0 && $_POST['suggest_minscore'] <= 99) {
            $options['suggest_minscore'] = absint($_POST['suggest_minscore']);
        } else {
            $message .= __('Suggestion minimum score value must be a number between 1 and 99', '404-solution') . ".<br>";
        }

        if (preg_match('/^[0-9]+$/', $_POST['suggest_max']) == 1 && $_POST['suggest_max'] >= 1) {
            $options['suggest_max'] = absint($_POST['suggest_max']);
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

        if (preg_match('/^[0-9]+$/', $_POST['dest404page']) == 1 && $_POST['dest404page'] >= 1) {
            $options['dest404page'] = absint($_POST['dest404page']);
        } else {
            $options['dest404page'] = 0;
        }

        $options['auto_redirects'] = (@$_POST['auto_redirects'] == "1") ? 1 : 0;
        $options['auto_cats'] = (@$_POST['auto_cats'] == "1") ? 1 : 0;
        $options['auto_tags'] = (@$_POST['auto_tags'] == "1") ? 1 : 0;

        if (preg_match('/^[0-9]+$/', $_POST['auto_score']) == 1 && $_POST['auto_score'] >= 0 && $_POST['auto_score'] <= 99) {
            $options['auto_score'] = absint($_POST['auto_score']);
        } else {
            $message .= __('Auto match score value must be a number between 0 and 99', '404-solution') . ".<br>";
        }

        if (preg_match('/^[0-9]+$/', $_POST['auto_deletion']) == 1 && $_POST['auto_deletion'] >= 0) {
            $options['auto_deletion'] = absint($_POST['auto_deletion']);
        } else {
            $message .= __('Auto redirect deletion value must be a number greater or equal to zero', '404-solution') . ".<br>";
        }

        $options['force_permalinks'] = (@$_POST['force_permalinks'] == "1") ? 1 : 0;

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
