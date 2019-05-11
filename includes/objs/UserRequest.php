<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/** Stores a message and its importance. */
class ABJ_404_Solution_UserRequest implements JsonSerializable {
    
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
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        
        // hanlde the case where '///?gf_page=upload' is returned as the request URI.
        $urlToParse = urldecode($_SERVER['REQUEST_URI']);
        $containsHost = $f->strpos($urlToParse, "://");
        
        if (($containsHost === false) || ($containsHost >= 7) || (!is_array(parse_url(esc_url($urlToParse))))) {
            // we have something like //login.php and it needs to be http://host.com/login.php
            $urlToParse = str_replace('//', '/', $urlToParse);
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
        // make things work with foreign languages.
        foreach ($urlParts as $key => $value) {
            $urlParts[$key] = urldecode($value);
        }
        
        // remove a pointless trailing /amp
        if ($f->endsWithCaseInsensitive($urlParts['path'], '/amp') && 
        		$f->strlen($urlParts['path']) >= 6) {
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
        $commentregex = '(.*)\/(' . $wp_rewrite->comments_pagination_base . '-[0-9]{1,})(\/|\z)?(.*)';
        $f->regexMatch($commentregex, $urlParts['path'], $results);
        
        if (count($results) > 0) {
            $urlWithoutCommentPage = $results[1];
            $commentPagePart = $results[2];
            $commentPagePart = ($commentPagePart == '') ? '' : $commentPagePart . '/';
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
        $this->queryString = $queryString;
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
        return $this->urlParts['path'];
    }
    
    function getPathWithSortedQueryString() {
        $f = ABJ_404_Solution_Functions::getInstance();
        $requestedURL = $this->getPath();
        $requestedURL .= $f->sortQueryString($this->getUrlParts());

        return $requestedURL;
    }
    
    /**  http://s.com/404solution-site/hello-world/comment-page-2/#comment-26?query_info=true becomes
     * /hello-world/comment-page-2/
     * @return string
     */
    function getOnlyTheSlug() {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
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

    public function jsonSerialize() {
        return get_object_vars($this);
    }

}
