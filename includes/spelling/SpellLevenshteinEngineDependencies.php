<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Eagerly-built dependency bundle for
 * {@see ABJ_404_Solution_SpellLevenshteinEngine::__construct()}.
 *
 * Replaces a 7-positional-parameter constructor (criterion 220 Interface
 * Size). All collaborators are required (non-null); the consumer reads the
 * fields directly. Fields are untyped to mirror the constructor's existing
 * duck-typed parameters (production and test doubles both supported);
 * $separatingCharacters carries the existing array hint.
 */
// allow-no-test-found: pure dependency-bundle DTO (public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_SpellLevenshteinEngine, driven through the spell-check matching path in SpellCheckerMatchingTest and SpellCheckerAlgorithmTest.
class ABJ_404_Solution_SpellLevenshteinEngineDependencies {

    /** @var ABJ_404_Solution_Functions */
    public $functions;

    /** @var ABJ_404_Solution_PluginLogic */
    public $logic;

    /** @var ABJ_404_Solution_Logging */
    public $logger;

    /** @var ABJ_404_Solution_ContentRepository */
    public $contentRepository;

    /** @var ABJ_404_Solution_NGramFilter */
    public $ngramFilter;

    /** @var ABJ_404_Solution_SpellURLMatcher */
    public $urlMatcher;

    /** @var array<int, string> */
    public array $separatingCharacters;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_PluginLogic $logic
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepository $contentRepository
     * @param ABJ_404_Solution_NGramFilter $ngramFilter
     * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
     * @param array<int, string> $separatingCharacters
     */
    public function __construct($functions, $logic, $logger, $contentRepository, $ngramFilter,
            $urlMatcher, array $separatingCharacters) {
        $this->functions = $functions;
        $this->logic = $logic;
        $this->logger = $logger;
        $this->contentRepository = $contentRepository;
        $this->ngramFilter = $ngramFilter;
        $this->urlMatcher = $urlMatcher;
        $this->separatingCharacters = $separatingCharacters;
    }
}
