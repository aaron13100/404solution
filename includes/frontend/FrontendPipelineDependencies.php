<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds frontend pipeline collaborators while preserving legacy test doubles.
 */
class ABJ_404_Solution_FrontendPipelineDependencies {

    /** @var mixed */
    private $logsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logging;

    /** @var ABJ_404_Solution_Functions */
    private $functions;

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

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /** @var ABJ_404_Solution_WordPressGuessFallback */
    private $wpGuessFallback;

    /** @var ABJ_404_Solution_RedirectDispatcher */
    private $dispatcher;

    /** @var ABJ_404_Solution_FrontendDbVersionRecovery */
    private $dbVersionRecovery;

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
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param array<int, mixed> $matchingEngines
     * @param mixed|null $logsRepository
     * @param ABJ_404_Solution_NotFoundResponseService|null $notFoundResponse
     * @param ABJ_404_Solution_RequestIgnoreNormalizer|null $requestIgnoreNormalizer
     * @param ABJ_404_Solution_PreviousRequestCookieTracker|null $previousRequestCookieTracker
     */
    function __construct(
        $pluginLogic,
        $redirectsRepository,
        $logging,
        $functions,
        $spellChecker,
        array $matchingEngines,
        $logsRepository,
        $notFoundResponse,
        $requestIgnoreNormalizer,
        $previousRequestCookieTracker
    ) {
        $this->logging = $logging;
        $this->functions = $functions;

        $adapters = new ABJ_404_Solution_FrontendLegacyAdapters();
        $this->notFoundResponse = $adapters->resolveNotFoundResponse($notFoundResponse);
        $normalizerLogsRepo = ($logsRepository instanceof ABJ_404_Solution_LogsRepositoryInterface) ? $logsRepository : null;
        $this->requestIgnoreNormalizer = $adapters->resolveRequestIgnoreNormalizer(
            $requestIgnoreNormalizer,
            new ABJ_404_Solution_RequestIgnoreNormalizerDependencies(
                abj_service('options_repository'),
                $functions,
                $logging,
                $redirectsRepository,
                $normalizerLogsRepo,
                $this->notFoundResponse
            )
        );
        $this->previousRequestCookieTracker = $adapters->resolvePreviousRequestCookieTracker($previousRequestCookieTracker, $logging);
        $this->logsRepository = $adapters->resolveLogsRepository($logsRepository, $redirectsRepository);

        $this->trace = new ABJ_404_Solution_FrontendPipelineTrace();
        $this->exclusionPolicy = new ABJ_404_Solution_RedirectExclusionPolicy();
        $this->matchingEngineOrchestrator = new ABJ_404_Solution_MatchingEngineOrchestrator(
            $matchingEngines,
            $logging,
            $this->exclusionPolicy
        );
        $this->candidateEvaluator = new ABJ_404_Solution_RedirectCandidateEvaluator($redirectsRepository);
        $this->telemetry = new ABJ_404_Solution_FrontendPipelineTelemetry($logging, $functions);
        $this->hitRecorder = new ABJ_404_Solution_FrontendHitRecorder($this->logsRepository);
        $this->asyncSuggestionTrigger = new ABJ_404_Solution_FrontendAsyncSuggestionTrigger($spellChecker);
        $this->runtimeOptions = new ABJ_404_Solution_FrontendRuntimeOptions();
        $this->wpGuessFallback = new ABJ_404_Solution_WordPressGuessFallback(
            $pluginLogic->urlNormalization(),
            $redirectsRepository,
            $this->notFoundResponse,
            $this->exclusionPolicy,
            $this->logsRepository
        );
        $this->dispatcher = new ABJ_404_Solution_RedirectDispatcher(
            $pluginLogic,
            $redirectsRepository,
            $logging,
            $functions,
            $spellChecker,
            $this->notFoundResponse,
            $this->previousRequestCookieTracker,
            $this->telemetry,
            $this->logsRepository,
            $this->asyncSuggestionTrigger
        );
        $this->existingRedirectLookup = new ABJ_404_Solution_ExistingRedirectLookup(
            $redirectsRepository,
            $this->candidateEvaluator,
            $this->dispatcher,
            $this->telemetry
        );
        $this->autoRedirectHandler = new ABJ_404_Solution_AutoRedirectHandler(
            $redirectsRepository,
            $this->matchingEngineOrchestrator,
            $this->notFoundResponse,
            $this->hitRecorder
        );
        $this->dbVersionRecovery = new ABJ_404_Solution_FrontendDbVersionRecovery($pluginLogic, $logging);
    }

    /** @return ABJ_404_Solution_Logging */
    function logging(): ABJ_404_Solution_Logging {
        return $this->logging;
    }

    /** @return ABJ_404_Solution_Functions */
    function functions(): ABJ_404_Solution_Functions {
        return $this->functions;
    }

    /** @return ABJ_404_Solution_NotFoundResponseService */
    function notFoundResponse(): ABJ_404_Solution_NotFoundResponseService {
        return $this->notFoundResponse;
    }

    /** @return ABJ_404_Solution_RequestIgnoreNormalizer */
    function requestIgnoreNormalizer(): ABJ_404_Solution_RequestIgnoreNormalizer {
        return $this->requestIgnoreNormalizer;
    }

    /** @return ABJ_404_Solution_FrontendPipelineTrace */
    function trace(): ABJ_404_Solution_FrontendPipelineTrace {
        return $this->trace;
    }

    /** @return ABJ_404_Solution_MatchingEngineOrchestrator */
    function matchingEngineOrchestrator(): ABJ_404_Solution_MatchingEngineOrchestrator {
        return $this->matchingEngineOrchestrator;
    }

    /** @return ABJ_404_Solution_FrontendPipelineTelemetry */
    function telemetry(): ABJ_404_Solution_FrontendPipelineTelemetry {
        return $this->telemetry;
    }

    /** @return ABJ_404_Solution_WordPressGuessFallback */
    function wpGuessFallback(): ABJ_404_Solution_WordPressGuessFallback {
        return $this->wpGuessFallback;
    }

    /** @return ABJ_404_Solution_RedirectDispatcher */
    function dispatcher(): ABJ_404_Solution_RedirectDispatcher {
        return $this->dispatcher;
    }

    /** @return ABJ_404_Solution_FrontendDbVersionRecovery */
    function dbVersionRecovery(): ABJ_404_Solution_FrontendDbVersionRecovery {
        return $this->dbVersionRecovery;
    }

    /** @return ABJ_404_Solution_FrontendHitRecorder */
    function hitRecorder(): ABJ_404_Solution_FrontendHitRecorder {
        return $this->hitRecorder;
    }

    /** @return ABJ_404_Solution_FrontendAsyncSuggestionTrigger */
    function asyncSuggestionTrigger(): ABJ_404_Solution_FrontendAsyncSuggestionTrigger {
        return $this->asyncSuggestionTrigger;
    }

    /** @return ABJ_404_Solution_FrontendRuntimeOptions */
    function runtimeOptions(): ABJ_404_Solution_FrontendRuntimeOptions {
        return $this->runtimeOptions;
    }

    /** @return ABJ_404_Solution_ExistingRedirectLookup */
    function existingRedirectLookup(): ABJ_404_Solution_ExistingRedirectLookup {
        return $this->existingRedirectLookup;
    }

    /** @return ABJ_404_Solution_AutoRedirectHandler */
    function autoRedirectHandler(): ABJ_404_Solution_AutoRedirectHandler {
        return $this->autoRedirectHandler;
    }
}
