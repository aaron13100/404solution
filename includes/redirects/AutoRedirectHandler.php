<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Promotes matching-engine results into automatic redirects and emits them.
 */
class ABJ_404_Solution_AutoRedirectHandler {

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_MatchingEngineOrchestrator */
    private $matchingEngineOrchestrator;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_FrontendHitRecorder */
    private $hitRecorder;

    /**
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_MatchingEngineOrchestrator $matchingEngineOrchestrator
     * @param ABJ_404_Solution_NotFoundResponseService $notFoundResponse
     * @param ABJ_404_Solution_FrontendHitRecorder $hitRecorder
     */
    function __construct($redirectsRepository, $matchingEngineOrchestrator, $notFoundResponse, $hitRecorder) {
        $this->redirectsRepository = $redirectsRepository;
        $this->matchingEngineOrchestrator = $matchingEngineOrchestrator;
        $this->notFoundResponse = $notFoundResponse;
        $this->hitRecorder = $hitRecorder;
    }

    /**
     * @param string $requestedURL
     * @param string $urlSlugOnly
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool True when a matching engine produced and emitted a redirect.
     */
    function promoteAndRedirectIfMatched(
        string $requestedURL,
        string $urlSlugOnly,
        array $options,
        ABJ_404_Solution_FrontendPipelineTrace $trace
    ): bool {
        $matchRequest = new ABJ_404_Solution_MatchRequest($requestedURL, $urlSlugOnly, $options);
        $matchResult = $this->matchingEngineOrchestrator->run($matchRequest, $trace);
        if ($matchResult === null) {
            return false;
        }

        $defaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
        $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
            $requestedURL,
            (string)ABJ404_STATUS_AUTO,
            $matchResult->getType(),
            $matchResult->getId(),
            $defaultRedirect,
            0,
            $matchResult->getEngineName(),
            $matchResult->getScore()
        ));

        $resolvedLink = $this->resolveMatchLink($matchResult);
        $this->hitRecorder->record($requestedURL, $resolvedLink, $matchResult->getEngineName(), null, $trace->getSteps());
        $this->notFoundResponse->forceRedirect(esc_url($resolvedLink), (int)$defaultRedirect);
        exit;
    }

    /**
     * @param ABJ_404_Solution_MatchResult $matchResult
     * @return string
     */
    private function resolveMatchLink(ABJ_404_Solution_MatchResult $matchResult): string {
        $resolvedLink = $matchResult->getLink();
        if ($matchResult->getId() === '' || $matchResult->getId() === '0') {
            return $resolvedLink;
        }

        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray(
            $matchResult->getId() . '|' . $matchResult->getType(),
            0
        );
        if (is_array($permalink) && !empty($permalink['link']) && is_string($permalink['link']) && $permalink['link'] !== 'dunno') {
            return $permalink['link'];
        }
        return $resolvedLink;
    }
}
