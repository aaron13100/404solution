<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Static functions that can be used from anywhere.  */
abstract class ABJ_404_Solution_Functions {

    /** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_Logging|null */
    protected $injectedLogging = null;

    /** @var ABJ_404_Solution_RequestContext|null */
    protected $injectedRequestContext = null;

    /**
     * Collaborators are passed in by the DI container's 'functions' factory
     * (see bootstrap.php). Nulls are tolerated for early-boot and direct
     * test instantiation; logging() and requestContext() lazy-resolve in
     * that case as a singular bootstrap-only fallback.
     *
     * @param ABJ_404_Solution_Logging|null        $logging
     * @param ABJ_404_Solution_RequestContext|null $requestContext
     */
    public function __construct($logging = null, $requestContext = null) {
        $this->injectedLogging        = $logging;
        $this->injectedRequestContext = $requestContext;
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

        if (extension_loaded('mbstring')) {
            self::$instance = new ABJ_404_Solution_FunctionsMBString();

        } else {
            self::$instance = new ABJ_404_Solution_FunctionsPreg();
        }

        return self::$instance;
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
    
    /** First urldecode then json_decode the data, then return it.
     * All of this encoding and decoding is so that [] characters are supported.
     * @param string $data
     * @return mixed
     */
    function decodeComplicatedData($data) {
    	$dataDecoded = urldecode($data);
    	
    	// JSON.stringify escapes single quotes and json_decode does not want them to be escaped.
    	$dataStripped = str_replace("\'", "'", $dataDecoded);
    	$fixedData = json_decode($dataStripped, true);
    	
    	$jsonErrorNumber = json_last_error();
    	if ($jsonErrorNumber != 0) {
    		$errorMsg = json_last_error_msg();
    		$lastMessagePart = ", Decoded: " . $dataDecoded;
    		if ($dataStripped != null && mb_strlen($dataStripped) > 1) {
    			$lastMessagePart = ", Stripped: " . $dataStripped;
    		}
    		
    		$logger = $this->logging();
    		$logger->errorMessage("Error " . $jsonErrorNumber . " parsing JSON in "
    			. __CLASS__ . "->" . __FUNCTION__ . "(). Error message: " . $errorMsg . $lastMessagePart);
    	}
    	
    	return $fixedData;
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

    /** @return int */
    abstract function ord(string $char): int;

    /** @return string */
    abstract function strtolower(string $string): string;

    /** @return int */
    abstract function strlen(string $string): int;

    /** @return int|false */
    abstract function strpos(string $haystack, string $needle, int $offset = 0);

    /** @return string */
    abstract function substr(string $str, int $start, ?int $length = null): string;

    /**
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    abstract function regexMatch(string $pattern, string $string, ?array &$regs = null);

    /**
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    abstract function regexMatchi(string $pattern, string $string, ?array &$regs = null);

    /**
     * @param string $pattern
     * @param string $replacement
     * @param string $string
     * @return string|null
     */
    abstract function regexReplace($pattern, $replacement, $string);

    /**
     * @param string|null $string
     * @return string
     */
    abstract function sanitizeInvalidUTF8(?string $string): string;

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
            $elapsedTime = microtime(true) - $startTime;
            
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
        	$text = $this->str_replace($regexSearchString, 
                    __($stringToReplace, '404-solution'), $text);
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
    
    /** Sort the QUERY parts of the requested URL. 
     * This is in place because these are stored as part of the URL in the database and used for forwarding to another page.
     * This is done because sometimes different query parts result in a completely different page. Therefore we have to 
     * take into account the query part of the URL (?query=part) when looking for a page to redirect to. 
     * 
     * Here we sort the query parts so that the same request will always look the same.
     * @param array<string, string> $urlParts
     * @return string
     */
    function sortQueryString(array $urlParts): string {
        if (!array_key_exists('query', $urlParts) || $urlParts['query'] == '') {
            return '';
        }

        // parse it into an array
        $queryParts = array();
        parse_str($urlParts['query'], $queryParts);

        // sort the parts
        ksort($queryParts);

        $sanitizer = abj_service('sanitizer');
        $sanitized = $sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /** We have to remove any 'p=##' because it will cause a 404 otherwise.
     * @param string $queryString
     * @return string
     */
    function removePageIDFromQueryString($queryString) {
        // parse the string
        $queryParts = array();
        parse_str($queryString, $queryParts);

        // remove the page id
        if (array_key_exists('p', $queryParts)) {
            unset($queryParts['p']);
        }

        // rebuild the string.
        $sanitizer = abj_service('sanitizer');
        $sanitized = $sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /**
     * Check if a URL appears to contain regex patterns.
     *
     * This is used to warn users when a redirect URL looks like it contains
     * regex syntax but is not marked as a regex redirect.
     *
     * @param string $url The URL to check
     * @return bool True if the URL appears to contain regex patterns
     */
    static function urlLooksLikeRegex($url) {
        if (empty($url) || !is_string($url)) {
            return false;
        }

        // Common regex patterns that are unlikely to appear in normal URLs
        $regexIndicators = array(
            '/\(\.\*\)/',           // (.*)  - common capture-all pattern
            '/\(\.\+\)/',           // (.+)  - one or more of anything
            '/\(\?\:/',             // (?:   - non-capturing group
            '/\(\?=/',              // (?=   - positive lookahead
            '/\(\?!/',              // (?!   - negative lookahead
            '/\[\^[^\]]+\]/',       // [^...]  - negated character class
            '/\[[a-z]-[a-z]\]/i',   // [a-z] or [A-Z] - character range
            '/\[[0-9]-[0-9]\]/',    // [0-9] - digit range
            '/\\\\d/',              // \d    - digit shorthand
            '/\\\\w/',              // \w    - word character shorthand
            '/\\\\s/',              // \s    - whitespace shorthand
            '/\.\*/',               // .*    - match anything (greedy)
            '/\.\+/',               // .+    - match one or more of anything
            '/\.\?/',               // .?    - match zero or one of anything
            '/\{\d+,?\d*\}/',       // {n} or {n,} or {n,m} - quantifiers
            '/\|/',                 // |     - alternation (but common in some URLs, so check context)
        );

        foreach ($regexIndicators as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
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
