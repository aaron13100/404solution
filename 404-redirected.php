<?php
/*
	Plugin Name: 404 Redirected
	Plugin URI:  https://remkusdevries.com/plugins/404-redirected
	Description: Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors
	Author:      Remkus de Vries
	Author URI:  https://remkusdevries.com

	Version: 1.4.7

	License:     GPL2
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
	Domain Path: /languages
	Text Domain: 404-redirected

	Developed by Weberz Hosting. Maintained by Remkus de Vries.

	Copyright 2009  Weberz Hosting  (email: rob@weberz.com)
	Copyright 2016  Remkus de Vries

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

//Constants
define( 'WBZ404_URL', plugin_dir_url( __FILE__ ) );
define( 'WBZ404_PATH', plugin_dir_path( __FILE__ ) );
define( 'WBZ404_NAME', plugin_basename( __FILE__ ) );
define( 'WBZ404_VERSION', '1.4.7' );
define( 'WBZ404_HOME', 'https://remkusdevries/plugins/404-redirected/' );
define( 'WBZ404_TRANS', 'wbz404_redirected' );

//URL Types
define( 'WBZ404_MANUAL', 1 );
define( 'WBZ404_AUTO', 2 );
define( 'WBZ404_CAPTURED', 3 );
define( 'WBZ404_IGNORED', 4 );

//Redirect Types
define( 'WBZ404_POST', 1 );
define( 'WBZ404_CAT', 2 );
define( 'WBZ404_TAG', 3 );
define( 'WBZ404_EXTERNAL', 4 );

require WBZ404_PATH."includes/functions.php";
require WBZ404_PATH."includes/frontend.php";
require WBZ404_PATH."includes/class-wbz404-bypass-404-redirect.php";

if ( is_admin() ) {
	require WBZ404_PATH."includes/admin.php";
}

/**
 * Load the text domain for translation of the plugin
 *
 * @since 1.4.2
 */
// Load text domain.
load_plugin_textdomain( '404-redirected', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
