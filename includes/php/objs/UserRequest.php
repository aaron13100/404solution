<?php

/** Stores a message and its importance. */
class ABJ_404_Solution_UserRequest {
    
    private static $instance = null;
    
    private $requestURIWithoutCommentsPage = null;
    
    /** @var string */
    private $requestURI = null;
    
    /** @var array */
    private $urlParts = null;
    
    /** @var string */
    private $queryString = null;
    
    /** @var string */
    private $commentPagePart = null;
    
    public static function getInstance() {
        if (self::$instance == null) {
            if (!self::initialize()) {
                $abj404logging = ABJ_404_Solution_Logging::getInstance();
                $abj404logging->errorMessage('Issue initializing ' . __CLASS__, 
                        new Exception("Issue initializing ' . __CLASS__"));
            }
        }
        
        return self::$instance;
    }
    
    public static function initialize() {
        global $wp_rewrite;
        
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        $urlToParse = urldecode($_SERVER['REQUEST_URI']);
      	
        // if the user somehow requested an invalid URL that's too long then fix it.
        if ($f->strlen($urlToParse) > ABJ404_MAX_URL_LENGTH) {
        	$matches = null;
        	$f->regexMatch("image (.+);base64,", $urlToParse, $matches);
        	if ($matches != null && $f->strlen($matches[0]) > 0) {
        		$instrPattern = $matches[0];
        		$truncateHere = $f->strpos($urlToParse, $instrPattern);
        		$truncatedRequest = $f->substr($urlToParse, 0, $truncateHere);
        		$urlToParse = $truncatedRequest;
        	}
        	
        	if ($f->strlen($urlToParse) > ABJ404_MAX_URL_LENGTH) {
        		// just truncate it to something reasonable.
        		$urlToParse = $f->substr($urlToParse, 0, ABJ404_MAX_URL_LENGTH);
        	}
        }
        
        // hanlde the case where '///?gf_page=upload' is returned as the request URI.
        $containsHost = $f->strpos($urlToParse, "://");
        
        if (($containsHost === false) || ($containsHost >= 7) || (!is_array(parse_url(esc_url($urlToParse))))) {
            // we have something like //login.php and it needs to be http://host.com/login.php
        	while ($f->strpos($urlToParse, "//") !== false) {
        		$urlToParse = $f->str_replace('//', '/', $urlToParse);
        	}
        	$urlToParse = ltrim($abj404logic->removeHomeDirectory($urlToParse), '/');
            $urlToParse = get_site_url() . '/' . $urlToParse;
        }
        
        $urlParts = parse_url(esc_url($urlToParse));
        if (!is_array($urlParts)) {
            $abj404logging->errorMessage('parse_url returned a non-array value. REQUEST_URI: "' . 
                    urldecode($_SERVER['REQUEST_URI']) . '", parse_url result: "' . json_encode($urlParts) . '", ' .
                    'urlToParse result: ' . $urlToParse);
            return false;
        }
        // make things work with foreign languages while avoiding XSS issues.
        foreach ($urlParts as $key => $value) {
            // Decode only if necessary, then sanitize and encode output
            if ($key === 'query') {
                // For query strings, sanitize each key-value pair
                parse_str($value, $queryArray);
                $safeQueryArray = array_map([$f, 'escapeForXSS'], $queryArray);
                $safeQueryArray = array_map([$f, 'selectivelyURLEncode'], $safeQueryArray);
                $safeQueryArray = array_map('sanitize_text_field', $safeQueryArray);

                $urlParts[$key] = http_build_query($safeQueryArray);
            } else {
                // Sanitize text parts like paths
                $urlParts[$key] = $f->escapeForXSS($value);
                $urlParts[$key] = $f->selectivelyURLEncode($value);
            }
        }
        
        // remove a pointless trailing /amp
        if (
        	($f->endsWithCaseInsensitive($urlParts['path'], '/amp') ||
        	 $f->endsWithCaseInsensitive($urlParts['path'], '/amp/')
        	)
        	&& $f->strlen($urlParts['path']) >= 6) {
        	$urlParts['path'] = substr($urlParts['path'], 0, $f->strlen($urlParts['path']) - 4);
        }
        
        // remove any "/comment-page-???/" if there is one.
        /* tested with:
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/#comment-26
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/?quer=true
         */
        $urlWithoutCommentPage = $urlParts['path'];
        $commentPagePart = '';
        $results = array();
        if (isset($wp_rewrite) && isset($wp_rewrite->comments_pagination_base)) {
        	$commentregex = '(.*)\/(' . $wp_rewrite->comments_pagination_base . '-[0-9]{1,})(\/|\z)?(.*)';
        	$f->regexMatch($commentregex, $urlParts['path'], $results);
        	
        	if (!empty($results)) {
        		$urlWithoutCommentPage = $results[1];
        		$commentPagePart = $results[2];
        		$commentPagePart = ($commentPagePart == '') ? '' : $commentPagePart . '/';
        	}
        }
        
        $queryString = '';
        if (!array_key_exists('query', $urlParts) || @$urlParts['query'] == "") {
            $queryString = '';
        } else {
            $queryString = $urlParts['query'];
        }
        
        self::$instance = new ABJ_404_Solution_UserRequest($urlToParse, $urlParts, $urlWithoutCommentPage, 
                $commentPagePart, $queryString);
            
        return true;
    }
    
    private function __construct($requestURI, $urlParts, $urlWithoutCommentPage, $commentPagePart, $queryString) {
        $this->requestURI = $requestURI;
        $this->urlParts = $urlParts;
        $this->requestURIWithoutCommentsPage = $urlWithoutCommentPage;
        $this->commentPagePart = $commentPagePart;
        
        
        // I didn't use makeQueryStringUnique because I guess it's not necessary and 
        // I'm not sure how it will affect empty query strings like "&noValue=&=noKey"
        $this->queryString = $queryString; //$this->makeQueryStringUnique($queryString);
    }
    
    /** 
     * Turn "page_id=13689?page_id=13689" into "page_id=13689"
     * @param $queryString
     * @return string
     */
    private function makeQueryStringUnique($queryString) {
    	//$paramsArray = array_reverse(explode('?', "page_id=13689?page_id=13689?page_id=13689?page_id=13689?page_id=13689?page_id=1?page_id=13689?page_id=1"));
    	
    	if ($queryString == null || $queryString == '') {
    		return $queryString;
    	}
    	
    	// split on '?'
    	$paramsArray = array_reverse(explode('?', $queryString));
    	
    	if (count($paramsArray) == 0) {
    		return $queryString;
    	}
    	
    	// store the unique values based on the key.
    	$uniqueArray = array();
    	foreach ($paramsArray as $argPair) {
    		$keyValue = explode('=', $argPair);
    		if (count($keyValue) != 2) {
    			continue;
    		}
    		$key = $keyValue[0];
    		$value = $keyValue[1];
    		if (!array_key_exists($key, $uniqueArray)) {
    			$uniqueArray[$key] = $value;
    		}
    	}
    	// correct the order. necessary?
    	$correctedOrder = array_reverse($uniqueArray);
    	
    	// recreate the string with only unique values.
    	$rejoined = '';
    	foreach ($correctedOrder as $key => $value) {
    		if (strlen($rejoined) > 0) {
    			$rejoined .= '?';
    		}
    		$rejoined .= $key . '=' . $value;
    	}
    	
    	return $rejoined;
    }
    
    function getRequestURI() {
        return $this->requestURI;
    }
    
    function getRequestURIWithoutCommentsPage() {
        return $this->requestURIWithoutCommentsPage;
    }

    /**  http://s.com/404solution-site/hello-world/comment-page-2/#comment-26?query_info=true becomes
     * /404solution-site/hello-world/comment-page-2/
     * @return string
     */
    function getPath() {
    	if (!array_key_exists('path', $this->urlParts)) {
    		// this happens for a request with no path. like http://example.com
    		return '';
    	}
    	
        return $this->urlParts['path'] ?? '';
    }
    
    function getPathWithSortedQueryString() {
        $f = ABJ_404_Solution_Functions::getInstance();
        $requestedURL = $this->getPath();
        $urlParts = $f->sortQueryString($this->getUrlParts());
        if ($urlParts != null && trim($urlParts) != '') {
        	$requestedURL .= '?' . $urlParts;
        }
        
        // otherwise various queries break.
        $requestedURL = $f->urlencodeEmojis($requestedURL);

        return $requestedURL ?? '';
    }
    
    /**  http://s.com/404solution-site/hello-world/comment-page-2/#comment-26?query_info=true becomes
     * /hello-world/comment-page-2/
     * @return string
     */
    function getOnlyTheSlug() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $path = $this->getRequestURIWithoutCommentsPage();
        return $abj404logic->removeHomeDirectory($path);
    }

    function getUrlParts() {
        return $this->urlParts;
    }

    function getQueryString() {
        return $this->queryString;
    }

    function getCommentPagePart() {
        return $this->commentPagePart;
    }

}
