<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist) && is_admin()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_Functions {
    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param type $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param type $linkScore
     * @param type $rowType if this is "image" then wp_get_attachment_image_src() is used.
     * @return type an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore, $rowType = null) {
        global $abj404logging;
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
        
        $fileContents = file_get_contents($path);
        if ($fileContents !== false) {
            return $fileContents;
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
        
        return $output;        
    }

    /** Deletes the existing file at $filePath and puts the URL contents in it's place.
     * @global type $abj404logging
     * @param type $url
     * @param type $filePath
     * @return type
     */
    static function readURLtoFile($url, $filePath) {
        global $abj404logging;
        
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
    static function endsWithCaseInsensitive($haystack, $needle) {
        $length = mb_strlen($needle);
        if (mb_strlen($haystack) < $length) {
            return false;
        }
        
        $lowerNeedle = mb_strtolower($needle);
        $lowerHay = mb_strtolower($haystack);
        
        return (mb_substr($lowerHay, -$length) == $lowerNeedle);
    }
}

