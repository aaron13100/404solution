<?php
/*
	Plugin Name: 404 Solution
	Plugin URI:  http://www.wealth-psychology.com/404-solution/
	Description: Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors
	Author:      Aaron J
	Author URI:  http://www.wealth-psychology.com/404-solution/

	Version: 1.5.9

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
define( 'ABJ404_URL', plugin_dir_url( __FILE__ ) );
define( 'ABJ404_PATH', plugin_dir_path( __FILE__ ) );
define( 'ABJ404_NAME', plugin_basename( __FILE__ ) );
define( 'ABJ404_VERSION', '1.5.9' );
define( 'ABJ404_HOME', 'http://www.wealth-psychology.com/404-solution/' );
define( 'ABJ404_TRANS', 'abj404_solution' );

// URL types
define( 'ABJ404_MANUAL', 1 );
define( 'ABJ404_AUTO', 2 );
define( 'ABJ404_CAPTURED', 3 );
define( 'ABJ404_IGNORED', 4 );

// Redirect types
define( 'ABJ404_404_DISPLAYED', 0 );
define( 'ABJ404_POST', 1 );
define( 'ABJ404_CAT', 2 );
define( 'ABJ404_TAG', 3 );
define( 'ABJ404_EXTERNAL', 4 );

// other
define("ABJ404_OPTION_DEFAULT_PERPAGE", 25);

require ABJ404_PATH . "includes/Functions.php";
require ABJ404_PATH . "includes/DataAccess.php";
require ABJ404_PATH . "includes/PluginLogic.php";
require ABJ404_PATH . "includes/WPConnector.php";
require ABJ404_PATH . "includes/SpellChecker.php";

if (is_admin()) {
    require ABJ404_PATH . "includes/View.php";
    $abj404view = new ABJ_404_Solution_View();
}

$abj404dao = new ABJ_404_Solution_DataAccess();
if (!isset($abj404logic)) {
    $abj404logic = new ABJ_404_Solution_PluginLogic();
}
$abj404spellChecker = new ABJ_404_Solution_SpellChecker();
$abj404connector = new ABJ_404_Solution_WordPress_Connector();

/**
 * Load the text domain for translation of the plugin.
 *
 * @since 1.4.2
 */
load_plugin_textdomain( '404-solution', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
