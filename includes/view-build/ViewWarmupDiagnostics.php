<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes structured diagnostics for admin view snapshot warmup failures.
 */
class ABJ_404_Solution_ViewWarmupDiagnostics {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewWarmupStatePolicy */
    private $statePolicy;

    /**
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ViewWarmupStatePolicy $statePolicy
     */
    public function __construct($logger, ABJ_404_Solution_ViewWarmupStatePolicy $statePolicy) {
        $this->logger = $logger;
        $this->statePolicy = $statePolicy;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @param int $elapsed
     * @param int $attemptCount
     * @return void
     */
    public function logStaleViewWarmupStage(string $sub, array $tableOptions, array $state, int $elapsed, int $attemptCount): void {
        $details = array(
            'stage' => is_string($state['stage'] ?? null) ? $state['stage'] : '',
            'query_label' => is_string($state['query_label'] ?? null) ? $state['query_label'] : '',
            'elapsed_seconds' => $elapsed,
            'subpage' => $sub,
            'attempt_count' => $attemptCount,
            'table_shape' => $this->tableShapeDetails($tableOptions),
        );
        $message = 'Table cache warmup stage appears stalled: ' . json_encode($details);
        $this->logger->warn($message);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @return void
     */
    public function logViewWarmupFailure(string $sub, array $tableOptions, array $state): void {
        $stage = is_string($state['stage'] ?? null) ? $state['stage'] : 'rows';
        $details = array(
            'status' => is_string($state['status'] ?? null) ? $state['status'] : '',
            'stage' => is_string($state['stage'] ?? null) ? $state['stage'] : '',
            'stage_number' => $this->statePolicy->getViewWarmupStageNumber($stage),
            'query_label' => is_string($state['query_label'] ?? null) ? $state['query_label'] : '',
            'last_error' => is_string($state['last_error'] ?? null) ? $state['last_error'] : '',
            'subpage' => $sub,
            'attempts_by_stage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'table_shape' => $this->tableShapeDetails($tableOptions),
        );
        $message = 'Table cache warmup failed: ' . json_encode($details);
        $this->logger->errorMessage($message);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    private function tableShapeDetails(array $tableOptions): array {
        return array(
            'filter' => $tableOptions['filter'] ?? null,
            'orderby' => $tableOptions['orderby'] ?? null,
            'order' => $tableOptions['order'] ?? null,
            'paged' => $tableOptions['paged'] ?? null,
            'perpage' => $tableOptions['perpage'] ?? null,
            'filterText_length' => is_string($tableOptions['filterText'] ?? null) ? strlen($tableOptions['filterText']) : 0,
            'score_range' => $tableOptions['score_range'] ?? null,
        );
    }
}
