<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend request pipeline for 404 processing and redirects.
 *
 * Orchestrator: sequences strategies and delegates the work to focused
 * collaborators. The phases of a 404 request:
 *
 *   1. (process404 only) Self-heal stale DB_VERSION via FrontendDbVersionRecovery.
 *   2. Initialize ignore values + check do-not-process list.
 *   3. Lookup existing redirect for URL, evaluate via RedirectCandidateEvaluator.
 *   4. tryRegexRedirect (via RedirectDispatcher).
 *   5. Run matching engines (via MatchingEngineOrchestrator).
 *   6. WordPress 404-permalink-guess fallback (via WordPressGuessFallback).
 *   7. Emit 404 page (via NotFoundResponseService) and log "gave up".
 *
 * Construction accepts a dependency bundle so collaborator assembly stays out
 * of the request orchestration path.
 */
class ABJ_404_Solution_FrontendRequestPipeline {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_RequestIgnoreNormalizer */
    private $requestIgnoreNormalizer;

    /** @var ABJ_404_Solution_FrontendPipelineTrace */
    private $trace;

    /** @var ABJ_404_Solution_MatchingEngineOrchestrator */
    private $matchingEngineOrchestrator;

    /** @var ABJ_404_Solution_WordPressGuessFallback */
    private $wpGuessFallback;

    /** @var ABJ_404_Solution_RedirectDispatcher */
    private $dispatcher;

    /** @var ABJ_404_Solution_FrontendDbVersionRecovery */
    private $dbVersionRecovery;

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /** @var ABJ_404_Solution_FrontendHitRecorder */
    private $hitRecorder;

    /** @var ABJ_404_Solution_FrontendAsyncSuggestionTrigger */
    private $asyncSuggestionTrigger;

    /** @var ABJ_404_Solution_FrontendRuntimeOptions */
    private $runtimeOptions;

    /** @var ABJ_404_Solution_ExistingRedirectLookup */
    private $existingRedirectLookup;

    /** @var ABJ_404_Solution_AutoRedirectHandler */
    private $autoRedirectHandler;

    /**
     * @param ABJ_404_Solution_FrontendPipelineDependencies $dependencies
     */
    function __construct(ABJ_404_Solution_FrontendPipelineDependencies $dependencies) {
        $this->f = $dependencies->functions();
        $this->logger = $dependencies->logging();
        $this->notFoundResponse = $dependencies->notFoundResponse();
        $this->requestIgnoreNormalizer = $dependencies->requestIgnoreNormalizer();
        $this->trace = $dependencies->trace();
        $this->matchingEngineOrchestrator = $dependencies->matchingEngineOrchestrator();
        $this->telemetry = $dependencies->telemetry();
        $this->wpGuessFallback = $dependencies->wpGuessFallback();
        $this->dispatcher = $dependencies->dispatcher();
        $this->dbVersionRecovery = $dependencies->dbVersionRecovery();
        $this->hitRecorder = $dependencies->hitRecorder();
        $this->asyncSuggestionTrigger = $dependencies->asyncSuggestionTrigger();
        $this->runtimeOptions = $dependencies->runtimeOptions();
        $this->existingRedirectLookup = $dependencies->existingRedirectLookup();
        $this->autoRedirectHandler = $dependencies->autoRedirectHandler();
    }

    /**
     * Exposed for tests that need to exercise the engine fanout in isolation
     * (see ExclusionMetaTest). Production callers route through process404().
     *
     * @return ABJ_404_Solution_MatchingEngineOrchestrator
     */
    function getMatchingEngineOrchestrator(): ABJ_404_Solution_MatchingEngineOrchestrator {
        return $this->matchingEngineOrchestrator;
    }

    /** @return void */
    function processRedirectAllRequests() {
        $this->trace->reset();
        $options = $this->runtimeOptions->get();

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            return;
        }
        $pathOnly = $userRequest->getPath();
        $urlSlugOnly = $userRequest->getOnlyTheSlug();

        $this->requestIgnoreNormalizer->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        $requestedURL = $userRequest->getPathWithSortedQueryString();

        $this->dispatcher->tryRegexRedirect($options, $requestedURL, $this->trace);

        if (is_admin() || !is_404()) {
            $this->logger->warn('If REDIRECT_ALL_REQUESTS is turned on then a regex redirect must be in place.');
        }
    }

    /**
     * Process the 404 path.
     * @return void
     */
    function process404() {
        if (!is_404() || is_admin()) {
            // SAFE_BAIL: not a 404 or in wp-admin - nothing for us to do.
            return;
        }

        // Self-heal a stale DB_VERSION on the frontend so end users get redirects
        // without needing an admin visit (task 233). If recovery cannot close the
        // gap (lock held, cooldown active, or migration repeatedly throws), fall
        // through to a degraded redirect lookup (task 234) so manual redirects
        // keep serving instead of every 404 falling to the theme 404 page.
        $degradedMode = false;
        if (defined('ABJ404_VERSION')) {
            $options = $this->runtimeOptions->get(true);
            if (isset($options['DB_VERSION']) && $options['DB_VERSION'] != ABJ404_VERSION) {
                $options = $this->dbVersionRecovery->recoverIfStale($options);
                if (!isset($options['DB_VERSION']) || $options['DB_VERSION'] != ABJ404_VERSION) {
                    $degradedMode = true;
                }
            }
        }

        abj_service('request_context')->process_start_time = abj_clock()->nowFloat();
        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            // SAFE_BAIL: no user request context - cannot resolve a URL to look up.
            return;
        }

        $pathOnly = $userRequest->getPath();
        $urlSlugOnly = $userRequest->getOnlyTheSlug();
        $this->requestIgnoreNormalizer->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        $this->trace->reset();

        if (abj_service('request_context')->ignore_donotprocess) {
            $this->trace->add('Ignore list', 'Matched - request ignored', '');
            $this->hitRecorder->record($pathOnly, '404', 'ignore_donotprocess', null, $this->trace->getSteps());
            $this->telemetry->emitBenchmarkHeadersIfEnabled();
            // SAFE_BAIL: ignore_donotprocess matched - admin opted this UA out.
            return;
        }
        $this->trace->add('Ignore list', 'Not ignored');

        $requestedURL = $userRequest->getPathWithSortedQueryString();
        $requestedURLWithoutComments = $requestedURL;
        if ($this->f->strpos($requestedURL, '/comment-page-') !== false) {
            $withoutComments = $userRequest->getRequestURIWithoutCommentsPage();
            if (is_string($withoutComments)) {
                $requestedURLWithoutComments = $withoutComments;
            }
        }

        $options = $this->runtimeOptions->get();
        $autoRedirectsAreOn = !array_key_exists('auto_redirects', $options) || $options['auto_redirects'] == '1';

        if ($requestedURL != '') {
            $deferredAutoRedirect = $this->existingRedirectLookup->dispatchManualOrFindDeferredAuto(
                $requestedURL,
                $requestedURLWithoutComments,
                $options,
                $degradedMode,
                $this->trace
            );

            $sentTo404Page = $this->dispatcher->tryRegexRedirect($options, $requestedURL, $this->trace);
            if ($sentTo404Page) {
                $this->telemetry->emitBenchmarkHeadersIfEnabled();
                return;
            }

            if ($deferredAutoRedirect !== null) {
                $this->dispatcher->processRedirect($requestedURL, $deferredAutoRedirect, 'existing', $this->trace);
                exit;
            }

            if ($autoRedirectsAreOn) {
                $this->autoRedirectHandler->promoteAndRedirectIfMatched($requestedURL, $urlSlugOnly, $options, $this->trace);
            }

            if (!$autoRedirectsAreOn) {
                $this->asyncSuggestionTrigger->triggerIfNeeded($requestedURL);
                $this->telemetry->emitBenchmarkHeadersIfEnabled();
                $this->notFoundResponse->sendTo404Page($requestedURL, 'Do not create redirects per the options.', true, $options);
                return;
            }
        } else {
            $redirect = $this->existingRedirectLookup->lookupForEmptyUrl($requestedURL, $options, $degradedMode);
            $this->dispatcher->handleEmptyUrlSinglePageRedirect($requestedURL, $redirect, $options, $this->trace);
        }

        $this->wpGuessFallback->tryFallback($autoRedirectsAreOn, $requestedURL, $options, $this->trace);

        $this->requestIgnoreNormalizer->tryNormalPostQuery($options);
        $this->trace->add('Result', 'No redirect - showed 404 page');
        $this->hitRecorder->record($requestedURL, '404', 'gave up.', null, $this->trace->getSteps());
        $this->asyncSuggestionTrigger->triggerIfNeeded($requestedURL);
        $this->telemetry->emitBenchmarkHeadersIfEnabled();
        $this->notFoundResponse->sendTo404Page($requestedURL, '', true, $options);
    }
}
