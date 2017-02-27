<?php

/*
 * 404 Manager Admin Functions
 *
 */

function abj404_addAdminPage() {
    global $menu;
    $options = abj404_getOptions();
    $pageName = "404 Solution";

    // Admin notice
    if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
        $captured = abj404_getCapturedCount();
        if (isset($options['admin_notification']) && $captured >= $options['admin_notification']) {
            $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html($captured) . "</span></span>";
            $pos = strpos($menu[80][0], 'update-plugins');
            if ($pos === false) {
                $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
            }
        }
    }

    add_options_page('404 Solution', $pageName, 'manage_options', 'abj404_solution', 'abj404_adminPage');
}

add_action('admin_menu', 'abj404_addAdminPage');

function abj404_dashboardNotification() {
    global $pagenow;
    if (current_user_can('manage_options')) {
        if (( isset($_GET['page']) && $_GET['page'] == "abj404_solution" ) || ( $pagenow == 'index.php' && (!( isset($_GET['page']) ) ) )) {
            $options = abj404_getOptions();
            if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
                $captured = abj404_getCapturedCount();
                if ($captured >= $options['admin_notification']) {
                    echo "<div class=\"updated\"><p><strong>" . esc_html(__('404 Solution', '404-solution')) . ":</strong> " . __('There are ' . esc_html($captured) . ' captured 404 URLs that need to be processed.', '404-solution') . "</p></div>";
                }
            }
        }
    }
}

add_action('admin_notices', 'abj404_dashboardNotification');

function abj404_postbox($id, $title, $content) {
    echo "<div id=\"" . esc_attr($id) . "\" class=\"postbox\">";
    echo "<h3 class=\"hndle\" style=\"cursor: default;\"><span>" . esc_html($title) . "</span></h3>";
    echo "<div class=\"inside\">" . $content /* Can't escape here, as contains forms */ . "</div>";
    echo "</div>";
}

/**
 * @global type $wpdb
 * @return int the total number of redirects that have been captured
 */
function abj404_getCapturedCount() {
    global $wpdb;

    $query = "select count(id) from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_CAPTURED;
    $captured = $wpdb->get_col($query, 0);
    if (count($captured) == 0) {
        $captured[0] = 0;
    }
    return $captured[0];
}

function abj404_updateOptions() {
    $message = "";
    $options = abj404_getOptions();
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

function abj404_getTableOptions() {
    $tableOptions = array();

    if (!isset($_POST['filter'])) {
        if (!isset($_GET['filter'])) {
            if (isset($_GET['subpage']) && $_GET['subpage'] == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        } else {
            $tableOptions['filter'] = filter_input(INPUT_GET, "filter", FILTER_SANITIZE_STRING);
        }
    } else {
        $tableOptions['filter'] = filter_input(INPUT_POST, "filter", FILTER_SANITIZE_STRING);
    }

    if (!isset($_GET['orderby'])) {
        if (isset($_GET['subpage']) && $_GET['subpage'] == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
        } else {
            $tableOptions['orderby'] = "url";
        }
    } else {
        $tableOptions['orderby'] = filter_input(INPUT_GET, "orderby", FILTER_SANITIZE_STRING);
    }

    if (!isset($_GET['order'])) {
        if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";
        } else {
            $tableOptions['order'] = "ASC";
        }
    } else {
        $tableOptions['order'] = filter_input(INPUT_GET, "order", FILTER_SANITIZE_STRING);
    }

    if (!isset($_GET['paged'])) {
        $tableOptions['paged'] = 1;
    } else {
        $tableOptions['paged'] = filter_input(INPUT_GET, "paged", FILTER_SANITIZE_STRING);
    }

    if (!isset($_GET['perpage'])) {
        $tableOptions['perpage'] = 25;
    } else {
        $tableOptions['perpage'] = filter_input(INPUT_GET, "perpage", FILTER_SANITIZE_STRING);
    }

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
 *
 * @global type $wpdb
 * @param type $types specified types such as ABJ404_MANUAL, ABJ404_AUTO, ABJ404_CAPTURED, ABJ404_IGNORED.
 * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
 * @return int the number of records matching the specified types.
 */
function abj404_getRecordCount($types = array(), int $trashed = 0) {
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
function abj404_getLogsCount(int $redirectID) {
    global $wpdb;

    $query = "select count(id) from " . $wpdb->prefix . "abj404_logs where 1 ";
    if ($redirectID != 0) {
        $query .= "and redirect_id = " . esc_sql($redirectID);
    }
    $row = $wpdb->get_row($query, ARRAY_N);
    $records = $row[0];

    return $records;
}

/**
 * @global type $wpdb
 * @param type $sub either "redirects" or "captured".
 * @param type $tableOptions filter, order by, paged, perpage etc.
 * @param type $limitEnforced add "limit" to the query.
 * @return type rows from the redirects table.
 */
function abj404_getRecords($sub, $tableOptions, $limitEnforced = 1) {
    global $wpdb;
    $rows = array();

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
            error_log("ABJ_404-SOLUTION: Unrecognized sub type.");
            echo "ABJ_404-SOLUTION: Error: Unrecognized sub type.";
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
 *
 * @global type $wpdb
 * @param type $tableOptions logsid, orderby, paged, perpage, etc.
 * @return type rows from querying the redirects table.
 */
function abj404_getLogRecords($tableOptions) {
    global $wpdb;
    $rows = array();

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

function abj404_drawFilters($sub, $tableOptions) {
    if (count($tableOptions) == 0) {
        $tableOptions = abj404_getTableOptions();
    }
    echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\">";
    echo "<ul class=\"subsubsub\">";

    $url = "?page=abj404_solution";
    if ($sub == "captured") {
        $url .= "&subpage=abj404_captured";
    }

    $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
    $url .= "&order=" . sanitize_text_field($tableOptions['order']);

    if ($sub == "redirects") {
        $types = array(ABJ404_MANUAL, ABJ404_AUTO);
    } else {
        $types = array(ABJ404_CAPTURED, ABJ404_IGNORED);
    }

    $class = "";
    if ($tableOptions['filter'] == 0) {
        $class = " class=\"current\"";
    }

    if ($sub != "captured") {
        echo "<li>";
        echo "<a href=\"" . esc_url($url) . "\"" . $class . ">" . __('All', '404-solution');
        echo " <span class=\"count\">(" . esc_html(abj404_getRecordCount($types)) . ")</span>";
        echo "</a>";
        echo "</li>";
    }

    foreach ($types as $type) {
        $thisurl = $url . "&filter=" . $type;

        $class = "";
        if ($tableOptions['filter'] == $type) {
            $class = " class=\"current\"";
        }

        if ($type == ABJ404_MANUAL) {
            $title = "Manual Redirects";
        } else if ($type == ABJ404_AUTO) {
            $title = "Automatic Redirects";
        } else if ($type == ABJ404_CAPTURED) {
            $title = "Captured URL's";
        } else if ($type == ABJ404_IGNORED) {
            $title = "Ignored 404's";
        } else {
            error_log("ABJ_404_SOLUTION: Unrecognized redirect type: " . esc_html($type));
        }

        echo "<li>";
        if (!( $sub == "captured" && $type == ABJ404_CAPTURED )) {
            echo " | ";
        }
        echo "<a href=\"" . esc_url($thisurl) . "\"" . $class . ">" . ( $title );
        echo " <span class=\"count\">(" . esc_html(abj404_getRecordCount(array($type))) . ")</span>";
        echo "</a>";
        echo "</li>";
    }


    $trashurl = $url . "&filter=-1";
    $class = "";
    if ($tableOptions['filter'] == -1) {
        $class = " class=\"current\"";
    }
    echo "<li> | ";
    echo "<a href=\"" . esc_url($trashurl) . "\"" . $class . ">" . __('Trash', '404-solution');
    echo " <span class=\"count\">(" . esc_html(abj404_getRecordCount($types, 1)) . ")</span>";
    echo "</a>";
    echo "</li>";

    echo "</ul>";
    echo "</span>";
}

function abj404_drawPaginationLinks($sub, $tableOptions) {
    $url = "?page=abj404_solution";
    if ($sub == "captured") {
        $url .= "&subpage=abj404_captured";
    } else if ($sub == "logs") {
        $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
    }

    $url .= "&orderby=" . $tableOptions['orderby'];
    $url .= "&order=" . $tableOptions['order'];

    if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
        if ($sub == "redirects") {
            $types = array(ABJ404_MANUAL, ABJ404_AUTO);
        } else {
            $types = array(ABJ404_CAPTURED, ABJ404_IGNORED);
        }
    } else {
        $types = array($tableOptions['filter']);
        $url .= "&filter=" . $tableOptions['filter'];
    }

    if ($sub != "logs") {
        // -1 means Trash. we should create a constant for this value...
        if ($tableOptions['filter'] == -1) {
            $num_records = abj404_getRecordCount($types, 1);
            
        } else {
            $num_records = abj404_getRecordCount($types);
        }
        
    } else {
        $num_records = abj404_getLogsCount($tableOptions['logsid']);
    }
    $total_pages = ceil($num_records / $tableOptions['perpage']);
    if ($total_pages == 0) {
        $total_pages = 1;
    }

    echo "<div class=\"tablenav-pages\">";
    $itemsText = sprintf( _n( '%s item', '%s items', $num_records, '404-solution'), $num_records);    
    echo "<span class=\"displaying-num\">" . " " . $itemsText . "</span>";
    echo "<span class=\"pagination-links\">";
    
    $classFirstPage = "";
    if ($tableOptions['paged'] == 1) {
        $classFirstPage = " disabled";
    }
    $firsturl = $url;
    echo "<a href=\"" . esc_url($firsturl) . "\" class=\"first-page" . $classFirstPage . "\" title=\"" . __('Go to first page', '404-solution') . "\">&laquo;</a>";
    
    $classPreviousPage = "";
    if ($tableOptions['paged'] == 1) {
        $classPreviousPage = " disabled";
        $prevurl = $url;
    } else {
        $prev = $tableOptions['paged'] - 1;
        $prevurl = $url . "&paged=" . $prev;
    }
    echo "<a href=\"" . esc_url($prevurl) . "\" class=\"prev-page" . $classPreviousPage . "\" title=\"" . __('Go to previous page', '404-solution') . "\">&lsaquo;</a>";
    echo " ";
    echo __('Page', '404-solution') . " " . $tableOptions['paged'] . " " . __('of', '404-solution') . " " . esc_html($total_pages);
    echo " ";
    
    $classNextPage = "";
    if ($tableOptions['paged'] + 1 > $total_pages) {
        $classNextPage = " disabled";
        if ($tableOptions['paged'] == 1) {
            $nexturl = $url;
        } else {
            $nexturl = $url . "&paged=" . $tableOptions['paged'];
        }
    } else {
        $next = $tableOptions['paged'] + 1;
        $nexturl = $url . "&paged=" . $next;
    }
    echo "<a href=\"" . esc_url($nexturl) . "\" class=\"next-page" . $classNextPage . "\" title=\"" . __('Go to next page', '404-solution') . "\">&rsaquo;</a>";
    
    $classLastPage = "";
    if ($tableOptions['paged'] + 1 > $total_pages) {
        $classLastPage = " disabled";
        if ($tableOptions['paged'] == 1) {
            $lasturl = $url;
        } else {
            $lasturl = $url . "&paged=" . $tableOptions['paged'];
        }
    } else {
        $lasturl = $url . "&paged=" . $total_pages;
    }
    echo "<a href=\"" . esc_url($lasturl) . "\" class=\"last-page" . $classLastPage . "\" title=\"" . __('Go to last page', '404-solution') . "\">&raquo;</a>";
    echo "</span>";
    echo "</div>";
}

function abj404_buildTableColumns($sub, $tableOptions, $columns) {
    echo "<tr>";
    if ($sub == "captured" && $tableOptions['filter'] != '-1') {
        $cbinfo = "class=\"manage-column column-cb check-column\"";
    } else {
        $cbinfo = "style=\"width: 1px;\"";
    }
    echo "<th " . $cbinfo . ">";
    if ($sub == "captured" && $tableOptions['filter'] != '-1') {
        echo "<input type=\"checkbox\">";
    }
    echo "</th>";
    foreach ($columns as $column) {
        $style = "";
        if ($column['width'] != "") {
            $style = " style=\"width: " . esc_attr($column['width']) . ";\" ";
        }
        $nolink = 0;
        $sortorder = "";
        if ($tableOptions['orderby'] == $column['orderby']) {
            $class = " sorted";
            if ($tableOptions['order'] == "ASC") {
                $class .= " asc";
                $sortorder = "DESC";
            } else {
                $class .= " desc";
                $sortorder = "ASC";
            }
        } else {
            if ($column['orderby'] != "") {
                $class = " sortable";
                if ($column['orderby'] == "timestamp" || $column['orderby'] == "lastused") {
                    $class .= " asc";
                    $sortorder = "DESC";
                } else {
                    $class .= " desc";
                    $sortorder = "ASC";
                }
            } else {
                $class = "";
                $nolink = 1;
            }
        }

        $url = "?page=abj404_solution";
        if ($sub == "captured") {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == "logs") {
            $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
        }
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        $url .= "&orderby=" . $column['orderby'] . "&order=" . $sortorder;

        echo "<th" . $style . "class=\"manage-column column-title" . $class . "\">";
        if ($nolink == 1) {
            echo $column['title'];
        } else {
            echo "<a href=\"" . esc_url($url) . "\">";
            echo "<span>" . esc_html($column['title']) . "</span>";
            echo "<span class=\"sorting-indicator\"></span>";
            echo "</a>";
        }
        echo "</th>";
    }
    echo "<th style=\"width: 1px;\"></th>";
    echo "</tr>";
}

function abj404_getRedirectLastUsed($id) {
    global $wpdb;

    $query = $wpdb->prepare("select timestamp from " . $wpdb->prefix . "abj404_logs where redirect_id = %d order by timestamp desc", esc_sql($id));
    $row = $wpdb->get_col($query);

    if (isset($row[0])) {
        return $row[0];
    } else {
        return;
    }
}

function abj404_addAdminRedirect() {
    global $wpdb;
    $messasge = "";

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
            
            abj404_setupRedirect(esc_sql(filter_input(INPUT_POST, "url", FILTER_SANITIZE_URL)), ABJ404_MANUAL, $type, $dest, esc_sql(filter_input(INPUT_POST, "code", FILTER_SANITIZE_STRING)), 0);
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

function abj404_editRedirectData() {
    global $wpdb;
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
            $wpdb->update($wpdb->prefix . "abj404_redirects", array(
                'url' => esc_url(filter_input(INPUT_POST, "url", FILTER_SANITIZE_URL)),
                'status' => ABJ404_MANUAL,
                'type' => esc_html($type),
                'final_dest' => esc_html($dest),
                'code' => esc_html(filter_input(INPUT_POST, "code", FILTER_SANITIZE_STRING))
                    ), array(
                'id' => absint(filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT))
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
 * Move a redirect to the "trash"folder.
 * @global type $wpdb
 * @param type $id
 * @param type $trash
 * @return type
 */
function abj404_moveRedirectsToTrash($id, $trash) {
    global $wpdb;

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

function abj404_moveRedirectsToIgnore($id, $newstatus) {
    global $wpdb;

    $result = false;
    if (preg_match('/[0-9]+/', $id)) {

        $result = $wpdb->update($wpdb->prefix . "abj404_redirects", array('status' => esc_html($newstatus)), array('id' => absint($id)), array('%d'), array('%d')
        );
    }
    if ($result == false) {
        $message = __('Error: Unknown Database Error!', '404-solution');
    }
    return $message;
}

function abj404_adminHeader($sub = 'list', $message = '') {
    if ($sub == "options") {
        $header = " " . __('Options', '404-solution');
    } else if ($sub == "logs") {
        $header = " " . __('Logs', '404-solution');
    } else if ($sub == "stats") {
        $header = " " . __('Stats', '404-solution');
    } else if ($sub == "edit") {
        $header = ": " . __('Edit Redirect', '404-solution');
    } else if ($sub == "redirects") {
        $header = "";
    } else {
        $header = "";
    }
    echo "<div class=\"wrap\">";
    if ($sub == "options") {
        echo "<div id=\"icon-options-general\" class=\"icon32\"></div>";
    } else {
        echo "<div id=\"icon-tools\" class=\"icon32\"></div>";
    }
    echo "<h2>" . __('404 Solution', '404-solution') . esc_html($header) . "</h2>";
    if ($message != "") {
        // esc_html to wp_kses by schlessera
        $allowed_tags = [
            'br' => [],
            'em' => [],
            'strong' => [],
        ];
        echo "<div class=\"message updated\"><p>" . wp_kses($message, $allowed_tags) . "</p></div>";
    }

    $class = "";
    if ($sub == "redirects") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution\" title=\"" . __('Page Redirects', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Page Redirects', '404-solution') . "</a>";
    
    $class = "";
    if ($sub == "captured") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution&subpage=abj404_captured\" title=\"" . __('Captured 404 URLs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Captured 404 URLs', '404-solution') . "</a>";
    
    $class = "";
    if ($sub == "logs") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution&subpage=abj404_logs\" title=\"" . __('Redirect & Capture Logs', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Logs', '404-solution') . "</a>";
    
    $class = "";
    if ($sub == "stats") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution&subpage=abj404_stats\" title=\"" . __('Stats', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Stats', '404-solution') . "</a>";
    
    $class = "";
    if ($sub == "tools") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution&subpage=abj404_tools\" title=\"" . __('Tools', '404-solution') . "\" class=\"nav-tab " . $class . "\">" . __('Tools', '404-solution') . "</a>";
    
    $class = "";
    if ($sub == "options") {
        $class = "nav-tab-active";
    }
    echo "<a href=\"?page=abj404_solution&subpage=abj404_options\" title=\"Options\" class=\"nav-tab " . $class . "\">" . __('Options', '404-solution') . "</a>";
    
    echo "<hr style=\"border: 0px; border-bottom: 1px solid #DFDFDF; margin-top: 0px; margin-bottom: 0px; \">";
}

function abj404_adminFooter() {
    echo "<div style=\"clear: both;\">";
    echo "<BR/>";
    echo "<HR/><strong>Credits:</strong><br>";
    echo "<a href=\"" . ABJ404_HOME . "\" title=\"" . __('404 Solution') . "\" target=\"_blank\">" . __('404 Solution') . "</a> ";
    echo __('is maintained by', '404-solution');
    echo " ";
    echo "<a href=\"http://www.wealth-psychology.com/404-solution/\" title=\"Aaron J\" target=\"_blank\">Aaron J</a>. | ";

    echo __('Version', '404-solution') . ": " . ABJ404_VERSION;

    echo "</div>";
    echo "</div>";
}

/** 
 * This is for both empty trash buttons (page redirects and captured 404 URLs).
 * @param type $sub
 */
function abj404_emptyTrash($sub) {
    $tableOptions = abj404_getTableOptions();

    $rows = abj404_getRecords($sub, $tableOptions, 0);

    // nonce already verified.
    
    foreach ($rows as $row) {
        abj404_deleteRedirect($row['id']);
    }
}

function abj404_bulkProcess($action, $ids) {
    $message = "";
    
    // nonce already verified.
    
    if ($action == "bulkignore" || $action == "bulkcaptured") {
        if ($action == "bulkignore") {
            $status = ABJ404_IGNORED;
        } else if ($action == "bulkcaptured") {
            $status = ABJ404_CAPTURED;
        } else {
            error_log("ABJ_404-SOLUTION: Unrecognized bulk action: " + $action);
        }
        $count = 0;
        foreach ($ids as $id) {
            $s = abj404_moveRedirectsToIgnore($id, $status);
            if ($s == "") {
                $count++;
            }
        }
        if ($action == "bulkignore") {
            $message = $count . " " . __('URLs marked as ignored.', '404-solution');
        } else if ($action == "bulkcaptured") {
            $message = $count . " " . __('URLs marked as captured.', '404-solution');
        } else {
            error_log("ABJ_404-SOLUTION: Unrecognized bulk action: " + $action);
        }
        
    } else if ($action == "bulktrash") {
        $count = 0;
        foreach ($ids as $id) {
            $s = abj404_moveRedirectsToTrash($id, 1);
            if ($s == "") {
                $count ++;
            }
        }
        $message = $count . " " . __('URLs moved to trash', '404-solution');
        
    } else {
        error_log("ABJ_404-SOLUTION: Unrecognized bulk action: " + $action);
    }
    return $message;
}

function abj404_adminPage() {
    $sub = "";
    $message = "";

    //Handle Post Actions
    if (isset($_POST['action'])) {
        $action = filter_input(INPUT_POST, "action", FILTER_SANITIZE_STRING);
    } else {
        $action = "";
    }

    if ($action == "updateOptions") {
        if (check_admin_referer('abj404UpdateOptions') && is_admin()) {
            $sub = "abj404_options";
            $message = abj404_updateOptions();
            if ($message == "") {
                $message = __('Options Saved Successfully!', '404-solution');
            } else {
                $message .= __('Some options were not saved successfully.', '404-solution');
            }
        }
    } else if ($action == "addRedirect") {
        if (check_admin_referer('abj404addRedirect') && is_admin()) {
            $message = abj404_addAdminRedirect();
            if ($message == "") {
                $message = __('New Redirect Added Successfully!', '404-solution');
            } else {
                $message .= __('Error: unable to add new redirect.', '404-solution');
            }
        }
    } else if ($action == "emptyRedirectTrash") {
        if (check_admin_referer('abj404_emptyRedirectTrash') && is_admin()) {
            abj404_emptyTrash('redirects');
            $message = __('All trashed URLs have been deleted!', '404-solution');
        }
    } else if ($action == "emptyCapturedTrash") {
        if (check_admin_referer('abj404_emptyCapturedTrash') && is_admin()) {
            abj404_emptyTrash('captured');
            $message = __('All trashed URLs have been deleted!', '404-solution');
        }
    } else if ($action == "bulkignore" || $action == "bulkcaptured" || $action == "bulktrash") {
        if (check_admin_referer('abj404_bulkProcess') && is_admin()) {
            $message = abj404_bulkProcess($action, filter_input(INPUT_POST, "idnum", FILTER_SANITIZE_STRING));
        }
    } else if ($action == "purgeRedirects") {
        if (check_admin_referer('abj404_purgeRedirects') && is_admin()) {
            $message = abj404_purgeRedirects();
        }
    }

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
                $message = abj404_moveRedirectsToTrash(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT), $trash);
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

    //Handle Delete Functionality
    if (isset($_GET['remove']) && $_GET['remove'] == 1) {
        if (check_admin_referer('abj404_removeRedirect') && is_admin()) {
            if (preg_match('/[0-9]+/', $_GET['id'])) {
                $sanitize_id = absint(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT));
                abj404_deleteRedirect($sanitize_id);
                $message = __('Redirect Removed Successfully!', '404-solution');
            }
        }
    }

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
                    $message = abj404_moveRedirectsToIgnore(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT), $newstatus);
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

    //Handle edit posts
    if (isset($_POST['action']) && $_POST['action'] == "editRedirect") {
        if (isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id'])) {
            if (check_admin_referer('abj404editRedirect') && is_admin()) {
                $message = abj404_editRedirectData();
                if ($message == "") {
                    $message .= __('Redirect Information Updated Successfully!', '404-solution');
                    $sub = "redirects";
                } else {
                    $message .= __('Error: Unable to update redirect data.', '404-solution');
                }
            }
        }
    }

    // Deal With Page Tabs
    if ($sub == "") {
        if (isset($_GET['subpage'])) {
            $sub = strtolower(filter_input(INPUT_GET, "subpage", FILTER_SANITIZE_STRING));
        } else {
            $sub = "";
        }
    }
    if ($sub == "abj404_options") {
        $sub = "options";
    } else if ($sub == "abj404_captured") {
        $sub = "captured";
    } else if ($sub == "abj404_logs") {
        $sub = "logs";
    } else if ($sub == "abj404_edit") {
        $sub = "edit";
    } else if ($sub == "abj404_stats") {
        $sub = "stats";
    } else if ($sub == "abj404_tools") {
        $sub = "tools";
    } else {
        $sub = "redirects";
    }

    abj404_adminHeader($sub, $message);
    if ($sub == "redirects") {
        abj404_adminRedirectsPage();
    } else if ($sub == "captured") {
        abj404_adminCapturedPage();
    } else if ($sub == "options") {
        abj404_adminOptionsPage();
    } else if ($sub == "logs") {
        abj404_adminLogsPage();
    } else if ($sub == "edit") {
        abj404_adminEditPage();
    } else if ($sub == "stats") {
        abj404_adminStatsPage();
    } else if ($sub == "tools") {
        abj404_adminToolsPage();
    } else {
        echo __('Invalid Sub Page ID', '404-solution');
    }
    abj404_adminFooter();
}

/**
 * This returns only the first column of the first row of the result.
 * @global type $wpdb
 * @param type $query a query that starts with "select count(id) from ..."
 * @param array $valueParams values to use to prepare the query.
 * @return int the count (result) of the query.
 */
function abj404_getStatsCount($query = '', array $valueParams) {
    global $wpdb;

    if ($query == '') {
        return 0;
    }

    $results = $wpdb->get_col($wpdb->prepare($query, $valueParams));

    return $results[0];
}

function abj404_adminStatsPage() {
    global $wpdb;
    $sub = "stats";

    $redirects = $wpdb->prefix . "abj404_redirects";
    $logs = $wpdb->prefix . "abj404_logs";
    $hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

    echo "<div class=\"postbox-container\" style=\"float: right; width: 49%;\">";
    echo "<div class=\"metabox-holder\">";
    echo " <div class=\"meta-box-sortables\">";

    $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_AUTO;
    $auto301 = abj404_getStatsCount($query, array(ABJ404_AUTO));

    $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_AUTO;
    $auto302 = abj404_getStatsCount($query, array(ABJ404_AUTO));

    $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d"; // . ABJ404_MANUAL;
    $manual301 = abj404_getStatsCount($query, array(ABJ404_MANUAL));

    $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d"; // . ABJ404_MANUAL;
    $manual302 = abj404_getStatsCount($query, array(ABJ404_MANUAL));

    $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
    $trashed = abj404_getStatsCount($query, array(ABJ404_AUTO, ABJ404_MANUAL));

    $total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

    $content = "";
    $content .= "<p $hr>";
    $content .= "<strong>" . __('Automatic 301 Redirects', '404-solution') . ":</strong> " . esc_html($auto301) . "<br>";
    $content .= "<strong>" . __('Automatic 302 Redirects', '404-solution') . ":</strong> " . esc_html($auto302) . "<br>";
    $content .= "<strong>" . __('Manual 301 Redirects', '404-solution') . ":</strong> " . esc_html($manual301) . "<br>";
    $content .= "<strong>" . __('Manual 302 Redirects', '404-solution') . ":</strong> " . esc_html($manual302) . "<br>";
    $content .= "<strong>" . __('Trashed Redirects', '404-solution') . ":</strong> " . esc_html($trashed) . "</p>";
    $content .= "<p style=\"margin-top: 4px;\">";
    $content .= "<strong>" . __('Total Redirects', '404-solution') . ":</strong> " . esc_html($total);
    $content .= "</p>";
    abj404_postbox("abj404-redirectStats", __('Redirects', '404-solution'), $content);

    $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_CAPTURED;
    $captured = abj404_getStatsCount($query, array(ABJ404_CAPTURED));

    $query = "select count(id) from $redirects where disabled = 0 and status = %d"; // . ABJ404_IGNORED;
    $ignored = abj404_getStatsCount($query, array(ABJ404_IGNORED));

    $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
    $trashed = abj404_getStatsCount($query, array(ABJ404_CAPTURED, ABJ404_IGNORED));

    $total = $captured + $ignored + $trashed;

    $content = "";
    $content .= "<p $hr>";
    $content .= "<strong>" . __('Captured URLs', '404-solution') . ":</strong> " . esc_html($captured) . "<br>";
    $content .= "<strong>" . __('Ignored 404 URLs', '404-solution') . ":</strong> " . esc_html($ignored) . "<br>";
    $content .= "<strong>" . __('Trashed URLs', '404-solution') . ":</strong> " . esc_html($trashed) . "</p>";
    $content .= "<p style=\"margin-top: 4px;\">";
    $content .= "<strong>" . __('Total URLs', '404-solution') . ":</strong> " . esc_html($total);
    $content .= "</p>";
    abj404_postbox("abj404-capturedStats", __('Captured URLs', '404-solution'), $content);

    echo "</div>";
    echo "</div>";
    echo "</div>";

    echo "<div class=\"postbox-container\" style=\"width: 49%;\">";
    echo "<div class=\"metabox-holder\">";
    echo " <div class=\"meta-box-sortables\">";

    $today = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
    $firstm = mktime(0, 0, 0, date('m'), 1, date('Y'));
    $firsty = mktime(0, 0, 0, 1, 1, date('Y'));

    for ($x = 0; $x <= 3; $x++) {
        if ($x == 0) {
            $title = "Today's Stats";
            $ts = $today;
        } else if ($x == 1) {
            $title = "This Month";
            $ts = $firstm;
        } else if ($x == 2) {
            $title = "This Year";
            $ts = $firsty;
        } else if ($x == 3) {
            $title = "All Stats";
            $ts = 0;
        }

        $query = "select count(id) from $logs where timestamp >= $ts and action = %s";
        $disp404 = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action = %s";
        $distinct404 = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action = %s";
        $visitors404 = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct referrer) from $logs where timestamp >= $ts and action = %s";
        $refer404 = abj404_getStatsCount($query, array("404"));

        $query = "select count(id) from $logs where timestamp >= $ts and action != %s";
        $redirected = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action != %s";
        $distinctredirected = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action != %s";
        $distinctvisitors = abj404_getStatsCount($query, array("404"));

        $query = "select count(distinct referrer) from $logs where timestamp >= $ts and action != %s";
        $distinctrefer = abj404_getStatsCount($query, array("404"));

        $content = "";
        $content .= "<p>";
        $content .= "<strong>" . __('Page Not Found Displayed', '404-solution') . ":</strong> " . esc_html($disp404) . "<br>";
        $content .= "<strong>" . __('Unique Page Not Found URLs', '404-solution') . ":</strong> " . esc_html($distinct404) . "<br>";
        $content .= "<strong>" . __('Unique Page Not Found Visitors', '404-solution') . ":</strong> " . esc_html($visitors404) . "<br>";
        $content .= "<strong>" . __('Unique Page Not Found Referrers', '404-solution') . ":</strong> " . esc_html($refer404) . "<br>";
        $content .= "<strong>" . __('Hits Redirected', '404-solution') . ":</strong> " . esc_html($redirected) . "<br>";
        $content .= "<strong>" . __('Unique URLs Redirected', '404-solution') . ":</strong> " . esc_html($distinctredirected) . "<br>";
        $content .= "<strong>" . __('Unique Redirected Visitors', '404-solution') . ":</strong> " . esc_html($distinctvisitors) . "<br>";
        $content .= "<strong>" . __('Unique Redirected Referrers', '404-solution') . ":</strong> " . esc_html($distinctrefer) . "<br>";
        $content .= "</p>";
        abj404_postbox("abj404-stats" . $x, __($title), $content);
    }
    echo "</div>";
    echo "</div>";
    echo "</div>";
}

function abj404_adminLogsPage() {
    global $wpdb;
    $sub = "logs";
    $tableOptions = abj404_getTableOptions();

    // Sanitizing unchecked table options
    foreach ($tableOptions as $key => $value) {
        $key = wp_kses_post($key);
        $tableOptions[$key] = wp_kses_post($value);
    }

    $redirects = array();
    $query = "select id, url from " . $wpdb->prefix . "abj404_redirects order by url";

    $rows = $wpdb->get_results($query, ARRAY_A);
    foreach ($rows as $row) {
        $redirects[$row['id']]['id'] = absint($row['id']);
        $redirects[$row['id']]['url'] = esc_url($row['url']);
    }


    echo "<br>";
    echo "<form method=\"GET\" action=\"\" style=\"clear: both; display: block;\" class=\"clearbothdisplayblock\">";
    echo "<input type=\"hidden\" name=\"page\" value=\"abj404_solution\">";
    echo "<input type=\"hidden\" name=\"subpage\" value=\"abj404_logs\">";
    echo "<strong><label for=\"id\">" . __('View Logs For', '404-solution') . ":</label></strong> ";
    echo "<select name=\"id\" id=\"id\">";
    $selected = "";
    if ($tableOptions['logsid'] == 0) {
        $selected = " selected";
    }
    echo "<option value=\"0\"" . $selected . ">" . __('All Redirects', '404-solution') . "</option>";
    foreach ($redirects as $redirect) {
        $selected = "";
        if ($tableOptions['logsid'] == $redirect['id']) {
            $selected = " selected";
        }
        echo "<option value=\"" . esc_attr($redirect['id']) . "\"" . $selected . ">" . esc_html($redirect['url']) . "</option>";
    }
    echo "</select><br>";
    echo "<input type=\"submit\" value=\"View Logs\" class=\"button-secondary\">";
    echo "</form>";

    $columns['url']['title'] = "URL";
    $columns['url']['orderby'] = "url";
    $columns['url']['width'] = "25%";
    $columns['host']['title'] = "IP Address";
    $columns['host']['orderby'] = "remote_host";
    $columns['host']['width'] = "10%";
    $columns['refer']['title'] = "Referrer";
    $columns['refer']['orderby'] = "referrer";
    $columns['refer']['width'] = "25%";
    $columns['dest']['title'] = "Action Taken";
    $columns['dest']['orderby'] = "action";
    $columns['dest']['width'] = "25%";
    $columns['timestamp']['title'] = "Date";
    $columns['timestamp']['orderby'] = "timestamp";
    $columns['timestamp']['width'] = "15%";

    echo "<div class=\"tablenav\">";
    abj404_drawPaginationLinks($sub, $tableOptions);
    echo "</div>";

    echo "<table class=\"wp-list-table widefat fixed\">";
    echo "<thead>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</thead>";
    echo "<tfoot>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</tfoot>";
    echo "<tbody>";

    $rows = abj404_getLogRecords($tableOptions);
    $displayed = 0;
    $y = 1;

    $timezone = get_option('timezone_string');
    if ('' == $timezone) {
        $timezone = 'UTC';
    }
    date_default_timezone_set($timezone);
    foreach ($rows as $row) {
        $class = "";
        if ($y == 0) {
            $class = " class=\"alternate\"";
            $y++;
        } else {
            $y = 0;
        }
        echo "<tr" . $class . ">";
        echo "<td></td>";
        echo "<td>" . esc_html($redirects[$row['redirect_id']]['url']) . "</td>";
        echo "<td>" . esc_html($row['remote_host']) . "</td>";
        echo "<td>";
        if ($row['referrer'] != "") {
            echo "<a href=\"" . esc_url($row['referrer']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['referrer']) . "\" target=\"_blank\">" . esc_html($row['referrer']) . "</a>";
        } else {
            echo "&nbsp;";
        }
        echo "</td>";
        echo "<td>";
        if ($row['action'] == "404") {
            echo __('Displayed 404 Page', '404-solution');
        } else {
            echo __('Redirect to', '404-solution') . " ";
            echo "<a href=\"" . esc_url($row['action']) . "\" title=\"" . __('Visit', '404-solution') . ": " . esc_attr($row['action']) . "\" target=\"_blank\">" . esc_html($row['action']) . "</a>";
        }
        echo "</td>";
        echo "<td>" . esc_html(date('Y/m/d h:i:s A', $row['timestamp'])) . "</td>";
        echo "<td></td>";
        echo "</tr>";
        $displayed++;
    }
    if ($displayed == 0) {
        echo "<tr>";
        echo "<td></td>";
        echo "<td colspan=\"5\" style=\"text-align: center; font-weight: bold;\">" . __('No Results To Display', '404-solution') . "</td>";
        echo "<td></td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    echo "<div class=\"tablenav\">";
    abj404_drawPaginationLinks($sub, $tableOptions);
    echo "</div>";
}

function abj404_adminOptionsPage() {
    $options = abj404_getOptions();

    $url = "?page=abj404_solution";

    //General Options
    $action = "abj404UpdateOptions";
    $link = wp_nonce_url($url, $action);

    echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
    echo "<div class=\"metabox-holder\">";
    echo " <div class=\"meta-box-sortables\">";

    echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
    echo "<input type=\"hidden\" name=\"action\" value=\"updateOptions\">";

    $contentAutomaticRedirects = abj404_adminOptionsPageAutomaticRedirects($options);
    abj404_postbox("abj404-autooptions", __('Automatic Redirects', '404-solution'), $contentAutomaticRedirects);

    $contentGeneralSettings = abj404_adminOptionsPageGeneralSettings($options);
    abj404_postbox("abj404-generaloptions", __('General Settings', '404-solution'), $contentGeneralSettings);

    $content404PageSuggestions = abj404_adminOptionsPage404PageSuggestions($options);
    abj404_postbox("abj404-suggestoptions", __('404 Page Suggestions', '404-solution'), $content404PageSuggestions);

    echo "<input type=\"submit\" id=\"abj404-optionssub\" value=\"Save Settings\" class=\"button-primary\">";
    echo "</form>";

    echo "</div>";
    echo "</div>";
    echo "</div>";
}

function abj404_adminOptionsPageGeneralSettings($options) {
    $content = "<p>" . __('DB Version Number', '404-solution') . ": " . esc_html($options['DB_VERSION']) . "</p>";
    $content .= "<p>" . __('Default redirect type', '404-solution') . ": ";
    $content .= "<select name=\"default_redirect\">";
    $selectedDefaultRedirect301 = "";
    if ($options['default_redirect'] == '301') {
        $selectedDefaultRedirect301 = " selected";
    }
    $content .= "<option value=\"301\"" . $selectedDefaultRedirect301 . ">" . __('Permanent 301', '404-solution') . "</option>";
    $selectedDefaultRedirect302 = "";
    if ($options['default_redirect'] == '302') {
        $selectedDefaultRedirect302 = " selected";
    }
    $content .= "<option value=\"302\"" . $selectedDefaultRedirect302 . ">" . __('Temporary 302', '404-solution') . "</option>";
    $content .= "</select></p>";

    $selectedCapture404 = "";
    if ($options['capture_404'] == '1') {
        $selectedCapture404 = " checked";
    }
    $content .= "<p>" . __('Collect incoming 404 URLs', '404-solution') . ": <input type=\"checkbox\" name=\"capture_404\" value=\"1\"" . $selectedCapture404 . "></p>";

    $content .= "<p>" . __('Admin notification level', '404-solution') . ": <input type=\"text\" name=\"admin_notification\" value=\"" . esc_attr($options['admin_notification']) . "\" style=\"width: 50px;\"> " . __('Captured URLs (0 Disables Notification)', '404-solution') . "<br>";
    $content .= __('Display WordPress admin notifications when number of captured URLs goes above specified level', '404-solution') . "</p>";

    $content .= "<p>" . __('Collected 404 URL deletion', '404-solution') . ": <input type=\"text\" name=\"capture_deletion\" value=\"" . esc_attr($options['capture_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
    $content .= __('Automatically removes 404 URLs that have been captured if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

    $content .= "<p>" . __('Manual redirect deletion', '404-solution') . ": <input type=\"text\" name=\"manual_deletion\" value=\"" . esc_attr($options['manual_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
    $content .= __('Automatically removes manually created page redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

    $selectedRemoveMatches = "";
    if ($options['remove_matches'] == '1') {
        $selectedRemoveMatches = " checked";
    }
    $content .= "<p>" . __('Remove redirect upon matching permalink', '404-solution') . ": <input type=\"checkbox\" value=\"1\" name=\"remove_matches\"" . $selectedRemoveMatches . "><br>";
    $content .= __('Checks each redirect for a new matching permalink before user is redirected. If a new page permalink is found matching the redirected URL then the redirect will be deleted.', '404-solution') . "</p>";

    return $content;
}

function abj404_adminOptionsPage404PageSuggestions($options) {
    // Suggested Alternatives Options
    $selectedDisplaySuggest = "";
    if ($options['display_suggest'] == '1') {
        $selectedDisplaySuggest = " checked";
    }
    $content = "<p>" . __('Turn on 404 suggestions', '404-solution') . ": <input type=\"checkbox\" name=\"display_suggest\" value=\"1\"" . $selectedDisplaySuggest . "><br>";
    $content .= __('Activates the 404 page suggestions function. Only works if the code is in your 404 page template.', '404-solution') . "</p>";

    $selectedSuggestCats = "";
    if ($options['suggest_cats'] == '1') {
        $selectedSuggestCats = " checked";
    }
    $content .= "<p>" . __('Allow category suggestions', '404-solution') . ": <input type=\"checkbox\" name=\"suggest_cats\" value=\"1\"" . $selectedSuggestCats . "><br>";

    $selectedSuggestTags = "";
    if ($options['suggest_tags'] == '1') {
        $selectedSuggestTags = " checked";
    }
    $content .= "<p>" . __('Allow tag suggestions', '404-solution') . ": <input type=\"checkbox\" name=\"suggest_tags\" value=\"1\"" . $selectedSuggestTags . "><br>";

    $content .= "<p>" . __('Minimum score of suggestions to display', '404-solution') . ": <input type=\"text\" name=\"suggest_minscore\" value=\"" . esc_attr($options['suggest_minscore']) . "\" style=\"width: 50px;\"></p>"
    ;
    $content .= "<p>" . __('Maximum number of suggestions to display', '404-solution') . ": <input type=\"text\" name=\"suggest_max\" value=\"" . esc_attr($options['suggest_max']) . "\" style=\"width: 50px;\"></p>";

    $content .= "<p>" . __('Page suggestions title', '404-solution') . ": <input type=\"text\" name=\"suggest_title\" value=\"" . esc_attr($options['suggest_title']) . "\" style=\"width: 200px;\"></p>";

    $content .= "<p>" . __('Display Before/After page suggestions', '404-solution') . ": ";
    $content .= "<input type=\"text\" name=\"suggest_before\" value=\"" . esc_attr($options['suggest_before']) . "\" style=\"width: 100px;\"> / ";
    $content .= "<input type=\"text\" name=\"suggest_after\" value=\"" . esc_attr($options['suggest_after']) . "\" style=\"width: 100px;\">";

    $content .= "<p>" . __('Display Before/After each suggested entry', '404-solution') . ": ";
    $content .= "<input type=\"text\" name=\"suggest_entrybefore\" value=\"" . esc_attr($options['suggest_entrybefore']) . "\" style=\"width: 100px;\"> / ";
    $content .= "<input type=\"text\" name=\"suggest_entryafter\" value=\"" . esc_attr($options['suggest_entryafter']) . "\" style=\"width: 100px;\">";

    $content .= "<p>" . __('Display if no suggestion results', '404-solution') . ": ";
    $content .= "<input type=\"text\" name=\"suggest_noresults\" value=\"" . esc_attr($options['suggest_noresults']) . "\" style=\"width: 200px;\">";

    return $content;
}

function abj404_adminOptionsPageAutomaticRedirects($options) {
    $content = "";

    $selected = "";
    global $wpdb;
    $content .= "<label for=\"dest404page\">" . __('Redirect all unhandled 404s to', '404-solution') . ":</label> <select id=\"dest404page\" name=\"dest404page\">";

    $userSelected = (isset($options['dest404page']) ? $options['dest404page'] : null);
    if ($userSelected == 'none') {
        $selected = "selected";
    }

    $content .= "<option value=\"none\"" . $selected . ">" . "Default 404 Page (Unchanged)" . "</option>";

    $rows = get_pages();
    foreach ($rows as $row) {
        $id = $row->ID;
        $theTitle = $row->post_title;
        $thisval = $id . "|POST";

        $parent = $row->post_parent;
        while ($parent != 0) {
            $parent = absint($parent);
            $query = $wpdb->prepare("select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = %s", $parent);
            $prow = $wpdb->get_row($query, OBJECT);
            if (!( $prow == NULL )) {
                $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                $parent = $prow->post_parent;
            } else {
                break;
            }
        }
        if ($userSelected == $thisval) {
            $selected = "selected";
        } else {
            $selected = "";
        }

        $content .= "<option value=\"" . $thisval . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . esc_html($theTitle) . "</option>";
    }

    $content .= "</select><br>";

    $selectedAutoRedirects = "";
    if ($options['auto_redirects'] == '1') {
        $selectedAutoRedirects = " checked";
    }

    $content .= "<p>" . __('Create automatic redirects', '404-solution') . ": <input type=\"checkbox\" name=\"auto_redirects\" value=\"1\"" . $selectedAutoRedirects . "><br>";
    $content .= __('Automatically creates redirects based on best possible suggested page.', '404-solution') . "</p>";

    $content .= "<p>" . __('Minimum match score', '404-solution') . ": <input type=\"text\" name=\"auto_score\" value=\"" . esc_attr($options['auto_score']) . "\" style=\"width: 50px;\"><br>";
    $content .= __('Only create an automatic redirect if the suggested page has a score above the specified number', '404-solution') . "</p>";

    $selectedAutoCats = "";
    if ($options['auto_cats'] == '1') {
        $selectedAutoCats = " checked";
    }
    $content .= "<p>" . __('Create automatic redirects for categories', '404-solution') . ": <input type=\"checkbox\" name=\"auto_cats\" value=\"1\"" . $selectedAutoCats . "></p>";

    $selectedAutoTags = "";
    if ($options['auto_tags'] == '1') {
        $selectedAutoTags = " checked";
    }
    $content .= "<p>" . __('Create automatic redirects for tags', '404-solution') . ": <input type=\"checkbox\" name=\"auto_tags\" value=\"1\"" . $selectedAutoTags . "></p>";

    $selectedForcePermaLinks = "";
    if ($options['force_permalinks'] == '1') {
        $selectedForcePermaLinks = " checked";
    }
    $content .= "<p>" . __('Force current permalinks', '404-solution') . ": <input type=\"checkbox\" name=\"force_permalinks\" value=\"1\"" . $selectedForcePermaLinks . "><br>";
    $content .= __('Creates auto redirects for any url resolving to a post/page that doesn\'t match the current permalinks', '404-solution') . "</p>";

    $content .= "<p>" . __('Auto redirect deletion', '404-solution') . ": <input type=\"text\" name=\"auto_deletion\" value=\"" . esc_attr($options['auto_deletion']) . "\" style=\"width: 50px;\"> " . __('Days (0 Disables Auto Delete)', '404-solution') . "<br>";
    $content .= __('Removes auto created redirects if they haven\'t been used for the specified amount of time.', '404-solution') . "</p>";

    return $content;
}

function abj404_adminRedirectsPage() {
    global $wpdb;
    $sub = "redirects";

    $options = abj404_getOptions();
    $tableOptions = abj404_getTableOptions();

    // Sanitizing unchecked table options
    foreach ($tableOptions as $key => $value) {
        $key = wp_kses_post($key);
        $tableOptions[$key] = wp_kses_post($value);
    }

    abj404_drawFilters($sub, $tableOptions);

    $columns['url']['title'] = "URL";
    $columns['url']['orderby'] = "url";
    $columns['url']['width'] = "25%";
    $columns['status']['title'] = "Status";
    $columns['status']['orderby'] = "status";
    $columns['status']['width'] = "5%";
    $columns['type']['title'] = "Type";
    $columns['type']['orderby'] = "type";
    $columns['type']['width'] = "10%";
    $columns['dest']['title'] = "Destination";
    $columns['dest']['orderby'] = "final_dest";
    $columns['dest']['width'] = "25%";
    $columns['code']['title'] = "Redirect";
    $columns['code']['orderby'] = "code";
    $columns['code']['width'] = "5%";
    $columns['hits']['title'] = "Hits";
    $columns['hits']['orderby'] = "hits";
    $columns['hits']['width'] = "10%";
    $columns['timestamp']['title'] = "Created";
    $columns['timestamp']['orderby'] = "timestamp";
    $columns['timestamp']['width'] = "10%";
    $columns['last_used']['title'] = "Last Used";
    $columns['last_used']['orderby'] = "";
    $columns['last_used']['width'] = "10%";

    $timezone = get_option('timezone_string');
    if ('' == $timezone) {
        $timezone = 'UTC';
    }
    date_default_timezone_set($timezone);

    echo "<div class=\"tablenav\">";
    abj404_drawPaginationLinks($sub, $tableOptions);

    if ($tableOptions['filter'] == '-1') {
        echo "<div class=\"alignleft actions\">";
        $eturl = "?page=abj404_solution&filter=-1";
        $trashaction = "abj404_emptyRedirectTrash";
        $eturl = wp_nonce_url($eturl, $trashaction);

        echo "<form method=\"POST\" action=\"" . esc_url($eturl) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"emptyRedirectTrash\">";
        echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Empty Trash', '404-solution') . "\">";
        echo "</form>";
        echo "</div>";
    }
    echo "</div>";

    echo "<table class=\"wp-list-table widefat fixed\">";
    echo "<thead>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</thead>";
    echo "<tfoot>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</tfoot>";
    echo "<tbody id=\"the-list\">";
    $rows = abj404_getRecords($sub, $tableOptions);
    $displayed = 0;
    $y = 1;
    foreach ($rows as $row) {
        $displayed++;
        $status = "";
        if ($row['status'] == ABJ404_MANUAL) {
            $status = __('Manual', '404-solution');
        } else if ($row['status'] == ABJ404_AUTO) {
            $status = __('Automatic', '404-solution');
        }

        $type = "";
        $dest = "";
        $link = "";
        $title = __('Visit', '404-solution') . " ";
        if ($row['type'] == ABJ404_EXTERNAL) {
            $type = __('External', '404-solution');
            $dest = $row['final_dest'];
            $link = $row['final_dest'];
            $title .= $row['final_dest'];
        } else if ($row['type'] == ABJ404_POST) {
            $type = __('Post/Page', '404-solution');
            $permalink = abj404_permalinkInfo($row['final_dest'] . "|POST", 0);
            $dest = $permalink['title'];
            $link = $permalink['link'];
            $title .= $permalink['title'];
        } else if ($row['type'] == ABJ404_CAT) {
            $type = __('Category', '404-solution');
            $permalink = abj404_permalinkInfo($row['final_dest'] . "|CAT", 0);
            $dest = $permalink['title'];
            $link = $permalink['link'];
            $title .= __('Category:', '404-solution') . " " . $permalink['title'];
        } else if ($row['type'] == ABJ404_TAG) {
            $type = __('Tag', '404-solution');
            $permalink = abj404_permalinkInfo($row['final_dest'] . "|TAG", 0);
            $dest = $permalink['title'];
            $link = $permalink['link'];
            $title .= __('Tag:', '404-solution') . " " . $permalink['title'];
        }


        $hits = $row['hits'];
        $last_used = abj404_getRedirectLastUsed($row['id']);
        if ($last_used != 0) {
            $last = date("Y/m/d h:i:s A", $last_used);
        } else {
            $last = __('Never Used', '404-solution');
        }

        
        $editlink = "?page=abj404_solution&subpage=abj404_edit&id=" . absint($row['id']);
        $logslink = "?page=abj404_solution&subpage=abj404_logs&id=" . absint($row['id']);
        $trashlink = "?page=abj404_solution&id=" . absint($row['id']);
        $deletelink = "?page=abj404_solution&remove=1&id=" . absint($row['id']);

        if ($tableOptions['filter'] == -1) {
            $trashlink .= "&trash=0";
            $trashtitle = __('Restore', '404-solution');
        } else {
            $trashlink .= "&trash=1";
            $trashtitle = __('Trash', '404-solution');
        }

        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            $deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }
        if ($tableOptions['filter'] != 0) {
            $trashlink .= "&filter=" . $tableOptions['filter'];
            $deletelink .= "&filter=" . $tableOptions['filter'];
        }

        $trashaction = "abj404_trashRedirect";
        $trashlink = wp_nonce_url($trashlink, $trashaction);

        if ($tableOptions['filter'] == -1) {
            $deleteaction = "abj404_removeRedirect";
            $deletelink = wp_nonce_url($deletelink, $deleteaction);
        }

        $class = "";
        if ($y == 0) {
            $class = " class=\"alternate\"";
            $y++;
        } else {
            $y = 0;
        }

        echo "<tr id=\"post-" . esc_attr($row['id']) . "\"" . $class . ">";
        echo "<td></td>";
        echo "<td>";
        echo "<strong><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . esc_html($row['url']) . "</a></strong>";
        echo "<div class=\"row-actions\">";
        if ($tableOptions['filter'] != -1) {
            echo "<span class=\"edit\"><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . __('Edit') . "</a></span>";
            echo " | ";
        }
        echo "<span class=\"trash\"><a href=\"" . esc_url($trashlink) . "\" title=\"" . __('Trash Redirected URL', '404-solution') . "\">" . esc_html($trashtitle) . "</a></span>";
        echo " | ";
        echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs') . "</a></span>";
        if ($tableOptions['filter'] == -1) {
            echo " | ";
            echo "<span class=\"delete\"><a href=\"" . esc_url($deletelink) . "\" title=\"" . __('Delete Redirect Permanently', '404-solution') . "\">" . __('Delete Permanently', '404-solution') . "</a></span>";
        }
        echo "</div>";
        echo "</td>";
        echo "<td>" . esc_html($status) . "</td>";
        echo "<td>" . esc_html($type) . "</td>";
        echo "<td><a href=\"" . esc_url($link) . "\" title=\"" . $title . "\" target=\"_blank\">" . esc_html($dest) . "</a></td>";
        echo "<td>" . esc_html($row['code']) . "</td>";
        echo "<td>" . esc_html($hits) . "</td>";
        echo "<td>" . esc_html(date("Y/m/d h:i:s A", $row['timestamp'])) . "</td>";
        echo "<td>" . esc_html($last) . "</td>";
        echo "<td></td>";
        echo "</tr>";
    }
    if ($displayed == 0) {
        echo "<tr>";
        echo "<td></td>";
        echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . __('No Records To Display', '404-solution') . "</td>";
        echo "<td></td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    echo "<div class=\"tablenav\">";
    abj404_drawPaginationLinks($sub, $tableOptions);
    echo "</div>";

    if ($tableOptions['filter'] != -1) {
        echo "<h3>" . __('Add Manual Redirect', '404-solution') . "</h3>";

        $url = "?page=abj404_solution";

        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }

        $action = "abj404addRedirect";
        $link = wp_nonce_url($url, $action);

        echo "<form method=\"POST\" action=\"" . $link . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"addRedirect\">";
        if (isset($_POST['url'])) {
            $postedURL = filter_input(INPUT_POST, "url", FILTER_SANITIZE_URL);
        } else {
            $postedURL = "";
        }
        echo "<strong><label for=\"url\">" . __('URL', '404-solution') . ":</label></strong> <input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . esc_attr($postedURL) . "\"> (" . __('Required', '404-solution') . ")<br>";
        echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</strong> <select id=\"dest\" name=\"dest\">";
        $selected = "";
        if (isset($_POST['dest']) && $_POST['dest'] == "EXTERNAL") {
            $selected = " selected";
        }
        echo "<option value=\"EXTERNAL\"" . $selected . ">" . __('External Page', '404-solution') . "</options>";

        $query = "select id from $wpdb->posts where post_status='publish' and post_type='post' order by post_date desc";
        $rows = $wpdb->get_results($query);
        foreach ($rows as $row) {
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|POST";

            $selected = "";
            if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Post', '404-solution') . ": " . esc_html($theTitle) . "</option>";
        }

        $rows = get_pages();
        foreach ($rows as $row) {
            $id = $row->ID;
            $theTitle = $row->post_title;
            $thisval = $id . "|POST";

            $parent = $row->post_parent;
            while ($parent != 0) {
                $parent = absint($parent);
                $query = $wpdb->prepare("select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = %s", $parent);
                $prow = $wpdb->get_row($query, OBJECT);
                if (!( $prow == NULL )) {
                    $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                    $parent = $prow->post_parent;
                } else {
                    break;
                }
            }

            $selected = "";
            if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_url($thisval) . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . esc_html($theTitle) . "</option>";
        }

        $cats = get_categories('hierarchical=0');
        foreach ($cats as $cat) {
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|CAT";

            $selected = "";
            if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . esc_html($theTitle) . "</option>";
        }

        $tags = get_tags('hierarchical=0');
        foreach ($tags as $tag) {
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|TAG";

            $selected = "";
            if (isset($_POST['dest']) && $_POST['dest'] == $thisval) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . esc_html($theTitle) . "</option>";
        }

        echo "</select><br>";
        if (isset($_POST['external'])) {
            $postedExternal = filter_input(INPUT_POST, "external", FILTER_SANITIZE_URL);
        } else {
            $postedExternal = "";
        }
        echo "<strong><label for=\"external\">" . __('External URL', '404-solution') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . esc_attr($postedExternal) . "\"> (" . __('Required if Redirect to is set to External Page', '404-solution') . ")<br>";
        echo "<strong><label for=\"code\">" . __('Redirect Type', '404-solution') . ":</label></strong> <select id=\"code\" name=\"code\">";
        if ((!isset($_POST['code']) ) || $_POST['code'] == "") {
            $codeselected = $options['default_redirect'];
        } else {
            $codeselected = filter_input(INPUT_POST, "code", FILTER_SANITIZE_STRING);
        }
        $codes = array(301, 302);
        foreach ($codes as $code) {
            $selected = "";
            if ($code == $codeselected) {
                $selected = " selected";
            }
            if ($code == 301) {
                $title = '301 Permanent Redirect';
            } else {
                $title = '302 Temporary Redirect';
            }
            echo "<option value=\"" . esc_attr($code) . "\"" . $selected . ">" . esc_html($title) . "</option>";
        }
        echo "</select><br>";
        echo "<input type=\"submit\" value=\"" . __('Add Redirect', '404-solution') . "\" class=\"button-secondary\">";
        echo "</form>";
    }
}

function abj404_adminCapturedPage() {
    $sub = "captured";

    $options = abj404_getOptions();
    $tableOptions = abj404_getTableOptions();

    abj404_drawFilters($sub, $tableOptions);

    $columns['url']['title'] = "URL";
    $columns['url']['orderby'] = "url";
    $columns['url']['width'] = "50%";
    $columns['hits']['title'] = "Hits";
    $columns['hits']['orderby'] = "hits";
    $columns['hits']['width'] = "10%";
    $columns['timestamp']['title'] = "Created";
    $columns['timestamp']['orderby'] = "timestamp";
    $columns['timestamp']['width'] = "20%";
    $columns['last_used']['title'] = "Last Used";
    $columns['last_used']['orderby'] = "";
    $columns['last_used']['width'] = "20%";

    $timezone = get_option('timezone_string');
    if ('' == $timezone) {
        $timezone = 'UTC';
    }
    date_default_timezone_set($timezone);


    echo "<div class=\"tablenav\">";
    abj404_drawPaginationLinks($sub, $tableOptions);

    if ($tableOptions['filter'] == '-1') {
        echo "<div class=\"alignleft actions\">";
        $eturl = "?page=abj404_solution&subpage=abj404_captured&filter=-1";
        $trashaction = "abj404_emptyCapturedTrash";
        $eturl = wp_nonce_url($eturl, $trashaction);

        echo "<form method=\"POST\" action=\"" . esc_url($eturl) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"emptyCapturedTrash\">";
        echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Empty Trash', '404-solution') . "\">";
        echo "</form>";
        echo "</div>";
    } else {
        echo "<div class=\"alignleft actions\">";
        $url = "?page=abj404_solution&subpage=abj404_captured";
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }

        $bulkaction = "abj404_bulkProcess";
        // is there a way to use the <select> below and use the selected action (bulkignore, bulkcaptured, bulktrash)
        // when creating the nonce (instead of using one nonce for all actions)?
        $url = wp_nonce_url($url, $bulkaction); 

        echo "<form method=\"POST\" action=\"" . $url . "\">";
        echo "<select name=\"action\">";
        if ($tableOptions['filter'] != ABJ404_IGNORED) {
            echo "<option value=\"bulkignore\">" . __('Mark as ignored', '404-solution') . "</option>";
        } else {
            echo "<option value=\"bulkcaptured\">" . __('Mark as captured', '404-solution') . "</option>";
        }
        echo "<option value=\"bulktrash\">" . __('Trash', '404-solution') . "</option>";
        echo "</select>";
        echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . __('Apply', '404-solution') . "\">";
        echo "</div>";
    }
    echo "</div>";

    echo "<table class=\"wp-list-table widefat fixed\">";
    echo "<thead>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</thead>";
    echo "<tfoot>";
    abj404_buildTableColumns($sub, $tableOptions, $columns);
    echo "</tfoot>";
    echo "<tbody id=\"the-list\">";
    $rows = abj404_getRecords($sub, $tableOptions);
    $displayed = 0;
    $y = 1;
    foreach ($rows as $row) {
        $displayed++;

        $hits = $row['hits'];
        $last_used = abj404_getRedirectLastUsed($row['id']);
        if ($last_used != 0) {
            $last = date("Y/m/d h:i:s A", $last_used);
        } else {
            $last = __('Never Used', '404-solution');
        }

        $editlink = "?page=abj404_solution&subpage=abj404_edit&id=" . $row['id'];
        $logslink = "?page=abj404_solution&subpage=abj404_logs&id=" . $row['id'];
        $trashlink = "?page=abj404_solution&&subpage=abj404_captured&id=" . $row['id'];
        $ignorelink = "?page=abj404_solution&&subpage=abj404_captured&id=" . $row['id'];
        $deletelink = "?page=abj404_solution&subpage=abj404_captured&remove=1&id=" . $row['id'];

        if ($tableOptions['filter'] == -1) {
            $trashlink .= "&trash=0";
            $trashtitle = __('Restore', '404-solution');
        } else {
            $trashlink .= "&trash=1";
            $trashtitle = __('Trash', '404-solution');
        }

        if ($tableOptions['filter'] == ABJ404_IGNORED) {
            $ignorelink .= "&ignore=0";
            $ignoretitle = __('Remove Ignore Status', '404-solution');
        } else {
            $ignorelink .= "&ignore=1";
            $ignoretitle = __('Ignore 404 Error', '404-solution');
        }

        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            $ignorelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
            $deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
        }
        if ($tableOptions['filter'] != 0) {
            $trashlink .= "&filter=" . $tableOptions['filter'];
            $ignorelink .= "&filter=" . $tableOptions['filter'];
            $deletelink .= "&filter=" . $tableOptions['filter'];
        }

        $trashaction = "abj404_trashRedirect";
        $trashlink = wp_nonce_url($trashlink, $trashaction);

        if ($tableOptions['filter'] == -1) {
            $deleteaction = "abj404_removeRedirect";
            $deletelink = wp_nonce_url($deletelink, $deleteaction);
        }

        $ignoreaction = "abj404_ignore404";
        $ignorelink = wp_nonce_url($ignorelink, $ignoreaction);

        $class = "";
        if ($y == 0) {
            $class = " class=\"alternate\"";
            $y++;
        } else {
            $y = 0;
        }

        echo "<tr id=\"post-" . esc_attr($row['id']) . "\"" . $class . ">";
        echo "<th class=\"check-column\">";
        if ($tableOptions['filter'] != '-1') {
            echo "<input type=\"checkbox\" name=\"idnum[]\" value=\"" . esc_attr($row['id']) . "\">";
        }
        echo "</th>";
        echo "<td>";
        echo "<strong><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . esc_html($row['url']) . "</a></strong>";
        echo "<div class=\"row-actions\">";
        if ($tableOptions['filter'] != -1) {
            echo "<span class=\"edit\"><a href=\"" . esc_url($editlink) . "\" title=\"" . __('Edit Redirect Details', '404-solution') . "\">" . __('Edit', '404-solution') . "</a></span>";
            echo " | ";
        }
        echo "<span class=\"trash\"><a href=\"" . esc_url($trashlink) . "\" title=\"" . __('Trash Redirected URL', '404-solution') . "\">" . esc_html($trashtitle) . "</a></span>";
        echo " | ";
        echo "<span class=\"view\"><a href=\"" . esc_url($logslink) . "\" title=\"" . __('View Redirect Logs', '404-solution') . "\">" . __('View Logs', '404-solution') . "</a></span>";
        if ($tableOptions['filter'] != -1) {
            echo " | ";
            echo "<span class=\"ignore\"><a href=\"" . esc_url($ignorelink) . "\" title=\"" . $ignoretitle . "\">" . esc_html($ignoretitle) . "</a></span>";
        } else {
            echo " | ";
            echo "<span class=\"delete\"><a href=\"" . esc_url($deletelink) . "\" title=\"" . __('Delete Redirect Permanently', '404-solution') . "\">" . __('Delete Permanently', '404-solution') . "</a></span>";
        }
        echo "</div>";
        echo "</td>";
        echo "<td>" . esc_html($hits) . "</td>";
        echo "<td>" . esc_html(date("Y/m/d h:i:s A", $row['timestamp'])) . "</td>";
        echo "<td>" . esc_html($last) . "</td>";
        echo "<td></td>";
        echo "</tr>";
    }
    if ($displayed == 0) {
        echo "<tr>";
        echo "<td></td>";
        echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . __('No Records To Display', '404-solution') . "</td>";
        echo "<td></td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    echo "<div class=\"tablenav\">";
    if ($tableOptions['filter'] != '-1') {
        echo "</form>";
    }
    abj404_drawPaginationLinks($sub, $tableOptions);
    echo "</div>";
}

function abj404_adminEditPage() {
    global $wpdb;
    $sub = "edit";

    echo "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" /> <BR/>";
    if (!( isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id']) )) {
        if (!( isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id']) )) {
            echo "Error: Invalid ID Number";
            return;
        } else {
            $recnum = absint(filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT));
        }
    } else {
        $recnum = absint(filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT));
    }

    $query = $wpdb->prepare("select id, url, type, final_dest, code from " . $wpdb->prefix . "abj404_redirects where 1 and id = %d", $recnum);
    $redirect = $wpdb->get_row($query, ARRAY_A);

    if (!( $redirect == null )) {
        echo "<h3>" . __('Redirect Details', '404-solution') . "</h3>";

        $url = "?page=abj404_solution&subpage=abj404_edit";

        $action = "abj404editRedirect";
        $link = wp_nonce_url($url, $action);

        echo "<form method=\"POST\" action=\"" . esc_attr($link) . "\">";
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";
        echo "<input type=\"hidden\" name=\"id\" value=\"" . esc_attr($redirect['id']) . "\">";
        echo "<strong><label for=\"url\">" . __('URL', '404-solution') . ":</label></strong> <input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . esc_attr($redirect['url']) . "\"> (" . __('Required', '404-solution') . ")<br>";
        echo "<strong><label for=\"dest\">" . __('Redirect to', '404-solution') . ":</strong> <select id=\"dest\" name=\"dest\">";
        $selected = "";
        if ($redirect['type'] == ABJ404_EXTERNAL) {
            $selected = " selected";
        }
        echo "<option value=\"" . ABJ404_EXTERNAL . "\"" . $selected . ">" . __('External Page', '404-solution') . "</options>";

        $query = "select id from $wpdb->posts where post_status='publish' and post_type='post' order by post_date desc";
        $rows = $wpdb->get_results($query);
        foreach ($rows as $row) {
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_POST;

            $selected = "";
            if ($redirect['type'] == ABJ404_POST && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Post', '404-solution') . ": " . $theTitle . "</option>";
        }

        $rows = get_pages();
        foreach ($rows as $row) {
            $id = $row->ID;
            $theTitle = $row->post_title;
            $thisval = $id . "|" . ABJ404_POST;

            $parent = $row->post_parent;
            while ($parent != 0) {
                $query = $wpdb->prepare("select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = %d", $parent);
                $prow = $wpdb->get_row($query, OBJECT);
                if (!( $prow == NULL )) {
                    $theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
                    $parent = $prow->post_parent;
                } else {
                    break;
                }
            }

            $selected = "";
            if ($redirect['type'] == ABJ404_POST && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Page', '404-solution') . ": " . $theTitle . "</option>\n";
        }

        $cats = get_categories('hierarchical=0');
        foreach ($cats as $cat) {
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_CAT;

            $selected = "";
            if ($redirect['type'] == ABJ404_CAT && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }

        $tags = get_tags('hierarchical=0');
        foreach ($tags as $tag) {
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TAG;

            $selected = "";
            if ($redirect['type'] == ABJ404_TAG && $redirect['final_dest'] == $id) {
                $selected = " selected";
            }
            echo "<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }

        echo "</select><br>";
        $final = "";
        if ($redirect['type'] == ABJ404_EXTERNAL) {
            $final = $redirect['final_dest'];
        }
        echo "<strong><label for=\"external\">" . __('External URL', '404-solution') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . $final . "\"> (" . __('Required if Redirect to is set to External Page', '404-solution') . ")<br>";
        echo "<strong><label for=\"code\">" . __('Redirect Type', '404-solution') . ":</label></strong> <select id=\"code\" name=\"code\">";
        if ($redirect['code'] == "") {
            $codeselected = $options['default_redirect'];
        } else {
            $codeselected = $redirect['code'];
        }
        $codes = array(301, 302);
        foreach ($codes as $code) {
            $selected = "";
            if ($code == $codeselected) {
                $selected = " selected";
            }
            if ($code == 301) {
                $title = '301 Permanent Redirect';
            } else {
                $title = '302 Temporary Redirect';
            }
            echo "<option value=\"" . esc_attr($code) . "\"" . $selected . ">" . $title . "</option>";
        }
        echo "</select><br>";
        echo "<input type=\"submit\" value=\"" . __('Update Redirect', '404-solution') . "\" class=\"button-secondary\">";
        echo "</form>";
    } else {
        echo "Error: Invalid ID Number!";
    }
}

function abj404_AdminToolsPage() {
    $sub = "tools";

    $hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

    $url = "?page=abj404_solution&subpage=abj404_tools";
    $action = "abj404_purgeRedirects";

    $link = wp_nonce_url($url, $action);


    echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
    echo "<div class=\"metabox-holder\">";
    echo " <div class=\"meta-box-sortables\">";

    $content = "";
    $content .= "<form method=\"POST\" action=\"" . esc_url($link) . "\">";
    $content .= "<input type=\"hidden\" name=\"action\" value=\"purgeRedirects\">";

    $content .= "<p>";
    $content .= "<strong><label for=\"purgetype\">" . __('Purge Type', '404-solution') . ":</label></strong> <select name=\"purgetype\" id=\"purgetype\">";
    $content .= "<option value=\"logs\">" . __('Logs Only', '404-solution') . "</option>";
    $content .= "<option value=\"redirects\">" . __('Logs & Redirects', '404-solution') . "</option>";
    $content .= "</select><br><br>";

    $content .= "<strong>" . __('Redirect Types', '404-solution') . ":</strong><br>";
    $content .= "<ul style=\"margin-left: 40px;\">";
    $content .= "<li><input type=\"checkbox\" id=\"auto\" name=\"types[]\" value=\"" . ABJ404_AUTO . "\"> <label for=\"auto\">" . __('Automatic Redirects', '404-solution') . "</label></li>";
    $content .= "<li><input type=\"checkbox\" id=\"manual\" name=\"types[]\" value=\"" . ABJ404_MANUAL . "\"> <label for=\"manual\">" . __('Manual Redirects', '404-solution') . "</label></li>";
    $content .= "<li><input type=\"checkbox\" id=\"captured\" name=\"types[]\" value=\"" . ABJ404_CAPTURED . "\"> <label for=\"captured\">" . __('Captured URLs', '404-solution') . "</label></li>";
    $content .= "<li><input type=\"checkbox\" id=\"ignored\" name=\"types[]\" value=\"" . ABJ404_IGNORED . "\"> <label for=\"ignored\">" . __('Ignored URLs', '404-solution') . "</label></li>";
    $content .= "</ul>";

    $content .= "<strong>" . __('Sanity Check', '404-solution') . "</strong><br>";
    $content .= __('Using the purge options will delete logs and redirects matching the boxes selected above. This action is not reversible. Hopefully you know what you\'re doing.', '404-solution') . "<br>";
    $content .= "<br>";
    $content .= "<input type=\"checkbox\" id=\"sanity\" name=\"sanity\" value=\"1\"> " . __('I understand the above statement, I know what I am doing... blah blah blah. Just delete the records!', '404-solution') . "<br>";
    $content .= "<br>";
    $content .= "<input type=\"submit\" value=\"" . __('Purge Entries!', '404-solution') . "\" class=\"button-secondary\">";
    $content .= "</p>";

    $content .= "</form>";

    abj404_postbox("abj404-purgeRedirects", __('Purge Options', '404-solution'), $content);

    echo "</div></div></div>";
}

function abj404_purgeRedirects() {
    global $wpdb;
    $message = "";

    // nonce already verified.
    
    $redirects = $wpdb->prefix . "abj404_redirects";
    $logs = $wpdb->prefix . "abj404_logs";

    $sanity = filter_input(INPUT_POST, "sanity", FILTER_SANITIZE_STRING);
    if ($sanity == "1") {
        if (isset($_POST['types']) && '' != $_POST['types']) {
            $type = filter_input(INPUT_POST, "types", FILTER_SANITIZE_STRING);
            if (is_array($type)) {
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

                if ($types != "") {
                    $purge = filter_input(INPUT_POST, "purgetype", FILTER_SANITIZE_STRING);

                    if ($purge == "logs" || $purge == "redirects") {
                        $query = $wpdb->prepare("delete from " . $logs . " where redirect_id in (select id from " . $redirects . " where status in (%s))", esc_html($types));
                        $logcount = $wpdb->query($query);
                        $message = $logcount . " " . __('Log entries were purged.', '404-solution');

                        if ($purge == "redirects") {
                            $query = $wpdb->prepare("delete from " . $redirects . " where status in (%s)", esc_html($types));
                            $count = $wpdb->query($query);
                            $message .= "<br>";
                            $message .= $count . " " . __('Redirect entries were purged.', '404-solution');
                        }
                    } else {
                        $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
                    }
                } else {
                    $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
                }
            } else {
                $message = __('An unknown error has occurred.', '404-solution');
            }
        } else {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
        }
    } else {
        $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
    }

    return $message;
}
