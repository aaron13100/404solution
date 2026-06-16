<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional dependency bundle for
 * {@see ABJ_404_Solution_NGramRebuilder::__construct()}.
 *
 * Replaces a 6-positional-parameter constructor (criterion 220 Interface
 * Size). Every field is nullable; the consumer keeps its per-field
 * `?? abj_service(...)` resolution and the post-resolution type guard. The
 * dbCore field is legitimately null at the NGramFilter call site.
 */
// allow-no-test-found: pure dependency-bundle DTO (nullable public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_NGramRebuilder, driven through the spell-check n-gram prefilter path in SpellCheckerNGramPrefilterTest and SpellCheckerPrefilteringTest.
class ABJ_404_Solution_NGramRebuilderDependencies {

    /** @var ABJ_404_Solution_DatabaseCore|null */
    public $dbCore;

    /** @var ABJ_404_Solution_Logging|null */
    public $logging;

    /** @var ABJ_404_Solution_Functions|null */
    public $functions;

    /** @var ABJ_404_Solution_NGramExtractor|null */
    public $extractor;

    /** @var ABJ_404_Solution_NGramCacheRepository|null */
    public $repo;

    /** @var ABJ_404_Solution_NGramCoveragePolicy|null */
    public $coveragePolicy;

    /**
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_NGramExtractor|null $extractor
     * @param ABJ_404_Solution_NGramCacheRepository|null $repo
     * @param ABJ_404_Solution_NGramCoveragePolicy|null $coveragePolicy
     */
    public function __construct($dbCore = null, $logging = null, $functions = null,
            $extractor = null, $repo = null, $coveragePolicy = null) {
        $this->dbCore = $dbCore;
        $this->logging = $logging;
        $this->functions = $functions;
        $this->extractor = $extractor;
        $this->repo = $repo;
        $this->coveragePolicy = $coveragePolicy;
    }
}
