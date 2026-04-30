<?php


if (!defined('ABSPATH')) {
    exit;
}

/** Stores a message and its importance. */
class ABJ_404_Solution_UserRequest {
    
    /** @var self|null */
    private static $instance = null;

    /** @var string|null */
    private $requestURIWithoutCommentsPage = null;
    
    /** @var string */
    private $requestURI = null;
    
    /** @var array<string, int|string>|null */
    private $urlParts = null;
    
    /** @var string */
    private $queryString = null;
    
    /** @var string */
    private $commentPagePart = null;
    
    /** @return self|null */
    public static function getInstance() {
        if (self::$instance == null) {
            if (!self::initialize()) {
                $abj404logging = abj_service('logging');
                $abj404logging->errorMessage('Issue initializing ' . __CLASS__, 
                        new Exception('Issue initializing ' . __CLASS__));
            }
        }
        
        return self::$instance;
    }
    
    /** @return bool */
    public static function initialize(): bool {
        global $wp_rewrite;
        
        $abj404logging = abj_service('logging');
        $f = abj_service('functions');
        $abj404logic = abj_service('plugin_logic');
        
        $urlToParse = $f->normalizeUrlString($_SERVER['REQUEST_URI']);
      	
        // if the user somehow requested an invalid URL that's too long then fix it.
        if ($f->strlen($urlToParse) > ABJ404_MAX_URL_LENGTH) {
        	$matches = null;
        	$f->regexMatch("image (.+);base64,", $urlToParse, $matches);
        	if ($matches != null && $f->strlen($matches[0]) > 0) {
        		$instrPattern = $matches[0];
        		$truncateHere = $f->strpos($urlToParse, $instrPattern);
        		$truncatedRequest = $f->substr($urlToParse, 0, ($truncateHere !== false ? $truncateHere : null));
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
        
        $urlParts = parse_url($urlToParse);
        if (!is_array($urlParts)) {
            $abj404logging->errorMessage('parse_url returned a non-array value. REQUEST_URI: "' . 
                    $f->normalizeUrlString($_SERVER['REQUEST_URI']) . '", parse_url result: "' . json_encode($urlParts) . '", ' .
                    'urlToParse result: ' . $urlToParse);
            return false;
        }
        // make things work with foreign languages while avoiding XSS issues.
        foreach ($urlParts as $key => $value) {
            if ($key === 'query') {
                // For query strings, preserve reserved characters while removing invalid bytes.
                parse_str($value, $queryArray);
                $safeQueryArray = $f->sanitizeUrlComponent($queryArray);
                $urlParts[$key] = http_build_query(is_array($safeQueryArray) ? $safeQueryArray : $queryArray);
            } else {
                // Sanitize path/host/etc. without stripping reserved URL characters.
                $urlParts[$key] = $f->sanitizeUrlComponent($value);
            }
        }

        // remove a pointless trailing /amp
        $urlPath = isset($urlParts['path']) && is_string($urlParts['path']) ? $urlParts['path'] : '';
        if ($urlPath !== '' &&
        	($f->endsWithCaseInsensitive($urlPath, '/amp') ||
        	 $f->endsWithCaseInsensitive($urlPath, '/amp/')
        	)
        	&& $f->strlen($urlPath) >= 6) {
        	$urlParts['path'] = $f->substr($urlPath, 0, $f->strlen($urlPath) - 4);
        }
        
        // remove any "/comment-page-???/" if there is one.
        /* tested with:
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/#comment-26
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2
         * http://localhost:8888/404solution-site/2019/02/hello-world2/comment-page-2/?quer=true
         */
        // Fix for PHP 8.2: Handle URLs with no path component (e.g., http://example.com)
        $urlWithoutCommentPage = (isset($urlParts['path']) && is_string($urlParts['path'])) ? $urlParts['path'] : '/';
        $commentPagePart = '';
        $results = array();
        if (isset($wp_rewrite) && isset($wp_rewrite->comments_pagination_base)) {
        	$safeBase = preg_quote($wp_rewrite->comments_pagination_base);
        	$commentregex = '(.*)\/(' . $safeBase . '-[0-9]{1,})(\/|\z)?(.*)';
        	$f->regexMatch($commentregex, $urlWithoutCommentPage, $results);
        	
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
        
        /** @var array<string, int|string> $urlPartsSafe */
        $urlPartsSafe = $urlParts;
        self::$instance = new ABJ_404_Solution_UserRequest($urlToParse, $urlPartsSafe, $urlWithoutCommentPage,
                $commentPagePart, $queryString);
            
        return true;
    }
    
    /**
     * @param string $requestURI
     * @param array<string, int|string> $urlParts
     * @param string $urlWithoutCommentPage
     * @param string $commentPagePart
     * @param string $queryString
     */
    private function __construct(string $requestURI, array $urlParts, string $urlWithoutCommentPage, string $commentPagePart, string $queryString) {
        $this->requestURI = $requestURI;
        $this->urlParts = $urlParts;
        $this->requestURIWithoutCommentsPage = $urlWithoutCommentPage;
        $this->commentPagePart = $commentPagePart;
        
        $this->queryString = $queryString;
    }
 
    /** @return string|null */
    function getRequestURI() {
        return $this->requestURI;
    }
    
    /** @return string|null */
    function getRequestURIWithoutCommentsPage() {
        return $this->requestURIWithoutCommentsPage;
    }

    /**  http://s.com/404solution-site/hello-world/comment-page-2/#comment-26?query_info=true becomes
     * /404solution-site/hello-world/comment-page-2/
     * @return string
     */
    function getPath() {
    	if ($this->urlParts === null || !array_key_exists('path', $this->urlParts)) {
    		// this happens for a request with no path. like http://example.com
    		return '';
    	}

        return (string)($this->urlParts['path']);
    }
    
    /** @return string */
    function getPathWithSortedQueryString(): string {
        $f = abj_service('functions');
        $requestedURL = $this->getPath();
        /** @var array<string, string> $urlPartsForSort */
        $urlPartsForSort = $this->getUrlParts() ?? array();
        $urlParts = $f->sortQueryString($urlPartsForSort);
        if ($urlParts != null && trim($urlParts) != '') {
        	$requestedURL .= '?' . $urlParts;
        }
        
        // otherwise various queries break.
        $requestedURL = $f->urlencodeEmojis($requestedURL);

        return $requestedURL;
    }
    
    /**  http://s.com/404solution-site/hello-world/comment-page-2/#comment-26?query_info=true becomes
     * /hello-world/comment-page-2/
     * @return string
     */
    function getOnlyTheSlug() {
        $abj404logic = abj_service('plugin_logic');
        $path = $this->getRequestURIWithoutCommentsPage();
        return $abj404logic->removeHomeDirectory($path);
    }

    /** @return array<string, int|string>|null */
    function getUrlParts() {
        return $this->urlParts;
    }

    /** @return string|null */
    function getQueryString() {
        return $this->queryString;
    }

    /** @return string|null */
    function getCommentPagePart() {
        return $this->commentPagePart;
    }

}
