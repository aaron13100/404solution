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
 * Construction still accepts legacy duck-typed objects so older test cases
 * keep working until they migrate to the typed services.
 */
class ABJ_404_Solution_FrontendRequestPipeline {

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var mixed */
    private $logsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_RequestIgnoreNormalizer */
    private $requestIgnoreNormalizer;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker */
    private $previousRequestCookieTracker;

    /** @var ABJ_404_Solution_FrontendPipelineTrace */
    private $trace;

    /** @var ABJ_404_Solution_RedirectExclusionPolicy */
    private $exclusionPolicy;

    /** @var ABJ_404_Solution_MatchingEngineOrchestrator */
    private $matchingEngineOrchestrator;

    /** @var ABJ_404_Solution_RedirectCandidateEvaluator */
    private $candidateEvaluator;

    /** @var ABJ_404_Solution_WordPressGuessFallback */
    private $wpGuessFallback;

    /** @var ABJ_404_Solution_RedirectDispatcher */
    private $dispatcher;

    /** @var ABJ_404_Solution_FrontendDbVersionRecovery */
    private $dbVersionRecovery;

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /**
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param array<int, mixed> $matchingEngines
     * @param mixed|null $logsRepository Log writer. Accepts legacy doubles with logRedirectHit().
     * @param ABJ_404_Solution_NotFoundResponseService|null $notFoundResponse
     * @param ABJ_404_Solution_RequestIgnoreNormalizer|null $requestIgnoreNormalizer
     * @param ABJ_404_Solution_PreviousRequestCookieTracker|null $previousRequestCookieTracker
     */
    function __construct($pluginLogic, $redirectsRepository, $logging, $functions, $spellChecker, array $matchingEngines = [], $logsRepository = null, $notFoundResponse = null, $requestIgnoreNormalizer = null, $previousRequestCookieTracker = null) {
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logging;
        $this->f = $functions;
        $this->spellChecker = $spellChecker;
        $this->notFoundResponse = $this->resolveNotFoundResponse($notFoundResponse);
        $normalizerLogsRepo = ($logsRepository instanceof ABJ_404_Solution_LogsRepositoryInterface) ? $logsRepository : null;
        $this->requestIgnoreNormalizer = $this->resolveRequestIgnoreNormalizer($requestIgnoreNormalizer, $normalizerLogsRepo);
        $this->previousRequestCookieTracker = $this->resolvePreviousRequestCookieTracker($previousRequestCookieTracker);
        $this->logsRepository = $this->resolveLogsRepository($logsRepository, $redirectsRepository);

        $this->trace = new ABJ_404_Solution_FrontendPipelineTrace();
        $this->exclusionPolicy = new ABJ_404_Solution_RedirectExclusionPolicy();
        $this->matchingEngineOrchestrator = new ABJ_404_Solution_MatchingEngineOrchestrator(
            $matchingEngines, $logging, $this->exclusionPolicy
        );
        $this->candidateEvaluator = new ABJ_404_Solution_RedirectCandidateEvaluator($redirectsRepository);
        $this->telemetry = new ABJ_404_Solution_FrontendPipelineTelemetry($logging, $functions);
        $this->wpGuessFallback = new ABJ_404_Solution_WordPressGuessFallback(
            $functions, $pluginLogic->urlNormalization(), $redirectsRepository,
            $this->notFoundResponse, $this->exclusionPolicy, $this->logsRepository
        );
        $this->dispatcher = new ABJ_404_Solution_RedirectDispatcher(
            $pluginLogic, $redirectsRepository, $logging, $functions, $spellChecker,
            $this->notFoundResponse, $this->previousRequestCookieTracker,
            $this->telemetry, $this->logsRepository
        );
        $this->dbVersionRecovery = new ABJ_404_Solution_FrontendDbVersionRecovery($pluginLogic, $logging);
    }

    /**
     * @param mixed $logsRepository
     * @param mixed $redirectsRepository
     * @return mixed
     */
    private function resolveLogsRepository($logsRepository, $redirectsRepository) {
        // Resolve the LogsRepository for logRedirectHit() writes. Preference order:
        //   1. Explicit $logsRepository argument (modern DI signature).
        //   2. If the redirects-repo facade exposes getLogsRepo(), resolve the typed
        //      LogsRepository off it. Avoids depending on a logRedirectHit pass-through
        //      living on DataAccess (see q task i775).
        //   3. Legacy fallback: facade with a logRedirectHit() entry point.
        //   4. Service container lookup.
        if ($logsRepository !== null) {
            return $logsRepository;
        }
        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'getLogsRepo')) {
            // Real method (DataAccess facade): resolve the typed LogsRepository off it so we
            // do not depend on logRedirectHit() pass-throughs living on DataAccess (q task i775).
            // method_exists is preferred over is_callable here because Mockery mocks answer
            // is_callable for every method but raise BadMethodCallException unless an
            // expectation was declared; method_exists only sees declared methods.
            try {
                $resolved = $redirectsRepository->getLogsRepo();
                return ($resolved !== null) ? $resolved : $redirectsRepository;
            } catch (\Throwable $e) {
                // allow-silent-catch: Mockery mocks throw if getLogsRepo() has no expectation;
                // tests that pre-date this resolver may construct a redirects mock without
                // the LogsRepo plumbing. Fall through to the legacy logRedirectHit path so
                // those pre-existing tests keep working until they migrate.
                return (method_exists($redirectsRepository, 'logRedirectHit'))
                    ? $redirectsRepository
                    : abj_service('logs_repository');
            }
        }
        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'logRedirectHit')) {
            return $redirectsRepository;
        }
        return abj_service('logs_repository');
    }

    /**
     * @param mixed $notFoundResponse
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    private function resolveNotFoundResponse($notFoundResponse) {
        $resolved = $notFoundResponse !== null ? $notFoundResponse : abj_service('not_found_response');
        if ($resolved instanceof ABJ_404_Solution_NotFoundResponseService) {
            return $resolved;
        }
        if (is_object($resolved)) {
            $forceRedirect = array($resolved, 'forceRedirect');
            $sendTo404Page = array($resolved, 'sendTo404Page');
            $thereIsAUserSpecified404Page = array($resolved, 'thereIsAUserSpecified404Page');
            if (is_callable($forceRedirect)
                    && is_callable($sendTo404Page)
                    && is_callable($thereIsAUserSpecified404Page)) {
                return $this->adaptNotFoundResponse($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page);
            }
        }
        throw new InvalidArgumentException('FrontendRequestPipeline requires NotFoundResponseService.');
    }

    /**
     * @param callable $forceRedirect
     * @param callable $sendTo404Page
     * @param callable $thereIsAUserSpecified404Page
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    private function adaptNotFoundResponse($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page) {
        return new class($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page) extends ABJ_404_Solution_NotFoundResponseService {
            /** @var callable */
            private $forceRedirect;

            /** @var callable */
            private $sendTo404Page;

            /** @var callable */
            private $thereIsAUserSpecified404Page;

            function __construct(callable $forceRedirect, callable $sendTo404Page, callable $thereIsAUserSpecified404Page) {
                $this->forceRedirect = $forceRedirect;
                $this->sendTo404Page = $sendTo404Page;
                $this->thereIsAUserSpecified404Page = $thereIsAUserSpecified404Page;
            }

            function forceRedirect(string $location, int $status = 302, $type = -1, string $requestedURL = '', bool $isCustom404 = false): bool {
                return (bool)call_user_func($this->forceRedirect, $location, $status, $type, $requestedURL, $isCustom404);
            }

            function sendTo404Page(string $requestedURL, string $reason = '', bool $useUserSpecified404 = true, $optionsOverride = null): void {
                call_user_func($this->sendTo404Page, $requestedURL, $reason, $useUserSpecified404, $optionsOverride);
            }

            function thereIsAUserSpecified404Page($dest404page): bool {
                return (bool)call_user_func($this->thereIsAUserSpecified404Page, $dest404page);
            }
        };
    }

    /**
     * @param mixed $requestIgnoreNormalizer
     * @param ABJ_404_Solution_LogsRepositoryInterface|null $logsRepository
     * @return ABJ_404_Solution_RequestIgnoreNormalizer
     */
    private function resolveRequestIgnoreNormalizer($requestIgnoreNormalizer, $logsRepository) {
        if ($requestIgnoreNormalizer instanceof ABJ_404_Solution_RequestIgnoreNormalizer) {
            return $requestIgnoreNormalizer;
        }
        if (is_object($requestIgnoreNormalizer)) {
            $initializeIgnoreValues = array($requestIgnoreNormalizer, 'initializeIgnoreValues');
            $tryNormalPostQuery = array($requestIgnoreNormalizer, 'tryNormalPostQuery');
            if (is_callable($initializeIgnoreValues) && is_callable($tryNormalPostQuery)) {
                return $this->adaptRequestIgnoreNormalizer($initializeIgnoreValues, $tryNormalPostQuery);
            }
        }
        return new ABJ_404_Solution_RequestIgnoreNormalizer(
            abj_service('options_repository'),
            $this->f,
            $this->logger,
            $this->redirectsRepository,
            $logsRepository,
            $this->notFoundResponse
        );
    }

    /**
     * @param callable $initializeIgnoreValues
     * @param callable $tryNormalPostQuery
     * @return ABJ_404_Solution_RequestIgnoreNormalizer
     */
    private function adaptRequestIgnoreNormalizer($initializeIgnoreValues, $tryNormalPostQuery) {
        return new class($initializeIgnoreValues, $tryNormalPostQuery) extends ABJ_404_Solution_RequestIgnoreNormalizer {
            /** @var callable */
            private $initializeIgnoreValues;

            /** @var callable */
            private $tryNormalPostQuery;

            function __construct(callable $initializeIgnoreValues, callable $tryNormalPostQuery) {
                $this->initializeIgnoreValues = $initializeIgnoreValues;
                $this->tryNormalPostQuery = $tryNormalPostQuery;
            }

            function initializeIgnoreValues(string $urlRequest, string $urlSlugOnly): void {
                call_user_func($this->initializeIgnoreValues, $urlRequest, $urlSlugOnly);
            }

            function tryNormalPostQuery(array $options): void {
                call_user_func($this->tryNormalPostQuery, $options);
            }
        };
    }

    /**
     * @param mixed $previousRequestCookieTracker
     * @return ABJ_404_Solution_PreviousRequestCookieTracker
     */
    private function resolvePreviousRequestCookieTracker($previousRequestCookieTracker) {
        if ($previousRequestCookieTracker instanceof ABJ_404_Solution_PreviousRequestCookieTracker) {
            return $previousRequestCookieTracker;
        }
        if (is_object($previousRequestCookieTracker)) {
            $setCookieWithPreviousRequest = array($previousRequestCookieTracker, 'setCookieWithPreviousRequest');
            $readCookieWithPreviousRqeuestShort = array($previousRequestCookieTracker, 'readCookieWithPreviousRqeuestShort');
            if (is_callable($setCookieWithPreviousRequest) && is_callable($readCookieWithPreviousRqeuestShort)) {
                return $this->adaptPreviousRequestCookieTracker($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest);
            }
        }
        return new ABJ_404_Solution_PreviousRequestCookieTracker($this->f, $this->logger);
    }

    /**
     * @param callable $readCookieWithPreviousRqeuestShort
     * @param callable $setCookieWithPreviousRequest
     * @return ABJ_404_Solution_PreviousRequestCookieTracker
     */
    private function adaptPreviousRequestCookieTracker($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest) {
        return new class($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest) extends ABJ_404_Solution_PreviousRequestCookieTracker {
            /** @var callable */
            private $readCookieWithPreviousRqeuestShort;

            /** @var callable */
            private $setCookieWithPreviousRequest;

            function __construct(callable $readCookieWithPreviousRqeuestShort, callable $setCookieWithPreviousRequest) {
                $this->readCookieWithPreviousRqeuestShort = $readCookieWithPreviousRqeuestShort;
                $this->setCookieWithPreviousRequest = $setCookieWithPreviousRequest;
            }

            function readCookieWithPreviousRqeuestShort(): string {
                $value = call_user_func($this->readCookieWithPreviousRqeuestShort);
                return is_string($value) ? $value : '';
            }

            function setCookieWithPreviousRequest(): void {
                call_user_func($this->setCookieWithPreviousRequest);
            }
        };
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

    /**
     * Read runtime options from OptionsRepository.
     *
     * @param bool $skipDbCheck
     * @return array<string, mixed>
     */
    private function getRuntimeOptions(bool $skipDbCheck = false): array {
        $options = abj_service('plugin_logic')->optionsResolver()->getOptions($skipDbCheck);
        return is_array($options) ? $options : array();
    }

    /** @return void */
    function processRedirectAllRequests() {
        $this->trace->reset();
        $options = $this->getRuntimeOptions();

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
            $options = $this->getRuntimeOptions(true);
            if (isset($options['DB_VERSION']) && $options['DB_VERSION'] != ABJ404_VERSION) {
                $options = $this->dbVersionRecovery->recoverIfStale($options);
                if (!isset($options['DB_VERSION']) || $options['DB_VERSION'] != ABJ404_VERSION) {
                    $degradedMode = true;
                }
            }
        }

        abj_service('request_context')->process_start_time = microtime(true);
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
            $this->writeHit($pathOnly, '404', 'ignore_donotprocess', null, $this->trace->getSteps());
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

        $options = $this->getRuntimeOptions();

        $lookupStart = microtime(true);
        $redirect = $this->redirectsRepository->getActiveRedirectForURL($requestedURL, $degradedMode);
        $this->telemetry->recordRedirectLookupTiming($lookupStart);
        $this->telemetry->logAReallyLongDebugMessage($options, $requestedURL, $redirect);
        $autoRedirectsAreOn = !array_key_exists('auto_redirects', $options) || $options['auto_redirects'] == '1';
        $deferredAutoRedirect = null;

        if ($requestedURL != '') {
            $matched = $this->candidateEvaluator->evaluate($redirect, '', $options, $this->trace);
            if ($matched !== null) {
                if ($this->candidateEvaluator->isAutoRedirect($matched)) {
                    $deferredAutoRedirect = $matched;
                } else {
                    $this->dispatcher->processRedirect($requestedURL, $matched, 'existing', $this->trace);
                    exit;
                }
            }

            if ($requestedURLWithoutComments != $requestedURL) {
                $lookupStart = microtime(true);
                $wcRedirect = $this->redirectsRepository->getActiveRedirectForURL($requestedURLWithoutComments, $degradedMode);
                $this->telemetry->recordRedirectLookupTiming($lookupStart);
                $matched = $this->candidateEvaluator->evaluate($wcRedirect, ' (without comments)', $options, $this->trace);
                if ($matched !== null) {
                    if ($this->candidateEvaluator->isAutoRedirect($matched)) {
                        if ($deferredAutoRedirect === null) {
                            $deferredAutoRedirect = $matched;
                        }
                    } else {
                        $this->dispatcher->processRedirect($requestedURL, $matched, 'existing', $this->trace);
                        exit;
                    }
                }
            }

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
                $matchRequest = new ABJ_404_Solution_MatchRequest($requestedURL, $urlSlugOnly, $options);

                $matchResult = $this->matchingEngineOrchestrator->run($matchRequest, $this->trace);
                if ($matchResult !== null) {
                    $defaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
                    $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                        $requestedURL, (string)ABJ404_STATUS_AUTO, $matchResult->getType(), $matchResult->getId(), $defaultRedirect, 0, $matchResult->getEngineName(), $matchResult->getScore()
                    ));

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

                    $this->writeHit($requestedURL, $resolvedLink, $matchResult->getEngineName(), null, $this->trace->getSteps());
                    $this->notFoundResponse->forceRedirect(esc_url($resolvedLink), (int)$defaultRedirect);
                    exit;
                }
            }

            if (!$autoRedirectsAreOn) {
                $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
                $this->telemetry->emitBenchmarkHeadersIfEnabled();
                $this->notFoundResponse->sendTo404Page($requestedURL, 'Do not create redirects per the options.', true, $options);
                return;
            }
        } else {
            $this->dispatcher->handleEmptyUrlSinglePageRedirect($requestedURL, $redirect, $options, $this->trace);
        }

        $this->wpGuessFallback->tryFallback($autoRedirectsAreOn, $requestedURL, $options, $this->trace);

        $this->requestIgnoreNormalizer->tryNormalPostQuery($options);
        $this->trace->add('Result', 'No redirect - showed 404 page');
        $this->writeHit($requestedURL, '404', 'gave up.', null, $this->trace->getSteps());
        $this->triggerAsyncSuggestionsIfNeeded($requestedURL);
        $this->telemetry->emitBenchmarkHeadersIfEnabled();
        $this->notFoundResponse->sendTo404Page($requestedURL, '', true, $options);
    }

    /**
     * @param string $requestedURL
     * @return void
     */
    private function triggerAsyncSuggestionsIfNeeded($requestedURL): void {
        if ($this->spellChecker->does404PageHaveSuggestionsShortcode()) {
            $this->spellChecker->triggerAndCleanupOnFailure($requestedURL);
        }
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
        call_user_func(array($this->logsRepository, 'logRedirectHit'), $requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace);
    }
}
