<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

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
    
    abstract function strtolower($string);
    
    abstract function strlen($string);
    
    abstract function strpos($haystack, $needle, $offset = 0);
    
    abstract function substr($str, $start, $length = null);

    abstract function regexMatch($pattern, $string, &$regs = null);
    
    abstract function regexMatchi($pattern, $string, &$regs = null);
    
    abstract function regexReplace($pattern, $replacement, $string);
    
    abstract function regexSplit($pattern, $subject);
    
    
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
        $text = str_replace(array_keys($knownReplacements), array_values($knownReplacements), $text);
        
        // Find the strings to replace in the content.
        $re = '/\{(.+?)\}/x';
        $stringsToReplace = array();
        // TODO does this need to be $f->regexMatch?
        preg_match_all($re, $text, $stringsToReplace, PREG_PATTERN_ORDER);

        // Iterate through each string to replace.
        foreach ($stringsToReplace[1] as $stringToReplace) {
            $text = str_replace('{' . $stringToReplace . '}', 
                    __($stringToReplace, '404-solution'), $text);
        }
        
        return $text;
    }

    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param string $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param int $linkScore
     * @param string $rowType if this is "image" then wp_get_attachment_image_src() is used.
     * @return array an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore, $rowType = null) {
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

        if ($permalink['type'] == ABJ404_TYPE_POST) {
            if ($rowType == 'image') {
                $imageURL = wp_get_attachment_image_src($permalink['id'], "attached-image");
                $permalink['link'] = $imageURL[0];
            } else {
                $permalink['link'] = get_permalink($permalink['id']);
            }
            $permalink['title'] = get_the_title($permalink['id']);
            
        } else if ($permalink['type'] == ABJ404_TYPE_TAG) {
            $permalink['link'] = get_tag_link($permalink['id']);
            $tag = get_term($permalink['id'], 'post_tag');
            if ($tag != null) {
                $permalink['title'] = $tag->name;
            } else {
                $permalink['title'] = $permalink['link'];
            }
            
        } else if ($permalink['type'] == ABJ404_TYPE_CAT) {
            $permalink['link'] = get_category_link($permalink['id']);
            $cat = get_term($permalink['id'], 'category');
            if ($cat != null) {
                $permalink['title'] = $cat->name;
            } else {
                $permalink['title'] = $permalink['link'];
            }
            
        } else if ($permalink['type'] == ABJ404_TYPE_HOME) {
            $permalink['link'] = get_home_url();
            $permalink['title'] = get_bloginfo('name');
            
        } else if ($permalink['type'] == ABJ404_TYPE_EXTERNAL) {
            $permalink['link'] = $permalink['id'];
            
        } else {
            $abj404logging->errorMessage("Unrecognized permalink type: " . 
                    wp_kses_post(json_encode($permalink)));
        }
        
        // decode anything that might be encoded to support utf8 characters
        $permalink['link'] = urldecode($permalink['link']);
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
    
    /** Reads an entire file at once into a string and return it.
     * @param string $path
     * @return string
     * @throws Exception
     */
    static function readFileContents($path) {
        // modify what's returned to make debugging easier.
        $dataSupplement = self::getDataSupplement($path);
        
        if (!file_exists($path)) {
            throw new Exception("Error: Can't find file: " . $path);
        }
        
        $fileContents = file_get_contents($path);
        if ($fileContents !== false) {
            return $dataSupplement['prefix'] . $fileContents . $dataSupplement['suffix'];
        }
        
        // if we can't read the file that way then try curl.
        if (!function_exists('curl_init')) {
            throw new Exception("Error: Can't read file: " . $path .
                    "\n   file_get_contents didn't work and curl is not installed.");
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'file://' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        
        if ($output == null) {
            throw new Exception("Error: Can't read file, even with cURL: " . $path);
        }
        
        return $dataSupplement['prefix'] . $output . $dataSupplement['suffix'];
    }

    private static function getDataSupplement($filePath) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $path = strtolower($filePath);
        $supplement = array();
        if ($f->endsWithCaseInsensitive($path, '.sql')) {
            $supplement['prefix'] = "/* ------------------ " . $filePath . " BEGIN ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END ----- */ \n";
            
        } else if ($f->endsWithCaseInsensitive($path, '.html')) {
            $supplement['prefix'] = "<!-- ------------------ " . $filePath . " BEGIN ----- --> \n";
            $supplement['suffix'] = "\n<!-- ------------------ " . $filePath . " END ----- --> \n";
            
        } else {
            $supplement['prefix'] = "/* ------------------ " . $filePath . " BEGIN unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
        }
        
        return $supplement;
    }
    
    /** Deletes the existing file at $filePath and puts the URL contents in it's place.
     * @global type $abj404logging
     * @param string $url
     * @param string $filePath
     */
    static function readURLtoFile($url, $filePath) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        ABJ_404_Solution_Functions::safeUnlink($filePath);

        // if we can't read the file that way then try curl.
        if (function_exists('curl_init')) {
            try {
                //This is the file where we save the information
                $destinationFileWriteHandle = fopen($filePath, 'w+');
                //Here is the file we are downloading, replace spaces with %20
                $ch = curl_init(str_replace(" ", "%20", $url));
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

