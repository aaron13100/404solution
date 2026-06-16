<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional dependency bundle for
 * {@see ABJ_404_Solution_SpellChecker::__construct()}.
 *
 * Replaces a 7-positional-parameter constructor (criterion 220 Interface
 * Size). Every field is nullable and defaults to null; the consumer keeps
 * its per-field ?? abj_service(...) resolution, its abj_service_optional
 * notFoundResponse path, and the special branch that falls back the
 * viewReadService to a raw contentRepository exposing getRedirectsWithRegEx.
 * Fields are untyped to preserve the constructor's null-tolerant parameters.
 */
// allow-no-test-found: pure dependency-bundle DTO (nullable public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_SpellChecker, constructed and exercised in SpellCheckerAlgorithmTest and SpellCheckerHighLevelTest.
class ABJ_404_Solution_SpellCheckerDependencies {

    /** @var ABJ_404_Solution_Functions|null */
    public $functions;

    /** @var ABJ_404_Solution_PluginLogic|null */
    public $pluginLogic;

    /** @var ABJ_404_Solution_ContentRepository|null */
    public $contentRepository;

    /** @var ABJ_404_Solution_Logging|null */
    public $logging;

    /** @var ABJ_404_Solution_PermalinkCache|null */
    public $permalinkCache;

    /** @var ABJ_404_Solution_NGramFilter|null */
    public $ngramFilter;

    /** @var ABJ_404_Solution_ViewReadService|null */
    public $viewReadService;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_PluginLogic|null $pluginLogic
     * @param ABJ_404_Solution_ContentRepository|null $contentRepository
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache
     * @param ABJ_404_Solution_NGramFilter|null $ngramFilter
     * @param ABJ_404_Solution_ViewReadService|null $viewReadService
     */
    public function __construct($functions = null, $pluginLogic = null, $contentRepository = null,
            $logging = null, $permalinkCache = null, $ngramFilter = null, $viewReadService = null) {
        $this->functions = $functions;
        $this->pluginLogic = $pluginLogic;
        $this->contentRepository = $contentRepository;
        $this->logging = $logging;
        $this->permalinkCache = $permalinkCache;
        $this->ngramFilter = $ngramFilter;
        $this->viewReadService = $viewReadService;
    }
}
