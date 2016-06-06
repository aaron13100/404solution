<?php
/*
	Plugin Name: 404 Redirected
	Plugin URI: https://wordpress.org/plugins/404-redirected/
	Description: A smart 404 redirection plugin that provides the ability to log incoming 404 URLs in real time, automatically redirect visitors to most relevant content, and provides page suggestions when relevant content can not be found. Admins can also manually add redirects to system and control automatic deletion of old redirects when they are no longer being used.
	Version: 1.4.1
	Author: Remkus de Vries
	Author URI: https://remkusdevries.com/
	License: GPLv2

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
define( 'WBZ404_VERSION', '1.4' );
define( 'WBZ404_HOME', 'https://wordpress.org/plugins/404-redirected/' );
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
