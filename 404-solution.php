<?php

/*
	Plugin Name: 404 Solution
	Plugin URI:  https://www.ajexperience.com/404-solution/
	Description: Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors
	Author:      Aaron J
	Author URI:  https://www.ajexperience.com/404-solution/

	Version: 2.36.7

	License: GPL-3.0-or-later
	License URI: https://www.gnu.org/licenses/gpl-3.0.html
	Domain Path: /languages
	Text Domain: 404-solution

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define('ABJ404_PP', 'abj404_solution');
define('ABJ404_FILE', __FILE__);
define('ABJ404_PATH', plugin_dir_path(ABJ404_FILE));
$GLOBALS['abj404_display_errors'] = false;
$GLOBALS['abj404_whitelist'] = array('127.0.0.1', '::1', 'localhost', 
		'ajexperience.com', 'www.ajexperience.com');

$abj404_autoLoaderClassMap = array();
function abj404_autoloader($class) {
	// some people were having issues with possibly parent classes not being loaded before their children.
	$childParentMap = [
		'ABJ_404_Solution_FunctionsMBString' => 'ABJ_404_Solution_Functions',
		'ABJ_404_Solution_FunctionsPreg' => 'ABJ_404_Solution_Functions',
	];

	// only pay attention if it's for us. don't bother for other things.
	if (substr($class, 0, 16) == 'ABJ_404_Solution') {
		global $abj404_autoLoaderClassMap;
		if (empty($abj404_autoLoaderClassMap)) {
			foreach (array('includes/php/objs', 'includes/php/wordpress', 'includes/php', 'includes/php',
					'includes/ajax', 'includes') as $dir) {
					
					$globInput = ABJ404_PATH . $dir . DIRECTORY_SEPARATOR . '*.php';
					$files = glob($globInput);
					foreach ($files as $file) {
						// /Users/user..../php/Study.php becomes ABJ_FC\Study
						$pathParts = pathinfo($file);
						$classNameWhenLoading = 'ABJ_404_Solution_' . $pathParts['filename'];
						$abj404_autoLoaderClassMap[$classNameWhenLoading] = $file;
					}
			}
		}

		if (array_key_exists($class, $abj404_autoLoaderClassMap)) {
			// Ensure the parent class is loaded first
			if (array_key_exists($class, $childParentMap)) {
				$parentClass = $childParentMap[$class];
				if (!class_exists($parentClass)) {
					require_once $abj404_autoLoaderClassMap[$parentClass];
				}
			}

			require_once $abj404_autoLoaderClassMap[$class];
		}
	}
}
spl_autoload_register('abj404_autoloader');

// shortcode
add_shortcode('abj404_solution_page_suggestions', 'abj404_shortCodeListener');
function abj404_shortCodeListener($atts) {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($atts);
}

// admin
if (is_admin()) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	ABJ_404_Solution_WordPress_Connector::init();
	ABJ_404_Solution_ViewUpdater::init();
}

// ----
// get the plugin priority to use before adding the template_redirect action.
$__abj404_options = get_option('abj404_settings');
$__abj404_template_redirect_priority = absint($__abj404_options['template_redirect_priority'] ?? 9);

add_action('template_redirect', 'abj404_404listener', $__abj404_template_redirect_priority);

unset($__abj404_options);
unset($__abj404_template_redirect_priority);
// ---

// 404
function abj404_404listener() {
	// always ignore admin screens and login requests.
	$isLoginScreen = (false !== stripos(wp_login_url(), $_SERVER['SCRIPT_NAME']));
	$isCurrentlyViewingAnAdminPage = is_admin();
	if ($isCurrentlyViewingAnAdminPage || $isLoginScreen) {
        return;
    }
    
    if (!is_404()) {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        // if we should redirect all requests then don't return.
    	$options = get_option('abj404_settings');
    	$arrayKeyExists = is_array($options) && array_key_exists('redirect_all_requests', $options);
    	if ($arrayKeyExists && $options['redirect_all_requests'] == 1) {
    		require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    		$connector = ABJ_404_Solution_WordPress_Connector::getInstance();
    		$connector->processRedirectAllRequests();
    		return;
    	}
    	
    	/** If we're currently redirecting to a custom 404 page and we are about to show page
    	 * suggestions then update the URL displayed to the user. */
    	$cookieName = ABJ404_PP . '_REQUEST_URI';
    	$cookieName .= '_UPDATE_URL';
    	if (isset($_COOKIE[$cookieName]) && !empty($_COOKIE[$cookieName])) {

    		$cookieName404 = ABJ404_PP . '_STATUS_404';
    		
    		if (array_key_exists($cookieName404, $_COOKIE) && 
    			$_COOKIE[$cookieName404] == 'true') {

   				// clear the cookie
    			setcookie($cookieName404, 'false', time() - 5, "/");
    			// we're going to a custom 404 page so se the status to 404.
	    		status_header(404);
    		}
    		
	    	if (array_key_exists('update_suggest_url', $options) &&
    			isset($options['update_suggest_url']) &&
    			$options['update_suggest_url'] == 1) {
    				
    			// clear the cookie
   				$_REQUEST[$cookieName] = $_COOKIE[$cookieName];
    			setcookie($cookieName, '', time() - 5, "/");
    				
    			require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    			add_action('wp_head', 'ABJ_404_Solution_ShortCode::updateURLbarIfNecessary');
    		}
    	}
    }
    if (!is_404() || is_admin()) {
    	return;
    }
    
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $connector = ABJ_404_Solution_WordPress_Connector::getInstance();
    return $connector->process404();
}

function abj404_dailyMaintenanceCronJobListener() {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    $abj404dao->deleteOldRedirectsCron();
}
function abj404_updateLogsHitsTableListener() {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
	$abj404dao->createRedirectsForViewHitsTable();
}
function abj404_updatePermalinkCacheListener($maxExecutionTime, $executionCount = 1) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
	$permalinkCache->updatePermalinkCache($maxExecutionTime, $executionCount);
}
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
add_action('abj404_updateLogsHitsTableAction', 'abj404_updateLogsHitsTableListener');
add_action('abj404_updatePermalinkCacheAction', 'abj404_updatePermalinkCacheListener', 10, 2);

function abj404_getUploadsDir() {
	// figure out the temp directory location.
	$uploadsDirArray = wp_upload_dir(null, false);
	$uploadsDir = $uploadsDirArray['basedir'];
	$uploadsDir .= DIRECTORY_SEPARATOR . 'temp_' . ABJ404_PP . DIRECTORY_SEPARATOR;
	return $uploadsDir;	
}

/** This only runs after WordPress is done enqueuing scripts. */
function abj404_loadSomethingWhenWordPressIsReady() {
	/** Load the text domain for translation of the plugin. */
	load_plugin_textdomain('404-solution', false, dirname(plugin_basename(ABJ404_FILE)) . '/languages' );

	// make debugging easier on localhost etc	
	$serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
	$serverNameIsInTheWhiteList = in_array($serverName, $GLOBALS['abj404_whitelist']);
	
	if ($serverNameIsInTheWhiteList && function_exists('wp_get_current_user')) {
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		if ($abj404logic->userIsPluginAdmin()) {
			$GLOBALS['abj404_display_errors'] = true;
		}
	}

	$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : null);
	if ($action === 'exportRedirects') {
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$abj404logic->handleActionExport();
	}
}
add_action('init', 'abj404_loadSomethingWhenWordPressIsReady');
