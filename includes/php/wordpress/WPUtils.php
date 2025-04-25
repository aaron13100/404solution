<?php

class ABJ_404_Solution_WPUtils {
	
	/** @var array */
	static $actionsAlreadyAdded = array();
	
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
		
		// If we've already added the action then make sure it's the SAME action that we've already
		// added and that we're not overwriting something.
		// This isn't strictly necessary but it's cleaner to have
		// one function instead of two.
		if (array_key_exists($tag, self::$actionsAlreadyAdded)) {
			// we already saw the action. check if they're the same.
			$shouldError = true;
			if (array_key_exists($tag, self::$actionsAlreadyAdded)) {
				$functionAlreadyAdded = self::$actionsAlreadyAdded[$tag];
				$differences = array_udiff($functionAlreadyAdded, $function_to_add,
					array(self::class, 'compareAjaxActionArrays'));
				
				// any differences mean we accidentally registered the same action to do
				// two different things. If the differences are 0 then we've accidentally registered
				// the same action multiple times.
				if (empty($differences)) {
					$shouldError = false;
				}
			}
			
			if ($shouldError) {
				throw new \Exception("I can't add the action " . $tag .
					" because someone has already registered that tag. Here's what the existing action looks like: " .
					json_encode($wp_filter[$tag], JSON_PRETTY_PRINT));
			}
		}
		
		self::$actionsAlreadyAdded[$tag] = $function_to_add;
		return add_action($tag, $function_to_add, $priority, $accepted_args);
	}

	private static function compareAjaxActionArrays($a, $b) {
		$str1 = self::getValueOrObjectClass($a);
		$str2 = self::getValueOrObjectClass($b);
		
		return strcmp($str1, $str2);
	}

	/**
	 * Return a string representation of the given WP_Error object.
	 *
	 * If the argument is not a WP_Error object, return a string indicating that.
	 *
	 * Otherwise, return a string that includes the following information:
	 *
	 * - code: the error code
	 * - message: the error message
	 * - data: the error data (using var_export)
	 * - all error codes: each code and its associated messages
	 * - backtrace: the backtrace at the time of calling (using print_r on debug_backtrace)
	 *
	 * If any of the above information cannot be retrieved, include an error message
	 * indicating that.
	 *
	 * @param WP_Error $error The object to stringify.
	 * @return string A string representation of the object.
	 */
	static function stringify_wp_error($error) {
		if (!is_wp_error($error)) {
			return 'Not a WP_Error object.';
		}
	
		$output = "WP_Error object:\n";
	
		try {
			$output .= "Code: " . $error->get_error_code() . "\n";
		} catch (Throwable $e) {
			$output .= "Code: [error getting code: " . $e->getMessage() . "]\n";
		}
	
		try {
			$output .= "Message: " . $error->get_error_message() . "\n";
		} catch (Throwable $e) {
			$output .= "Message: [error getting message: " . $e->getMessage() . "]\n";
		}
	
		try {
			$data = $error->get_error_data();
			if (is_array($data) || is_object($data)) {
				$output .= "Data: " . print_r($data, true) . "\n";
			} else {
				$output .= "Data: " . var_export($data, true) . "\n";
			}
		} catch (Throwable $e) {
			$output .= "Data: [error getting data: " . $e->getMessage() . "]\n";
		}
	
		try {
			$codes = $error->get_error_codes();
			$output .= "All error codes:\n";
			foreach ($codes as $code) {
				try {
					$messages = $error->get_error_messages($code);
					$output .= "- $code: " . implode("; ", $messages) . "\n";
				} catch (Throwable $e) {
					$output .= "- $code: [error getting messages: " . $e->getMessage() . "]\n";
				}
			}
		} catch (Throwable $e) {
			$output .= "All error codes: [error fetching codes: " . $e->getMessage() . "]\n";
		}
	
		try {
			$output .= "Backtrace:\n" . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true) . "\n";
		} catch (Throwable $e) {
			$output .= "Backtrace: [error generating backtrace: " . $e->getMessage() . "]\n";
		}
	
		return $output;
	}
	
	/**
     * Gets a string representation of a callable for comparison.
     *
     * @param mixed $callable The callable (string function name, array [object/class, method], Closure).
     * @return string A string representation.
     */
    private static function getValueOrObjectClass($callable) {
        if (is_string($callable)) {
            // Simple function name
            return trim($callable);
        } elseif (is_array($callable) && count($callable) === 2) {
            // Array callable: [object/class, method]
            $classOrObject = $callable[0];
            $method = $callable[1];
            if (is_object($classOrObject)) {
                // Instance method: [new ClassName(), 'methodName']
                return get_class($classOrObject) . '::' . trim($method);
            } elseif (is_string($classOrObject)) {
                // Static method: ['ClassName', 'methodName']
                return trim($classOrObject) . '::' . trim($method);
            }
        } elseif ($callable instanceof \Closure) {
            // It's a Closure (anonymous function). Comparing these reliably is tricky.
            // Returning a generic placeholder might be sufficient if you don't expect
            // multiple different closures on the same hook tag.
            // Alternatively, use spl_object_hash for a unique ID per instance,
            // but note this hash can change between requests.
            return 'Closure#' . spl_object_hash($callable);
        }

        // Fallback for unexpected types - you might want to log or throw an error here
        return serialize($callable);
    }
	
	/** Set the version to the file date/time.
	 * @param $handle
	 * @param string $src
	 * @param array $deps
	 * @param boolean $ver
	 * @param boolean $in_footer
	 */
	static function my_wp_enq_scrpt($handle, $src = '', $deps = array(),
		$ver = false, $in_footer = false) {
			
			$ver = ABJ_404_Solution_WPUtils::createUpdatedVersionNumber($src, $ver);
			
			wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
	}
	
	/** Set the version to the file date/time.
	 * @param $handle
	 * @param string $src
	 * @param array $deps
	 * @param boolean $ver
	 * @param string $media
	 */
	static function my_wp_enq_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {
		$ver = ABJ_404_Solution_WPUtils::createUpdatedVersionNumber($src, $ver);
		
		wp_enqueue_style($handle, $src, $deps, $ver, $media);
	}
	
	/** This forces the version number of a file to be the modified date of that
	 * file. It gets the local file location by changing the URL, gets the modified
	 * date, then returns that date as a string for the version number.
	 * @param string $src
	 * @param boolean $ver
	 * @return string
	 */
	static function createUpdatedVersionNumber($src = '', $ver = false) {
		// if there's no version number and the file is for our plugin
		if (($ver === false || $ver == null) && ($src != null && $src != '' &&
			strpos($src, ABJ404_URL) === 0)) {
			
			// get the local file path by changing the URL.
			$correctedFilePath = str_replace(ABJ404_URL, ABJ404_PATH, $src);
			// get the modified date. as the version.
			$ver = date('Y-m-d_H:i:s', filemtime($correctedFilePath));
		}
			
		return $ver;
	}
	
}

