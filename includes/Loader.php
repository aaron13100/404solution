<?php

// turn on debug for localhost etc
$GLOBALS['abj404_whitelist'] = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com',
		'www.wealth-psychology.com', 'wealth-psychology');
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/*
	Plugin Name: 404 Solution
	Plugin URI:  http://www.wealth-psychology.com/404-solution/
	Description: Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors
	Author:      Aaron J
	Author URI:  http://www.wealth-psychology.com/404-solution/

	Version: 2.19.0

	License:     GPL2
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
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

// Constants
define( 'ABJ404_AUTHOR_EMAIL', 'aaron@wealth-psychology.com' );
/* plugin_dir_url( __FILE__ ) */
define( 'ABJ404_URL', plugin_dir_url(ABJ404_FILE));

/** plugin_dir_path( __FILE__ ) */
define( 'ABJ404_PATH', plugin_dir_path(ABJ404_FILE));
define( 'ABJ404_NAME', plugin_basename(ABJ404_FILE));
define('ABJ404_SOLUTION_BASENAME', function_exists('plugin_basename') ? plugin_basename(ABJ404_FILE) : 
	basename(dirname(ABJ404_FILE)) . '/' . basename(ABJ404_FILE));

define( 'ABJ404_VERSION', '2.19.0' );
define( 'ABJ404_HOME_URL', 'http://www.wealth-psychology.com/404-solution/'
        . '?utm_source=404SolutionPlugin&utm_medium=WordPress');
define( 'ABJ404_PP', 'abj404_solution'); // plugin path
define( 'PLUGIN_NAME', '404 Solution');

// STATUS types
define( 'ABJ404_TRASH_FILTER', -1 );
define( 'ABJ404_STATUS_MANUAL', 1 );
define( 'ABJ404_STATUS_AUTO', 2 );
define( 'ABJ404_STATUS_CAPTURED', 3 );
define( 'ABJ404_STATUS_IGNORED', 4 );
define( 'ABJ404_STATUS_LATER', 5 );
define( 'ABJ404_STATUS_REGEX', 6 );

// Redirect types
define( 'ABJ404_TYPE_404_DISPLAYED', 0 );
define( 'ABJ404_TYPE_POST', 1 );
define( 'ABJ404_TYPE_CAT', 2 );
define( 'ABJ404_TYPE_TAG', 3 );
define( 'ABJ404_TYPE_EXTERNAL', 4 );
define( 'ABJ404_TYPE_HOME', 5 );

$abj404_redirect_types = array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX);
$abj404_captured_types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);

// other
define("ABJ404_OPTION_DEFAULT_PERPAGE", 25);
define("ABJ404_OPTION_MIN_PERPAGE", 3);
define("ABJ404_OPTION_MAX_PERPAGE", 500);
define("ABJ404_MAX_AJAX_DROPDOWN_SIZE", 500);

// always include
require_once ABJ404_PATH . "includes/ShortCode.php";

// include only if necessary
require_once ABJ404_PATH . "includes/objs/UserRequest.php";
require_once ABJ404_PATH . "includes/Functions.php";
require_once ABJ404_PATH . "includes/php/FunctionsPreg.php";
require_once ABJ404_PATH . "includes/php/FunctionsMBString.php";
require_once ABJ404_PATH . "includes/Logging.php";
require_once ABJ404_PATH . "includes/DataAccess.php";
require_once ABJ404_PATH . "includes/DatabaseUpgradesEtc.php";
require_once ABJ404_PATH . "includes/PluginLogic.php";
require_once ABJ404_PATH . "includes/WPConnector.php";
require_once ABJ404_PATH . "includes/SpellChecker.php";
require_once ABJ404_PATH . "includes/ErrorHandler.php";
require_once ABJ404_PATH . 'includes/Timer.php';
require_once ABJ404_PATH . 'includes/PermalinkCache.php';
require_once ABJ404_PATH . 'includes/SynchronizationUtils.php';

if (is_admin()) {
    require_once ABJ404_PATH . "includes/objs/WPNotice.php";
    require_once ABJ404_PATH . "includes/wordpress/WPNotices.php";
    require_once ABJ404_PATH . "includes/View.php";
    require_once ABJ404_PATH . "includes/View_Suggestions.php";
    require_once ABJ404_PATH . "includes/ajax/ViewUpdater.php";
    require_once ABJ404_PATH . "includes/ajax/Ajax_Php.php";
    require_once ABJ404_PATH . 'includes/ajax/Ajax_TrashAction.php';
    
    // TODO make these not global
    $abj404view = new ABJ_404_Solution_View();
    $abj404viewSuggestions = new ABJ_404_Solution_View_Suggestions();
}

/**
 * Load the text domain for translation of the plugin.
 *
 * @since 1.4.2
 */
load_plugin_textdomain('404-solution', false, dirname(plugin_basename(ABJ404_FILE)) . '/languages' );
