<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_Functions {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it. Mirrors the setInstance()
     * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /** @var ABJ_404_Solution_Logging|null */
    protected $injectedLogging = null;

    /** @var ABJ_404_Solution_RequestContext|null */
    protected $injectedRequestContext = null;

    /** @var ABJ_404_Solution_MbStringAdapter */
    protected $mbAdapter;

    /** @var ABJ_404_Solution_RegexHelper */
    protected $regexHelper;

    /**
     * Collaborators are passed in by the DI container's 'functions' factory
     * (see bootstrap.php). Nulls are tolerated for early-boot and direct
     * test instantiation; logging() and requestContext() lazy-resolve in
     * that case as a singular bootstrap-only fallback. The mbstring
     * adapter and regex helper default to the platform-appropriate
     * implementation so tests and early-boot callers do not have to wire
     * them up explicitly.
     *
     * @param ABJ_404_Solution_Logging|null          $logging
     * @param ABJ_404_Solution_RequestContext|null   $requestContext
     * @param ABJ_404_Solution_MbStringAdapter|null  $mbAdapter
     * @param ABJ_404_Solution_RegexHelper|null      $regexHelper
     */
    public function __construct($logging = null, $requestContext = null, $mbAdapter = null, $regexHelper = null) {
        $this->injectedLogging        = $logging;
        $this->injectedRequestContext = $requestContext;
        $this->mbAdapter              = $mbAdapter !== null
            ? $mbAdapter
            : (extension_loaded('mbstring')
                ? ABJ_404_Solution_MbStringAdapterMb::getInstance()
                : ABJ_404_Solution_MbStringAdapterPreg::getInstance());
        $this->regexHelper            = $regexHelper !== null
            ? $regexHelper
            : (extension_loaded('mbstring')
                ? ABJ_404_Solution_RegexHelperMb::getInstance()
                : ABJ_404_Solution_RegexHelperPreg::getInstance());
    }

    /** @return self */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $service = ABJ_404_Solution_ServiceContainer::safeGet('functions');
            if ($service instanceof self) {
                self::$instance = $service;
                return self::$instance;
            }
        }

        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Returns the polymorphic mbstring/preg adapter. Useful for callers
     * that only need the string primitives and want to depend on a smaller
     * interface than ABJ_404_Solution_Functions.
     *
     * @return ABJ_404_Solution_MbStringAdapter
     */
    public function getMbStringAdapter() {
        return $this->mbAdapter;
    }

    /**
     * Returns the polymorphic regex helper. Useful for callers that only
     * need the regex primitives and want to depend on a smaller interface
     * than ABJ_404_Solution_Functions.
     *
     * @return ABJ_404_Solution_RegexHelper
     */
    public function getRegexHelper() {
        return $this->regexHelper;
    }

    /**
     * Returns the injected Logging service, falling back to the locator
     * when constructed outside the DI container (early boot / tests).
     * Production paths go through DI via the bootstrap factory.
     *
     * @return ABJ_404_Solution_Logging
     */
    protected function logging() {
        if ($this->injectedLogging !== null) {
            return $this->injectedLogging;
        }
        return abj_service('logging');
    }

    /**
     * Returns the injected RequestContext, falling back to the locator
     * when constructed outside the DI container (early boot / tests).
     *
     * @return ABJ_404_Solution_RequestContext
     */
    protected function requestContext() {
        if ($this->injectedRequestContext !== null) {
            return $this->injectedRequestContext;
        }
        return abj_service('request_context');
    }

    /** Uses explode() to return an array.
     * @param string $string
     * @return array<int, string>
     */
    function explodeNewline(string $string): array {
        $normalized = str_replace("\r\n", "\n", $string);
        $normalized = str_replace('\n', "\n", $normalized);
        $result = array_filter(explode("\n", $this->strtolower($normalized)),
            array($this, 'removeEmptyCustom'));

        return $result;
    }

    /**
     * Like {@see explodeNewline()} but tolerates comma-separated input.
     * Use for textarea-backed settings whose values are slug-like tokens
     * (post type names, taxonomy slugs) that cannot legitimately contain
     * commas. A site owner who types "post,page,product" instead of one
     * per line should not silently break the suggestion engine (i321).
     *
     * Do NOT use for free-form values where commas are valid content
     * (User-Agent strings, regex patterns, paths). Those callers must
     * keep {@see explodeNewline()}.
     *
     * @param string $string
     * @return array<int, string>
     */
    function explodeNewlineOrComma(string $string): array {
        $normalized = str_replace("\r\n", "\n", $string);
        $normalized = str_replace('\n', "\n", $normalized);
        $normalized = str_replace(',', "\n", $normalized);
        $result = array_filter(
            array_map('trim', explode("\n", $this->strtolower($normalized))),
            array($this, 'removeEmptyCustom')
        );
        return $result;
    }
    
    /**
     * @param string|array<int, string> $needle
     * @param string|array<int, mixed>|null $replacement
     * @param string $haystack
     * @return string
     */
    function str_replace($needle, $replacement, string $haystack): string {
    	if ($replacement === null) {
    		$replacement = '';
    	}
    	/** @var string $result */
    	$result = str_replace($needle, $replacement, $haystack);
    	return $result;
    }

    /**
     * @param string $needle
     * @param string $replacement
     * @param string $haystack
     * @return string
     */
    function single_str_replace(string $needle, string $replacement, string $haystack): string {
    	if ($haystack == "" || $this->strlen($haystack) == 0) {
    		return "";
    		
    	} else if ($needle === '' || $this->strpos($haystack, $needle) === false) {
    		return $haystack;
    	}

    	$splitResult = explode($needle, $haystack);
    	$implodeResult = implode($replacement, $splitResult);
    	
    	return $implodeResult;
    }
    
    /** Hash the last octet of an IP address. 
     * @param string $ip
     * @return string
     */
    function md5lastOctet($ip) {
    	if (trim($ip) == "") {
    		return $ip;
    	}
    	$partsToStrip = 1;
    	$separatorChar = ".";
    	
    	// split into parts
    	$parts = explode(".", $ip);
    	if (count($parts) == 1) {
    		$parts = explode(":", $ip);
    		// if exploding on : worked then assume we have an IPv6.
    		if (count($parts) > 1) {
    			$partsToStrip = max(count($parts) - 3, 1);
    			$separatorChar = ":";
    		}
    	}
    	$firstPart = implode($separatorChar, array_slice($parts, 0, count($parts) - $partsToStrip));
    	$partToHash = $parts[count($parts) - $partsToStrip];
    	$lastPart = $separatorChar . substr(base_convert(md5($partToHash), 16,32), 0, 12);
    	
    	return $firstPart . $lastPart;
    }

    // =========================================================================
    // mbstring / preg primitives - delegated to ABJ_404_Solution_MbStringAdapter
    // =========================================================================

    /** @return int */
    function ord(string $char): int {
        return $this->mbAdapter->ord($char);
    }

    /** @return string */
    function strtolower(string $string): string {
        return $this->mbAdapter->strtolower($string);
    }

    /** @return int */
    function strlen(string $string): int {
        return $this->mbAdapter->strlen($string);
    }

    /** @return int|false */
    function strpos(string $haystack, string $needle, int $offset = 0) {
        return $this->mbAdapter->strpos($haystack, $needle, $offset);
    }

    /** @return string */
    function substr(?string $str, int $start, ?int $length = null): string {
        return $this->mbAdapter->substr($str, $start, $length);
    }

    /**
     * @param string|null $string
     * @return string
     */
    function sanitizeInvalidUTF8(?string $string): string {
        return $this->mbAdapter->sanitizeInvalidUTF8($string);
    }

    // =========================================================================
    // Regex primitives - delegated to ABJ_404_Solution_RegexHelper
    // =========================================================================

    /**
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    function regexMatch(string $pattern, string $string, ?array &$regs = null) {
        return $this->regexHelper->regexMatch($pattern, $string, $regs);
    }

    /**
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    function regexMatchi(string $pattern, string $string, ?array &$regs = null) {
        return $this->regexHelper->regexMatchi($pattern, $string, $regs);
    }

    /**
     * @param string $pattern
     * @param string $replacement
     * @param string $string
     * @return string|null
     */
    function regexReplace($pattern, $replacement, $string) {
        return $this->regexHelper->regexReplace($pattern, $replacement, $string);
    }

    /**  Used with array_filter()
     * @param string $value
     * @return boolean
     */
    function removeEmptyCustom($value) {
        if ($value == null) {
            return false;
        }
        return trim($value) !== '';
    }
    
    /**
     * @return float|string
     */
    function getExecutionTime() {
        $startTime = $this->requestContext()->process_start_time;
        if ($startTime !== null) {
            $elapsedTime = abj_clock()->nowFloat() - $startTime;
            
            return $elapsedTime;
        }
        
        return '';
    }
    
    /** Replace constants and translations.
     * @param string $text
     * @return string
     */
    function doNormalReplacements($text) {
        global $wpdb;
        
        // known strings that do not exist in the translation file.
        $knownReplacements = array(
            '{ABJ404_STATUS_AUTO}' => ABJ404_STATUS_AUTO,
            '{ABJ404_STATUS_MANUAL}' => ABJ404_STATUS_MANUAL,
            '{ABJ404_STATUS_CAPTURED}' => ABJ404_STATUS_CAPTURED,
            '{ABJ404_STATUS_IGNORED}' => ABJ404_STATUS_IGNORED,
            '{ABJ404_STATUS_LATER}' => ABJ404_STATUS_LATER,
            '{ABJ404_STATUS_REGEX}' => ABJ404_STATUS_REGEX,
            '{ABJ404_TYPE_404_DISPLAYED}' => ABJ404_TYPE_404_DISPLAYED,
            '{ABJ404_TYPE_POST}' => ABJ404_TYPE_POST,
            '{ABJ404_TYPE_CAT}' => ABJ404_TYPE_CAT,
            '{ABJ404_TYPE_TAG}' => ABJ404_TYPE_TAG,
            '{ABJ404_TYPE_EXTERNAL}' => ABJ404_TYPE_EXTERNAL,
            '{ABJ404_TYPE_HOME}' => ABJ404_TYPE_HOME,
            '{ABJ404_HOME_URL}' => ABJ404_HOME_URL,
            '{PLUGIN_NAME}' => PLUGIN_NAME,
            '{ABJ404_VERSION}' => ABJ404_VERSION,
            '{PHP_VERSION}' => phpversion(),
            '{WP_VERSION}' => get_bloginfo('version'),
            '{MYSQL_VERSION}' => $wpdb->db_version(),
            '{ABJ404_MAX_AJAX_DROPDOWN_SIZE}' => ABJ404_MAX_AJAX_DROPDOWN_SIZE,
            '{WP_MEMORY_LIMIT}' => WP_MEMORY_LIMIT,
            '{MBSTRING}' => extension_loaded('mbstring') ? 'true' : 'false',
            );
        
        // replace known strings that do not exist in the translation file.
        $text = $this->str_replace(array_keys($knownReplacements), array_values($knownReplacements), $text);
        
        // Find the strings to replace in the content.
        $re = '/\{(.+?)\}/x';
        $stringsToReplace = array();
        // TODO does this need to be $f->regexMatch?
        preg_match_all($re, $text, $stringsToReplace, PREG_PATTERN_ORDER);

        // Iterate through each string to replace.
        foreach ($stringsToReplace[1] as $stringToReplace) {
        	$regexSearchString = '{' . $stringToReplace . '}';
            // External HTML template placeholders are extracted and checked by
            // HtmlTemplateTranslationCoverageTest because they do not live in PHP call sites.
            $translated = function_exists('translate') ? translate($stringToReplace, '404-solution') : $stringToReplace;
            $text = $this->str_replace($regexSearchString, $translated, $text);
        }
        
        return $text;
    }
    
    
    /**
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function endsWithCaseInsensitive(string $haystack, string $needle): bool {
        $length = $this->strlen($needle);
        if ($this->strlen($haystack) < $length) {
            return false;
        }

        $lowerNeedle = $this->strtolower($needle);
        $lowerHay = $this->strtolower($haystack);

        return ($this->substr($lowerHay, -$length) == $lowerNeedle);
    }
    
    /**
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function endsWithCaseSensitive(string $haystack, string $needle): bool {
    	$length = $this->strlen($needle);
    	if ($this->strlen($haystack) < $length) {
    		return false;
    	}

    	return ($this->substr($haystack, -$length) == $needle);
    }
    
    // =========================================================================
    // Request parameter sanitization (relocated from DataAccessTrait_Stats, Phase 4)
    // =========================================================================

    /**
     * @param string $name The key to retrieve the value for.
     * @param string|null $defaultValue The value to return if the value is not set.
     * @return string The sanitized value.
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
        $returnValue = self::applyBulkActionFallback($name, $returnValue);
        if ($returnValue !== null) {
            if (is_array($returnValue)) {
                $returnValue = array_map('sanitize_text_field', $returnValue);
            } else {
                $returnValue = sanitize_text_field($returnValue);
            }
        }
        $finalValue = $returnValue ?? $defaultValue;
        return is_string($finalValue) ? $finalValue : (is_string($defaultValue) ? $defaultValue : '');
    }

    /**
     * Native WP_List_Table renders bulk-action <select>s at top and bottom of
     * the table using name="action" and name="action2". The 404 Solution
     * wrappers mirror this with abj404action (top) and abj404action2 (bottom).
     * When the top select is empty (default placeholder), fall back to the
     * bottom select's value so Apply submits from either utility row.
     *
     * @param string $name
     * @param mixed $current
     * @return mixed
     */
    private static function applyBulkActionFallback($name, $current) {
        if ($name !== 'abj404action') {
            return $current;
        }
        if ($current !== null && $current !== '' && $current !== '-1') {
            return $current;
        }
        $alt = isset($_GET['abj404action2']) ? $_GET['abj404action2'] : (isset($_POST['abj404action2']) ? $_POST['abj404action2'] : null);
        if ($alt === null || $alt === '' || $alt === '-1') {
            return $current;
        }
        return $alt;
    }

    /**
     * @param string $name The key to retrieve the value for.
     * @param string|null $defaultValue The value to return if the value is not set.
     * @return string|array<string>|null The normalized URL value.
     */
    function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null) {
            return $defaultValue;
        }

        $sanitizer = abj_service('sanitizer');
        $unslash = function($value) {
            return function_exists('wp_unslash') ? wp_unslash($value) : $value;
        };

        if (is_array($returnValue)) {
            return array_map(function($value) use ($sanitizer, $unslash) {
                $value = $unslash($value);
                return $sanitizer->normalizeUrlString($value);
            }, $returnValue);
        }

        $returnValue = $unslash($returnValue);
        return $sanitizer->normalizeUrlString($returnValue);
    }

}
