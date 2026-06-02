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

    /**
     * This function selectively urlencodes a string. Characters outside of the latin1
     * range (0-255) are urlencoded, while characters inside the range are kept as is.
     * @param string|array<int|string, mixed> $input The string to be selectively urlencoded.
     * @return string|array<int|string, mixed> The urlencoded string or array of strings.
     */
    function selectivelyURLEncode($input) {
        // Handle array input
        if (is_array($input)) {
            /** @var callable(mixed): mixed $callback */
            $callback = [$this, 'selectivelyURLEncode'];
            return array_map($callback, $input);
        }

        if (!is_string($input)) {
            $input = strval($input);
        }
    
        // Define replacements for unsafe characters
        $replacements = [
            '<' => '%3C', 
            '>' => '%3E', 
            '"' => '%22', 
            "'" => '%27', 
            '`' => '%60', 
            '{' => '%7B', 
            '}' => '%7D', 
            '(' => '%28', 
            ')' => '%29',
        ];
    
        // Perform replacements
        $input = strtr($input, $replacements);
    
        $encodedString = '';
        // Iterate through each character in the string
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $ord = $this->ord($char);
            
            // If the character is outside of latin1 range or is not representable
            if ($ord > 255) {
                // Convert to hexadecimal representation
                $encodedString .= urlencode($char);
            } else {
                // Keep the original character if it's in the latin1 range
                $encodedString .= $char;
            }
        }
    
        return $encodedString;
    }

    /**
     * Recursively applies `sanitize_text_field` to strings in an array or other data structure.
     * @param mixed $data The data to sanitize. If an array, will recursively
     * apply this function to all elements.
     * @return mixed The sanitized data.
     */
    function sanitize_text_field_recursive($data) {
        if (is_array($data)) {
            // Recursively apply to each element
            return array_map([$this, 'sanitize_text_field_recursive'], $data);
        }

        return sanitize_text_field(is_string($data) ? $data : (is_scalar($data) ? (string)$data : ''));
    }

    /** Escape a string to avoid Cross Site Scripting (XSS) attacks by encoding unsafe HTML characters.
     * @param string $value The string to be escaped.
     * @return string The escaped string.
     */
    function escapeForXSS(?string $value): string {
        if ($value === null) {
            return '';
        }
        // Remove control characters and other unsafe characters
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        // Remove any other characters you consider unsafe
        $value = preg_replace('/[<>"\'`{}()]/u', '', $value) ?? '';

        return $value;
    }

    /**
     * Normalize a URL string for storage or matching.
     * - Optionally decode percent-encoded octets
     * - Strip invalid UTF-8/control bytes
     *
     * @param string|null $url
     * @param array<string, bool> $options Supported keys: decode (bool)
     * @return string
     */
    function normalizeUrlString($url, array $options = array()) {
        $options = array_merge(array('decode' => true), $options);

        if ($url === null || $url === '') {
            return '';
        }

        if (!is_string($url)) {
            $url = strval($url);
        }

        $url = trim($url);
        if ($options['decode']) {
            $url = rawurldecode($url);
        }

        $url = $this->sanitizeInvalidUTF8($url);
        // Remove remaining control characters (keep whitespace)
        $url = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $url) ?? $url;

        return $url;
    }

    /**
     * Sanitize URL components without stripping reserved characters.
     * Keeps characters like ()[]{} for matching but removes invalid UTF-8/control bytes.
     *
     * @param mixed $value
     * @return mixed
     */
    function sanitizeUrlComponent($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeUrlComponent'], $value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            $value = is_scalar($value) ? strval($value) : '';
        }

        $value = $this->sanitizeInvalidUTF8($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return $value;
    }

    /**
     * Encode a URL for legacy matching while preserving URL delimiters.
     *
     * @param string|null $url
     * @return string
     */
    function encodeUrlForLegacyMatch($url) {
        if ($url === null || $url === '') {
            return '';
        }

        if (!is_string($url)) {
            $url = strval($url);
        }

        $encoded = rawurlencode($url);
        $encoded = str_replace(
            array('%2F', '%3F', '%26', '%3D', '%23', '%3A', '%40'),
            array('/', '?', '&', '=', '#', ':', '@'),
            $encoded
        );

        return $encoded;
    }

    /**
     * Normalize a URL for use as a cache/transient key.
     *
     * This function ensures consistent URL normalization across the codebase:
     * - Strips query strings (removes everything after '?')
     * - Applies esc_url for security and consistency
     *
     * IMPORTANT: All code that computes cache keys or transient keys from URLs
     * should use this function to ensure keys match across different code paths.
     *
     * Used by: SpellChecker, ShortCode, Ajax_SuggestionPolling, PluginLogic
     *
     * @param string $url The URL to normalize
     * @return string The normalized URL (query string stripped, esc_url applied)
     */
    function normalizeURLForCacheKey($url) {
        $url = $this->normalizeUrlString($url);
        // Strip query string (everything after '?')
        $normalized = $this->regexReplace('\?.*', '', $url) ?? $url;
        // Apply esc_url for security and consistency
        return esc_url($normalized);
    }

    /** Only URL encode emojis from a string.  
     * @param string $url
     * @return string
     */
    function urlencodeEmojis($url) {
        // Get all emojis in the string.
        $matches = [];
        $emojiPattern = '/[\x{1F000}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E6}-\x{1F1FF}]/u';
        // next try:  = '/[\x{1F6000}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}]/u';
        $emojis = preg_match_all($emojiPattern, $url, $matches);
        
        // If there are any emojis in the string, urlencode them.
        if ($emojis > 0) {
            foreach ($matches[0] as $emoji) {
                $url = str_replace($emoji, urlencode($emoji), $url);
            }
        }
        
        // Return the urlencoded string.
        return $url;
    }
    
    /**
     * Check whether a string contains any UTF-8 4-byte characters (codepoints > U+FFFF).
     * These characters require utf8mb4 storage; they cannot exist in a utf8mb3 or latin1 column.
     *
     * @param string $string
     * @return bool true if the string contains at least one 4-byte UTF-8 character
     */
    function containsUtf8mb4Characters(string $string): bool {
        if ($string === '') {
            return false;
        }
        // 4-byte UTF-8 sequences start with a byte in the range F0-F4
        // followed by three continuation bytes (80-BF).
        return (bool) preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $string);
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

        $sanitized = $this->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->normalizeUrlString($decoded, array('decode' => false));
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
        $sanitized = $this->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->normalizeUrlString($decoded, array('decode' => false));
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

        $f = $this;
        $unslash = function($value) {
            return function_exists('wp_unslash') ? wp_unslash($value) : $value;
        };

        if (is_array($returnValue)) {
            return array_map(function($value) use ($f, $unslash) {
                $value = $unslash($value);
                return $f->normalizeUrlString($value);
            }, $returnValue);
        }

        $returnValue = $unslash($returnValue);
        return $f->normalizeUrlString($returnValue);
    }

}
