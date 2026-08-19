<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional dependency bundle for
 * {@see ABJ_404_Solution_NotFoundResponseService::__construct()}.
 *
 * Replaces a 6-positional-parameter constructor (criterion 220 Interface
 * Size). Every field is nullable; the consumer keeps its per-field
 * `?? abj_service(...)` resolution. Fields are untyped to preserve the
 * constructor's existing null-tolerant interface parameters.
 */
// allow-no-test-found: pure dependency-bundle DTO (nullable public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_NotFoundResponseService, constructed and exercised in NotFoundResponseServiceTest.
class ABJ_404_Solution_NotFoundResponseDependencies {

    /** @var ABJ_404_Solution_Functions|null */
    public $functions;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface|null */
    public $redirectsRepo;

    /** @var ABJ_404_Solution_LogsRepositoryInterface|null */
    public $logsRepo;

    /** @var ABJ_404_Solution_Logging|null */
    public $logging;

    /** @var ABJ_404_Solution_PluginLogicOptionsResolver|null */
    public $optionsRepository;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker|null */
    public $previousRequestCookieTracker;

    /** @var ABJ_404_Solution_NearMissRecorder|null */
    public $nearMissRecorder;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_RedirectsRepositoryInterface|null $redirectsRepo
     * @param ABJ_404_Solution_LogsRepositoryInterface|null $logsRepo
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_PluginLogicOptionsResolver|null $optionsRepository
     * @param ABJ_404_Solution_PreviousRequestCookieTracker|null $previousRequestCookieTracker
     * @param ABJ_404_Solution_NearMissRecorder|null $nearMissRecorder
     */
    public function __construct($functions = null, $redirectsRepo = null, $logsRepo = null,
            $logging = null, $optionsRepository = null, $previousRequestCookieTracker = null,
            $nearMissRecorder = null) {
        $this->functions = $functions;
        $this->redirectsRepo = $redirectsRepo;
        $this->logsRepo = $logsRepo;
        $this->logging = $logging;
        $this->optionsRepository = $optionsRepository;
        $this->previousRequestCookieTracker = $previousRequestCookieTracker;
        $this->nearMissRecorder = $nearMissRecorder;
    }
}
