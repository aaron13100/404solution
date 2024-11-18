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
        $permalink['type'] = $meta[1];
        $permalink['score'] = $linkScore;
        $permalink['status'] = 'unknown';
        $permalink['link'] = 'dunno';

        if ($permalink['type'] == ABJ404_TYPE_POST) {
            if ($rowType == 'image') {
                $imageURL = wp_get_attachment_image_src($permalink['id'], "attached-image");
                $permalink['link'] = $imageURL[0];
            } else {
                $permalink['link'] = get_permalink($permalink['id']);
            }
            $permalink['title'] = get_the_title($permalink['id']);
            $permalink['status'] = get_post_status($permalink['id']);
            
        } else if ($permalink['type'] == ABJ404_TYPE_TAG) {
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
            
        } else if ($permalink['type'] == ABJ404_TYPE_CAT) {
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
            
        } else if ($permalink['type'] == ABJ404_TYPE_HOME) {
            $permalink['link'] = get_home_url();
            $permalink['title'] = get_bloginfo('name');
            $permalink['status'] = 'published';
            
        } else if ($permalink['type'] == ABJ404_TYPE_EXTERNAL) {
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
        	
        } else if ($permalink['type'] == ABJ404_TYPE_404_DISPLAYED) {
        	$permalink['link'] = '404';
        	$permalink['status'] = 'published';
        	
        } else {
            $abj404logging->errorMessage("Unrecognized permalink type: " . 
                    wp_kses_post(json_encode($permalink)));
        }
        
        if ($permalink['status'] === false) {
        	$permalink['status'] = 'trash';
        }
        
        // decode anything that might be encoded to support utf8 characters
        if (array_key_exists('link', $permalink)) {
        	$permalink['link'] = urldecode($permalink['link']);
        }
        $permalink['title'] = array_key_exists('title', $permalink) ? urldecode($permalink['title']) : '';
        
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
        
        ABJ_404_Solution_Functions::safeUnlink($filePath);
        file_put_contents($filePath, fopen($url, 'r'));
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
        
        return urldecode(http_build_query($queryParts));
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
        return urldecode(http_build_query($queryParts));
    }

}

