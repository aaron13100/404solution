<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes frontend redirect-hit log records when a compatible log writer exists.
 */
class ABJ_404_Solution_FrontendHitRecorder {

    /** @var mixed */
    private $logsRepository;

    /**
     * @param mixed $logsRepository Object exposing logRedirectHit().
     */
    function __construct($logsRepository) {
        $this->logsRepository = $logsRepository;
    }

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    function record(string $requestedUrl, string $action, string $matchReason, ?string $requestedUrlDetail = null, ?array $pipelineTrace = null): void {
        if (!is_object($this->logsRepository) || !is_callable(array($this->logsRepository, 'logRedirectHit'))) {
            return;
        }
        call_user_func(array($this->logsRepository, 'logRedirectHit'), ABJ_404_Solution_RedirectHitLogEntry::create($requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace));
    }
}
