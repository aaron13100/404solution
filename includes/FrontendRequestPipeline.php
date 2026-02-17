<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend request pipeline for 404 processing and redirects.
 *
 * Keeps runtime flow isolated from admin hook wiring.
 */
class ABJ_404_Solution_FrontendRequestPipeline {

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    function __construct($pluginLogic, $dataAccess, $logging, $functions, $spellChecker) {
        $this->logic = $pluginLogic;
        $this->dao = $dataAccess;
        $this->logger = $logging;
        $this->f = $functions;
        $this->spellChecker = $spellChecker;
    }

    /** @return mixed */
    private function callWpFunction($name, $args = array(), $default = null) {
        if (!function_exists($name)) {
            return $default;
        }
        return call_user_func_array($name, $args);
    }

    private function wpTypePost() {
        return defined('ABJ404_TYPE_POST') ? constant('ABJ404_TYPE_POST') : 1;
    }

    /**
     * Emit benchmark header immediately for paths that may not reach WordPress send_headers.
     *
     * @return void
     */
    private function emitBenchmarkHeadersIfEnabled() {
        if (function_exists('abj404_benchmark_emit_headers')) {
            abj404_benchmark_emit_headers();
        }
    }

    /**
     * @param float $startTime
     * @return void
     */
    private function recordRedirectLookupTiming($startTime) {
        if (!function_exists('abj404_benchmark_record_redirect_lookup')) {
            return;
        }
        $elapsedMs = (microtime(true) - (float)$startTime) * 1000.0;
        abj404_benchmark_record_redirect_lookup($elapsedMs);
    }

    function processRedirectAllRequests() {
        $options = $this->logic->getOptions();

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        $pathOnly = $userRequest->getPath();
        $urlSlugOnly = $userRequest->getOnlyTheSlug();

        $this->logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        $requestedURL = $userRequest->getPathWithSortedQueryString();

        $this->tryRegexRedirect($options, $requestedURL);

        if (is_admin() || !is_404()) {
            $this->logger->warn("If REDIRECT_ALL_REQUESTS is turned on then a regex redirect must be in place.");
        }
    }

    /**
     * Process the 404 path.
     */
    function process404() {
        if (!is_404() || is_admin()) {
            return;
        }

        $_REQUEST[ABJ404_PP]['process_start_time'] = microtime(true);
        $userRequest = ABJ_404_Solution_UserRequest::getInstance();

        $pathOnly = $userRequest->getPath();
        $urlSlugOnly = $userRequest->getOnlyTheSlug();
        $this->logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);

        if ($_REQUEST[ABJ404_PP]['ignore_donotprocess']) {
            $this->dao->logRedirectHit($pathOnly, '404', 'ignore_donotprocess');
            $this->emitBenchmarkHeadersIfEnabled();
            return;
        }

        $requestedURL = $userRequest->getPathWithSortedQueryString();
        $requestedURLWithoutComments = $requestedURL;
        if ($this->f->strpos($requestedURL, '/comment-page-') !== false) {
            $requestedURLWithoutComments = $userRequest->getRequestURIWithoutCommentsPage();
        }

        $lookupStart = microtime(true);
        $redirect = $this->dao->getActiveRedirectForURL($requestedURL);
        $this->recordRedirectLookupTiming($lookupStart);
        $options = $this->logic->getOptions();
        $this->logAReallyLongDebugMessage($options, $requestedURL, $redirect);

        if ($requestedURL != "") {
            if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                $this->processRedirect($requestedURL, $redirect, 'existing');
                exit;
            }

            if ($requestedURLWithoutComments != $requestedURL) {
                $lookupStart = microtime(true);
                $redirect = $this->dao->getActiveRedirectForURL($requestedURLWithoutComments);
                $this->recordRedirectLookupTiming($lookupStart);
                if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                    $this->processRedirect($requestedURL, $redirect, 'existing');
                    exit;
                }
            }

            $sentTo404Page = $this->tryRegexRedirect($options, $requestedURL);
            if ($sentTo404Page) {
                $this->emitBenchmarkHeadersIfEnabled();
                return;
            }

            $autoRedirectsAreOn = !array_key_exists('auto_redirects', $options) || $options['auto_redirects'] == '1';

            if ($autoRedirectsAreOn) {
                $slugPermalink = $this->spellChecker->getPermalinkUsingSlug($urlSlugOnly);
                if (!empty($slugPermalink)) {
                    $redirectType = $slugPermalink['type'];
                    $this->dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $slugPermalink['id'], $options['default_redirect'], 0);

                    $this->dao->logRedirectHit($requestedURL, $slugPermalink['link'], 'exact slug');
                    $this->logic->forceRedirect(esc_url($slugPermalink['link']), esc_html($options['default_redirect']));
                    exit;
                }
            }

            if (!$autoRedirectsAreOn) {
                $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
                $this->emitBenchmarkHeadersIfEnabled();
                $this->logic->sendTo404Page($requestedURL, 'Do not create redirects per the options.', true, $options);
                return;
            }

            if (!$this->shouldSkipSpellingLookup($urlSlugOnly)) {
                $permalink = $this->spellChecker->getPermalinkUsingSpelling($urlSlugOnly, $requestedURL, $options);
                if (!empty($permalink)) {
                    $redirectType = $permalink['type'];
                    $this->dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $permalink['id'], $options['default_redirect'], 0);

                    $this->dao->logRedirectHit($requestedURL, $permalink['link'], 'spell check');
                    $this->logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                    exit;
                }
            }
        } else {
            if ($this->callWpFunction('is_single', array(), false) || $this->callWpFunction('is_page', array(), false)) {
                if (!$this->callWpFunction('is_feed', array(), false) &&
                        !$this->callWpFunction('is_trackback', array(), false) &&
                        !$this->callWpFunction('is_preview', array(), false)) {
                    $theID = $this->callWpFunction('get_the_ID', array(), 0);
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|" . $this->wpTypePost(), 0, null, $options);

                    $urlParts = parse_url($permalink['link']);
                    $perma_link = $urlParts['path'];

                    $pageQueryVar = $this->callWpFunction('get_query_var', array('page'), false);
                    $paged = $pageQueryVar ? esc_html($pageQueryVar) : false;
                    if (!$paged === false) {
                        if ($urlParts['query'] == "") {
                            if ($this->f->substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        } else {
                            $urlParts['query'] .= "&page=" . $paged;
                        }
                    }

                    $perma_link .= $this->f->sortQueryString($urlParts);

                    if (@$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $this->processRedirect($requestedURL, $redirect, 'single page 3');
                            } else {
                                $this->dao->setupRedirect(esc_url($requestedURL), ABJ404_STATUS_AUTO, $this->wpTypePost(), $permalink['id'], $options['default_redirect'], 0);
                                $this->dao->logRedirectHit($requestedURL, $permalink['link'], 'single page');
                                $this->logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $this->dao->deleteRedirect($redirect['id']);
                            }
                        }
                    }
                }
            }
        }

        $this->logic->tryNormalPostQuery($options);
        $this->dao->logRedirectHit($requestedURL, '404', 'gave up.');
        $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
        $this->emitBenchmarkHeadersIfEnabled();
        $this->logic->sendTo404Page($requestedURL, '', true, $options);
    }

    /**
     * Skip expensive spelling lookup for URL shapes that are very unlikely to be useful typo-corrections.
     *
     * @param string $urlSlugOnly
     * @return bool
     */
    private function shouldSkipSpellingLookup($urlSlugOnly) {
        if (!is_string($urlSlugOnly) || $urlSlugOnly === '') {
            return true;
        }

        $segments = array_values(array_filter(explode('/', $urlSlugOnly)));
        if (count($segments) === 0) {
            return true;
        }

        $lastSegment = (string)end($segments);
        $segmentLength = strlen($lastSegment);

        // Long tokenized slugs with many separators/numeric chunks are usually tracking or synthetic IDs.
        if ($segmentLength > 80) {
            return true;
        }
        if (substr_count($lastSegment, '-') >= 6) {
            return true;
        }
        if (preg_match('/\d{4,}/', $lastSegment)) {
            return true;
        }
        if (!preg_match('/[a-zA-Z]/', $lastSegment)) {
            return true;
        }

        return false;
    }

    /**
     * @return bool True if sent to configured default 404 page.
     */
    function tryRegexRedirect($options, $requestedURL) {
        $lookupStart = microtime(true);
        $regexPermalink = $this->spellChecker->getPermalinkUsingRegEx($requestedURL, $options);
        $this->recordRedirectLookupTiming($lookupStart);
        if (!empty($regexPermalink)) {
            $this->dao->logRedirectHit($regexPermalink['matching_regex'], $regexPermalink['link'], 'regex match', $requestedURL);
            $sentTo404Page = $this->logic->forceRedirect(
                $regexPermalink['link'],
                esc_html($options['default_redirect']),
                $regexPermalink['type'],
                $requestedURL
            );
            if ($sentTo404Page) {
                return true;
            }
            exit;
        }
        return false;
    }

    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
        if (!$this->logger->isDebug()) {
            return;
        }

        $debugOptionsMsg = esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' .
                $options['auto_score'] . ', template_redirect_priority: ' . $options['template_redirect_priority'] .
                ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
                $options['auto_tags'] . ', dest404page: ' . $options['dest404page']);

        $remoteAddress = esc_sql($_SERVER['REMOTE_ADDR']);
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $remoteAddress = $this->f->md5lastOctet($remoteAddress);
        }

        $httpUserAgent = "";
        if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
            $httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $httpUserAgent . ', REMOTE_ADDR: ' .
                $remoteAddress . ', REQUEST_URI: ' . $this->f->normalizeUrlString($_SERVER['REQUEST_URI']));
        $isSingle = $this->callWpFunction('is_single', array(), false);
        $isPage = $this->callWpFunction('is_page', array(), false);
        $isFeed = $this->callWpFunction('is_feed', array(), false);
        $isTrackback = $this->callWpFunction('is_trackback', array(), false);
        $isPreview = $this->callWpFunction('is_preview', array(), false);
        $this->logger->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
                wp_kses_post(json_encode($redirect)) . " | is_single(): " . $isSingle . " | " . "is_page(): " . $isPage .
                " | is_feed(): " . $isFeed . " | is_trackback(): " . $isTrackback . " | is_preview(): " .
                $isPreview . " | options: " . $debugOptionsMsg . ', ' . $debugServerMsg);
    }

    /**
     * Redirect to destination.
     *
     * @return bool true if user is sent to default 404 page.
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {
        if (($redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO) || $redirect['disabled'] != 0) {
            $this->logger->errorMessage("processRedirect() was called with bad redirect data. Data: " .
                    wp_kses_post(print_r($redirect, true)));
        }

        if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
            $this->dao->logRedirectHit($redirect['url'], '404', $matchReason);
            $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
            $this->emitBenchmarkHeadersIfEnabled();
            $this->logic->sendTo404Page($requestedURL, $matchReason);
            return true;
        }

        $isRedirectToCustom404Page = false;
        if ($redirect['type'] == $this->wpTypePost()) {
            $options = $this->logic->getOptions();
            $dest404page = isset($options['dest404page']) ? $options['dest404page'] : null;

            if ($dest404page !== null && $this->logic->thereIsAUserSpecified404Page($dest404page)) {
                $dest404Parts = explode('|', $dest404page);
                $custom404Id = isset($dest404Parts[0]) ? (int)$dest404Parts[0] : 0;
                if ($custom404Id > 0 && $redirect['final_dest'] == $custom404Id) {
                    $isRedirectToCustom404Page = true;
                }
            }

            if (!$isRedirectToCustom404Page) {
                $destPage = $this->callWpFunction('get_post', array($redirect['final_dest']), null);
                $hasShortcode = ($destPage && isset($destPage->post_content))
                    ? $this->callWpFunction('has_shortcode', array($destPage->post_content, ABJ404_SHORTCODE_NAME), false)
                    : false;
                if ($hasShortcode) {
                    $isRedirectToCustom404Page = true;
                }
            }
        }

        if ($isRedirectToCustom404Page) {
            $this->logic->setCookieWithPreviousRequest();
            setcookie(ABJ404_PP . '_STATUS_404', 'true', time() + 20, "/");

            $urlSlugOnly = $this->logic->removeHomeDirectory($requestedURL);
            $spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
            $options = $this->logic->getOptions();
            $spellChecker->findMatchingPosts($urlSlugOnly, @$options['suggest_cats'], @$options['suggest_tags']);
            $spellChecker->triggerAsyncSuggestionComputation($requestedURL);
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $this->dao->logRedirectHit($redirect['url'], $redirect['final_dest'], 'external');
            $this->logic->forceRedirect($redirect['final_dest'], esc_html($redirect['code']));
            exit;
        }

        // Guard against broken redirects with missing/invalid destinations.
        $finalDestRaw = isset($redirect['final_dest']) ? trim((string)$redirect['final_dest']) : '';
        if ($finalDestRaw === '' && (int)$redirect['type'] !== ABJ404_TYPE_HOME && (int)$redirect['type'] !== ABJ404_TYPE_404_DISPLAYED) {
            $this->logger->warn("Redirect destination missing. Sending request to 404 page instead. Redirect ID: " . ($redirect['id'] ?? 'unknown'));
            $this->dao->logRedirectHit($redirect['url'], '404', $matchReason . ' (missing destination)');
            $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
            $this->emitBenchmarkHeadersIfEnabled();
            $this->logic->sendTo404Page($requestedURL, 'missing redirect destination');
            return true;
        }

        $key = $redirect['final_dest'] . "|" . $redirect['type'];
        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);

        $finalLink = (is_array($permalink) && array_key_exists('link', $permalink))
            ? $permalink['link']
            : '';
        if (!is_string($finalLink) || trim($finalLink) === '' || $finalLink === 'dunno') {
            $this->logger->warn("Resolved permalink is empty/invalid. Sending request to 404 page instead. Redirect ID: " . ($redirect['id'] ?? 'unknown'));
            $this->dao->logRedirectHit($redirect['url'], '404', $matchReason . ' (invalid destination)');
            $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
            $this->emitBenchmarkHeadersIfEnabled();
            $this->logic->sendTo404Page($requestedURL, 'invalid redirect destination');
            return true;
        }

        $redirectedTo = esc_url($finalLink);
        $urlParts = parse_url($redirectedTo);
        if (is_array($urlParts) && array_key_exists('path', $urlParts)) {
            $redirectedTo = $urlParts['path'];
        }

        $this->dao->logRedirectHit($redirect['url'], $redirectedTo, $matchReason);

        $sendTo404Page = $this->logic->forceRedirect(
            $finalLink,
            esc_html($redirect['code']),
            -1,
            $requestedURL,
            $isRedirectToCustom404Page
        );

        if ($sendTo404Page) {
            return true;
        }
        exit;
    }

    /**
     * Trigger async suggestion computation only when needed.
     */
    private function triggerAsyncSuggestionsIfNeeded($requestedURL) {
        if ($this->spellChecker->does404PageHaveSuggestionsShortcode()) {
            $this->spellChecker->triggerAsyncSuggestionComputation($requestedURL);
        }
    }
}
