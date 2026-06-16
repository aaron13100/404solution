<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional dependency bundle shared by
 * {@see ABJ_404_Solution_RequestIgnoreNormalizer::__construct()} and the
 * {@see ABJ_404_Solution_FrontendLegacyAdapters::resolveRequestIgnoreNormalizer()}
 * facade.
 *
 * Replaces a 6-positional-parameter constructor and a 6-positional facade
 * argument list (criterion 220 Interface Size, findings #1 and #6). Every
 * field is nullable; the normalizer keeps its per-field ?? abj_service(...)
 * resolution. Fields are untyped to preserve the existing null-tolerant
 * parameters (optionsProvider is any object exposing getOptions()).
 */
// allow-no-test-found: pure dependency-bundle DTO (nullable public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_RequestIgnoreNormalizer, constructed and exercised in RequestIgnoreNormalizerTest.
class ABJ_404_Solution_RequestIgnoreNormalizerDependencies {

    /** @var mixed Object exposing getOptions(). */
    public $optionsProvider;

    /** @var ABJ_404_Solution_Functions|null */
    public $functions;

    /** @var ABJ_404_Solution_Logging|null */
    public $logging;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface|null */
    public $redirectsRepo;

    /** @var ABJ_404_Solution_LogsRepositoryInterface|null */
    public $logsRepo;

    /** @var ABJ_404_Solution_NotFoundResponseService|null */
    public $notFoundResponse;

    /**
     * @param mixed $optionsProvider Object exposing getOptions().
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RedirectsRepositoryInterface|null $redirectsRepo
     * @param ABJ_404_Solution_LogsRepositoryInterface|null $logsRepo
     * @param ABJ_404_Solution_NotFoundResponseService|null $notFoundResponse
     */
    public function __construct($optionsProvider = null, $functions = null, $logging = null,
            $redirectsRepo = null, $logsRepo = null, $notFoundResponse = null) {
        $this->optionsProvider = $optionsProvider;
        $this->functions = $functions;
        $this->logging = $logging;
        $this->redirectsRepo = $redirectsRepo;
        $this->logsRepo = $logsRepo;
        $this->notFoundResponse = $notFoundResponse;
    }
}
