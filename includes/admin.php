<?php
/*
 * 404 Manager Admin Functions
 *
*/

function wbz404_addAdminPage() {
	global $menu;
        if (function_exists('add_options_page')) {
		$options = wbz404_getOptions();
		$pageName = "404 Redirected";
		if ($options['admin_notification'] != '0') {
			$captured = wbz404_capturedCount();
			if ($captured >= $options['admin_notification']) {
				$pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . $captured . "</span></span>";
				$pos = strpos($menu[80][0], 'update-plugins');
				if ($pos === false) {
					$menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>"; 
				}
			}
		}
                add_options_page('404 Redirected', $pageName, 'manage_options', 'wbz404_redirected', 'wbz404_adminPage');
        }
}

add_action('admin_menu', 'wbz404_addAdminPage');

function wbz404_dashboardNotification() {
	global $pagenow;
	if ( current_user_can('manage_options') ) {
		if ((isset($_GET['page']) && $_GET['page'] == "wbz404_redirected") || ( $pagenow == 'index.php' && (!(isset($_GET['page']))))) {
			$options = wbz404_getOptions();
			if ($options['admin_notification'] != '0') {
				$captured = wbz404_capturedCount();
				if ($captured >= $options['admin_notification']) {
					echo "<div class=\"updated\"><p><strong>" . wbz404_trans('404 Redirected') . ":</strong> " . wbz404_trans('There are ' . $captured . ' captured 404 URLs that need to be processed.') . "</p></div>";
				}
			}
		}
	}
}

add_action('admin_notices', 'wbz404_dashboardNotification' );

function wbz404_postbox($id, $title, $content) {
	echo "<div id=\"" . $id . "\" class=\"postbox\">";
		echo "<h3 class=\"hndle\" style=\"cursor: default;\"><span>" . $title . "</span></h3>";
		echo "<div class=\"inside\">" . $content . "</div>";
	echo "</div>";
}

function wbz404_capturedCount() {
	global $wpdb;
	$query="select count(id) from " . $wpdb->prefix . "wbz404_redirects where status = " . $wpdb->escape(WBZ404_CAPTURED);
	$captured = $wpdb->get_col($query, 0);
	if (count($captured) == 0) {
		$captured[0] = 0;
	}
	return $captured[0];
}

function wbz404_updateOptions() {
	$message="";
	$options = wbz404_getOptions();
	if ($_POST['default_redirect'] == "301" || $_POST['default_redirect'] == "302") {
		$options['default_redirect'] = $_POST['default_redirect'];
	} else {
		$message.= wbz404_trans('Error: Invalid value specified for default redirect type') . ".<br>";
	}

	if ($_POST['capture_404'] == "1") { 
		$options['capture_404'] = '1';
	} else {
		$options['capture_404'] = '0';
	}

	if (preg_match('/^[0-9]+$/', $_POST['admin_notification']) == 1) {
		$options['admin_notification'] = $_POST['admin_notification'];
	}

	if (preg_match('/^[0-9]+$/', $_POST['capture_deletion'])==1 && $_POST['capture_deletion'] >= 0) {
		$options['capture_deletion'] = $_POST['capture_deletion'];
	} else {
		$message.= wbz404_trans('Collected URL deletion value must be a number greater or equal to zero') . ".<br>";
	}

	if (preg_match('/^[0-9]+$/', $_POST['manual_deletion'])==1 && $_POST['manual_deletion'] >= 0) {
		$options['manual_deletion'] = $_POST['manual_deletion'];
	} else {
		$message.= wbz404_trans('Manual redirect deletion value must be a number greater or equal to zero') . ".<br>";
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
		$options['suggest_minscore'] = $_POST['suggest_minscore'];
	} else {
		$message.= wbz404_trans('Suggestion minimum score value must be a number between 1 and 99') . ".<br>";
	}

	if (preg_match('/^[0-9]+$/', $_POST['suggest_max']) == 1 && $_POST['suggest_max'] >= 1) {
		$options['suggest_max'] = $_POST['suggest_max'];
	} else {
		$message.= wbz404_trans('Maximum number of suggest value must be a number greater or equal to 1') . ".<br>";
	}

	$options['suggest_title'] = $_POST['suggest_title'];
	$options['suggest_before'] = $_POST['suggest_before'];
	$options['suggest_after'] = $_POST['suggest_after'];
	$options['suggest_entrybefore'] = $_POST['suggest_entrybefore'];
	$options['suggest_entryafter'] = $_POST['suggest_entryafter'];
	$options['suggest_noresults'] = $_POST['suggest_noresults'];

	if ($_POST['404_promote'] == "1") {
		$options['404_promote'] = '1';
	} else {
		$options['404_promote'] = '0';
	}

	if ($_POST['auto_redirects'] == "1") {
		$options['auto_redirects'] = '1';
	} else {
		$options['auto_redirects'] = '0';
	}

	if ($_POST['auto_cats'] == "1") {
		$options['auto_cats'] = '1';
	} else {
		$options['auto_cats'] = '0';
	}

	if ($_POST['auto_tags'] == "1") {
		$options['auto_tags'] = '1';
	} else {
		$options['auto_tags'] = '0';
	}

	if (preg_match('/^[0-9]+$/', $_POST['auto_score']) == 1 && $_POST['auto_score'] >= 0 && $_POST['auto_score'] <= 99) {
		$options['auto_score'] = $_POST['auto_score'];
	} else {
		$message .= wbz404_trans('Auto match score value must be a number between 0 and 99') . ".<br>";
	}
	
	
	if (preg_match('/^[0-9]+$/', $_POST['auto_deletion'])==1 && $_POST['auto_deletion'] >= 0) {
		$options['auto_deletion'] = $_POST['auto_deletion'];
	} else {
		$message.= wbz404_trans('Auto redirect deletion value must be a number greater or equal to zero') . ".<br>";
	}

	if ($_POST['force_permalinks'] == "1") {
		$options['force_permalinks'] = '1';
	} else {
		$options['force_permalinks'] = '0';
	}

	if (function_exists('update_option')) {
		update_option('wbz404_settings', $options);
	}
	return $message;
}

function wbz404_getTableOptions() {
	$tableOptions = array();

	if (!isset($_POST['filter'])) {
		if (!isset($_GET['filter'])) {
			if (isset($_GET['subpage']) && $_GET['subpage'] == 'wbz404_captured') {
				$tableOptions['filter'] = WBZ404_CAPTURED;
			} else {
				$tableOptions['filter'] = '0';
			}
		} else {
			$tableOptions['filter'] = $_GET['filter'];
		}
	} else {
		$tableOptions['filter'] = $_POST['filter'];
	}

	if (!isset($_GET['orderby'])) {
		if (isset($_GET['subpage']) && $_GET['subpage'] == "wbz404_logs") {
			$tableOptions['orderby'] = "timestamp";
		} else {
			$tableOptions['orderby'] = "url";
		}
	} else {
		$tableOptions['orderby'] = $_GET['orderby'];
	}

	if (!isset($_GET['order'])) {
		if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
			$tableOptions['order'] = "DESC";
		} else {
			$tableOptions['order'] = "ASC";
		}
	} else {
		$tableOptions['order'] = $_GET['order'];
	}

	if (!isset($_GET['paged'])) {
		$tableOptions['paged'] = 1;
	} else {
		$tableOptions['paged'] = $_GET['paged'];
	}

	if (!isset($_GET['perpage'])) {
		$tableOptions['perpage'] = 25;
	} else {
		$tableOptions['perpage'] = $_GET['perpage'];
	}

	if (isset($_GET['subpage']) && $_GET['subpage'] == "wbz404_logs") {
		if (isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id'])) {
			$tableOptions['logsid'] = $_GET['id'];
		} else {
			$tableOptions['logsid'] = 0;
		}
	}

	return $tableOptions;
}

function wbz404_getRecordCount($types = array(), $trashed = 0) {
	global $wpdb;
	$records = 0;
	
	if (count($types)>=1) {

		$query = "select count(id) from " . $wpdb->prefix . "wbz404_redirects where 1 and (";
		$x=0;
		foreach ($types as $type) {
			if ($x >= 1) {
				$query.=" or ";
			}
			$query .= "status = " . $type;
			$x++;
		}
		$query.=")";

		$query .= " and disabled = " . $trashed;

		$row = $wpdb->get_row($query, ARRAY_N);
		$records = $row[0];
	}

	return $records;
}

function wbz404_getLogsCount($id) {
	global $wpdb;
	$records = 0;
	
	$query = "select count(id) from " . $wpdb->prefix . "wbz404_logs where 1 ";
	if ($id != 0) {
		$query .= "and redirect_id = " . $id;
	}
	$row = $wpdb->get_row($query, ARRAY_N);
	$records = $row[0];

	return $records;
}

function wbz404_getRecords($sub, $tableOptions, $limitEnforced = 1) {
	global $wpdb;
	$rows = array();

	$redirects = $wpdb->prefix . "wbz404_redirects";
	$logs = $wpdb->prefix . "wbz404_logs";

	$query = "select " . $redirects . ".id, " . $redirects . ".url, " . $redirects . ".status, " . $redirects . ".type, " . $redirects . ".final_dest, " . $redirects . ".code, " . $redirects . ".timestamp";
	$query .= ", count(" . $logs . ".id) as hits from " . $redirects . " ";
	$query .= " left outer join " . $logs . " on " . $redirects . ".id = " . $logs . ".redirect_id ";
	$query .= " where 1 and (";
	if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
		if ($sub == "redirects") {
			$query .= "status = " . WBZ404_MANUAL . " or status = " . WBZ404_AUTO;
		} else if ($sub == "captured") {
			$query .= "status = " . WBZ404_CAPTURED . " or status = " . WBZ404_IGNORED;
		}
	} else {
		$query.="status = " . $tableOptions['filter'];
	}
	$query .= ") ";

	if ($tableOptions['filter'] != -1) {
		$query .= "and disabled = 0 ";
	} else {
		$query .= "and disabled = 1 ";
	}

	$query .= "group by " . $redirects . ".id ";

	$query .= "order by " . $tableOptions['orderby'] . " " . $tableOptions['order'] . " ";
	
	if ($limitEnforced == 1) {
		$start = ($tableOptions['paged'] - 1) * $tableOptions['perpage'];
		$query .= "limit " . $start . ", " . $tableOptions['perpage'];
	}

	$rows = $wpdb->get_results($query, ARRAY_A);
	return $rows;
}

function wbz404_getLogRecords($tableOptions) {
	global $wpdb;
	$rows = array();

	$logs = $wpdb->prefix . "wbz404_logs";
	$redirects = $wpdb->prefix . "wbz404_redirects";

	$query = "select " . $logs . ".redirect_id, " . $logs . ".timestamp, " . $logs . ".remote_host, " . $logs . ".referrer, " . $logs . ".action, " . $redirects . ".url from " . $logs;
	$query .= " left outer join " . $redirects . " on " . $logs . ".redirect_id = " . $redirects . ".id where 1 ";
	if ($tableOptions['logsid'] != 0) {
		$query .= " and redirect_id = " . $tableOptions['logsid'] . " ";
	}

	$query .= "order by " . $tableOptions['orderby'] . " " . $tableOptions['order'] . " ";
	$start = ($tableOptions['paged'] - 1) * $tableOptions['perpage'];
	$query .= "limit " . $start . ", " . $tableOptions['perpage'];

	$rows = $wpdb->get_results($query, ARRAY_A);
	return $rows;
}

function wbz404_drawFilters($sub, $tableOptions) {
	if (count($tableOptions) == 0) {
		$tableOptions = wbz404_getTableOptions();
	}
	echo "<ul class=\"subsubsub\">";

	$url = "?page=wbz404_redirected";
	if ($sub == "captured") {
		$url .= "&subpage=wbz404_captured";
	}

	$url .= "&orderby=" . $tableOptions['orderby'];
	$url .= "&order=" . $tableOptions['order'];

	if ($sub == "redirects") {
		$types = array(WBZ404_MANUAL, WBZ404_AUTO);
	} else {
		$types = array(WBZ404_CAPTURED, WBZ404_IGNORED);
	}

	$class = "";
	if ($tableOptions['filter'] == 0) {
		$class = " class=\"current\"";
	}

	if ($sub != "captured") {
		echo "<li>";
			echo "<a href=\"" . $url . "\"" . $class . ">" . wbz404_trans('All');
				echo " <span class=\"count\">(" . wbz404_getRecordCount($types) . ")</span>";
			echo "</a>";
		echo "</li>";
	}

	foreach ($types as $type) {
		$thisurl = $url . "&filter=" . $type;

		$class = "";
		if ($tableOptions['filter'] == $type) {
			$class=" class=\"current\"";
		}

		if ($type == WBZ404_MANUAL) {
			$title = "Manual Redirects";
		} else if ($type == WBZ404_AUTO) {
			$title = "Automatic Redirects";
		} else if ($type == WBZ404_CAPTURED) {
			$title = "Captured URL's";
		} else if ($type == WBZ404_IGNORED) {
			$title = "Ignored 404's";
		}

		echo "<li>";
			if (! ($sub == "captured" && $type == WBZ404_CAPTURED)) {
				echo " | ";
			}
			echo "<a href=\"" . $thisurl . "\"" . $class . ">" . wbz404_trans($title);
				echo " <span class=\"count\">(" . wbz404_getRecordCount(array($type)) . ")</span>";
			echo "</a>";
		echo "</li>";
	}


	$trashurl = $url . "&filter=-1";
	$class = "";
	if ($tableOptions['filter'] == -1) {
		$class = " class=\"current\"";
	}
	echo "<li> | ";
		echo "<a href=\"" . $trashurl . "\"" . $class . ">" . wbz404_trans('Trash');
			echo " <span class=\"count\">(" . wbz404_getRecordCount($types,1) . ")</span>";
		echo "</a>";
	echo "</li>";

	echo "</ul>";
}

function wbz404_drawPaginationLinks($sub, $tableOptions) {
        $url = "?page=wbz404_redirected";
        if ($sub == "captured") {
                $url .= "&subpage=wbz404_captured";
        } else if ($sub == "logs") {
		$url .= "&subpage=wbz404_logs&id=" . $tableOptions['logsid'];
	}

        $url .= "&orderby=" . $tableOptions['orderby'];
        $url .= "&order=" . $tableOptions['order'];

	if ($tableOptions['filter'] == 0) {
	        if ($sub == "redirects") {
        	        $types = array(WBZ404_MANUAL, WBZ404_AUTO);
	        } else {
	                $types = array(WBZ404_CAPTURED, WBZ404_IGNORED);
	        }
	} else {
		$types = array($tableOptions['filter']);
		$url .= "&filter=" . $tableOptions['filter'];
	}

	if ($sub != "logs") {
		$num_records = wbz404_getRecordCount($types);
	} else {
		$num_records = wbz404_getLogsCount($tableOptions['logsid']);
	}
	$total_pages = ceil($num_records / $tableOptions['perpage']);
	if ($total_pages == 0) {
		$total_pages = 1;
	}

	echo "<div class=\"tablenav-pages\">";
		echo "<span class=\"displaying-num\">" . $tableOptions['perpage'] . " " . wbz404_trans('items') . "</span>";
		echo "<span class=\"pagination-links\">";
			$class = "";
			if ($tableOptions['paged'] == 1) {
				$class=" disabled";
			} 
			$firsturl = $url;
			echo "<a href=\"" . $firsturl . "\" class=\"first-page" . $class . "\" title=\"" . wbz404_trans('Go to first page') . "\">&laquo;</a>";
			$class = "";
			if ($tableOptions['paged'] == 1) {
				$class=" disabled";
				$prevurl = $url;
			} else {
				$prev = $tableOptions['paged'] -1;
				$prevurl = $url . "&paged=" . $prev;
			}
			echo "<a href=\"" . $prevurl . "\" class=\"prev-page" . $class . "\" title=\"" . wbz404_trans('Go to previous page') . "\">&lsaquo;</a>";
			echo " ";
			echo wbz404_trans('Page') . " " . $tableOptions['paged'] . " " . wbz404_trans('of') . " " . $total_pages;
			echo " ";
			$class = "";
			if ($tableOptions['paged'] + 1 > $total_pages) {
				$class=" disabled";
				if ($tableOptions['paged'] == 1) {
					$nexturl = $url;
				} else {
					$nexturl = $url . "&paged=" . $tableOptions['paged'];
				}
			} else {
				$next = $tableOptions['paged'] + 1;
				$nexturl = $url . "&paged=" . $next;
			}
			echo "<a href=\"" . $nexturl . "\" class=\"next-page" . $class . "\" title=\"" . wbz404_trans('Go to next page') . "\">&rsaquo;</a>";
			$class = "";
			if ($tableOptions['paged'] + 1 > $total_pages) {
				$class=" disabled";
				if ($tableOptions['paged'] == 1) {
					$lasturl = $url;
				} else {
					$lasturl = $url . "&paged=" . $tableOptions['paged'];
				}
			} else {
				$lasturl = $url . "&paged=" . $total_pages;
			}
			echo "<a href=\"" . $lasturl . "\" class=\"last-page" . $class . "\" title=\"" . wbz404_trans('Go to last page') . "\">&raquo;</a>";
		echo "</span>";
	echo "</div>";
}

function wbz404_buildTableColumns($sub, $tableOptions, $columns) {
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
				$style = " style=\"width: " . $column['width'] . ";\" ";
			}
			$nolink=0;
			$sortorder = "";
			if ($tableOptions['orderby'] == $column['orderby']) {
				$class=" sorted";
				if ($tableOptions['order'] == "ASC") {
					$class .= " asc";
					$sortorder = "DESC";
				} else {
					$class .= " desc";
					$sortorder = "ASC";
				}
			} else {
				if ($column['orderby'] != "") {
					$class=" sortable";
					if ($column['orderby'] == "timestamp" || $column['orderby'] == "lastused") {
						$class .= " asc";
						$sortorder = "DESC";
					} else {
						$class .= " desc";
						$sortorder = "ASC";
					}
				} else {
					$class = "";
					$nolink=1;
				}
			}

			$url = "?page=wbz404_redirected";
			if ($sub == "captured") {
				$url .= "&subpage=wbz404_captured";
			} else if ($sub == "logs") {
				$url .= "&subpage=wbz404_logs&id=" . $tableOptions['logsid'];
			}
			if ($tableOptions['filter'] != 0) {
				$url .= "&filter=" . $tableOptions['filter'];
			}
			$url .= "&orderby=" . $column['orderby'] . "&order=" . $sortorder;

			echo "<th" . $style . "class=\"manage-column column-title" . $class . "\">";
				if ($nolink == 1) {
					echo $column['title'];
				} else {
					echo "<a href=\"" . $url . "\">";
					echo "<span>" . $column['title'] . "</span>";
					echo "<span class=\"sorting-indicator\"></span>";
					echo "</a>";
				}
			echo "</th>";
		}
		echo "<th style=\"width: 1px;\"></th>";
	echo "</tr>";
}

function wbz404_getRedirectHits($id) {
	global $wpdb;
	
	$query = "select count(id) from " . $wpdb->prefix . "wbz404_logs where redirect_id = " . $wpdb->escape($id);
	$row = $wpdb->get_col($query);
	return $row[0];
}

function wbz404_getRedirectLastUsed($id) {
	global $wpdb;
	
	$query = "select timestamp from " . $wpdb->prefix . "wbz404_logs where redirect_id = " . $wpdb->escape($id) . " order by timestamp desc";
	$row = $wpdb->get_col($query);
	return $row[0];
}

function wbz404_addAdminRedirect() {
	global $wpdb;
	$messasge = "";

	if ($_POST['url'] != "") {
		if (substr($_POST['url'],0,1) != "/") {
			$message .= wbz404_trans('Error: URL must start with /') . "<br>";
		} 
	} else {
		$message .= wbz404_trans("Error: URL is a required field.") . "<br>";
	}

	if ($_POST['dest'] == "EXTERNAL") {
		if ($_POST['external'] == "") {
			$message .= wbz404_trans('Error: You selected external URL but did not enter a URL.') . "<br>";
		} else {
			if (substr($_POST['external'],0,7) != "http://" && substr($_POST['external'],0,8) != "https://" && substr($_POST['external'],0,6) != "ftp://") {
				$message .= wbz404_trans('Error: External URL\'s must start with http://, https://, or ftp://') . "<br>";
			}
		}
	}

	if ($message == "") {
		$type = "";
		$dest = "";
		if ($_POST['dest'] == "EXTERNAL") {
			$type = WBZ404_EXTERNAL;
			$dest = $wpdb->escape($_POST['external']);
		} else {
			$info = explode("|", $_POST['dest']);
			if (count($info)==2) {
				$dest = $info[0];
				if ($info[1] == "POST") {
					$type = WBZ404_POST;
				} else if ($info[1] == "CAT") {
					$type = WBZ404_CAT;
				} else if ($info[1] == "TAG") {
					$type = WBZ404_TAG;
				}
			}
		}
		if ($type != "" && $dest != "") {
			wbz404_setupRedirect($wpdb->escape($_POST['url']), WBZ404_MANUAL, $type, $dest, $wpdb->escape($_POST['code']), 0);
			$_POST['url'] = "";
			$_POST['code'] = "";
			$_POST['external'] = "";
			$_POST['dest'] = "";
		} else {
			$message .= wbz404_trans('Error: Data not formatted properly.') . "<br>";
		}
	}

	return $message;
}

function wbz404_editRedirectData() {
	global $wpdb;
	$message = "";

	if ($_POST['url'] != "") {
		if (substr($_POST['url'],0,1) != "/") {
			$message .= wbz404_trans('Error: URL must start with /') . "<br>";
		} 
	} else {
		$message .= wbz404_trans("Error: URL is a required field.") . "<br>";
	}

	if ($_POST['dest'] == "EXTERNAL") {
		if ($_POST['external'] == "") {
			$message .= wbz404_trans('Error: You selected external URL but did not enter a URL.') . "<br>";
		} else {
			if (substr($_POST['external'],0,7) != "http://" && substr($_POST['external'],0,8) != "https://" && substr($_POST['external'],0,6) != "ftp://") {
				$message .= wbz404_trans('Error: External URL\'s must start with http://, https://, or ftp://') . "<br>";
			}
		}
	}

	if ($message == "") {
		$type = "";
		$dest = "";
		if ($_POST['dest'] === "" . WBZ404_EXTERNAL) {
			$type = WBZ404_EXTERNAL;
			$dest = $wpdb->escape($_POST['external']);
		} else {
			$info = explode("|", $_POST['dest']);
			if (count($info)==2) {
				$dest = $info[0];
				if ($info[1] == WBZ404_POST) {
					$type = WBZ404_POST;
				} else if ($info[1] == WBZ404_CAT) {
					$type = WBZ404_CAT;
				} else if ($info[1] == WBZ404_TAG) {
					$type = WBZ404_TAG;
				}
			}
		}

		if ($type != "" && $dest != "") {
			$wpdb->update($wpdb->prefix . "wbz404_redirects",
				array(
					'url' => $_POST['url'],
					'status' => WBZ404_MANUAL,
					'type' => $type,
					'final_dest' => $dest,
					'code' => $_POST['code']
				),
				array (
					'id' => $_POST['id']
				),
				array(
					'%s',
					'%d',
					'%d',
					'%s',
					'%d'
				),
				array(
					'%d'
				)
			);

			$_POST['url'] = "";
			$_POST['code'] = "";
			$_POST['external'] = "";
			$_POST['dest'] = "";
		} else {
			$message .= wbz404_trans('Error: Data not formatted properly.') . "<br>";
		}
	}

	return $message;
}

function wbz404_setTrash($id, $trash) {
	global $wpdb;

	$result = false;
	if (preg_match('/[0-9]+/', $id)) {
		$result = $wpdb->update($wpdb->prefix . "wbz404_redirects",
			array('disabled' => $trash),
			array ('id' => $id),
			array ('%d'),
			array ('%d')
		);
	}
	if ($result == false) {
		$message = wbz404_trans('Error: Unknown Database Error!');
	}
	return $message;
}

function wbz404_setIgnore($id, $newstatus) {
	global $wpdb;

	$result = false;
	if (preg_match('/[0-9]+/', $id)) {
		$result = $wpdb->update($wpdb->prefix . "wbz404_redirects",
			array('status' => $newstatus),
			array ('id' => $id),
			array ('%d'),
			array ('%d')
		);
	}
	if ($result == false) {
		$message = wbz404_trans('Error: Unknown Database Error!');
	}
	return $message;
}

function wbz404_adminHeader($sub = 'list', $message = '') {
	if ($sub == "options") {
		$header = " " . wbz404_trans('Options');
	} else if ($sub == "logs") {
		$header = " " . wbz404_trans('Logs');
	} else if ($sub == "stats") {
		$header = " " . wbz404_trans('Stats');
	} else if ($sub == "edit") {
		$header = ": " . wbz404_trans('Edit Redirect');
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
	echo "<h2>" . wbz404_trans('404 Redirected') . $header . "</h2>";
	if ($message != "") {
		echo "<div class=\"message updated\"><p>" . $message . "</p></div>";
	}
	echo wbz404_trans('by') . " <a href=\"http://www.weberz.com/\" title=\"Weberz Hosting\" target=\"_blank\">Weberz Hosting</a><br>";
	echo wbz404_trans('Version') . ": " . WBZ404_VERSION . " | ";
	echo "<a href=\"" . WBZ404_HOME . "\" title=\"" . wbz404_trans('Plugin Home Page') . "\" target=\"_blank\">" . wbz404_trans('Plugin Home Page') . "</a> | ";
	echo "<a href=\"http://twitter.com/Weberz\" title=\"Weberz Hosting on Twitter\" target=\"_blank\">Weberz on Twitter</a> | ";
	echo "<a href=\"http://www.facebook.com/Weberz\" title=\"Weberz Hosting on Facebook\" target=\"_blank\">Weberz on Facebook</a><br>";
	echo "<br>";
	
	$class="";
	if ($sub == "redirects") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected\" title=\"" . wbz404_trans('Page Redirects') . "\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Page Redirects') . "</a>";
	$class="";
	if ($sub == "captured") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected&subpage=wbz404_captured\" title=\"" . wbz404_trans('Captured 404 URLs') . "\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Captured 404 URLs') . "</a>";
	$class="";
	if ($sub == "logs") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected&subpage=wbz404_logs\" title=\"" . wbz404_trans('Redirect & Capture Logs') . "\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Logs') . "</a>";
	$class="";
	if ($sub == "stats") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected&subpage=wbz404_stats\" title=\"" . wbz404_trans('Stats') . "\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Stats') . "</a>";
	$class="";
	if ($sub == "tools") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected&subpage=wbz404_tools\" title=\"" . wbz404_trans('Tools') . "\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Tools') . "</a>";
	$class="";
	if ($sub == "options") {
		$class="nav-tab-active";
	}
	echo "<a href=\"?page=wbz404_redirected&subpage=wbz404_options\" title=\"Options\" class=\"nav-tab " . $class . "\">" . wbz404_trans('Options') . "</a>";	
	echo "<hr style=\"border: 0px; border-bottom: 1px solid #DFDFDF; margin-top: 0px; margin-bottom: 0px; \">";
}

function wbz404_adminFooter() {
	echo "<div style=\"clear: both;\">";
		echo "<br>";
		echo "<strong>Credits:</strong><br>";
		echo "<a href=\"" . WBZ404_HOME . "\" title=\"" . wbz404_trans('404 Redirected') . "\" target=\"_blank\">" . wbz404_trans('404 Redirected') . "</a> ";
		echo wbz404_trans('was designed by');
		echo " ";
		echo "<a href=\"http://twitter.com/rrolfe/\" title=\"Robert Rolfe\" target=\"_blank\">Robert Rolfe</a>. ";
		echo wbz404_trans('It\'s released under the GNU GPL version 2 License.');
	echo "</div>";
	echo "</div>";
}

function wbz404_emptyTrash($sub) {
	$tableOptions = wbz404_getTableOptions();

	$rows = wbz404_getRecords($sub, $tableOptions, 0);
	foreach ($rows as $row) {
		wbz404_cleanRedirect($row['id']);
	}
}

function wbz404_bulkProcess($action, $ids) {
	$message = "";
	if ($action == "bulkignore" || $action == "bulkcaptured") {
		if ($action == "bulkignore") {
			$status = WBZ404_IGNORED;
		} else {
			$status = WBZ404_CAPTURED;
		}
		$count = 0;
		foreach ($ids as $id) {
			$s = wbz404_setIgnore($id, $status);
			if ($s == "") {
				$count++;
			} 
		}
		if ($action == "bulkignore") {
			$message = $count . " " . wbz404_trans('URLs marked as ignored.');
		} else {
			$message = $count . " " . wbz404_trans('URLs marked as captured.');
		}
	} else {
		$count = 0;
		foreach ($ids as $id) {
			$s = wbz404_setTrash($id, 1);
			if ($s == "") {
				$count ++;
			}
		}
		$message = $count . " " . wbz404_trans('URLs moved to trash');
	}
	return $message;
}

function wbz404_adminPage() {
	$sub="";
	$message="";

	//Handle Post Actions
	if (isset($_POST['action'])) {
		$action = $_POST['action'];
	} else {
		$action = "";
	}

	if ($action == "updateOptions") {
		if (check_admin_referer('wbz404UpdateOptions') && is_admin()) {
			$sub="wbz404_options";
			$message=wbz404_updateOptions();
			if ($message == "") {
				$message = wbz404_trans('Options Saved Successfully!');
			} else {
				$message .= wbz404_trans('Some options were not saved successfully.');
			}
		}
	} else if ($action == "addRedirect") {
		if (check_admin_referer('wbz404addRedirect') && is_admin()) {
			$message=wbz404_addAdminRedirect();
			if ($message == "") {
				$message = wbz404_trans('New Redirect Added Successfully!');
			} else {
				$message .= wbz404_trans('Error: unable to add new redirect successfully.');
			}
		}
	} else if ($action == "emptyRedirectTrash") {
		if (check_admin_referer('wbz404_emptyRedirectTrash') && is_admin()) {
			wbz404_emptyTrash('redirects');
			$message = wbz404_trans('All trashed URLs have been deleted!');
		}
	} else if ($action == "emptyCapturedTrash") {
		if (check_admin_referer('wbz404_emptyCapturedTrash') && is_admin()) {
			wbz404_emptyTrash('captured');
			$message = wbz404_trans('All trashed URLs have been deleted!');
		}
	} else if ($action == "bulkignore" || $action == "bulkcaptured" || $action == "bulktrash") {
		if (check_admin_referer('wbz404_capturedBulkAction') && is_admin()) {
			$message = wbz404_bulkProcess($action, $_POST['idnum']);
		}
	} else if ($action == "purgeRedirects") {
		if (check_admin_referer('wbz404_purgeRedirects') && is_admin()) {
			$message = wbz404_purgeRedirects();
		}
	}

	// Handle Trash Functionality
	if (isset($_GET['trash'])) {
		if (check_admin_referer('wbz404_trashRedirect') && is_admin()) {
			$trash = "";
			if ($_GET['trash'] == 0) {
				$trash = 0;
			} else if ($_GET['trash'] == 1) {
				$trash = 1;
			}
			if ($trash == 0 || $trash == 1) {
				$message = wbz404_setTrash($_GET['id'], $trash);
				if ($message == "") {
					if ($trash == 1) {
						$message = wbz404_trans("Redirect moved to trash successfully!");
					} else {
						$message = wbz404_trans("Redirect restored from trash successfully!");
					}
				} else {
					if ($trash == 1) {
						$message = wbz404_trans("Error: Unable to move redirect to trash.");
					} else {
						$message = wbz404_trans("Error: Unable to move redirect from trash.");
					}
				}
			}
		}
	}

	//Handle Delete Functionality
	if (isset($_GET['remove']) && $_GET['remove'] == 1) {
		if (check_admin_referer('wbz404_removeRedirect') && is_admin()) {
			if (preg_match('/[0-9]+/', $_GET['id'])) {
				wbz404_cleanRedirect($_GET['id']);
				$message = wbz404_trans('Redirect Removed Successfully!');
			}
		}
	}

	//Handle Ignore Functionality
	if (isset($_GET['ignore'])) {
		if (check_admin_referer('wbz404_ignore404') && is_admin()) {
			if ($_GET['ignore'] == 0 || $_GET['ignore'] == 1) {
				if (preg_match('/[0-9]+/', $_GET['id'])) {
					if ($_GET['ignore'] == 1) {
						$newstatus = WBZ404_IGNORED;
					} else {
						$newstatus = WBZ404_CAPTURED;
					}
					$message = wbz404_setIgnore($_GET['id'], $newstatus);
					if ($message == "") {
						if ($newstatus == WBZ404_CAPTURED) {
							$message = wbz404_trans('Removed 404 URL from ignored list successfully!');
						} else {
							$message = wbz404_trans('404 URL marked as ignored successfully!');
						}
					} else {
						if ($newstatus == WBZ404_CAPTURED) {
							$message = wbz404_trans('Error: unable to remove URL from ignored list');
						} else {
							$message = wbz404_trans('Error: unable to mark URL as ignored');
						}
					}
				}
			}
		}
	}

	//Handle edit posts
	if (isset($_POST['action']) && $_POST['action'] == "editRedirect") {
		if (isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id'])) {
			if (check_admin_referer('wbz404editRedirect') && is_admin()) {
				$message = wbz404_editRedirectData();
				if ($message == "") {
					$message .= wbz404_trans("Redirect Information Updated Successfully!");
					$sub = "redirects";
				} else {
					$message .= wbz404_trans("Error: Unable to update redirect data.");
				}
			}
		}
	}

	// Deal With Page Tabs
	if ($sub == "") {
		if (isset($_GET['subpage'])) {
			$sub = strtolower($_GET['subpage']);
		} else {
			$sub = "";
		}
	}
	if ($sub == "wbz404_options") {
		$sub = "options";
	} else if ($sub == "wbz404_captured") {
		$sub = "captured";
	} else if ($sub == "wbz404_logs") {
		$sub = "logs";
	} else if ($sub == "wbz404_edit") {
		$sub = "edit";
	} else if ($sub == "wbz404_stats") {
		$sub = "stats";
	} else if ($sub == "wbz404_tools") {
		$sub = "tools";
	} else {
		$sub = "redirects";
	}

	wbz404_adminHeader($sub, $message);
	if ($sub == "redirects") {
		wbz404_adminRedirectsPage();
	} else if ($sub == "captured") {
		wbz404_adminCapturedPage();
	} else if ($sub == "options") {
		wbz404_adminOptionsPage();
	} else if ($sub == "logs") {
		wbz404_adminLogsPage();
	} else if ($sub == "edit") {
		wbz404_adminEditPage();
	} else if ($sub == "stats") {
		wbz404_adminStatsPage();
	} else if ($sub == "tools") {
		wbz404_adminToolsPage();
	} else {
		echo wbz404_trans('Invalid Sub Page ID');
	}
	wbz404_adminFooter();
}

function wbz404_getStatsCount($query='') {
	global $wpdb;
	$results = 0;
	if ($query != '') {
		$row = $wpdb->get_col($query);
		$results = $row[0];
	}
	return $results;
}

function wbz404_adminStatsPage() {
	global $wpdb;
	$sub = "stats";

	$redirects = $wpdb->prefix . "wbz404_redirects";
	$logs = $wpdb->prefix . "wbz404_logs";
	$hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

	echo "<div class=\"postbox-container\" style=\"float: right; width: 49%;\">";
		echo "<div class=\"metabox-holder\">";
			echo " <div class=\"meta-box-sortables\">";

				$query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = " . WBZ404_AUTO;
				$auto301 = wbz404_getStatsCount($query);

				$query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = " . WBZ404_AUTO;
				$auto302 = wbz404_getStatsCount($query);

				$query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = " . WBZ404_MANUAL;
				$manual301 = wbz404_getStatsCount($query);
				
				$query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = " . WBZ404_MANUAL;
				$manual302 = wbz404_getStatsCount($query);

				$query = "select count(id) from $redirects where disabled = 1 and (status = " . WBZ404_AUTO . " or status = " . WBZ404_MANUAL . ")";
				$trashed = wbz404_getStatsCount($query);

				$total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

				$content = "";
				$content .= "<p $hr>";
				$content .= "<strong>" . wbz404_trans('Automatic 301 Redirects') . ":</strong> " . $auto301 . "<br>";
				$content .= "<strong>" . wbz404_trans('Automatic 302 Redirects') . ":</strong> " . $auto302 . "<br>";
				$content .= "<strong>" . wbz404_trans('Manual 301 Redirects') . ":</strong> " . $manual301 . "<br>";
				$content .= "<strong>" . wbz404_trans('Manual 302 Redirects') . ":</strong> " . $manual302 . "<br>";
				$content .= "<strong>" . wbz404_trans('Trashed Redirects') . ":</strong> " . $trashed . "</p>";
				$content .= "<p style=\"margin-top: 4px;\">";
				$content .= "<strong>" . wbz404_trans('Total Redirects') . ":</strong> " . $total;
				$content .= "</p>";
				wbz404_postbox("wbz404-redirectStats", wbz404_trans('Redirects'), $content);

				$query = "select count(id) from $redirects where disabled = 0 and status = " . WBZ404_CAPTURED;
				$captured = wbz404_getStatsCount($query);

				$query = "select count(id) from $redirects where disabled = 0 and status = " . WBZ404_IGNORED;
				$ignored = wbz404_getStatsCount($query);

				$query = "select count(id) from $redirects where disabled = 1 and (status = " . WBZ404_CAPTURED . " or status = " . WBZ404_IGNORED . ")";
				$trashed = wbz404_getStatsCount($query);

				$total = $captured + $ignored + $trashed;
				
				$content = "";
				$content .= "<p $hr>";
				$content .= "<strong>" . wbz404_trans('Captured URLs') . ":</strong> " . $captured . "<br>";
				$content .= "<strong>" . wbz404_trans('Ignored 404 URLs') . ":</strong> " . $ignored . "<br>";
				$content .= "<strong>" . wbz404_trans('Trashed URLs') . ":</strong> " . $trashed . "</p>";
				$content .= "<p style=\"margin-top: 4px;\">";
				$content .= "<strong>" . wbz404_trans('Total URLs') . ":</strong> " . $total;
				$content .= "</p>";
				wbz404_postbox("wbz404-capturedStats", wbz404_trans('Captured URLs'), $content);

			echo "</div>";
		echo "</div>";
	echo "</div>";

	echo "<div class=\"postbox-container\" style=\"width: 49%;\">";
		echo "<div class=\"metabox-holder\">";
			echo " <div class=\"meta-box-sortables\">";

			$today = mktime(0,0,0, date('m'), date('d'), date('Y'));
			$firstm = mktime(0,0,0,date('m'), 1, date('Y'));
			$firsty = mktime(0,0,0,1,1,date('Y'));

			for ($x=0; $x <= 3; $x++) {
				if ($x == 0) {
					$title="Today's Stats";
					$ts = $today;
				} else if ($x == 1) {
					$title="This Month";
					$ts = $firstm;
				} else if ($x == 2) {
					$title="This Year";
					$ts = $firsty;
				} else if ($x == 3) {
					$title="All Stats";
					$ts = 0;
				}

				$query = "select count(id) from $logs where timestamp >= $ts and action = '404'";
				$disp404 = wbz404_getStatsCount($query);

				$query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action = '404'";
				$distinct404 = wbz404_getStatsCount($query);

				$query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action = '404'";
				$visitors404 = wbz404_getStatsCount($query);

				$query = "select count(distinct referrer) from $logs where timestamp >= $ts and action = '404'";
				$refer404 = wbz404_getStatsCount($query);

				$query = "select count(id) from $logs where timestamp >= $ts and action != '404'";
				$redirected = wbz404_getStatsCount($query);

				$query = "select count(distinct redirect_id) from $logs where timestamp >= $ts and action != '404'";
				$distinctredirected = wbz404_getStatsCount($query);

				$query = "select count(distinct remote_host) from $logs where timestamp >= $ts and action != '404'";
				$distinctvisitors = wbz404_getStatsCount($query);

				$query = "select count(distinct referrer) from $logs where timestamp >= $ts and action != '404'";
				$distinctrefer = wbz404_getStatsCount($query);

				$content = "";
				$content .= "<p>";
				$content .= "<strong>" . wbz404_trans('Page Not Found Displayed') . ":</strong> " . $disp404 . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique Page Not Found URLs') . ":</strong> " . $distinct404 . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique Page Not Found Visitors') . ":</strong> " . $visitors404 . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique Page Not Found Referrers') . ":</strong> " . $refer404 . "<br>";
				$content .= "<strong>" . wbz404_trans('Hits Redirected') . ":</strong> " . $redirected . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique URLs Redirected') . ":</strong> " . $distinctredirected . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique Redirected Visitors') . ":</strong> " . $distinctvisitors . "<br>";
				$content .= "<strong>" . wbz404_trans('Unique Redirected Referrers') . ":</strong> " . $distinctrefer . "<br>";
				$content .= "</p>";
				wbz404_postbox("wbz404-stats" . $x, wbz404_trans($title), $content);
			}
			echo "</div>";
		echo "</div>";
	echo "</div>";
	
}

function wbz404_adminLogsPage() {
	global $wpdb;
	$sub = "logs";
	$tableOptions = wbz404_getTableOptions();
	
	$url = "?page=wbz404_redirected&subpage=wbz404_logs";

	$redirects = array();
	$query = "select id, url from " . $wpdb->prefix . "wbz404_redirects order by url";

	$rows = $wpdb->get_results($query, ARRAY_A);
	foreach($rows as $row) {
		$redirects[$row['id']]['id'] = $row['id'];
		$redirects[$row['id']]['url'] = $row['url'];
	}


	echo "<br>";
	echo "<form method=\"GET\" action=\"\">";
	echo "<input type=\"hidden\" name=\"page\" value=\"wbz404_redirected\">";
	echo "<input type=\"hidden\" name=\"subpage\" value=\"wbz404_logs\">";
	echo "<strong><label for=\"id\">" . wbz404_trans('Viewing Logs For') . ":</label></strong> ";
	echo "<select name=\"id\" id=\"id\">";
		$selected = "";
		if ($tableOptions['logsid'] == 0) {
			$selected = " selected";
		}
		echo "<option value=\"0\"" . $selected . ">" . wbz404_trans('All Redirects') . "</option>";
		foreach ($redirects as $redirect) {
			$selected = "";
			if ($tableOptions['logsid'] == $redirect['id']) {
				$selected = " selected";
			}
			echo "<option value=\"" . $redirect['id'] . "\"" . $selected . ">" . $redirect['url'] . "</option>";
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
		wbz404_drawPaginationLinks($sub, $tableOptions);
	echo "</div>";

	echo "<table class=\"wp-list-table widefat fixed\">";
		echo "<thead>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</thead>";
		echo "<tfoot>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</tfoot>";
		echo "<tbody>";

		$rows = wbz404_getLogRecords($tableOptions);
		$displayed = 0;
		$y=1;
		date_default_timezone_set(get_option('timezone_string'));
		foreach ($rows as $row) {
			$class = "";
			if ($y == 0) {
				$class=" class=\"alternate\"";
				$y++;
			} else {
				$y=0;
			}
			echo "<tr" . $class . ">";
				echo "<td></td>";
				echo "<td>" . $redirects[$row['redirect_id']]['url'] . "</td>";
				echo "<td>" . $row['remote_host'] . "</td>";
				echo "<td>";
					if ($row['referrer'] != "") {
						echo "<a href=\"" . $row['referrer'] . "\" title=\"" . wbz404_trans('Visit') . ": " . $row['referrer'] . "\" target=\"_blank\">" . $row['referrer'] . "</a>";
					} else {
						echo "&nbsp;";
					}
				echo "</td>";
				echo "<td>";
					if ($row['action'] == "404") {
						echo wbz404_trans('Displayed 404 Page');
					} else {
						echo wbz404_trans('Redirect to') . " ";
						echo "<a href=\"" . $row['action'] . "\" title=\"" . wbz404_trans('Visit') . ": " . $row['action'] . "\" target=\"_blank\">" . $row['action'] . "</a>";
					}
				echo "</td>";
				echo "<td>" . date('Y/m/d h:i:s A', $row['timestamp']) . "</td>";
				echo "<td></td>";
			echo "</tr>";
			$displayed++;
		} 
		if ($displayed == 0) {
			echo "<tr>";
				echo "<td></td>";
				echo "<td colspan=\"5\" style=\"text-align: center; font-weight: bold;\">" . wbz404_trans('No Results To Display') . "</td>";
				echo "<td></td>";
			echo "</tr>";
		}
		echo "</tbody>";
	echo "</table>";
	
	echo "<div class=\"tablenav\">";
		wbz404_drawPaginationLinks($sub, $tableOptions);
	echo "</div>";

}

function wbz404_adminOptionsPage() {
				$options = wbz404_getOptions();

				$url = "?page=wbz404_redirected";

				//General Options
				$action = "wbz404UpdateOptions";
				$link = wp_nonce_url($url, $action);

				echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
				echo "<div class=\"metabox-holder\">";
				echo " <div class=\"meta-box-sortables\">";

				echo "<form method=\"POST\" action=\"" . $link . "\">";
				echo "<input type=\"hidden\" name=\"action\" value=\"updateOptions\">";
			
				$content = "<p>" . wbz404_trans('DB Version Number') . ": " . $options['DB_VERSION'] . "</p>";
				$content .= "<p>" . wbz404_trans('Default redirect type') . ": ";
				$content .= "<select name=\"default_redirect\">";
				$selected = "";
				if ($options['default_redirect'] == '301') {
					$selected = " selected";
				}
				$content .= "<option value=\"301\"" . $selected . ">" . wbz404_trans('Permanent 301') . "</option>";
				$selected = "";
				if ($options['default_redirect'] == '302') {
					$selected = " selected";
				}
				$content .= "<option value=\"302\"" . $selected . ">" . wbz404_trans('Temporary 302') . "</option>";
				$content .= "</select></p>";

				$selected = "";
				if ($options['capture_404'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Collect incoming 404 URLs') . ": <input type=\"checkbox\" name=\"capture_404\" value=\"1\"" . $selected . "></p>";

				$content .= "<p>" . wbz404_trans('Admin notification level') . ": <input type=\"text\" name=\"admin_notification\" value=\"" . $options['admin_notification'] . "\" style=\"width: 50px;\"> " . wbz404_trans('Captured URLs (0 Disables Notification)') . "<br>";
				$content .= wbz404_trans('Display WordPress admin notifications when number of captured URLs goes above specified level') . "</p>";

				$content .= "<p>" . wbz404_trans('Collected 404 URL deletion') . ": <input type=\"text\" name=\"capture_deletion\" value=\"" . $options['capture_deletion'] . "\" style=\"width: 50px;\"> " . wbz404_trans('Days (0 Disables Auto Delete)') . "<br>";
				$content .= wbz404_trans('Automatically removes 404 URLs that have been captured if they haven\'t been used for the specified amount of time.') . "</p>";

				$content .= "<p>" . wbz404_trans('Manual redirect deletion') . ": <input type=\"text\" name=\"manual_deletion\" value=\"" . $options['manual_deletion'] . "\" style=\"width: 50px;\"> " . wbz404_trans('Days (0 Disables Auto Delete)') . "<br>";
				$content .= wbz404_trans('Automatically removes manually created page redirects if they haven\'t been used for the specified amount of time.') . "</p>";

				$selected = "";
				if ($options['remove_matches'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Remove redirect upon matching permalink') . ": <input type=\"checkbox\" value=\"1\" name=\"remove_matches\"" . $selected . "><br>";
				$content .= wbz404_trans('Checks each redirect for a new matching permalink before user is redirected. If a new page permalink is found matching the redirected URL then the redirect will be deleted.') . "</p>";

				wbz404_postbox("wbz404-generaloptions", wbz404_trans('General Settings'), $content);

				// Suggested Alternatives Options
				$selected = "";
				if ($options['display_suggest'] == '1') {
					$selected = " checked";
				}
				$content = "<p>" . wbz404_trans('Turn on 404 suggestions') . ": <input type=\"checkbox\" name=\"display_suggest\" value=\"1\"" . $selected . "><br>";
				$content .= wbz404_trans('Activates the 404 page suggestions function. Only works if the code is in your 404 page template.') . "</p>";

				$selected = "";
				if ($options['suggest_cats'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Allow category suggestions') . ": <input type=\"checkbox\" name=\"suggest_cats\" value=\"1\"" . $selected . "><br>";

				$selected = "";
				if ($options['suggest_tags'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Allow tag suggestions') . ": <input type=\"checkbox\" name=\"suggest_tags\" value=\"1\"" . $selected . "><br>";
				
				$content .= "<p>" . wbz404_trans('Minimum score of suggestions to display') . ": <input type=\"text\" name=\"suggest_minscore\" value=\"" . $options['suggest_minscore'] . "\" style=\"width: 50px;\"></p>";

				$content .= "<p>" . wbz404_trans('Maximum number of suggestions to display') . ": <input type=\"text\" name=\"suggest_max\" value=\"" . $options['suggest_max'] . "\" style=\"width: 50px;\"></p>";

				$content .= "<p>" . wbz404_trans('Page suggestions title') . ": <input type=\"text\" name=\"suggest_title\" value=\"" . $options['suggest_title'] . "\" style=\"width: 200px;\"></p>";
			
				$content .= "<p>" . wbz404_trans('Display Before/After page suggestions') . ": ";
				$content .= "<input type=\"text\" name=\"suggest_before\" value=\"" . $options['suggest_before'] . "\" style=\"width: 100px;\"> / ";
				$content .= "<input type=\"text\" name=\"suggest_after\" value=\"" . $options['suggest_after'] . "\" style=\"width: 100px;\">";

				$content .= "<p>" . wbz404_trans('Display Before/After each suggested entry') . ": ";
				$content .= "<input type=\"text\" name=\"suggest_entrybefore\" value=\"" . $options['suggest_entrybefore'] . "\" style=\"width: 100px;\"> / ";
				$content .= "<input type=\"text\" name=\"suggest_entryafter\" value=\"" . $options['suggest_entryafter'] . "\" style=\"width: 100px;\">";

				$content .= "<p>" . wbz404_trans('Display if no suggestion results') . ": ";
				$content .= "<input type=\"text\" name=\"suggest_noresults\" value=\"" . $options['suggest_noresults'] . "\" style=\"width: 200px;\">";

				$selected = "";
				if ($options['404_promote'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Help promote 404 Redirected plugin?') . ": <input type=\"checkbox\" name=\"404_promote\" value=\"1\"" . $selected . "><br>";
				$content .= wbz404_trans('Displays promotion link only on 404 pages that do not redirect.') . "</p>";

				wbz404_postbox("wbz404-suggestoptions", wbz404_trans('404 Page Suggestions'), $content);

				$selected = "";
				if ($options['auto_redirects'] == '1') {
					$selected = " checked";
				}
				$content = "<p>" . wbz404_trans('Create automatic redirects') . ": <input type=\"checkbox\" name=\"auto_redirects\" value=\"1\"" . $selected . "><br>";
				$content .= wbz404_trans('Automatically creates redirects based on best possible suggested page.') . "</p>"; 

				$content .= "<p>" . wbz404_trans('Minimum match score') . ": <input type=\"text\" name=\"auto_score\" value=\"" . $options['auto_score'] . "\" style=\"width: 50px;\"><br>";
				$content .= wbz404_trans('Only create an automatic redirect if the suggested page has a score above the specified number') . "</p>";

				$selected = "";
				if ($options['auto_cats'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Create automatic redirects for categories') . ": <input type=\"checkbox\" name=\"auto_cats\" value=\"1\"" . $selected . "></p>";

				$selected = "";
				if ($options['auto_tags'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Create automatic redirects for tags') . ": <input type=\"checkbox\" name=\"auto_tags\" value=\"1\"" . $selected . "></p>";

				$selected = "";
				if ($options['force_permalinks'] == '1') {
					$selected = " checked";
				}
				$content .= "<p>" . wbz404_trans('Force current permalinks') . ": <input type=\"checkbox\" name=\"force_permalinks\" value=\"1\"" . $selected . "><br>";
				$content .= wbz404_trans('Creates auto redirects for any url resolving to a post/page that doesn\'t match the current permalinks') . "</p>";

				$content .= "<p>" . wbz404_trans('Auto redirect deletion') . ": <input type=\"text\" name=\"auto_deletion\" value=\"" . $options['auto_deletion'] . "\" style=\"width: 50px;\"> " . wbz404_trans('Days (0 Disables Auto Delete)') . "<br>";
				$content .= wbz404_trans('Removes auto created redirects if they haven\'t been used for the specified amount of time.') . "</p>";
				
				wbz404_postbox("wbz404-autooptions", wbz404_trans('Automatic Redirects'), $content);	
				echo "<input type=\"submit\" id=\"wbz404-optionssub\" value=\"Save Settings\" class=\"button-primary\">";
				echo "</form>";

				echo "</div>";
				echo "</div>";
				echo "</div>";

}

function wbz404_adminRedirectsPage() {
	global $wpdb;
	$sub = "redirects";
	
	$options = wbz404_getOptions();
	$tableOptions = wbz404_getTableOptions();

	wbz404_drawFilters($sub, $tableOptions);

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

	date_default_timezone_set(get_option('timezone_string'));

	echo "<div class=\"tablenav\">";
		wbz404_drawPaginationLinks($sub, $tableOptions);

		if ($tableOptions['filter'] == '-1') {
			echo "<div class=\"alignleft actions\">";
				$eturl = "?page=wbz404_redirected&filter=-1";
                                $trashaction = "wbz404_emptyRedirectTrash";
                                $eturl = wp_nonce_url($eturl, $trashaction);

				echo "<form method=\"POST\" action=\"" . $eturl . "\">";
				echo "<input type=\"hidden\" name=\"action\" value=\"emptyRedirectTrash\">";
				echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . wbz404_trans('Empty Trash') . "\">";
				echo "</form>";
			echo "</div>";		
		}
	echo "</div>";

	echo "<table class=\"wp-list-table widefat fixed\">";
		echo "<thead>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</thead>";
		echo "<tfoot>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</tfoot>";
		echo "<tbody id=\"the-list\">";
			$rows = wbz404_getRecords($sub, $tableOptions);
			$displayed = 0;
			$y=1;
			foreach ($rows as $row) {
				$displayed++;
				$status = "";
				if ($row['status'] == WBZ404_MANUAL) {
					$status = wbz404_trans('Manual');
				} else if ($row['status'] == WBZ404_AUTO) {
					$status = wbz404_trans('Automatic');
				}

				$type = "";
				$dest = "";
				$link = "";
				$title = wbz404_trans('Visit') . " ";
				if ($row['type'] == WBZ404_EXTERNAL) {
					$type = wbz404_trans('External');
					$dest = $row['final_dest'];
					$link = $row['final_dest'];
					$title .= $row['final_dest'];
				} else if ($row['type'] == WBZ404_POST) {
					$type = wbz404_trans('Post/Page');
					$permalink = wbz404_permalinkInfo($row['final_dest'] . "|POST", 0);
					$dest = $permalink['title'];
					$link = $permalink['link'];
					$title .= $permalink['title'];
				} else if ($row['type'] == WBZ404_CAT) {
					$type = wbz404_trans('Category');
					$permalink = wbz404_permalinkInfo($row['final_dest'] . "|CAT", 0);
					$dest = $permalink['title'];
					$link = $permalink['link'];
					$title .= wbz404_trans('Category:') . " " . $permalink['title'];
				} else if ($row['type'] == WBZ404_TAG) {
					$type = wbz404_trans('Tag');
					$permalink = wbz404_permalinkInfo($row['final_dest'] . "|TAG", 0);
					$dest = $permalink['title'];
					$link = $permalink['link'];
					$title .= wbz404_trans('Tag:') . " " . $permalink['title'];
				}


				$hits = $row['hits'];
				$last_used = wbz404_getRedirectLastUsed($row['id']);
				if ($last_used != 0) {
					$last = date("Y/m/d h:i:s A", $last_used);
				} else {
					$last = wbz404_trans('Never Used');
				}

				$editlink = "?page=wbz404_redirected&subpage=wbz404_edit&id=" . $row['id'];
				$logslink = "?page=wbz404_redirected&subpage=wbz404_logs&id=" . $row['id'];
				$trashlink = "?page=wbz404_redirected&id=" . $row['id'];
				$deletelink = "?page=wbz404_redirected&remove=1&id=" . $row['id'];

				if ($tableOptions['filter'] == -1) {
					$trashlink .= "&trash=0";
					$trashtitle = wbz404_trans('Restore');
				} else {
					$trashlink .= "&trash=1";
					$trashtitle = wbz404_trans('Trash');
				}

				if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
					$trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
					$deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
				}
				if ($tableOptions['filter'] != 0) {
					$trashlink .= "&filter=" . $tableOptions['filter'];
					$deletelink .= "&filter=" . $tableOptions['filter'];
				}

				$trashaction = "wbz404_trashRedirect";
				$trashlink = wp_nonce_url($trashlink, $trashaction);

				if ($tableOptions['filter'] == -1) {
					$deleteaction = "wbz404_removeRedirect";
					$deletelink = wp_nonce_url($deletelink, $deleteaction);
				}

	                        $class = "";
	                        if ($y == 0) {
	                                $class=" class=\"alternate\"";
	                                $y++;
	                        } else {
	                                $y=0;
	                        }

				echo "<tr id=\"post-" . $row['id'] . "\"" . $class . ">";
					echo "<td></td>";
					echo "<td>";
						echo "<strong><a href=\"" . $editlink . "\" title=\"" . wbz404_trans('Edit Redirect Details') . "\">" . $row['url'] . "</a></strong>";
						echo "<div class=\"row-actions\">";
							if ($tableOptions['filter'] != -1) {
								echo "<span class=\"edit\"><a href=\"" . $editlink . "\" title=\"" . wbz404_trans('Edit Redirect Details') . "\">" . wbz404_trans('Edit') . "</a></span>";
								echo " | ";
							}
							echo "<span class=\"trash\"><a href=\"" . $trashlink . "\" title=\"" . wbz404_trans('Trash Redirected URL') . "\">" . $trashtitle . "</a></span>";
							echo " | ";
							echo "<span class=\"view\"><a href=\"" . $logslink . "\" title=\"" . wbz404_trans('View Redirect Logs') . "\">" . wbz404_trans('View Logs') . "</a></span>";
							if ($tableOptions['filter'] == -1) {
								echo " | ";
								echo "<span class=\"delete\"><a href=\"" . $deletelink . "\" title=\"" . wbz404_trans('Delete Redirect Permanently') . "\">" . wbz404_trans('Delete Permanently') . "</a></span>";
							}
						echo "</div>";
					echo "</td>";
					echo "<td>" . $status . "</td>";
					echo "<td>" . $type . "</td>";
					echo "<td><a href=\"" . $link . "\" title=\"" . $title . "\" target=\"_blank\">" . $dest . "</a></td>";
					echo "<td>" . $row['code'] . "</td>";
					echo "<td>" . $hits . "</td>";
					echo "<td>" . date("Y/m/d h:i:s A", $row['timestamp']) . "</td>";
					echo "<td>" . $last . "</td>";
					echo "<td></td>";
				echo "</tr>";
			}
			if ($displayed == 0) {
				echo "<tr>";
					echo "<td></td>";
					echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . wbz404_trans('No Records To Display') . "</td>";
					echo "<td></td>";
				echo "</tr>";
			}
		echo "</tbody>";
	echo "</table>";

	echo "<div class=\"tablenav\">";
		wbz404_drawPaginationLinks($sub, $tableOptions);
	echo "</div>";

	if ($tableOptions['filter'] != -1) {
		echo "<h3>" . wbz404_trans('Add Manual Redirect') . "</h3>";

		$url = "?page=wbz404_redirected";

		if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
			$url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
		}
		if ($tableOptions['filter'] != 0) {
			$url .= "&filter=" . $tableOptions['filter'];
		}

		$action = "wbz404addRedirect";
	        $link = wp_nonce_url($url, $action);

		echo "<form method=\"POST\" action=\"" . $link . "\">";
		echo "<input type=\"hidden\" name=\"action\" value=\"addRedirect\">";
		if (isset($_POST['url'])) {
			$postedURL = $_POST['url'];
		} else {
			$postedURL = "";
		}
		echo "<strong><label for=\"url\">" . wbz404_trans('URL') . ":</label></strong> <input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . $postedURL . "\"> (" . wbz404_trans('Required') . ")<br>";
		echo "<strong><label for=\"dest\">" . wbz404_trans('Redirect to') . ":</strong> <select id=\"dest\" name=\"dest\">";
			$selected = "";
			if (isset($_POST['dest']) && $_POST['dest'] == "EXTERNAL") {
				$selected = " selected";
			}
			echo "<option value=\"EXTERNAL\"" . $selected . ">" . wbz404_trans('External Page') . "</options>";
	
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
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Post') . ": " . $theTitle . "</option>";
			}
	
			$rows = get_pages();
			foreach ($rows as $row) {
				$id = $row->ID;
				$theTitle = $row->post_title;
				$thisval = $id . "|POST";

				$parent = $row->post_parent;
				while ($parent != 0) {
					$query = "select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = $parent";
					$prow = $wpdb->get_row($query, OBJECT);
					if (! ($prow == NULL)) {
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
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Page') . ": " . $theTitle . "</option>";
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
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Category') . ": " . $theTitle . "</option>";
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
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Tag') . ": " . $theTitle . "</option>";
			}
			
		echo "</select><br>";
		if (isset($_POST['external'])) {
			$postedExternal = $_POST['external'];
		} else {
			$postedExternal = "";
		}
		echo "<strong><label for=\"external\">" . wbz404_trans('External URL') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . $postedExternal . "\"> (" . wbz404_trans('Required if Redirect to is set to External Page') . ")<br>";
		echo "<strong><label for=\"code\">" . wbz404_trans('Redirect Type') . ":</label></strong> <select id=\"code\" name=\"code\">";
			if ((! isset($_POST['code'])) || $_POST['code'] == "") {
				$codeselected = $options['default_redirect'];
			} else {
				$codeselected = $_POST['code'];
			}
			$codes = array(301,302);
			foreach ($codes as $code) {
				$selected = "";
				if ($code == $codeselected) {
					$selected = " selected";
				}
				if ($code == 301) {
					$title = wbz404_trans('301 Permanent Redirect');
				} else {
					$title = wbz404_trans('302 Temporary Redirect');
				}
				echo "<option value=\"" . $code . "\"" . $selected . ">" . $title . "</option>";
			}
		echo "</select><br>";
		echo "<input type=\"submit\" value=\"" . wbz404_trans('Add Redirect') . "\" class=\"button-secondary\">";
		echo "</form>";
	}
}

function wbz404_adminCapturedPage() {
	$sub = "captured";
	
	$options = wbz404_getOptions();
	$tableOptions = wbz404_getTableOptions();

	wbz404_drawFilters($sub, $tableOptions);

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

	date_default_timezone_set(get_option('timezone_string'));  


	echo "<div class=\"tablenav\">";
		wbz404_drawPaginationLinks($sub, $tableOptions);

		if ($tableOptions['filter'] == '-1') {
			echo "<div class=\"alignleft actions\">";
				$eturl = "?page=wbz404_redirected&subpage=wbz404_captured&filter=-1";
                                $trashaction = "wbz404_emptyCapturedTrash";
                                $eturl = wp_nonce_url($eturl, $trashaction);

				echo "<form method=\"POST\" action=\"" . $eturl . "\">";
				echo "<input type=\"hidden\" name=\"action\" value=\"emptyCapturedTrash\">";
				echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . wbz404_trans('Empty Trash') . "\">";
				echo "</form>";
			echo "</div>";		
		} else {
			echo "<div class=\"alignleft actions\">";
				$url = "?page=wbz404_redirected&subpage=wbz404_captured";
				if ($tableOptions['filter'] != 0) {
					$url .= "&filter=" . $tableOptions['filter'];
				}
				if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
					$url .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
				}

                                $bulkaction = "wbz404_capturedBulkAction";
                                $url = wp_nonce_url($url, $bulkaction);

				echo "<form method=\"POST\" action=\"" . $url . "\">";
				echo "<select name=\"action\">";
					if ($tableOptions['filter'] != WBZ404_IGNORED) {
						echo "<option value=\"bulkignore\">" . wbz404_trans('Mark as ignored') . "</option>";
					} else {
						echo "<option value=\"bulkcaptured\">" . wbz404_trans('Mark as captured') . "</option>";
					}
					echo "<option value=\"bulktrash\">" . wbz404_trans('Trash') . "</option>";
				echo "</select>";
				echo "<input type=\"submit\" class=\"button-secondary\" value=\"" . wbz404_trans('Apply') . "\">";
			echo "</div>";
		}
	echo "</div>";
	
	echo "<table class=\"wp-list-table widefat fixed\">";
		echo "<thead>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</thead>";
		echo "<tfoot>";
			wbz404_buildTableColumns($sub, $tableOptions, $columns);
		echo "</tfoot>";
		echo "<tbody id=\"the-list\">";
			$rows = wbz404_getRecords($sub, $tableOptions);
			$displayed = 0;
			$y=1;
			foreach ($rows as $row) {
				$displayed++;

				$hits = $row['hits'];
				$last_used = wbz404_getRedirectLastUsed($row['id']);
				if ($last_used != 0) {
					$last = date("Y/m/d h:i:s A", $last_used);
				} else {
					$last = wbz404_trans('Never Used');
				}

				$editlink = "?page=wbz404_redirected&subpage=wbz404_edit&id=" . $row['id'];
				$logslink = "?page=wbz404_redirected&subpage=wbz404_logs&id=" . $row['id'];
				$trashlink = "?page=wbz404_redirected&&subpage=wbz404_captured&id=" . $row['id'];
				$ignorelink = "?page=wbz404_redirected&&subpage=wbz404_captured&id=" . $row['id'];
				$deletelink = "?page=wbz404_redirected&subpage=wbz404_captured&remove=1&id=" . $row['id'];

				if ($tableOptions['filter'] == -1) {
					$trashlink .= "&trash=0";
					$trashtitle = wbz404_trans('Restore');
				} else {
					$trashlink .= "&trash=1";
					$trashtitle = wbz404_trans('Trash');
				}

				if ($tableOptions['filter'] == WBZ404_IGNORED) {
					$ignorelink .= "&ignore=0";
					$ignoretitle = wbz404_trans('Remove Ignore Status');
				} else {
					$ignorelink .= "&ignore=1";
					$ignoretitle = wbz404_trans('Ignore 404 Error');
				}

				if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
					$trashlink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
					$ignorelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
					$deletelink .= "&orderby=" . $tableOptions['orderby'] . "&order=" . $tableOptions['order'];
				}
				if ($tableOptions['filter'] != 0) {
					$trashlink .= "&filter=" . $tableOptions['filter'];
					$ignorelink .= "&filter=" . $tableOptions['filter'];
					$deletelink .= "&filter=" . $tableOptions['filter'];
				}

				$trashaction = "wbz404_trashRedirect";
				$trashlink = wp_nonce_url($trashlink, $trashaction);

				if ($tableOptions['filter'] == -1) {
					$deleteaction = "wbz404_removeRedirect";
					$deletelink = wp_nonce_url($deletelink, $deleteaction);
				}

				$ignoreaction = "wbz404_ignore404";
				$ignorelink = wp_nonce_url($ignorelink, $ignoreaction);

	                        $class = "";
	                        if ($y == 0) {
	                                $class=" class=\"alternate\"";
	                                $y++;
	                        } else {
	                                $y=0;
	                        }

				echo "<tr id=\"post-" . $row['id'] . "\"" . $class . ">";
					echo "<th class=\"check-column\">";
						if ($tableOptions['filter'] != '-1') {
							echo "<input type=\"checkbox\" name=\"idnum[]\" value=\"" . $row['id'] . "\">";
						}
					echo "</th>";
					echo "<td>";
						echo "<strong><a href=\"" . $editlink . "\" title=\"" . wbz404_trans('Edit Redirect Details') . "\">" . $row['url'] . "</a></strong>";
						echo "<div class=\"row-actions\">";
							if ($tableOptions['filter'] != -1) {
								echo "<span class=\"edit\"><a href=\"" . $editlink . "\" title=\"" . wbz404_trans('Edit Redirect Details') . "\">" . wbz404_trans('Edit') . "</a></span>";
								echo " | ";
							}
							echo "<span class=\"trash\"><a href=\"" . $trashlink . "\" title=\"" . wbz404_trans('Trash Redirected URL') . "\">" . $trashtitle . "</a></span>";
							echo " | ";
							echo "<span class=\"view\"><a href=\"" . $logslink . "\" title=\"" . wbz404_trans('View Redirect Logs') . "\">" . wbz404_trans('View Logs') . "</a></span>";
							if ($tableOptions['filter'] != -1) {
								echo " | ";
								echo "<span class=\"ignore\"><a href=\"" . $ignorelink . "\" title=\"" . $ignoretitle . "\">" . $ignoretitle . "</a></span>";
							} else {
								echo " | ";
								echo "<span class=\"delete\"><a href=\"" . $deletelink . "\" title=\"" . wbz404_trans('Delete Redirect Permanently') . "\">" . wbz404_trans('Delete Permanently') . "</a></span>";
							}
						echo "</div>";
					echo "</td>";
					echo "<td>" . $hits . "</td>";
					echo "<td>" . date("Y/m/d h:i:s A", $row['timestamp']) . "</td>";
					echo "<td>" . $last . "</td>";
					echo "<td></td>";
				echo "</tr>";
			}
			if ($displayed == 0) {
				echo "<tr>";
					echo "<td></td>";
					echo "<td colspan=\"8\" style=\"text-align: center; font-weight: bold;\">" . wbz404_trans('No Records To Display') . "</td>";
					echo "<td></td>";
				echo "</tr>";
			}
		echo "</tbody>";
	echo "</table>";
	
	echo "<div class=\"tablenav\">";
		if ($tableOptions['filter'] != '-1') {
			echo "</form>";
		}
		wbz404_drawPaginationLinks($sub, $tableOptions);
	echo "</div>";
}

function wbz404_adminEditPage() {
	global $wpdb;
	$sub = "edit";

	if (! (isset($_GET['id']) && preg_match('/[0-9]+/', $_GET['id']))) {
		if (! (isset($_POST['id']) && preg_match('/[0-9]+/', $_POST['id']))) {
			echo "Error: Invalid ID Number";
			return;
		} else {
			$recnum = $_POST['id'];
		}
	} else {
		$recnum = $_GET['id'];
	}

	$query = "select id, url, type, final_dest, code from " . $wpdb->prefix . "wbz404_redirects where 1 ";
	$query .= "and id = " . $wpdb->escape($recnum);
	
	$redirect = $wpdb->get_row($query, ARRAY_A);

	if (! ($redirect == null)) {
		echo "<h3>" . wbz404_trans('Redirect Details') . "</h3>";

		$url = "?page=wbz404_redirected&subpage=wbz404_edit";

		$action = "wbz404editRedirect";
	        $link = wp_nonce_url($url, $action);

		echo "<form method=\"POST\" action=\"" . $link . "\">";
		echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";
		echo "<input type=\"hidden\" name=\"id\" value=\"" . $redirect['id'] . "\">";
		echo "<strong><label for=\"url\">" . wbz404_trans('URL') . ":</label></strong> <input id=\"url\" style=\"width: 200px;\" type=\"text\" name=\"url\" value=\"" . $redirect['url'] . "\"> (" . wbz404_trans('Required') . ")<br>";
		echo "<strong><label for=\"dest\">" . wbz404_trans('Redirect to') . ":</strong> <select id=\"dest\" name=\"dest\">";
			$selected = "";
			if ($redirect['type'] == WBZ404_EXTERNAL) {
				$selected = " selected";
			}
			echo "<option value=\"" . WBZ404_EXTERNAL . "\"" . $selected . ">" . wbz404_trans('External Page') . "</options>";
	
			$query = "select id from $wpdb->posts where post_status='publish' and post_type='post' order by post_date desc";
			$rows = $wpdb->get_results($query);
			foreach ($rows as $row) {
				$id = $row->id;
				$theTitle = get_the_title($id);
				$thisval = $id . "|" . WBZ404_POST;

				$selected = "";
				if ($redirect['type'] == WBZ404_POST && $redirect['final_dest'] == $id) {
					$selected = " selected";
				}
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Post') . ": " . $theTitle . "</option>";
			}
	
			$rows = get_pages();
			foreach ($rows as $row) {
				$id = $row->ID;
				$theTitle = $row->post_title;
				$thisval = $id . "|" . WBZ404_POST;

				$parent = $row->post_parent;
				while ($parent != 0) {
					$query = "select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = $parent";
					$prow = $wpdb->get_row($query, OBJECT);
					if (! ($prow == NULL)) {
						$theTitle = get_the_title($prow->id) . " &raquo; " . $theTitle;
						$parent = $prow->post_parent;
					} else {
						break;
					}
				}

				$selected = "";
				if ($redirect['type'] == WBZ404_POST && $redirect['final_dest'] == $id) {
					$selected = " selected";
				}
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Page') . ": " . $theTitle . "</option>\n";
			}
	
			$cats = get_categories('hierarchical=0');
			foreach ($cats as $cat) {
				$id = $cat->term_id;
				$theTitle = $cat->name;
				$thisval = $id . "|" . WBZ404_CAT;
				
				$selected = "";
				if ($redirect['type'] == WBZ404_CAT && $redirect['final_dest'] == $id) {
					$selected = " selected";
				}
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Category') . ": " . $theTitle . "</option>";
			}
	
			$tags = get_tags('hierarchical=0');
			foreach ($tags as $tag) {
				$id = $tag->term_id;
				$theTitle = $tag->name;
				$thisval = $id . "|" . WBZ404_TAG;
				
				$selected = "";
				if ($redirect['type'] == WBZ404_TAG && $redirect['final_dest'] == $id) {
					$selected = " selected";
				}
				echo "<option value=\"" . $thisval . "\"" . $selected . ">" . wbz404_trans('Tag') . ": " . $theTitle . "</option>";
			}
			
		echo "</select><br>";
		$final = "";
		if ($redirect['type'] == WBZ404_EXTERNAL) {
			$final = $redirect['final_dest'];
		}
		echo "<strong><label for=\"external\">" . wbz404_trans('External URL') . ":</label></strong> <input id=\"external\" style=\"width: 200px;\" type=\"text\" name=\"external\" value=\"" . $final . "\"> (" . wbz404_trans('Required if Redirect to is set to External Page') . ")<br>";
		echo "<strong><label for=\"code\">" . wbz404_trans('Redirect Type') . ":</label></strong> <select id=\"code\" name=\"code\">";
			if ($redirect['code'] == "") {
				$codeselected = $options['default_redirect'];
			} else {
				$codeselected = $redirect['code'];
			}
			$codes = array(301,302);
			foreach ($codes as $code) {
				$selected = "";
				if ($code == $codeselected) {
					$selected = " selected";
				}
				if ($code == 301) {
					$title = wbz404_trans('301 Permanent Redirect');
				} else {
					$title = wbz404_trans('302 Temporary Redirect');
				}
				echo "<option value=\"" . $code . "\"" . $selected . ">" . $title . "</option>";
			}
		echo "</select><br>";
		echo "<input type=\"submit\" value=\"" . wbz404_trans('Update Redirect') . "\" class=\"button-secondary\">";
		echo "</form>";
	} else {
		echo "Error: Invalid ID Number!";
	}
}

function wbz404_AdminToolsPage() {
	$sub = "tools";

	$hr = "style=\"border: 0px; margin-bottom: 0px; padding-bottom: 4px; border-bottom: 1px dotted #DEDEDE;\"";

	$url = "?page=wbz404_redirected&subpage=wbz404_tools";
	$action = "wbz404_purgeRedirects";

        $link = wp_nonce_url($url, $action);


	echo "<div class=\"postbox-container\" style=\"width: 100%;\">";
	echo "<div class=\"metabox-holder\">";
	echo " <div class=\"meta-box-sortables\">";

	$content = "";
	$content .= "<form method=\"POST\" action=\"" . $link . "\">";
	$content .= "<input type=\"hidden\" name=\"action\" value=\"purgeRedirects\">";

	$content .= "<p>";
	$content .= "<strong><label for=\"purgetype\">" . wbz404_trans('Purge Type') . ":</label></strong> <select name=\"purgetype\" id=\"purgetype\">";
	$content .= "<option value=\"logs\">" . wbz404_trans('Logs Only') . "</option>";
	$content .= "<option value=\"redirects\">" . wbz404_trans('Logs & Redirects') . "</option>";
	$content .= "</select><br><br>";

	$content .= "<strong>" . wbz404_trans('Redirect Types') . ":</strong><br>";
	$content .= "<ul style=\"margin-left: 40px;\">";
	$content .= "<li><input type=\"checkbox\" id=\"auto\" name=\"types[]\" value=\"" . WBZ404_AUTO . "\"> <label for=\"auto\">" . wbz404_trans('Automatic Redirects') . "</label></li>";
	$content .= "<li><input type=\"checkbox\" id=\"manual\" name=\"types[]\" value=\"" . WBZ404_MANUAL . "\"> <label for=\"manual\">" . wbz404_trans('Manual Redirects') . "</label></li>";
	$content .= "<li><input type=\"checkbox\" id=\"captured\" name=\"types[]\" value=\"" . WBZ404_CAPTURED . "\"> <label for=\"captured\">" . wbz404_trans('Captured URLs') . "</label></li>";
	$content .= "<li><input type=\"checkbox\" id=\"ignored\" name=\"types[]\" value=\"" . WBZ404_IGNORED . "\"> <label for=\"ignored\">" . wbz404_trans('Ignored URLs') . "</label></li>";
	$content .= "</ul>";

	$content .= "<strong>" . wbz404_trans('Sanity Check') . "</strong><br>";
	$content .= wbz404_trans('Using the purge options will delete logs and redirects matching the boxes selected above. This action is not reversible. Hopefully you know what you\'re doing.') . "<br>";
	$content .= "<br>";
	$content .= "<input type=\"checkbox\" id=\"sanity\" name=\"sanity\" value=\"1\"> " . wbz404_trans('I understand the above statement, I know what I am doing... blah blah blah. Just delete the records!') . "<br>";
	$content .= "<br>";
	$content .= "<input type=\"submit\" value=\"" . wbz404_trans('Purge Entries!') . "\" class=\"button-secondary\">";
	$content .= "</p>";

	$content .= "</form>";

	wbz404_postbox("wbz404-purgeRedirects", wbz404_trans('Purge Options'), $content);

	echo "</div></div></div>";
}

function wbz404_purgeRedirects() {
	global $wpdb;
	$message = "";

	$redirects = $wpdb->prefix . "wbz404_redirects";
	$logs = $wpdb->prefix . "wbz404_logs";

	$sanity = $_POST['sanity'];
	if ($sanity == "1") {
		$type = $_POST['types'];
		if ($type != "") {
			if (is_array($type)) {
				$types = "";
				$x=0;
				for ($i=0; $i < count($type); $i++) {
					if (preg_match('/[0-9]+/', $type[$i])) {
						if ($x > 0) {
							$types .= ",";
						}
						$types .= $type[$i];
						$x++;
					}
				}

				if ($types != "") {
					$purge = $_POST['purgetype'];
					if ($purge == "logs" || $purge == "redirects") {
						$query = "delete from " . $logs . " where redirect_id in (select id from " . $redirects . " where status in (" . $types . "))";
						$logcount = $wpdb->query( $query );
						$message = $logcount . " " . wbz404_trans('Log entries were purged.');

						if ($purge == "redirects") {
							$query = "delete from " . $redirects . " where status in (" . $types . ")";
							$count = $wpdb->query($query);
							$message .= "<br>";
							$message .= $count . " " . wbz404_trans('Redirect entries were purged.');
						}
					} else {
						$message = wbz404_trans('Error: An invalid purge type was selected. Exiting.');
					}
				} else {
					$message = wbz404_trans('Error: No valid redirect types were selected. Exiting.');
				}
			} else {
				$message = wbz404_trans('An unknown error has occurred.');
			}
		} else {
			$message = wbz404_trans('Error: No redirect types were selected. No purges will be done.');
		}
	} else {
		$message = wbz404_trans('Error: You didn\'t check the I understand checkbox. No purging of records for you!');
	}
	return $message;
}

