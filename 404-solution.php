<?php

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

define('ABJ404_FILE', __FILE__);

// shortcode
add_shortcode('abj404_solution_page_suggestions', 'abj404_shortCodeListener');
function abj404_shortCodeListener($atts) {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($atts);
}

// admin
if (is_admin()) {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
}

// 404
add_action('template_redirect', 'abj404_404listener', 9);
function abj404_404listener() {
    if (!is_404() || is_admin()) {
        return;
    }
    
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $connector = new ABJ_404_Solution_WordPress_Connector();
    return $connector->process404();
}

function abj404_dailyMaintenanceCronJobListener() {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    $abj404dao->deleteOldRedirectsCron();
}
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
