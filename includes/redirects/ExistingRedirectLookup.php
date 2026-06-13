<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves existing redirect rows and dispatches non-auto matches immediately.
 */
class ABJ_404_Solution_ExistingRedirectLookup {

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_RedirectCandidateEvaluator */
    private $candidateEvaluator;

    /** @var ABJ_404_Solution_RedirectDispatcher */
    private $dispatcher;

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /**
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_RedirectCandidateEvaluator $candidateEvaluator
     * @param ABJ_404_Solution_RedirectDispatcher $dispatcher
     * @param ABJ_404_Solution_FrontendPipelineTelemetry $telemetry
     */
    function __construct($redirectsRepository, $candidateEvaluator, $dispatcher, $telemetry) {
        $this->redirectsRepository = $redirectsRepository;
        $this->candidateEvaluator = $candidateEvaluator;
        $this->dispatcher = $dispatcher;
        $this->telemetry = $telemetry;
    }

    /**
     * @param string $requestedURL
     * @param string $requestedURLWithoutComments
     * @param array<string, mixed> $options
     * @param bool $degradedMode
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return array<string, mixed>|null Auto redirect that should defer until after regex lookup.
     */
    function dispatchManualOrFindDeferredAuto(
        string $requestedURL,
        string $requestedURLWithoutComments,
        array $options,
        bool $degradedMode,
        ABJ_404_Solution_FrontendPipelineTrace $trace
    ): ?array {
        $deferredAutoRedirect = null;

        $redirect = $this->lookup($requestedURL, $degradedMode);
        $this->telemetry->logAReallyLongDebugMessage($options, $requestedURL, $redirect);
        $matched = $this->candidateEvaluator->evaluate($redirect, '', $options, $trace);
        if ($matched !== null) {
            if ($this->candidateEvaluator->isAutoRedirect($matched)) {
                $deferredAutoRedirect = $matched;
            } else {
                $this->dispatcher->processRedirect($requestedURL, $matched, 'existing', $trace);
                exit;
            }
        }

        if ($requestedURLWithoutComments == $requestedURL) {
            return $deferredAutoRedirect;
        }

        $wcRedirect = $this->lookup($requestedURLWithoutComments, $degradedMode);
        $matched = $this->candidateEvaluator->evaluate($wcRedirect, ' (without comments)', $options, $trace);
        if ($matched === null) {
            return $deferredAutoRedirect;
        }

        if ($this->candidateEvaluator->isAutoRedirect($matched)) {
            return $deferredAutoRedirect === null ? $matched : $deferredAutoRedirect;
        }

        $this->dispatcher->processRedirect($requestedURL, $matched, 'existing', $trace);
        exit;
    }

    /**
     * @param string $requestedURL
     * @param array<string, mixed> $options
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    function lookupForEmptyUrl(string $requestedURL, array $options, bool $degradedMode): array {
        $redirect = $this->lookup($requestedURL, $degradedMode);
        $this->telemetry->logAReallyLongDebugMessage($options, $requestedURL, $redirect);
        return $redirect;
    }

    /**
     * @param string $requestedURL
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    private function lookup(string $requestedURL, bool $degradedMode): array {
        $lookupStart = abj_clock()->nowFloat();
        $redirect = $this->redirectsRepository->getActiveRedirectForURL($requestedURL, $degradedMode);
        $this->telemetry->recordRedirectLookupTiming($lookupStart);
        return is_array($redirect) ? $redirect : array('id' => '0', 'final_dest' => '0');
    }
}
