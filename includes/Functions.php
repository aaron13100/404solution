<?php

/* Static functions that can be used from anywhere.  */
abstract class ABJ_404_Solution_Functions {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance == null) {
            if (extension_loaded('mbstring')) { 
                self::$instance = new ABJ_404_Solution_FunctionsMBString();
                
            } else {
                self::$instance = new ABJ_404_Solution_FunctionsPreg();
            }
        }
        
        return self::$instance;
    }

    /**
     * This function selectively urlencodes a string. Characters outside of the latin1
     * range (0-255) are urlencoded, while characters inside the range are kept as is.
     * @param string $string The string to be selectively urlencoded.
     * @return string The urlencoded string.
     */
    function selectivelyURLEncode($input) {
        $f = ABJ_404_Solution_Functions::getInstance();
    
        // Handle array input
        if (is_array($input)) {
            return array_map([$f, 'selectivelyURLEncode'], $input);
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
            $ord = $f->ord($char);
            
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

    /**Recursively applies `sanitize_text_field` to strings in an array or other data structure.
     * @param mixed $data The data to sanitize. If an array, will recursively 
     * apply this function to all elements.
     * @return mixed The sanitized data. */
    function sanitize_text_field_recursive($data) {
        if (is_array($data)) {
            // Recursively apply to each element
            return array_map([$this, 'sanitize_text_field_recursive'], $data);
        }

        return sanitize_text_field($data);
    }

    /** Escape a string to avoid Cross Site Scripting (XSS) attacks by encoding unsafe HTML characters.
     * @param string $string The string to be escaped.
     * @return string The escaped string.
     */
    function escapeForXSS($value) {
        if (is_array($value)) {
            // Recursively sanitize each element in the array
            return array_map([$this, 'escapeForXSS'], $value);
        } elseif (!is_string($value)) {
            // Convert non-string values to strings
            $value = strval($value);
        }
    
        // Remove control characters and other unsafe characters
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value ?? '');
        // Remove any other characters you consider unsafe
        $value = preg_replace('/[<>"\'`{}()]/u', '', $value ?? '');
        
        return $value;
    }

    /**
     * Normalize a URL string for storage or matching.
     * - Optionally decode percent-encoded octets
     * - Strip invalid UTF-8/control bytes
     *
     * @param string|null $url
     * @param array $options Supported keys: decode (bool)
     * @return string
     */
    function normalizeUrlString($url, $options = array()) {
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
        $url = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $url);

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
            $value = strval($value);
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
        $normalized = $this->regexReplace('\?.*', '', $url);
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
    
    /** Uses explode() to return an array.
     * @param string $string
     */
    function explodeNewline($string) {
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
    		
    		$logger = ABJ_404_Solution_Logging::getInstance();
    		$logger->errorMessage("Error " . $jsonErrorNumber . " parsing JSON in "
    			. __CLASS__ . "->" . __FUNCTION__ . "(). Error message: " . $errorMsg . $lastMessagePart);
    	}
    	
    	return $fixedData;
    }
    
    function str_replace($needle, $replacement, $haystack) {
    	if ($replacement === null) {
    		$replacement = '';
    	}
    	return str_replace($needle, $replacement, $haystack);
    }
    
    function single_str_replace($needle, $replacement, $haystack) {
    	if ($haystack == "" || $this->strlen($haystack) == 0) {
    		return "";
    		
    	} else if ($this->strpos($haystack, $needle) === false) {
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

    abstract function ord($char);
    
    abstract function strtolower($string);
    
    abstract function strlen($string);
    
    abstract function strpos($haystack, $needle, $offset = 0);
    
    abstract function substr($str, $start, $length = null);

    abstract function regexMatch($pattern, $string, &$regs = null);
    
    abstract function regexMatchi($pattern, $string, &$regs = null);
    
    abstract function regexReplace($pattern, $replacement, $string);

    abstract function sanitizeInvalidUTF8($string);

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
    
    function getExecutionTime() {
        if (array_key_exists(ABJ404_PP, $_REQUEST) && 
                array_key_exists('process_start_time', $_REQUEST[ABJ404_PP])) {
            $elapsedTime = microtime(true) - $_REQUEST[ABJ404_PP]['process_start_time'];
            
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
     * @param string $directory
     * @return boolean
     */
    function createDirectoryWithErrorMessages($directory) {
    	if (!is_dir($directory)) {
    		if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
    			unlink($directory);
    			
    			if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
    				error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " .
    						$directory . ". A file with that name alraedy exists.");
    				return false;
    			}
    			
    		} else if (!mkdir($directory, 0755, true)) {
    			error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " .
    					$directory . ". Unknown issue.");
    			return false;
    		}
    	}
    	return true;
    }
    
    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param string $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param int $linkScore
     * @param string $rowType if this is "image" then wp_get_attachment_image_src() is used.
     * @param array $options in case an external URL is used.
     * @return array an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore, $rowType = null, $options = null) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $permalink = array();

        if ($idAndType == NULL) {
            $permalink['score'] = -999;
            return $permalink;
        }
        
        $meta = explode("|", $idAndType);

        $permalink['id'] = $meta[0];
        // Handle malformed data that doesn't contain a pipe separator
        $permalink['type'] = isset($meta[1]) ? $meta[1] : '';
        $permalink['score'] = $linkScore;
        $permalink['status'] = 'unknown';
        $permalink['link'] = 'dunno';

        // Use strict comparison to avoid null/false == 0 issues with type coercion
        // Cast to int for comparison since ABJ404_TYPE_* constants are integers
        $typeInt = is_numeric($permalink['type']) ? (int)$permalink['type'] : -1;

        if ($typeInt === ABJ404_TYPE_POST) {
            if ($rowType == 'image') {
                $imageURL = wp_get_attachment_image_src($permalink['id'], "attached-image");
                $permalink['link'] = $imageURL[0];
            } else {
                $permalink['link'] = get_permalink($permalink['id']);
            }
            $permalink['title'] = get_the_title($permalink['id']);
            $permalink['status'] = get_post_status($permalink['id']);
            
        } else if ($typeInt === ABJ404_TYPE_TAG) {
            $permalink['link'] = get_tag_link($permalink['id']);
            $tag = get_term($permalink['id'], 'post_tag');
            if ($tag != null) {
                $permalink['title'] = $tag->name;
            } else {
                $permalink['title'] = $permalink['link'];
            }
            if ($permalink['title'] == null || $permalink['title'] == '') {
            	$permalink['status'] = 'trash';
            } else {
            	$permalink['status'] = 'published';
            }
            
        } else if ($typeInt === ABJ404_TYPE_CAT) {
            $permalink['link'] = get_category_link($permalink['id']);
            $cat = get_term($permalink['id'], 'category');
            if ($cat != null) {
                $permalink['title'] = $cat->name;
            } else {
                $permalink['title'] = $permalink['link'];
            }
            if ($permalink['title'] == null || $permalink['title'] == '') {
            	$permalink['status'] = 'trash';
            } else {
            	$permalink['status'] = 'published';
            }
            
        } else if ($typeInt === ABJ404_TYPE_HOME) {
            $permalink['link'] = get_home_url();
            $permalink['title'] = get_bloginfo('name');
            $permalink['status'] = 'published';
            
        } else if ($typeInt === ABJ404_TYPE_EXTERNAL) {
        	$permalink['link'] = $permalink['id'];
        	if ($permalink['link'] == ABJ404_TYPE_EXTERNAL) {
	        	if ($options == null) {
	        		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
	        		$options = $abj404logic->getOptions();
	        	}
	        	$urlDestination = (array_key_exists('dest404pageURL', $options) &&
	        		isset($options['dest404pageURL']) ? $options['dest404pageURL'] : 
	        		'External URL not found in options ABJ404 Solution Error');
	        	$permalink['link'] = $urlDestination;
        	}
        	$permalink['status'] = 'published';
        	
        } else if ($typeInt === ABJ404_TYPE_404_DISPLAYED) {
        	$permalink['link'] = '404';
        	$permalink['status'] = 'published';
        	
        } else {
            $abj404logging->errorMessage("Unrecognized permalink type: " . 
                    wp_kses_post(json_encode($permalink)));
        }
        
        if ($permalink['status'] === false) {
        	$permalink['status'] = 'trash';
        }
        
        // Decode anything that might be encoded to support utf8 characters
        if (array_key_exists('link', $permalink)) {
        	$f = ABJ_404_Solution_Functions::getInstance();
        	$permalink['link'] = $f->normalizeUrlString($permalink['link']);
        }
        $permalink['title'] = array_key_exists('title', $permalink) ?
            ABJ_404_Solution_Functions::getInstance()->normalizeUrlString($permalink['title']) : '';
        
        return $permalink;
    }
    
    /** Returns true if the file does not exist after calling this method. 
     * @param string $path
     * @return boolean
     */
    static function safeUnlink($path) {
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }
    
    /** Returns true if the file does not exist after calling this method. 
     * @param string $path
     * @return boolean
     */
    static function safeRmdir($path) {
        if (file_exists($path)) {
            return rmdir($path);
        }
        return true;
    }
    
    /** Recursively delete a directory. 
     * @param string $dir
     * @throws Exception
     * @return boolean
     */
    static function deleteDirectoryRecursively($dir) {
    	// if the directory isn't a part of our plugin then don't do it.
    	if (strpos($dir, ABJ404_PATH) === false) {
    		throw new Exception("Can't delete " . esc_html($dir));
    	}

    	// if it's already gone then we're done.
    	if (!file_exists($dir)) {
    		return true;
    	}
    	
    	// if it's not a directory then delete the file.
    	if (!is_dir($dir)) {
    		return unlink($dir);
    	}
    	
    	// get a list of all files (and directories) in the directory.
    	$items = scandir($dir);
    	foreach ($items as $item) {
    		if ($item == '.' || $item == '..') {
    			continue;
    		}
    	
    		// call self to delete the file/directory.
    		if (!self::deleteDirectoryRecursively($dir . DIRECTORY_SEPARATOR . $item)) {
    			return false;
    		}
    		
    	}
    	
    	// remove the original directory.
    	return rmdir($dir);
    }
    
    /** Reads an entire file at once into a string and return it.
     * @param string $path
     * @param boolean $appendExtraData
     * @throws Exception
     * @return string
     */
    static function readFileContents($path, $appendExtraData = true) {
    	// modify what's returned to make debugging easier.
    	$dataSupplement = self::getDataSupplement($path, $appendExtraData);
        
        if (!file_exists($path)) {
            throw new Exception("Error: Can't find file: " . esc_html($path));
        }
        
        $fileContents = file_get_contents($path);
        if ($fileContents !== false) {
            return $dataSupplement['prefix'] . $fileContents . $dataSupplement['suffix'];
        }
        
        // if we can't read the file that way then try curl.
        if (!function_exists('curl_init')) {
            throw new Exception("Error: Can't read file: " . esc_html($path) .
                    "\n   file_get_contents didn't work and curl is not installed.");
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'file://' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        
        if ($output == null) {
            throw new Exception("Error: Can't read file, even with cURL: " . esc_html($path));
        }
        
        return $dataSupplement['prefix'] . $output . $dataSupplement['suffix'];
    }

    private static function getDataSupplement($filePath, $appendExtraData = true) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $path = strtolower($filePath);
        
        // remove the first part of the path because some people don't want to see
        // it in the log file.
        $homepath = dirname(ABSPATH);
        $beginningOfPath = substr($path, 0, strlen($homepath));
        if (strtolower($beginningOfPath) == strtolower($homepath)) {
        	$path = substr($path, strlen($homepath));
        }
        
        $supplement = array();
        
        if (!$appendExtraData) {
        	$supplement['prefix'] = '';
        	$supplement['suffix'] = '';
        	
        } else if ($f->endsWithCaseInsensitive($path, '.sql')) {
            $supplement['prefix'] = "\n/* ------------------ " . $filePath . " BEGIN ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END ----- */ \n";
            
        } else if ($f->endsWithCaseInsensitive($path, '.html')) {
            $supplement['prefix'] = "\n<!-- ------------------ " . $filePath . " BEGIN ----- --> \n";
            $supplement['suffix'] = "\n<!-- ------------------ " . $filePath . " END ----- --> \n";
            
        } else {
            $supplement['prefix'] = "\n/* ------------------ " . $filePath . " BEGIN unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
        }
        
        return $supplement;
    }
    
    /** Deletes the existing file at $filePath and puts the URL contents in it's place.
     * @param string $url
     * @param string $filePath
     */
    function readURLtoFile($url, $filePath) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        ABJ_404_Solution_Functions::safeUnlink($filePath);

        // if we can't read the file that way then try curl.
        if (function_exists('curl_init')) {
            try {
                //This is the file where we save the information
                $destinationFileWriteHandle = fopen($filePath, 'w+');
                //Here is the file we are downloading, replace spaces with %20
                $ch = curl_init($this->str_replace(" ", "%20", $url));
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/27.0.1453.94 Safari/537.36 (404 Solution WordPress Plugin)');
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                // write curl response to file
                curl_setopt($ch, CURLOPT_FILE, $destinationFileWriteHandle); 
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                // get curl response
                curl_exec($ch); 
                curl_close($ch);
                fclose($destinationFileWriteHandle);        
                
                if (file_exists($filePath) && filesize($filePath) > 0) {
                    return;
                }
            } catch (Exception $e) {
                $abj404logging->debugMessage("curl didn't work for downloading a URL. " . $e->getMessage());
            }
        }

        // Fallback to file_put_contents if curl didn't work or isn't available
        ABJ_404_Solution_Functions::safeUnlink($filePath);
        try {
            $fileHandle = @fopen($url, 'r');
            if ($fileHandle === false) {
                $abj404logging->errorMessage("Failed to open URL for reading: " . $url);
                return;
            }
            $result = file_put_contents($filePath, $fileHandle);
            fclose($fileHandle);

            if ($result === false) {
                $abj404logging->errorMessage("Failed to write file: " . $filePath);
            }
        } catch (Exception $e) {
            $abj404logging->errorMessage("Failed to download URL to file. URL: " . $url . ", Error: " . $e->getMessage());
        }
    }
    
    /** 
     * @param string $haystack
     * @param string $needle
     * @return string
     */
    function endsWithCaseInsensitive($haystack, $needle) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $length = $f->strlen($needle);
        if ($f->strlen($haystack) < $length) {
            return false;
        }
        
        $lowerNeedle = $this->strtolower($needle);
        $lowerHay = $this->strtolower($haystack);
        
        return ($f->substr($lowerHay, -$length) == $lowerNeedle);
    }
    
    /**
     * @param string $haystack
     * @param string $needle
     * @return string
     */
    function endsWithCaseSensitive($haystack, $needle) {
    	$f = ABJ_404_Solution_Functions::getInstance();
    	$length = $f->strlen($needle);
    	if ($f->strlen($haystack) < $length) {
    		return false;
    	}
    	
    	return ($f->substr($haystack, -$length) == $needle);
    }
    
    /** Sort the QUERY parts of the requested URL. 
     * This is in place because these are stored as part of the URL in the database and used for forwarding to another page.
     * This is done because sometimes different query parts result in a completely different page. Therefore we have to 
     * take into account the query part of the URL (?query=part) when looking for a page to redirect to. 
     * 
     * Here we sort the query parts so that the same request will always look the same.
     * @param array $urlParts
     * @return string
     */
    function sortQueryString($urlParts) {
        if (!array_key_exists('query', $urlParts) || $urlParts['query'] == '') {
            return '';
        }
        
        // parse it into an array
        $queryParts = array();
        parse_str($urlParts['query'], $queryParts);
        
        // sort the parts
        ksort($queryParts);

        $queryParts = $this->sanitizeUrlComponent($queryParts);
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
        $queryParts = $this->sanitizeUrlComponent($queryParts);
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

}
