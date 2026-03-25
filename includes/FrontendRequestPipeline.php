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

    /** @var array<int, mixed> Engines from apply_filters — may contain non-engine items */
    private $matchingEngines;

    /** @var array<int, array{step: string, outcome: string, detail: string}> */
    private $trace = [];

    /**
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_DataAccess $dataAccess
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param array<int, mixed> $matchingEngines
     */
    function __construct($pluginLogic, $dataAccess, $logging, $functions, $spellChecker, array $matchingEngines = []) {
        $this->logic = $pluginLogic;
        $this->dao = $dataAccess;
        $this->logger = $logging;
        $this->f = $functions;
        $this->spellChecker = $spellChecker;
        $this->matchingEngines = $matchingEngines;
    }

    /**
     * @param string $name
     * @param array<int, mixed> $args
     * @param mixed $default
     * @return mixed
     */
    private function callWpFunction($name, $args = array(), $default = null) {
        if (!function_exists($name)) {
            return $default;
        }
        return call_user_func_array($name, $args);
    }

    /** @return int */
    private function wpTypePost() {
        return defined('ABJ404_TYPE_POST') ? constant('ABJ404_TYPE_POST') : 1;
    }

    /**
     * @param string $step
     * @param string $outcome
     * @param string $detail
     * @return void
     */
    private function addTraceStep(string $step, string $outcome, string $detail = ''): void {
        $this->trace[] = ['step' => $step, 'outcome' => $outcome, 'detail' => $detail];
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

    /** @return void */
    function processRedirectAllRequests() {
        $this->trace = [];
        $options = $this->logic->getOptions();

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            return;
        }
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
     * @return void
     */
    function process404() {
        if (!is_404() || is_admin()) {
            return;
        }

        $_REQUEST[ABJ404_PP]['process_start_time'] = microtime(true);
        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            return;
        }

        $pathOnly = $userRequest->getPath();
        $urlSlugOnly = $userRequest->getOnlyTheSlug();
        $this->logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        $this->trace = [];

        if ($_REQUEST[ABJ404_PP]['ignore_donotprocess']) {
            $this->addTraceStep('ignore_check', 'skipped: do-not-process');
            $this->dao->logRedirectHit($pathOnly, '404', 'ignore_donotprocess', null, $this->trace);
            $this->emitBenchmarkHeadersIfEnabled();
            return;
        }
        $this->addTraceStep('ignore_check', 'passed');

        $requestedURL = $userRequest->getPathWithSortedQueryString();
        $requestedURLWithoutComments = $requestedURL;
        if ($this->f->strpos($requestedURL, '/comment-page-') !== false) {
            $withoutComments = $userRequest->getRequestURIWithoutCommentsPage();
            if (is_string($withoutComments)) {
                $requestedURLWithoutComments = $withoutComments;
            }
        }

        $lookupStart = microtime(true);
        $redirect = $this->dao->getActiveRedirectForURL($requestedURL);
        $this->recordRedirectLookupTiming($lookupStart);
        $options = $this->logic->getOptions();
        $this->logAReallyLongDebugMessage($options, $requestedURL, $redirect);

        if ($requestedURL != "") {
            // A redirect is actionable when it has an id AND either has a real destination
            // (final_dest != '0') OR is a homepage redirect (TYPE_HOME always uses final_dest=0).
            $typeHomeInt = defined('ABJ404_TYPE_HOME') ? (int)ABJ404_TYPE_HOME : 5;
            $redirectTypeInt1 = isset($redirect['type']) && is_scalar($redirect['type']) ? (int)$redirect['type'] : 0;
            if ($redirect['id'] != '0' && ($redirect['final_dest'] != '0' || $redirectTypeInt1 === $typeHomeInt)) {
                $this->addTraceStep('existing_redirect_lookup', 'found', 'id=' . (is_scalar($redirect['id']) ? (string)$redirect['id'] : '?'));
                $deadIds = function_exists('get_transient') ? get_transient('abj404_dead_dest_ids') : false;
                $redirectIdStr = isset($redirect['id']) && is_scalar($redirect['id']) ? (string) $redirect['id'] : '0';
                if (!is_array($deadIds) || !in_array($redirectIdStr, $deadIds, true)) {
                    $condEvaluator = new ABJ_404_Solution_RedirectConditionEvaluator($this->dao);
                    $redirectIdForCond = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                    if ($condEvaluator->shouldApplyRedirect($redirectIdForCond)) {
                        $this->addTraceStep('condition_eval', 'conditions passed');
                        $this->processRedirect($requestedURL, $redirect, 'existing');
                        exit;
                    }
                    // Conditions not met — fall through as if no redirect found.
                    $condTrace = $condEvaluator->getLastEvaluationTrace();
                    $condDetail = implode(', ', array_map(function ($c) {
                        return $c['type'] . '=' . ($c['result'] ? 'pass' : 'fail');
                    }, $condTrace));
                    $this->addTraceStep('condition_eval', 'conditions blocked', $condDetail);
                } else {
                    $this->addTraceStep('dead_dest_check', 'destination dead, skipping');
                }
            } else {
                $this->addTraceStep('existing_redirect_lookup', 'none found');
            }

            if ($requestedURLWithoutComments != $requestedURL) {
                $lookupStart = microtime(true);
                $redirect = $this->dao->getActiveRedirectForURL($requestedURLWithoutComments);
                $this->recordRedirectLookupTiming($lookupStart);
                $redirectTypeInt2 = isset($redirect['type']) && is_scalar($redirect['type']) ? (int)$redirect['type'] : 0;
                if ($redirect['id'] != '0' && ($redirect['final_dest'] != '0' || $redirectTypeInt2 === $typeHomeInt)) {
                    $this->addTraceStep('existing_redirect_lookup_nocomments', 'found', 'id=' . (is_scalar($redirect['id']) ? (string)$redirect['id'] : '?'));
                    $deadIds = function_exists('get_transient') ? get_transient('abj404_dead_dest_ids') : false;
                    $redirectIdStr = isset($redirect['id']) && is_scalar($redirect['id']) ? (string) $redirect['id'] : '0';
                    if (!is_array($deadIds) || !in_array($redirectIdStr, $deadIds, true)) {
                        $condEvaluator = new ABJ_404_Solution_RedirectConditionEvaluator($this->dao);
                        $redirectIdForCond = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                        if ($condEvaluator->shouldApplyRedirect($redirectIdForCond)) {
                            $this->addTraceStep('condition_eval_nocomments', 'conditions passed');
                            $this->processRedirect($requestedURL, $redirect, 'existing');
                            exit;
                        }
                        // Conditions not met — fall through as if no redirect found.
                        $condTrace = $condEvaluator->getLastEvaluationTrace();
                        $condDetail = implode(', ', array_map(function ($c) {
                            return $c['type'] . '=' . ($c['result'] ? 'pass' : 'fail');
                        }, $condTrace));
                        $this->addTraceStep('condition_eval_nocomments', 'conditions blocked', $condDetail);
                    } else {
                        $this->addTraceStep('dead_dest_check_nocomments', 'destination dead, skipping');
                    }
                }
            }

            $sentTo404Page = $this->tryRegexRedirect($options, $requestedURL);
            if ($sentTo404Page) {
                $this->emitBenchmarkHeadersIfEnabled();
                return;
            }

            $autoRedirectsAreOn = !array_key_exists('auto_redirects', $options) || $options['auto_redirects'] == '1';

            if ($autoRedirectsAreOn) {
                $matchRequest = new ABJ_404_Solution_MatchRequest($requestedURL, $urlSlugOnly, $options);

                $matchResult = $this->runMatchingEngines($matchRequest);
                if ($matchResult !== null) {
                    $defaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
                    $this->dao->setupRedirect($requestedURL, (string)ABJ404_STATUS_AUTO, $matchResult->getType(), $matchResult->getId(), $defaultRedirect, 0, $matchResult->getEngineName(), $matchResult->getScore());

                    // Resolve link via WordPress API to ensure correct site prefix
                    // (cached URLs from permalink_cache may omit subdirectory prefix)
                    $resolvedLink = $matchResult->getLink();
                    if ($matchResult->getId() !== '' && $matchResult->getId() !== '0') {
                        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray(
                            $matchResult->getId() . '|' . $matchResult->getType(), 0
                        );
                        if (is_array($permalink) && !empty($permalink['link']) && is_string($permalink['link']) && $permalink['link'] !== 'dunno') {
                            $resolvedLink = $permalink['link'];
                        }
                    }

                    $this->dao->logRedirectHit($requestedURL, $resolvedLink, $matchResult->getEngineName(), null, $this->trace);
                    $this->logic->forceRedirect(esc_url($resolvedLink), (int)$defaultRedirect);
                    exit;
                }
            }

            if (!$autoRedirectsAreOn) {
                $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
                $this->emitBenchmarkHeadersIfEnabled();
                $this->logic->sendTo404Page($requestedURL, 'Do not create redirects per the options.', true, $options);
                return;
            }
        } else {
            if ($this->callWpFunction('is_single', array(), false) || $this->callWpFunction('is_page', array(), false)) {
                if (!$this->callWpFunction('is_feed', array(), false) &&
                        !$this->callWpFunction('is_trackback', array(), false) &&
                        !$this->callWpFunction('is_preview', array(), false)) {
                    $theID = $this->callWpFunction('get_the_ID', array(), 0);
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|" . $this->wpTypePost(), 0, null, $options);

                    $permLinkVal = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
                    $urlParts = parse_url($permLinkVal);
                    if (!is_array($urlParts) || !isset($urlParts['path'])) {
                        return;
                    }
                    $perma_link = $urlParts['path'];

                    $pageQueryVar = $this->callWpFunction('get_query_var', array('page'), false);
                    $paged = ($pageQueryVar !== false && is_string($pageQueryVar)) ? esc_html($pageQueryVar) : false;
                    if (!$paged === false) {
                        if (isset($urlParts['query']) && $urlParts['query'] != "") {
                            $urlParts['query'] .= "&page=" . $paged;
                        } else {
                            if ($this->f->substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        }
                    }

                    /** @var array<string, string> $urlPartsStr */
                    $urlPartsStr = array_map('strval', $urlParts);
                    $perma_link .= $this->f->sortQueryString($urlPartsStr);

                    if (@$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $this->processRedirect($requestedURL, $redirect, 'single page 3');
                            } else {
                                $spFinalDest = isset($permalink['id']) && is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
                                $spDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
                                $this->dao->setupRedirect(esc_url($requestedURL), (string)ABJ404_STATUS_AUTO, (string)$this->wpTypePost(), $spFinalDest, $spDefaultRedirect, 0, 'single page');
                                $spLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
                                $this->dao->logRedirectHit($requestedURL, $spLink, 'single page', null, $this->trace);
                                $this->logic->forceRedirect(esc_url($spLink), (int)$spDefaultRedirect);
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $redirectIdVal = isset($redirect['id']) && is_scalar($redirect['id']) ? (string)$redirect['id'] : '0';
                                $this->dao->deleteRedirect($redirectIdVal);
                            }
                        }
                    }
                }
            }
        }

        $this->logic->tryNormalPostQuery($options);
        $this->addTraceStep('final', 'no match found');
        $this->dao->logRedirectHit($requestedURL, '404', 'gave up.', null, $this->trace);
        $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
        $this->emitBenchmarkHeadersIfEnabled();
        $this->logic->sendTo404Page($requestedURL, '', true, $options);
    }

    /**
     * Iterate registered matching engines in order. First non-null result wins.
     *
     * @param ABJ_404_Solution_MatchRequest $request
     * @return ABJ_404_Solution_MatchResult|null
     */
    private function runMatchingEngines(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $enginesToRun = ABJ_404_Solution_EngineProfileResolver::getInstance()
            ->resolve($request->getRequestedURL(), $this->matchingEngines);

        foreach ($enginesToRun as $engine) {
            if (!($engine instanceof ABJ_404_Solution_MatchingEngine)) {
                $this->logger->warn('Matching engine is not an instance of ABJ_404_Solution_MatchingEngine: ' .
                    (is_object($engine) ? get_class($engine) : gettype($engine)));
                continue;
            }

            try {
                if (!$engine->shouldRun($request)) {
                    $this->logger->debugMessage('Engine skipped: ' . $engine->getName());
                    $this->addTraceStep('engine:' . $engine->getName(), 'skipped', 'shouldRun=false');
                    continue;
                }

                $result = $engine->match($request);

                if ($result === null) {
                    $this->logger->debugMessage('Engine returned no match: ' . $engine->getName());
                    $this->addTraceStep('engine:' . $engine->getName(), 'no match');
                    continue;
                }

                if ($result->getLink() === '') {
                    $this->logger->debugMessage('Engine returned empty link, skipping: ' . $engine->getName());
                    $this->addTraceStep('engine:' . $engine->getName(), 'no match', 'empty link');
                    continue;
                }

                if ($this->isExcluded($result, $request->getOptions())) {
                    $this->logger->debugMessage('Match excluded: ' . $engine->getName() . ' id=' . $result->getId());
                    $this->addTraceStep('engine:' . $engine->getName(), 'excluded', 'id=' . $result->getId());
                    continue;
                }

                $this->logger->debugMessage('Engine matched: ' . $engine->getName());
                $this->addTraceStep(
                    'engine:' . $engine->getName(),
                    'matched',
                    'id=' . $result->getId() . ' score=' . $result->getScore() . ' → ' . $result->getLink()
                );
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warn('Matching engine error (' . $engine->getName() . '): ' . $e->getMessage());
                $this->addTraceStep('engine:' . $engine->getName(), 'error', $e->getMessage());
                continue;
            }
        }

        $this->addTraceStep('engines', 'no engine matched');
        return null;
    }

    /**
     * Check whether a match result should be excluded from redirect suggestions.
     *
     * Checks post meta (_abj404_exclude), term meta, and the legacy excludePages[] option.
     * External and Home redirect types are never excluded.
     *
     * @param ABJ_404_Solution_MatchResult $result
     * @param array<string, mixed> $options
     * @return bool
     */
    private function isExcluded(ABJ_404_Solution_MatchResult $result, array $options): bool {
        $type = $result->getType();
        $id = $result->getId();

        $typeInt = is_numeric($type) ? (int)$type : 0;
        $typePost = defined('ABJ404_TYPE_POST') ? (int)ABJ404_TYPE_POST : 1;
        $typeCat = defined('ABJ404_TYPE_CAT') ? (int)ABJ404_TYPE_CAT : 2;
        $typeTag = defined('ABJ404_TYPE_TAG') ? (int)ABJ404_TYPE_TAG : 3;
        $typeExternal = defined('ABJ404_TYPE_EXTERNAL') ? (int)ABJ404_TYPE_EXTERNAL : 4;
        $typeHome = defined('ABJ404_TYPE_HOME') ? (int)ABJ404_TYPE_HOME : 5;

        // External and Home types are never excluded.
        if ($typeInt === $typeExternal || $typeInt === $typeHome) {
            return false;
        }

        // Empty or non-numeric ID — nothing to check.
        if ($id === '' || !is_numeric($id)) {
            return false;
        }

        $idInt = (int)$id;

        // Check per-item meta.
        if ($typeInt === $typePost) {
            $meta = $this->callWpFunction('get_post_meta', [$idInt, '_abj404_exclude', true], '');
            if ($meta === '1') {
                return true;
            }
        } elseif ($typeInt === $typeCat || $typeInt === $typeTag) {
            $meta = $this->callWpFunction('get_term_meta', [$idInt, '_abj404_exclude', true], '');
            if ($meta === '1') {
                return true;
            }
        }

        // Check legacy excludePages[] option (covers ALL engines, not just Spelling).
        $excludePagesRaw = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';
        $excludePagesJson = is_string($excludePagesRaw) ? $excludePagesRaw : '';
        if (trim($excludePagesJson) !== '') {
            $excludePages = json_decode($excludePagesJson);
            if (!is_array($excludePages)) {
                $excludePages = [$excludePages];
            }
            $key = $id . '|' . $type;
            foreach ($excludePages as $entry) {
                if (!is_string($entry) && !is_scalar($entry)) {
                    continue;
                }
                if ((string)$entry === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @return bool True if sent to configured default 404 page.
     */
    function tryRegexRedirect($options, $requestedURL) {
        $lookupStart = microtime(true);
        $regexPermalink = $this->spellChecker->getPermalinkUsingRegEx($requestedURL, $options);
        $this->recordRedirectLookupTiming($lookupStart);
        if (!empty($regexPermalink)) {
            $regexMatchingUrl = isset($regexPermalink['matching_regex']) && is_string($regexPermalink['matching_regex']) ? $regexPermalink['matching_regex'] : '';
            $regexLink = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexAction = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexType = isset($regexPermalink['type']) && (is_int($regexPermalink['type']) || is_string($regexPermalink['type'])) ? $regexPermalink['type'] : -1;
            $regexDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (int)$options['default_redirect'] : 0;
            $regexCode = isset($regexPermalink['code']) && is_numeric($regexPermalink['code']) && (int)$regexPermalink['code'] > 0
                ? (int)$regexPermalink['code'] : $regexDefaultRedirect;
            $this->addTraceStep('regex_match', 'matched', $regexMatchingUrl . ' → ' . $regexLink);
            $this->dao->logRedirectHit($regexMatchingUrl, $regexAction, 'regex match', $requestedURL, $this->trace);
            $sentTo404Page = $this->logic->forceRedirect(
                $regexLink,
                $regexCode,
                $regexType,
                $requestedURL
            );
            if ($sentTo404Page) {
                return true;
            }
            exit;
        }
        $this->addTraceStep('regex_match', 'no match');
        return false;
    }

    /**
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @return void
     */
    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
        if (!$this->logger->isDebug()) {
            return;
        }

        $optAutoRedirects = isset($options['auto_redirects']) && is_scalar($options['auto_redirects']) ? (string)$options['auto_redirects'] : '';
        $optAutoScore = isset($options['auto_score']) && is_scalar($options['auto_score']) ? (string)$options['auto_score'] : '';
        $optTemplatePriority = isset($options['template_redirect_priority']) && is_scalar($options['template_redirect_priority']) ? (string)$options['template_redirect_priority'] : '';
        $optAutoCats = isset($options['auto_cats']) && is_scalar($options['auto_cats']) ? (string)$options['auto_cats'] : '';
        $optAutoTags = isset($options['auto_tags']) && is_scalar($options['auto_tags']) ? (string)$options['auto_tags'] : '';
        $optDest404 = isset($options['dest404page']) && is_scalar($options['dest404page']) ? (string)$options['dest404page'] : '';
        $debugOptionsMsg = esc_html('auto_redirects: ' . $optAutoRedirects . ', auto_score: ' .
                $optAutoScore . ', template_redirect_priority: ' . $optTemplatePriority .
                ', auto_cats: ' . $optAutoCats . ', auto_tags: ' .
                $optAutoTags . ', dest404page: ' . $optDest404);

        $remoteAddressRaw = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $remoteAddress = esc_sql($remoteAddressRaw);
        if (!is_string($remoteAddress)) {
            $remoteAddress = '';
        }
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $remoteAddress = $this->f->md5lastOctet($remoteAddress);
        }

        $httpUserAgent = "";
        if (array_key_exists("HTTP_USER_AGENT", $_SERVER) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        $requestUriStr = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $httpUserAgent . ', REMOTE_ADDR: ' .
                $remoteAddress . ', REQUEST_URI: ' . $this->f->normalizeUrlString($requestUriStr));
        $isSingle = $this->callWpFunction('is_single', array(), false);
        $isPage = $this->callWpFunction('is_page', array(), false);
        $isFeed = $this->callWpFunction('is_feed', array(), false);
        $isTrackback = $this->callWpFunction('is_trackback', array(), false);
        $isPreview = $this->callWpFunction('is_preview', array(), false);
        $redirectJson = json_encode($redirect);
        $this->logger->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
                wp_kses_post(is_string($redirectJson) ? $redirectJson : '{}') . " | is_single(): " . $isSingle . " | " . "is_page(): " . $isPage .
                " | is_feed(): " . $isFeed . " | is_trackback(): " . $isTrackback . " | is_preview(): " .
                $isPreview . " | options: " . $debugOptionsMsg . ', ' . $debugServerMsg);
    }

    /**
     * Redirect to destination.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param string $matchReason
     * @return bool true if user is sent to default 404 page.
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {
        if (($redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO) || $redirect['disabled'] != 0) {
            $this->logger->errorMessage("processRedirect() was called with bad redirect data. Data: " .
                    wp_kses_post(print_r($redirect, true)));
        }

        $redirectUrl = isset($redirect['url']) && is_string($redirect['url']) ? $redirect['url'] : '';
        $redirectFinalDest = isset($redirect['final_dest']) && is_scalar($redirect['final_dest']) ? (string)$redirect['final_dest'] : '';
        $redirectCode = isset($redirect['code']) && is_scalar($redirect['code']) ? (int)$redirect['code'] : 0;
        $redirectId = isset($redirect['id']) && is_scalar($redirect['id']) ? (string)$redirect['id'] : '0';

        // 410 Gone: send HTTP 410 status and let WordPress render the suggestions page normally.
        if ($redirectCode === 410) {
            $this->addTraceStep('redirect', '410 Gone', $redirectUrl);
            $this->dao->logRedirectHit($redirectUrl, '410', $matchReason, null, $this->trace);
            $this->logic->forceRedirect('', 410);
            // forceRedirect returns false for 410 without exiting — page continues to render.
            return false;
        }

        // 451 Unavailable For Legal Reasons: render template and exit.
        if ($redirectCode === 451) {
            $this->addTraceStep('redirect', '451 Unavailable For Legal Reasons', $redirectUrl);
            $this->dao->logRedirectHit($redirectUrl, '451', $matchReason, null, $this->trace);
            $this->logic->forceRedirect('', 451);
            return false;
        }

        if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
            $this->addTraceStep('redirect', 'TYPE_404_DISPLAYED → 404 page', $redirectUrl);
            $this->dao->logRedirectHit($redirectUrl, '404', $matchReason, null, $this->trace);
            $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
            $this->emitBenchmarkHeadersIfEnabled();
            $this->logic->sendTo404Page($requestedURL, $matchReason);
            return true;
        }

        $isRedirectToCustom404Page = false;
        if ($redirect['type'] == $this->wpTypePost()) {
            $options = $this->logic->getOptions();
            $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
            $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : null;

            if ($dest404page !== null && $this->logic->thereIsAUserSpecified404Page($dest404page)) {
                $dest404Parts = explode('|', $dest404page);
                $custom404Id = isset($dest404Parts[0]) ? (int)$dest404Parts[0] : 0;
                if ($custom404Id > 0 && $redirect['final_dest'] == $custom404Id) {
                    $isRedirectToCustom404Page = true;
                }
            }

            if (!$isRedirectToCustom404Page) {
                $destPage = $this->callWpFunction('get_post', array($redirect['final_dest']), null);
                $hasShortcode = (is_object($destPage) && isset($destPage->post_content) && is_string($destPage->post_content))
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
            $suggestCats = isset($options['suggest_cats']) && is_string($options['suggest_cats']) ? $options['suggest_cats'] : '1';
            $suggestTags = isset($options['suggest_tags']) && is_string($options['suggest_tags']) ? $options['suggest_tags'] : '1';
            $spellChecker->findMatchingPosts($urlSlugOnly, $suggestCats, $suggestTags);
            $spellChecker->triggerAsyncSuggestionComputation($requestedURL);
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $this->addTraceStep('redirect', 'external', $redirectFinalDest);
            $this->dao->logRedirectHit($redirectUrl, $redirectFinalDest, 'external', null, $this->trace);
            $this->logic->forceRedirect($redirectFinalDest, $redirectCode);
            exit;
        }

        // Guard against broken redirects with missing/invalid destinations.
        $finalDestRaw = trim($redirectFinalDest);
        $redirectTypeInt = is_scalar($redirect['type']) ? (int)$redirect['type'] : 0;
        if ($finalDestRaw === '' && $redirectTypeInt !== ABJ404_TYPE_HOME && $redirectTypeInt !== ABJ404_TYPE_404_DISPLAYED) {
            $this->logger->warn("Redirect destination missing. Sending request to 404 page instead. Redirect ID: " . $redirectId);
            $this->addTraceStep('redirect', 'missing destination → 404 page', 'id=' . $redirectId);
            $this->dao->logRedirectHit($redirectUrl, '404', $matchReason . ' (missing destination)', null, $this->trace);
            $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
            $this->emitBenchmarkHeadersIfEnabled();
            $this->logic->sendTo404Page($requestedURL, 'missing redirect destination');
            return true;
        }

        $key = $redirectFinalDest . "|" . (is_scalar($redirect['type']) ? (string)$redirect['type'] : '');
        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);

        $finalLink = (is_array($permalink) && array_key_exists('link', $permalink))
            ? $permalink['link']
            : '';
        if (!is_string($finalLink) || trim($finalLink) === '' || $finalLink === 'dunno') {
            $this->logger->warn("Resolved permalink is empty/invalid. Sending request to 404 page instead. Redirect ID: " . $redirectId);
            $this->addTraceStep('redirect', 'invalid destination → 404 page', 'id=' . $redirectId);
            $this->dao->logRedirectHit($redirectUrl, '404', $matchReason . ' (invalid destination)', null, $this->trace);
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

        $this->addTraceStep('redirect', $redirectCode . ' → ' . $redirectedTo);
        $this->dao->logRedirectHit($redirectUrl, $redirectedTo, $matchReason, null, $this->trace);

        $sendTo404Page = $this->logic->forceRedirect(
            $finalLink,
            $redirectCode,
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
     * @param string $requestedURL
     * @return void
     */
    private function triggerAsyncSuggestionsIfNeeded($requestedURL) {
        if ($this->spellChecker->does404PageHaveSuggestionsShortcode()) {
            $this->spellChecker->triggerAsyncSuggestionComputation($requestedURL);
        }
    }
}
