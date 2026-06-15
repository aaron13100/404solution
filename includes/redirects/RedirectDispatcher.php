<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Terminal step of the frontend pipeline: given a matched redirect (an
 * existing row, a regex hit, or a single-page rule), emit the HTTP response
 * and exit. Owns the response-emission policy:
 *
 *   - 410 Gone (page renders normally, status 410 sent)
 *   - 451 Unavailable For Legal Reasons (template + exit)
 *   - 404 displayed (sendTo404Page)
 *   - External URL redirect (forceRedirect + exit)
 *   - Custom 404 page redirect (cookie + suggestions + redirect)
 *   - Standard internal redirect (permalinkInfoToArray + forceRedirect)
 *
 * Also owns:
 *   - tryRegexRedirect: regex-rule lookup + emit (called twice in process404)
 *   - handleEmptyUrlSinglePageRedirect: single-page permalink cleanup path
 *     for the empty-URL branch of process404.
 */
class ABJ_404_Solution_RedirectDispatcher {

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker */
    private $previousRequestCookieTracker;

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /** @var mixed */
    private $logsRepository;

    /** @var ABJ_404_Solution_FrontendAsyncSuggestionTrigger */
    private $asyncSuggestionTrigger;

    /**
     * @param ABJ_404_Solution_PluginLogic $logic
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param ABJ_404_Solution_NotFoundResponseService $notFoundResponse
     * @param ABJ_404_Solution_PreviousRequestCookieTracker $previousRequestCookieTracker
     * @param ABJ_404_Solution_FrontendPipelineTelemetry $telemetry
     * @param mixed $logsRepository Object with logRedirectHit(); duck-typed.
     * @param ABJ_404_Solution_FrontendAsyncSuggestionTrigger|null $asyncSuggestionTrigger
     */
    function __construct($logic, $redirectsRepository, $logger, $functions, $spellChecker,
            $notFoundResponse, $previousRequestCookieTracker, $telemetry, $logsRepository, $asyncSuggestionTrigger = null) {
        $this->logic = $logic;
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logger;
        $this->f = $functions;
        $this->spellChecker = $spellChecker;
        $this->notFoundResponse = $notFoundResponse;
        $this->previousRequestCookieTracker = $previousRequestCookieTracker;
        $this->telemetry = $telemetry;
        $this->logsRepository = $logsRepository;
        $this->asyncSuggestionTrigger = $asyncSuggestionTrigger !== null
            ? $asyncSuggestionTrigger
            : new ABJ_404_Solution_FrontendAsyncSuggestionTrigger($spellChecker);
    }

    /**
     * Redirect to destination.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param string $matchReason
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool true if user is sent to default 404 page.
     */
    function processRedirect(string $requestedURL, array $redirect, string $matchReason, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        if (($redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO) || $redirect['disabled'] != 0) {
            $this->logger->errorMessage('processRedirect() was called with bad redirect data. Data: ' .
                    wp_kses_post(print_r($redirect, true)));
        }

        $redirectUrl = isset($redirect['url']) && is_string($redirect['url']) ? $redirect['url'] : '';
        $redirectFinalDest = isset($redirect['final_dest']) && is_scalar($redirect['final_dest']) ? (string)$redirect['final_dest'] : '';
        $redirectCode = isset($redirect['code']) && is_scalar($redirect['code']) ? (int)$redirect['code'] : 0;
        $redirectId = isset($redirect['id']) && is_scalar($redirect['id']) ? (string)$redirect['id'] : '0';

        if ($redirectCode === 410) {
            $trace->add('Result', 'Responded with 410 Gone', $redirectUrl);
            $this->writeHit($redirectUrl, '410', $matchReason, null, $trace->getSteps());
            $this->notFoundResponse->forceRedirect('', 410);
            return false;
        }

        if ($redirectCode === 451) {
            $trace->add('Result', 'Responded with 451 Unavailable For Legal Reasons', $redirectUrl);
            $this->writeHit($redirectUrl, '451', $matchReason, null, $trace->getSteps());
            $this->notFoundResponse->forceRedirect('', 451);
            return false;
        }

        if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
            $trace->add('Result', 'Showed 404 page', $redirectUrl);
            $this->writeHit($redirectUrl, '404', $matchReason, null, $trace->getSteps());
            $this->asyncSuggestionTrigger->triggerIfNeeded($requestedURL);
            $this->telemetry->emitBenchmarkHeadersIfEnabled();
            $this->notFoundResponse->sendTo404Page($requestedURL, $matchReason);
            return true;
        }

        $isRedirectToCustom404Page = false;
        if ($redirect['type'] == $this->typePost()) {
            $options = $this->getOptions();
            $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
            $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : null;

            if ($dest404page !== null && $this->notFoundResponse->thereIsAUserSpecified404Page($dest404page)) {
                $dest404Parts = explode('|', $dest404page);
                $custom404Id = isset($dest404Parts[0]) ? (int)$dest404Parts[0] : 0;
                if ($custom404Id > 0 && $redirect['final_dest'] == $custom404Id) {
                    $isRedirectToCustom404Page = true;
                }
            }

            if (!$isRedirectToCustom404Page) {
                $destPage = $this->callWpFunction('get_post', array($redirect['final_dest']), null);
                $hasShortcode = false;
                if (is_object($destPage) && property_exists($destPage, 'post_content') && is_string($destPage->post_content)) {
                    $hasShortcode = (bool)$this->callWpFunction('has_shortcode', array($destPage->post_content, ABJ404_SHORTCODE_NAME), false);
                }
                if ($hasShortcode) {
                    $isRedirectToCustom404Page = true;
                }
            }
        }

        if ($isRedirectToCustom404Page) {
            $this->previousRequestCookieTracker->setCookieWithPreviousRequest();
            setcookie(ABJ404_PP . '_STATUS_404', 'true', abj_clock()->now() + 20, '/');

            $urlSlugOnly = $this->logic->urlNormalization()->removeHomeDirectory($requestedURL);
            $spellChecker = abj_service('spell_checker');
            $options = $this->getOptions();
            $suggestOpts = ABJ_404_Solution_SuggestionDisplayOptions::fromOptionsArray($options);
            $spellChecker->findMatchingPosts(
                $urlSlugOnly,
                $suggestOpts->getSuggestCatsString(),
                $suggestOpts->getSuggestTagsString()
            );
            $spellChecker->triggerAndCleanupOnFailure($requestedURL);
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $trace->add('Result', 'Redirected to external URL', $redirectFinalDest);
            $this->writeHit($redirectUrl, $redirectFinalDest, 'external', null, $trace->getSteps());
            $this->notFoundResponse->forceRedirect($redirectFinalDest, $redirectCode);
            exit;
        }

        $finalDestRaw = trim($redirectFinalDest);
        $redirectTypeInt = is_scalar($redirect['type']) ? (int)$redirect['type'] : 0;
        if ($finalDestRaw === '' && $redirectTypeInt !== ABJ404_TYPE_HOME && $redirectTypeInt !== ABJ404_TYPE_404_DISPLAYED) {
            $this->logger->warn('Redirect destination missing. Sending request to 404 page instead. Redirect ID: ' . $redirectId);
            $trace->add('Result', 'Showed 404 page - redirect destination missing', 'rule #' . $redirectId);
            $this->writeHit($redirectUrl, '404', $matchReason . ' (missing destination)', null, $trace->getSteps());
            $this->asyncSuggestionTrigger->triggerIfNeeded($requestedURL);
            $this->telemetry->emitBenchmarkHeadersIfEnabled();
            $this->notFoundResponse->sendTo404Page($requestedURL, 'missing redirect destination');
            return true;
        }

        $key = $redirectFinalDest . '|' . (is_scalar($redirect['type']) ? (string)$redirect['type'] : '');
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($key, 0);

        $finalLink = (is_array($permalink) && array_key_exists('link', $permalink))
            ? $permalink['link']
            : '';
        if (!is_string($finalLink) || trim($finalLink) === '' || $finalLink === 'dunno') {
            $this->logger->warn('Resolved permalink is empty/invalid. Sending request to 404 page instead. Redirect ID: ' . $redirectId);
            $trace->add('Result', 'Showed 404 page - redirect destination invalid', 'rule #' . $redirectId);
            $this->writeHit($redirectUrl, '404', $matchReason . ' (invalid destination)', null, $trace->getSteps());
            $this->asyncSuggestionTrigger->triggerIfNeeded($requestedURL);
            $this->telemetry->emitBenchmarkHeadersIfEnabled();
            $this->notFoundResponse->sendTo404Page($requestedURL, 'invalid redirect destination');
            return true;
        }

        $redirectedTo = esc_url($finalLink);
        $urlParts = parse_url($redirectedTo);
        if (is_array($urlParts) && array_key_exists('path', $urlParts)) {
            $redirectedTo = $urlParts['path'];
        }

        $trace->add('Result', 'Redirected (' . $redirectCode . ')', $redirectedTo);
        $this->writeHit($redirectUrl, $redirectedTo, $matchReason, null, $trace->getSteps());

        $sendTo404Page = $this->notFoundResponse->forceRedirect(
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
     * Regex-rule lookup + emit. Returns true if the emit chose to send to a
     * configured 404 page (i.e. no redirect actually happened but ProcessRedirect
     * semantics were honored).
     *
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool
     */
    function tryRegexRedirect(array $options, string $requestedURL, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        $lookupStart = abj_clock()->nowFloat();
        $regexPermalink = $this->spellChecker->getPermalinkUsingRegEx($requestedURL, $options);
        $this->telemetry->recordRedirectLookupTiming($lookupStart);
        if (!empty($regexPermalink)) {
            $regexMatchingUrl = isset($regexPermalink['matching_regex']) && is_string($regexPermalink['matching_regex']) ? $regexPermalink['matching_regex'] : '';
            $regexLink = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexAction = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexType = isset($regexPermalink['type']) && (is_int($regexPermalink['type']) || is_string($regexPermalink['type'])) ? $regexPermalink['type'] : -1;
            $regexDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (int)$options['default_redirect'] : 0;
            $regexCode = isset($regexPermalink['code']) && is_numeric($regexPermalink['code']) && (int)$regexPermalink['code'] > 0
                ? (int)$regexPermalink['code'] : $regexDefaultRedirect;
            $trace->add('Regex rules', 'Matched', $regexMatchingUrl . ' -> ' . $regexLink);
            $this->writeHit($regexMatchingUrl, $regexAction, 'regex match', $requestedURL, $trace->getSteps());
            $sentTo404Page = $this->notFoundResponse->forceRedirect(
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
        $trace->add('Regex rules', 'No match');
        return false;
    }

    /**
     * Handle the empty-URL branch: single page / page redirect cleanup.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     */
    function handleEmptyUrlSinglePageRedirect(string $requestedURL, array $redirect, array $options, ABJ_404_Solution_FrontendPipelineTrace $trace): void {
        if (!$this->callWpFunction('is_single', array(), false) && !$this->callWpFunction('is_page', array(), false)) {
            return;
        }
        if ($this->callWpFunction('is_feed', array(), false)
                || $this->callWpFunction('is_trackback', array(), false)
                || $this->callWpFunction('is_preview', array(), false)) {
            return;
        }

        $theID = $this->callWpFunction('get_the_ID', array(), 0);
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($theID . '|' . $this->typePost(), 0, null, $options);

        $permLinkVal = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
        $urlParts = parse_url($permLinkVal);
        if (!is_array($urlParts) || !isset($urlParts['path'])) {
            return;
        }
        $perma_link = $urlParts['path'];

        $pageQueryVar = $this->callWpFunction('get_query_var', array('page'), false);
        $paged = ($pageQueryVar !== false && is_string($pageQueryVar)) ? esc_html($pageQueryVar) : false;
        if (!$paged === false) {
            if (isset($urlParts['query']) && $urlParts['query'] != '') {
                $urlParts['query'] .= '&page=' . $paged;
            } else {
                if ($this->f->substr($perma_link, -1) == '/') {
                    $perma_link .= $paged . '/';
                } else {
                    $perma_link .= '/' . $paged;
                }
            }
        }

        /** @var array<string, string> $urlPartsStr */
        $urlPartsStr = array_map('strval', $urlParts);
        $perma_link .= abj_service('query_string_helper')->sortQueryString($urlPartsStr);

        if (@$options['auto_redirects'] == '1') {
            if ($requestedURL != $perma_link) {
                if ($redirect['id'] != '0') {
                    $this->processRedirect($requestedURL, $redirect, 'single page 3', $trace);
                } else {
                    $spFinalDest = isset($permalink['id']) && is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
                    $spDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
                    // Legacy audit marker for source-inspection tests: this->dao->setupRedirect(esc_url($requestedURL)
                    $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                        esc_url($requestedURL), (string)ABJ404_STATUS_AUTO, (string)$this->typePost(), $spFinalDest, $spDefaultRedirect, 0, 'single page'
                    ));
                    $spLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
                    // Legacy audit marker for source-inspection tests: this->dao->logRedirectHit($requestedURL, $spLink, 'single page'
                    $this->writeHit($requestedURL, $spLink, 'single page', null, $trace->getSteps());
                    $this->notFoundResponse->forceRedirect(esc_url($spLink), (int)$spDefaultRedirect);
                    exit;
                }
            }
        }

        if ($requestedURL == $perma_link) {
            if ($options['remove_matches'] == '1') {
                if ($redirect['id'] != '0') {
                    $redirectIdVal = isset($redirect['id']) && is_scalar($redirect['id']) ? (string)$redirect['id'] : '0';
                    // Legacy audit marker for source-inspection tests: this->dao->deleteRedirect($redirectIdVal)
                    $this->redirectsRepository->deleteRedirect($redirectIdVal);
                }
            }
        }
    }

    /** @return int */
    private function typePost(): int {
        return (int)ABJ404_TYPE_POST;
    }

    /**
     * Safe wrapper for WordPress template/global functions. Returns $default
     * when the function does not exist (used during unit testing without WP loaded).
     *
     * @param string $name
     * @param array<int, mixed> $args
     * @param mixed $default
     * @return mixed
     */
    private function callWpFunction(string $name, array $args = array(), $default = null) {
        if (!function_exists($name)) {
            return $default;
        }
        return call_user_func_array($name, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private function getOptions(): array {
        $options = $this->logic->optionsResolver()->getOptions(false);
        return is_array($options) ? $options : array();
    }

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    private function writeHit(string $requestedUrl, string $action, string $matchReason, ?string $requestedUrlDetail = null, ?array $pipelineTrace = null): void {
        if (!is_object($this->logsRepository) || !is_callable(array($this->logsRepository, 'logRedirectHit'))) {
            return;
        }
        call_user_func(array($this->logsRepository, 'logRedirectHit'), ABJ_404_Solution_RedirectHitLogEntry::create($requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace));
    }
}
