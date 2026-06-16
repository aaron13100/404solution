<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parameter object describing a single redirect / 404 hit to be logged,
 * passed to {@see ABJ_404_Solution_LogWriteInterface::logRedirectHit()}.
 *
 * Replaces a 5-positional-parameter method signature (criterion 220
 * Interface Size) across the interface, its implementations, and the
 * duck-typed call_user_func forwarders. Plain data carrier read directly by
 * the writer; use {@see self::create()} at call sites.
 */
// allow-no-test-found: pure parameter-object DTO (typed public fields; create() factory only forwards to the constructor, no behavior); fields consumed by LogWriteInterface::logRedirectHit(), exercised in FrontendRedirect404PipelineEndToEndTest and LogQueueTest.
class ABJ_404_Solution_RedirectHitLogEntry {

    /** @var string */
    public string $requestedUrl;

    /** @var string */
    public string $action;

    /** @var string */
    public string $matchReason;

    /** @var string|null */
    public ?string $requestedUrlDetail;

    /** @var list<array{step: string, outcome: string, detail: string}>|null */
    public ?array $pipelineTrace;

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    public function __construct(string $requestedUrl, string $action, string $matchReason,
            ?string $requestedUrlDetail = null, ?array $pipelineTrace = null) {
        $this->requestedUrl = $requestedUrl;
        $this->action = $action;
        $this->matchReason = $matchReason;
        $this->requestedUrlDetail = $requestedUrlDetail;
        $this->pipelineTrace = $pipelineTrace;
    }

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     * @return self
     */
    public static function create(string $requestedUrl, string $action, string $matchReason,
            ?string $requestedUrlDetail = null, ?array $pipelineTrace = null): self {
        return new self($requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace);
    }
}
