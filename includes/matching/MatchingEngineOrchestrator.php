<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fans out a match request to the registered matching engines in order, picks
 * the first non-null match that survives the exclusion policy, and emits a
 * trace step per engine outcome (skipped / no-match / excluded / matched /
 * error).
 *
 * The engine list itself comes from the `abj404_matching_engines` filter via
 * `apply_filters` (resolved upstream); this class only iterates whatever it
 * was given. Profile-based engine filtering (ABJ_404_Solution_EngineProfileResolver)
 * runs inside run().
 */
class ABJ_404_Solution_MatchingEngineOrchestrator {

    /** @var array<int, mixed> Engines may contain non-engine items (filter abuse-tolerant). */
    private $matchingEngines;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectExclusionPolicy */
    private $exclusionPolicy;

    /**
     * @param array<int, mixed> $matchingEngines
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectExclusionPolicy $exclusionPolicy
     */
    function __construct(array $matchingEngines, $logger, ABJ_404_Solution_RedirectExclusionPolicy $exclusionPolicy) {
        $this->matchingEngines = $matchingEngines;
        $this->logger = $logger;
        $this->exclusionPolicy = $exclusionPolicy;
    }

    /**
     * @param ABJ_404_Solution_MatchRequest $request
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return ABJ_404_Solution_MatchResult|null
     */
    function run(ABJ_404_Solution_MatchRequest $request, ABJ_404_Solution_FrontendPipelineTrace $trace): ?ABJ_404_Solution_MatchResult {
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
                    $trace->add('Engine: ' . $engine->getName(), 'Skipped', 'not applicable');
                    continue;
                }

                $result = $engine->match($request);

                if ($result === null) {
                    $this->logger->debugMessage('Engine returned no match: ' . $engine->getName());
                    $trace->add('Engine: ' . $engine->getName(), 'No match');
                    continue;
                }

                if ($result->getLink() === '') {
                    $this->logger->debugMessage('Engine returned empty link, skipping: ' . $engine->getName());
                    $trace->add('Engine: ' . $engine->getName(), 'No match', 'empty link');
                    continue;
                }

                if ($this->exclusionPolicy->isExcluded($result, $request->getOptions())) {
                    $this->logger->debugMessage('Match excluded: ' . $engine->getName() . ' id=' . $result->getId());
                    $trace->add('Engine: ' . $engine->getName(), 'Excluded', 'post #' . $result->getId());
                    continue;
                }

                $this->logger->debugMessage('Engine matched: ' . $engine->getName());
                $trace->add(
                    'Engine: ' . $engine->getName(),
                    'Matched',
                    'score ' . $result->getScore() . ' -> ' . $result->getLink()
                );
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warn('Matching engine error (' . $engine->getName() . '): ' . $e->getMessage());
                $trace->add('Engine: ' . $engine->getName(), 'Error', $e->getMessage());
                continue;
            }
        }

        $trace->add('Suggestion engines', 'No match found');
        return null;
    }
}
