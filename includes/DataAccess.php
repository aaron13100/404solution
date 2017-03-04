<?php

/* Functions in this class should all reference the one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess {

    /** Create the tables when the plugin is first activated. 
     * @global type $wpdb
     */
    static function createDatabaseTables() {
        global $wpdb;
        
        $charset_collate = '';
        if (!empty($wpdb->charset)) {
            $charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
        }
        if (!empty($wpdb->collate)) {
            $charset_collate .= " COLLATE $wpdb->collate";
        }
        $query = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "abj404_redirects` (
              `id` bigint(30) NOT NULL auto_increment,
              `url` varchar(512) NOT NULL,
              `status` bigint(20) NOT NULL,
              `type` bigint(20) NOT NULL,
              `final_dest` varchar(512) NOT NULL,
              `code` bigint(20) NOT NULL,
              `disabled` int(10) NOT NULL default '0',
              `timestamp` bigint(30) NOT NULL,
              PRIMARY KEY  (`id`),
              KEY `status` (`status`),
              KEY `type` (`type`),
              KEY `code` (`code`),
              KEY `timestamp` (`timestamp`),
              KEY `disabled` (`disabled`),
              FULLTEXT KEY `url` (`url`),
              FULLTEXT KEY `final_dest` (`final_dest`)
            ) ENGINE=MyISAM " . esc_html($charset_collate) . " COMMENT='404 Solution Plugin Redirects Table' AUTO_INCREMENT=1";
        $wpdb->query($query);

        $query = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "abj404_logs` (
              `id` bigint(40) NOT NULL auto_increment,
              `redirect_id` bigint(40) NOT NULL,
              `timestamp` bigint(40) NOT NULL,
              `remote_host` varchar(512) NOT NULL,
              `referrer` varchar(512) NOT NULL,
              `action` varchar(512) NOT NULL,
              PRIMARY KEY  (`id`),
              KEY `redirect_id` (`redirect_id`),
              KEY `timestamp` (`timestamp`)
            ) ENGINE=MyISAM " . esc_html($charset_collate) . " COMMENT='404 Solution Plugin Logs Table' AUTO_INCREMENT=1";
        $wpdb->query($query);
        
        // TODO: optionally drop the logs table on the uninstall hook (not the deactivation hook).
        // see: https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
        // don't drop the other table though because that would lose all previous settings.
    }

   /**
    * @global type $wpdb
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       global $wpdb;

       $query = "select count(id) from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_CAPTURED;
       $captured = $wpdb->get_col($query, 0);
       if (count($captured) == 0) {
           $captured[0] = 0;
       }
       return intval($captured[0]);
   }

    /**
     * @global type $wpdb
     * @param type $types specified types such as ABJ404_MANUAL, ABJ404_AUTO, ABJ404_CAPTURED, ABJ404_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        global $wpdb;
        $recordCount = 0;

        if (count($types) >= 1) {

            $query = "select count(id) from " . $wpdb->prefix . "abj404_redirects where 1 and (";
            $x = 0;
            foreach ($types as $type) {
                if ($x >= 1) {
                    $query .= " or ";
                }
                $query .= "status = " . esc_sql($type);
                $x++;
            }
            $query .= ")";

            $query .= " and disabled = " . esc_sql($trashed);

            $row = $wpdb->get_row($query, ARRAY_N);
            $recordCount = $row[0];
        }

        return $recordCount;
    }

    /**
     * @global type $wpdb
     * @param int $redirectID only return results from this redirect ID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($redirectID) {
        global $wpdb;

        $query = "select count(id) from " . $wpdb->prefix . "abj404_logs where 1 ";
        if ($redirectID != 0) {
            $query .= "and redirect_id = " . esc_sql($redirectID);
        }
        $row = $wpdb->get_row($query, ARRAY_N);
        $records = $row[0];

        return intval($records);
    }

    /** 
     * Return the dates/times when a redirect was used.
     * @global type $wpdb
     * @param type $id
     * @return type
     */
    function getRedirectLastUsed($id) {
        global $wpdb;

        $query = $wpdb->prepare("select timestamp from " . $wpdb->prefix . "abj404_logs where redirect_id = %d order by timestamp desc", esc_sql($id));
        $row = $wpdb->get_col($query);

        if (isset($row[0])) {
            return $row[0];
        } else {
            return;
        }
    }
    
    /** 
     * @global type $wpdb
     * @return type
     */
    function getRedirectsAll() {
        global $wpdb;
        $query = "select id, url from " . $wpdb->prefix . "abj404_redirects order by url";
        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }

    /** Returns the redirects that are in place.
     * @global type $wpdb
     * @param type $sub either "redirects" or "captured".
     * @param type $tableOptions filter, order by, paged, perpage etc.
     * @param type $limitEnforced add "limit" to the query.
     * @return type rows from the redirects table.
     */
    function getRedirects($sub, $tableOptions, $limitEnforced = 1) {
        global $wpdb;

        $redirects = $wpdb->prefix . "abj404_redirects";
        $logs = $wpdb->prefix . "abj404_logs";

        $query = "select " . $redirects . ".id, " . $redirects . ".url, " . $redirects . ".status, " . $redirects . ".type, " . $redirects . ".final_dest, " . $redirects . ".code, " . $redirects . ".timestamp";
        $query .= ", count(" . $logs . ".id) as hits from " . $redirects . " ";
        $query .= " left outer join " . $logs . " on " . $redirects . ".id = " . $logs . ".redirect_id ";
        $query .= " where 1 and (";
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
            if ($sub == "redirects") {
                $query .= "status = " . ABJ404_MANUAL . " or status = " . ABJ404_AUTO;

            } else if ($sub == "captured") {
                $query .= "status = " . ABJ404_CAPTURED . " or status = " . ABJ404_IGNORED;

            } else {
                ABJ_404_Solution_Functions::errorMessage("Unrecognized sub type: " . esc_html($sub));
            }
        } else {
            $query .= "status = " . sanitize_text_field($tableOptions['filter']);
        }
        $query .= ") ";

        if ($tableOptions['filter'] != -1) {
            $query .= "and disabled = 0 ";
        } else {
            $query .= "and disabled = 1 ";
        }

        $query .= "group by " . $redirects . ".id ";

        $query .= "order by " . sanitize_text_field($tableOptions['orderby']) . " " . sanitize_text_field($tableOptions['order']) . " ";

        if ($limitEnforced == 1) {
            $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
            $query .= "limit " . $start . ", " . absint(sanitize_text_field($tableOptions['perpage']));
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }

    /**
     * @global type $wpdb
     * @param type $tableOptions logsid, orderby, paged, perpage, etc.
     * @return type rows from querying the logs table.
     */
    function getLogRecords($tableOptions) {
        global $wpdb;

        $logs = $wpdb->prefix . "abj404_logs";
        $redirects = $wpdb->prefix . "abj404_redirects";

        $query = "select " . $logs . ".redirect_id, " . $logs . ".timestamp, " . $logs . ".remote_host, " . $logs . ".referrer, " . $logs . ".action, " . $redirects . ".url from " . $logs;
        $query .= " left outer join " . $redirects . " on " . $logs . ".redirect_id = " . $redirects . ".id where 1 ";
        if ($tableOptions['logsid'] != 0) {
            $query .= " and redirect_id = " . sanitize_text_field($tableOptions['logsid']) . " ";
        }

        $query .= "order by " . sanitize_text_field($tableOptions['orderby']) . " " . sanitize_text_field($tableOptions['order']) . " ";
        $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
        $query .= "limit " . $start . ", " . absint(sanitize_text_field($tableOptions['perpage']));

        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }
    
    /** 
     * Log that a redirect was done.
     * @global type $wpdb
     * @param type $id
     * @param type $action
     */
    function logRedirectHit($id, $action) {
        global $wpdb;
        $now = time();

        // no nonce here because redirects are not user generated.

        $referer = @$_SERVER['HTTP_REFERER'];

        $wpdb->insert($wpdb->prefix . "abj404_logs", array(
            'redirect_id' => absint($id),
            'timestamp' => esc_html($now),
            'remote_host' => esc_html($_SERVER['REMOTE_ADDR']),
            'referrer' => esc_html($referer),
            'action' => esc_html($action),
                ), array(
            '%d',
            '%d',
            '%s',
            '%s',
            '%s'
                )
        );
    }

    /** Remove the redirect from the redirects table and from the logs.
     * @global type $wpdb
     * @param type $id
     */
    function deleteRedirect($id) {
        global $wpdb;
        $cleanedID = absint(sanitize_text_field($id));

        // no nonce here because this action is not always user generated.

        if ($cleanedID != "" && $cleanedID != '0') {
            $queryRedirects = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_redirects where id = %d", $cleanedID);
            $wpdb->query($queryRedirects);
            $queryLogs = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_logs where redirect_id = %d", $cleanedID);
            $wpdb->query($queryLogs);
        }
    }

    /** Delete old redirects based on how old they are. 
     * @global type $wpdb
     * @global type $abj404dao
     * @global type $abj404logic
     */
    static function deleteOldRedirectsCron() {
        global $wpdb;
        global $abj404dao;
        global $abj404logic;

        $options = $abj404logic->getOptions();
        $now = time();

        //Remove Captured URLs
        if ($options['capture_deletion'] != '0') {
            $capture_time = $options['capture_deletion'] * 86400;
            $then = $now - $capture_time;

            //Clean up old logs
            $query = "delete from " . $wpdb->prefix . "abj404_logs where ";
            $query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_CAPTURED . " or status = " . ABJ404_IGNORED . ") ";
            $query .= "and timestamp < " . esc_sql($then);
            $wpdb->query($query);

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where (status = " . ABJ404_CAPTURED . " or status = " . ABJ404_IGNORED . ") and ";
            $query .= "timestamp <= " . esc_sql($then) . " and id not in (";
            $query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
            $query .= ")";
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
            }
        }

        //Remove Automatic Redirects
        if ($options['auto_deletion'] != '0') {
            $auto_time = $options['auto_deletion'] * 86400;
            $then = $now - $auto_time;

            //Clean up old logs
            $query = "delete from " . $wpdb->prefix . "abj404_logs where ";
            $query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_AUTO . ") ";
            $query .= "and timestamp < " . esc_sql($then);
            $wpdb->query($query);

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_AUTO . " and ";
            $query .= "timestamp <= " . esc_sql($then) . " and id not in (";
            $query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
            $query .= ")";
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
            }
        }

        //Remove Manual Redirects
        if ($options['manual_deletion'] != '0') {
            $manual_time = $options['manual_deletion'] * 86400;
            $then = $now - $manual_time;

            //Clean up old logs
            $query = "delete from " . $wpdb->prefix . "abj404_logs where ";
            $query .= "redirect_id in (select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_MANUAL . ") ";
            $query .= "and timestamp < " . esc_sql($then);
            $wpdb->query($query);

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_MANUAL . " and ";
            $query .= "timestamp <= " . esc_sql($then) . " and id not in (";
            $query .= "select redirect_id from " . $wpdb->prefix . "abj404_logs";
            $query .= ")";
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
            }
        }
    }
    /** Remove duplicates. 
     * @global type $wpdb
     */
    static function removeDuplicatesCron() {
        global $wpdb;

        $rtable = $wpdb->prefix . "abj404_redirects";
        $ltable = $wpdb->prefix . "abj404_logs";

        $query = "SELECT COUNT(id) as repetitions, url FROM " . $rtable . " GROUP BY url HAVING repetitions > 1";
        $rows = $wpdb->get_results($query, ARRAY_A);
        foreach ($rows as $row) {
            $url = $row['url'];

            $queryr1 = "select id from " . $rtable . " where url = '" . esc_sql(esc_url($url)) . "' order by id limit 0,1";
            $orig = $wpdb->get_row($queryr1, ARRAY_A, 0);
            if ($orig['id'] != 0) {
                $original = $orig['id'];

                //Fix the logs table
                $queryr = "update " . $ltable . " set redirect_id = " . esc_sql($original) . " where redirect_id in (select id from " . esc_html($rtable) . " where url = '" . esc_sql($url) . "' and id != " . esc_sql($original) . ")";
                $wpdb->query($queryr);

                $queryl = "delete from " . $rtable . " where url='" . esc_sql(esc_url($url)) . "' and id != " . esc_sql($original);
                $wpdb->query($queryl);
            }
        }
    }

    /**
     * Store a redirect for future use.
     * @global type $wpdb
     * @param type $url
     * @param type $status
     * @param type $type
     * @param type $final_dest
     * @param type $code
     * @param type $disabled
     * @return type
     */
    function setupRedirect($url, $status, $type, $final_dest, $code, $disabled = 0) {
        global $wpdb;

        // nonce is verified outside of this method. We can't verify here because 
        // automatic redirects are sometimes created without user interaction.

        $now = time();
        $wpdb->insert($wpdb->prefix . 'abj404_redirects', array(
            'url' => esc_url($url),
            'status' => esc_html($status),
            'type' => esc_html($type),
            'final_dest' => esc_html($final_dest),
            'code' => esc_html($code),
            'disabled' => esc_html($disabled),
            'timestamp' => esc_html($now)
                ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%d',
            '%d',
            '%d'
                )
        );
        return $wpdb->insert_id;
    }

    /** Get the redirect for the URL. 
     * @global type $wpdb
     * @param type $url
     * @return type
     */
    function getRedirectDataFromURL($url) {
        global $wpdb;
        $redirect = array();

        $query = "select * from " . $wpdb->prefix . "abj404_redirects where url = '" . esc_sql(esc_url($url)) . "'";

        $row = $wpdb->get_row($query, ARRAY_A);
        if ($row == NULL) {
            $redirect['id'] = 0;
        } else {
            $redirect['id'] = $row['id'];
            $redirect['url'] = $row['url'];
            $redirect['status'] = $row['status'];
            $redirect['type'] = $row['type'];
            $redirect['final_dest'] = $row['final_dest'];
            $redirect['code'] = $row['code'];
            $redirect['disabled'] = $row['disabled'];
            $redirect['created'] = $row['timestamp'];
        }
        return $redirect;
    }

    /** Returns rows with the IDs of the published items.
     * @return type
     */
    function getPublishedPagesAndPostsIDs() {
        global $wpdb;
        
        $query = "select id from $wpdb->posts where post_status='publish' and (post_type='page' or post_type='post')";
        $rows = $wpdb->get_results($query);
        return $rows;
    }
    
    /** 
     * @global type $wpdb
     * @return type
     */
    function getPublishedPostIDs() {
        global $wpdb;
        $query = "select id from $wpdb->posts where post_status='publish' and post_type='post' order by post_date desc";
        $rows = $wpdb->get_results($query);
        
        return $rows;
    }
    
    /** Returns rows with the defined terms (tags).
     * @global type $wpdb
     * @return type
     */
    function getPublishedTags() {
        global $wpdb;
        
        $query = "select " . $wpdb->terms . ".term_id from " . $wpdb->terms . " ";
        $query .= "left outer join " . $wpdb->term_taxonomy . " on " . $wpdb->terms . ".term_id = " . $wpdb->term_taxonomy . ".term_id ";
        $query .= "where " . $wpdb->term_taxonomy . ".taxonomy='post_tag' and " . $wpdb->term_taxonom . ".count >= 1";
        $rows = $wpdb->get_results($query);
        return $rows;
    }
    
    /** Returns rows with the defined categories.
     * @global type $wpdb
     * @return type
     */
    function getPublishedCategories() {
        global $wpdb;
        
        $query = "select " . $wpdb->terms . ".term_id from " . $wpdb->terms . " ";
        $query .= "left outer join " . $wpdb->term_taxonomy . " on " . $wpdb->terms . ".term_id = " . $wpdb->term_taxonomy . ".term_id ";
        $query .= "where " . $wpdb->term_taxonomy . ".taxonomy='category' and " . $wpdb->term_taxonomy . ".count >= 1";
        $rows = $wpdb->get_results($query);
        return $rows;
    }

    /** Delete stored redirects based on passed in POST data.
     * @global type $wpdb
     * @return type
     */
    function deleteSpecifiedRedirects() {
        global $wpdb;
        $message = "";

        // nonce already verified.

        $redirects = $wpdb->prefix . "abj404_redirects";
        $logs = $wpdb->prefix . "abj404_logs";

        
        if ($_POST['sanity'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }
        
        if (!isset($_POST['types']) || $_POST['types'] == '') {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            return $message;
        }
        
        if (is_array($_POST['types'])) {
            $type = array_map('sanitize_text_field', $_POST['types']);
        } else {
            $type = sanitize_text_field($_POST['types']);
        }

        if (!is_array($type)) {
            $message = __('An unknown error has occurred.', '404-solution');
            return $message;
        }

        $types = "";
        $x = 0;
        for ($i = 0; $i < count($type); $i++) {
            if (preg_match('/[0-9]+/', $type[$i])) {
                if ($x > 0) {
                    $types .= ",";
                }
                $types .= $type[$i];
                $x++;
            }
        }

        if ($types == "") {
            $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            ABJ_404_Solution_Functions::debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post(json_encode($types)));
            return $message;
        }
        $purge = sanitize_text_field($_POST['purgetype']);

        if ($purge != "logs" && $purge != "redirects") {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            ABJ_404_Solution_Functions::debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post(json_encode($purge)));
            return $message;
        }
        
        $query = $wpdb->prepare("delete from " . $logs . " where redirect_id in (select id from " . $redirects . " where status in (%s))", esc_sql($types));
        $logcount = $wpdb->query($query);
        
        $message .= sprintf( _n( '%s log entry was purged.', 
                '%s log entries were purged.', $logcount, '404-solution'), $logcount);

        if ($purge == "redirects") {
            $query = $wpdb->prepare("delete from " . $redirects . " where status in (%s)", esc_sql($types));
            $redirectCount = $wpdb->query($query);
            $message .= "<br>";
            $message .= sprintf( _n( '%s redirect entry was purged.', 
                    '%s redirect entries were purged.', $redirectCount, '404-solution'), $redirectCount);
        }

        return $message;
    }

    /**
     * This returns only the first column of the first row of the result.
     * @global type $wpdb
     * @param type $query a query that starts with "select count(id) from ..."
     * @param array $valueParams values to use to prepare the query.
     * @return int the count (result) of the query.
     */
    function getStatsCount($query = '', array $valueParams) {
        global $wpdb;

        if ($query == '') {
            return 0;
        }

        $results = $wpdb->get_col($wpdb->prepare($query, $valueParams));

        return intval($results[0]);
    }
    
    /** 
     * Look at $_POST and $_GET for the specified option and return the default value if it's not set.
     * @param type $name
     * @param type $defaultValue
     * @return type
     */
    function getPostOrGetSanitize($name, $defaultValue) {
        if (isset($_GET[$name])) {
            return sanitize_text_field($_GET[$name]);

        } else if (isset($_POST[$name])) {
            return sanitize_text_field($_POST[$name]);

        } else {
            return $defaultValue;
        }
    }

    /** 
     * @param type $id
     * @return type
     */
    function getRedirectByID($id) {
        global $wpdb;
        $query = $wpdb->prepare("select id, url, type, final_dest, code from " . $wpdb->prefix . "abj404_redirects where 1 and id = %d", $id);
        $redirect = $wpdb->get_row($query, ARRAY_A);
        
        return $redirect;
    }
    
    /** 
     * @param type $id
     * @return type
     */
    function getPostParent($id) {
        global $wpdb;
        $query = $wpdb->prepare("select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = %d", $id);
        $prow = $wpdb->get_row($query, OBJECT);
        
        return $prow;
    }
    
    /** Change the status to "trash" or "ignored," for example.
     * @global type $wpdb
     * @param type $id
     * @param type $newstatus
     * @return type
     */
    function updateRedirectTypeStatus($id, $newstatus) {
        global $wpdb;
        $message = "";

        $result = false;
        if (preg_match('/[0-9]+/', $id)) {

            $result = $wpdb->update($wpdb->prefix . "abj404_redirects", 
                    array('status' => esc_sql($newstatus)), 
                    array('id' => absint($id)), 
                    array('%d'), 
                    array('%d')
            );
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /** Move a redirect to the "trash" folder.
     * @global type $wpdb
     * @param type $id
     * @param type $trash
     * @return type
     */
    function moveRedirectsToTrash($id, $trash) {
        global $wpdb;
        
        $message = "";
        $result = false;
        if (preg_match('/[0-9]+/', $id)) {

            $result = $wpdb->update($wpdb->prefix . "abj404_redirects", array('disabled' => esc_html($trash)), array('id' => absint($id)), array('%d'), array('%d')
            );
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /** Some data is passed in and some comes from the POST request.
     * This should be redone to be consistent. 
     * @global type $wpdb
     * @param type $type
     * @param type $dest
     */
    function updateRedirect($type, $dest) {
        global $wpdb;
        
        if (($type <= 0) || ($dest <= 0) || ($_POST['id'] <= 0)) {
            ABJ_404_Solution_Functions::errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html($type) . ", Dest: " . esc_html($dest) . ", ID: " . esc_html($_POST['id']));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return;
        }
        
        $wpdb->update($wpdb->prefix . "abj404_redirects", array(
            'url' => esc_url($_POST['url']),
            'status' => ABJ404_MANUAL,
            'type' => absint($type),
            'final_dest' => esc_sql($dest),
            'code' => esc_attr($_POST['code'])
                ), array(
            'id' => absint($_POST['id'])
                ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%d'
                ), array(
            '%d'
                )
        );
    }

    /** 
     * @return type
     */
    function getCapturedCountForNotification() {
        global $abj404dao;
        return $abj404dao->getRecordCount(array(ABJ404_CAPTURED));
    }
}
