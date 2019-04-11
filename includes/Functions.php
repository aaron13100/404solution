<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_Functions {
    
    /** Use this to find a delimiter. 
     * @var array */
    private $delimiterChars = array('`', '^', '|', '~', '!', ';', ':', ',', '@', "'", '/');
    
    /**  Used with array_filter()
     * @param type $value
     * @return boolean
     */
    function trimAndRemoveEmpty($value) {
        if ($value == null) {
            return false;
        }
        $value = trim($value);
        return $value !== '';
    }
    
    function getExecutionTime() {
        if (array_key_exists(ABJ404_PP, $_REQUEST) && 
                array_key_exists('process_start_time', $_REQUEST[ABJ404_PP])) {
            $elapsedTime = microtime(true) - $_REQUEST[ABJ404_PP]['process_start_time'];
            
            return $elapsedTime;
        }
        
        return '';
    }
    
    function strtolower($string) {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($string);
        }
        
        return strtolower($string);
    }
    
    function strlen($string) {
        if (function_exists('mb_strlen')) {
            return mb_strlen($string);
        }
        
        return strlen($string);
    }
    
    function strpos($haystack, $needle, $offset = 0) {
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, $offset);
        }
        
        if ($offset == 0) {
            return strpos($haystack, $needle);
        }
        return strpos($haystack, $needle, $offset);
    }
    
    function substr($str, $start, $length = null) {
        if (function_exists('mb_substr')) {
            return mb_substr($str, $start, $length);
        }
        
        if ($length == null) {
            return substr($str, $start);
        }
        return substr($str, $start, $length);
    }

    function regexMatch($pattern, $string, &$regs = null) {
        if (function_exists('mb_ereg')) {
            return mb_ereg($pattern, $string, $regs);
        }
        
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_match($delimiterA . $pattern . $delimiterB, $string, $regs);
    }
    
    function regexMatchi($pattern, $string, &$regs = null) {
        if (function_exists('mb_ereg')) {
            return mb_eregi($pattern, $string, $regs);
        }
        
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_match($delimiterA . $pattern . $delimiterB . 'i', $string, $regs);
    }
    
    /**  Replace regular expression with multibyte support.
     * Scans string for matches to pattern, then replaces the matched text with replacement.
     * @param type $pattern The regular expression pattern.
     * @param type $replacement The replacement text.
     * @param type $string The string being checked.
     * @return type The resultant string on success, or FALSE on error.
     */
    function regexReplace($pattern, $replacement, $string) {
        if (function_exists('mb_ereg')) {
            return mb_ereg_replace($pattern, $replacement, $string);
        }
        
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        $replacementDelimiter = $this->findADelimiter($replacement);
        $replacement = preg_replace($replacementDelimiter . '\\\\' . $replacementDelimiter, '\$', $replacement);
        return preg_replace($delimiterA . $pattern . $delimiterB, $replacement, $string);
    }
    
    function regexSplit($pattern, $subject) {
        if (function_exists('mb_split')) {
            return mb_split($pattern, $subject);
        }
        
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_split($delimiterA . $pattern . $delimiterB, $subject);
    }
    
    function findADelimiter($pattern) {
        if ($pattern == '') {
            return $this->delimiterChars[0];
        }
        
        $charToUse = null;
        foreach ($this->delimiterChars as $char) {
            $anArray = explode($char, $pattern);
            if (sizeof($anArray) == 1) {
                $charToUse = $char;
                break;
            }
        }
        
        if ($charToUse == null) {
            throw new Exception("I can't find a valid delimiter character to use for the regular expression: "
                    . $pattern);
        }
        
        return $charToUse;
    }
    
    
    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param type $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param type $linkScore
     * @param type $rowType if this is "image" then wp_get_attachment_image_src() is used.
     * @return type an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore, $rowType = null) {
        $abj404logging = new ABJ_404_Solution_Logging();
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
     * @param type $path
     * @return boolean
     */
    static function safeUnlink($path) {
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }
    
    /** Returns true if the file does not exist after calling this method. 
     * @param type $path
     * @return boolean
     */
    static function safeRmdir($path) {
        if (file_exists($path)) {
            return rmdir($path);
        }
        return true;
    }
    
    /** Reads an entire file at once into a string and return it.
     * @param type $path
     * @return type
     * @throws Exception
     */
    static function readFileContents($path) {
        if (!file_exists($path)) {
            throw new Exception("Error: Can't find file: " . $path);
        }
        
        $extraInfo = "";
        $file_parts = pathinfo($path);
        if ($file_parts['extension'] == "sql") {
            $extraInfo = "\n/* Loaded file name: " . $path . "*/\n";
        }
        
        $fileContents = file_get_contents($path);
        if ($fileContents !== false) {
            return $fileContents . $extraInfo;
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
        
        return $output . $extraInfo;        
    }

    /** Deletes the existing file at $filePath and puts the URL contents in it's place.
     * @global type $abj404logging
     * @param type $url
     * @param type $filePath
     * @return type
     */
    static function readURLtoFile($url, $filePath) {
        $abj404logging = new ABJ_404_Solution_Logging();
        
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
     * @param type $haystack
     * @param type $needle
     * @return type
     */
    function endsWithCaseInsensitive($haystack, $needle) {
        $f = new ABJ_404_Solution_Functions();
        $length = $f->strlen($needle);
        if ($f->strlen($haystack) < $length) {
            return false;
        }
        
        $lowerNeedle = $this->strtolower($needle);
        $lowerHay = $this->strtolower($haystack);
        
        return ($f->substr($lowerHay, -$length) == $lowerNeedle);
    }
}

