<?php

// Constants
define( 'ABJ404_AUTHOR_EMAIL', '404solution@ajexperience.com' );
/* plugin_dir_url( __FILE__ ) */
define( 'ABJ404_URL', plugin_dir_url(ABJ404_FILE)); // https://site-url/wp-content/plugins/404-solution/

/** plugin_dir_path( __FILE__ ) */
define( 'ABJ404_NAME', plugin_basename(ABJ404_FILE)); // wp-content/plugins/404-solution/404-solution.php
define('ABJ404_SOLUTION_BASENAME', function_exists('plugin_basename') ? plugin_basename(ABJ404_FILE) : 
	basename(dirname(ABJ404_FILE)) . '/' . basename(ABJ404_FILE));

// Read version from main plugin file header (single source of truth)
$abj404_plugin_data = get_file_data(ABJ404_FILE, array('Version' => 'Version'));
define('ABJ404_VERSION', $abj404_plugin_data['Version']);
define( 'URL_TRACKING_SUFFIX', '?utm_source=404SolutionPlugin&utm_medium=WordPress');
define( 'ABJ404_HOME_URL', 'https://www.ajexperience.com/404-solution/' . URL_TRACKING_SUFFIX);
define( 'ABJ404_FC_URL', 'https://www.ajexperience.com/' . URL_TRACKING_SUFFIX);
define( 'PLUGIN_NAME', '404 Solution');

// STATUS types
define( 'ABJ404_TRASH_FILTER', -1 );
define( 'ABJ404_STATUS_MANUAL', 1 );
define( 'ABJ404_STATUS_AUTO', 2 );
define( 'ABJ404_STATUS_CAPTURED', 3 );
define( 'ABJ404_STATUS_IGNORED', 4 );
define( 'ABJ404_STATUS_LATER', 5 );
define( 'ABJ404_STATUS_REGEX', 6 );
// Note: TRASH is handled via the 'disabled' column, not status. This constant exists for
// backward compatibility with UninstallModal.php. Value 0 ensures 'status != 0' returns all rows.
define( 'ABJ404_STATUS_TRASH', 0 );

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
define("ABJ404_MAX_URL_LENGTH", 4096);

// Load the bootstrap file which contains the service initialization function
require_once(__DIR__ . '/bootstrap.php');

// Initialize the service container
// This sets up dependency injection for all core services
abj_404_solution_init_services();

// always include
ABJ_404_Solution_ErrorHandler::init();

if (is_admin()) {
	ABJ_404_Solution_PermalinkCache::init();
	ABJ_404_Solution_SpellChecker::init();
	ABJ_404_Solution_SlugChangeHandler::init();
	ABJ_404_Solution_PostEditorIntegration::init();

    // Get services from the container instead of using getInstance()
    // Keeping the global variables for backward compatibility during migration
    $abj404view = abj_service('view');
    $abj404viewSuggestions = abj_service('view_suggestions');
}
