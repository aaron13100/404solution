<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
}

class ABJ_404_Solution_WPUtils {
	
	/** Wrapper for the add_action function that throws an exception if the action already exists.
	 *
	 * @global type $wp_filter
	 * @param string   $tag             The name of the action to which the $function_to_add is hooked.
	 * @param callable $function_to_add The name of the function you wish to be called.
	 * @param int      $priority        Optional. Used to specify the order in which the functions
	 *                                  associated with a particular action are executed. Default 10.
	 *                                  Lower numbers correspond with earlier execution,
	 *                                  and functions with the same priority are executed
	 *                                  in the order in which they were added to the action.
	 * @param int      $accepted_args   Optional. The number of arguments the function accepts. Default 1.
	 * @return
	 */
	static function safeAddAction($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
		global $wp_filter;
		
		if (array_key_exists($tag, $wp_filter)) {
			$abj404logging = ABJ_404_Solution_Logging::getInstance();
			$msg = "A duplicate action was added (" . trim($tag) .
				"). Someone has already registered that tag. " .
				"Here's what the existing action looks like: " .
				json_encode($wp_filter[$tag], JSON_PRETTY_PRINT);
			$abj404logging->errorMessage($msg);
		}
		return add_action($tag, $function_to_add, $priority, $accepted_args);
	}
	
}

