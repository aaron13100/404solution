<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_WPUtils {
	
	/** @var array<string, callable> */
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
	 * @return mixed Whatever add_action() returns.
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
				// Callables stored here are always arrays ([class/object, method])
				$existingArr = is_array($functionAlreadyAdded) ? $functionAlreadyAdded : array($functionAlreadyAdded);
				$newArr = is_array($function_to_add) ? $function_to_add : array($function_to_add);
				$differences = array_udiff($existingArr, $newArr,
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
					(string)json_encode($wp_filter[$tag], JSON_PRETTY_PRINT));
			}
		}
		
		self::$actionsAlreadyAdded[$tag] = $function_to_add;
		return add_action($tag, $function_to_add, $priority, $accepted_args);
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 * @return int
	 */
	public static function compareAjaxActionArrays($a, $b): int {
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
            $method = is_string($callable[1]) ? $callable[1] : '';
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
	 * @param string $handle
	 * @param string $src
	 * @param array<int, string> $deps
	 * @param string|bool $ver
	 * @param bool $in_footer
	 * @return void
	 */
	static function my_wp_enq_scrpt(string $handle, string $src = '', array $deps = array(),
		$ver = false, bool $in_footer = false): void {
			
			$ver = ABJ_404_Solution_WPUtils::createUpdatedVersionNumber($src, $ver);
			
			wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
	}
	
	/** Set the version to the file date/time.
	 * @param string $handle
	 * @param string $src
	 * @param array<int, string> $deps
	 * @param string|bool $ver
	 * @param string $media
	 * @return void
	 */
	static function my_wp_enq_style(string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all'): void {
		$ver = ABJ_404_Solution_WPUtils::createUpdatedVersionNumber($src, $ver);
		
		wp_enqueue_style($handle, $src, $deps, $ver, $media);
	}
	
	/** This forces the version number of a file to be the modified date of that
	 * file. It gets the local file location by changing the URL, gets the modified
	 * date, then returns that date as a string for the version number.
	 * @param string $src
	 * @param string|bool $ver
	 * @return string|false
	 */
	static function createUpdatedVersionNumber($src = '', $ver = false) {
		// if there's no version number and the file is for our plugin
		if ($ver === false && ($src != null && $src != '' &&
			strpos($src, ABJ404_URL) === 0)) {

			// get the local file path by changing the URL.
			$correctedFilePath = str_replace(ABJ404_URL, ABJ404_PATH, $src);
			// get the modified date as the version (guard missing files in tests/odd installs).
			if (is_string($correctedFilePath) && is_file($correctedFilePath)) {
				$mtime = @filemtime($correctedFilePath);
				if ($mtime !== false) {
					$ver = date('Y-m-d_H:i:s', $mtime);
				}
			}
		}

		if (is_string($ver)) {
			return $ver;
		}
		return false;
	}

	/** Text domain shared by every translated asset in this plugin. */
	const TEXT_DOMAIN = '404-solution';

	/** Register wp.i18n translations for every script handle this screen enqueued.
	 *
	 * wp_set_script_translations() looks for languages/404-solution-{locale}-{handle}.json
	 * (built from the .po catalogs by scripts/build-script-translations.php) and
	 * prints a wp.i18n.setLocaleData() call ahead of the handle, so the JS __() calls
	 * resolve against the same catalog the PHP side uses. It is a no-op for a handle
	 * that the current screen never registered, so calling this once per enqueue
	 * context covers every screen without per-screen branching.
	 *
	 * @return void
	 */
	static function registerScriptTranslations(): void {
		if (!function_exists('wp_set_script_translations')) {
			return;
		}

		self::addPluginLocaleScriptTranslationFilter();

		$languagesDir = defined('ABJ404_PATH') ? ABJ404_PATH . 'languages' :
			dirname(dirname(dirname(__DIR__))) . '/languages';
		foreach (array_keys(self::scriptTranslationHandles()) as $handle) {
			wp_set_script_translations($handle, self::TEXT_DOMAIN, $languagesDir);
		}
	}

	/** The handle-to-JS-source map shared by the runtime, the JSON builder and the tests.
	 *
	 * @return array<string, string>
	 */
	static function scriptTranslationHandles(): array {
		$dataFile = (defined('ABJ404_PATH') ? ABJ404_PATH : dirname(dirname(dirname(__DIR__))) . '/') .
			'includes/data/script-translation-handles.php';
		if (!is_file($dataFile)) {
			return array();
		}
		$handles = include $dataFile;
		if (!is_array($handles)) {
			return array();
		}
		// Validate the shape rather than trusting the include. A data file that was
		// truncated or edited into the wrong shape would otherwise reach
		// wp_set_script_translations() as a non-string handle, where it fails silently
		// and the modal renders in English with nothing in any log. Dropping the bad
		// entries here means the worst case is a missing translation the positive
		// control in ScriptTranslationsTest already fails on.
		$typed = array();
		foreach ($handles as $handle => $jsSource) {
			if (is_string($handle) && is_string($jsSource)) {
				$typed[$handle] = $jsSource;
			}
		}
		return $typed;
	}

	/** Register the filter that honours the plugin's own language override for JS strings.
	 *
	 * The "Plugin Language Override" setting is applied to PHP translations through the
	 * plugin_locale filter (abj404_override_plugin_locale). WordPress builds the script
	 * translation filename from determine_locale() instead, which does not see that
	 * override, so without this the admin page would render in the chosen language while
	 * the modal stayed in the site language. Rewriting the filename keeps both sides on
	 * one locale decision rather than introducing a second one.
	 *
	 * @return void
	 */
	private static function addPluginLocaleScriptTranslationFilter(): void {
		static $added = false;
		if ($added || !function_exists('add_filter')) {
			return;
		}
		$added = true;
		add_filter('load_script_translation_file',
			array('ABJ_404_Solution_WPUtils', 'useOverriddenPluginLocaleForScriptTranslations'), 10, 3);
	}

	/** Point a script-translation lookup at the overridden plugin locale when one is set.
	 *
	 * Falls through to the unmodified path whenever no override applies or the overridden
	 * locale has no JSON file, so a missing override catalog degrades to the site locale
	 * rather than to no translations at all.
	 *
	 * @param mixed $file   Absolute path WordPress resolved for the current locale.
	 * @param string $handle Script handle being translated.
	 * @param string $domain Text domain being translated.
	 * @return mixed The path to load.
	 */
	static function useOverriddenPluginLocaleForScriptTranslations($file, $handle, $domain) {
		if ($domain !== self::TEXT_DOMAIN || !is_string($file) || $file === '') {
			return $file;
		}

		$locale = function_exists('determine_locale') ? determine_locale() :
			(function_exists('get_locale') ? get_locale() : '');
		if (!is_string($locale) || $locale === '') {
			return $file;
		}
		$pluginLocale = apply_filters('plugin_locale', $locale, self::TEXT_DOMAIN);
		if (!is_string($pluginLocale) || $pluginLocale === '' || $pluginLocale === $locale) {
			return $file;
		}

		$prefix = self::TEXT_DOMAIN . '-' . $locale . '-';
		$base = basename($file);
		if (strpos($base, $prefix) !== 0) {
			return $file;
		}
		$overridden = dirname($file) . '/' . self::TEXT_DOMAIN . '-' . $pluginLocale . '-' .
			substr($base, strlen($prefix));
		return is_file($overridden) ? $overridden : $file;
	}

}
