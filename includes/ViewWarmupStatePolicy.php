<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes and advances the persisted admin table warmup state machine.
 */
class ABJ_404_Solution_ViewWarmupStatePolicy {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    /** @param ABJ_404_Solution_DatabaseCore $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewSnapshotCache requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }

    /** @param string $cacheKey @return string */
    public function getViewWarmupStateOptionName(string $cacheKey): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_warmup_' . md5((string)$cacheKey);
    }

    /**
     * @param mixed $state
     * @return array<string, mixed>
     */
    public function normalizeViewWarmupState($state): array {
        $default = $this->defaultWarmupState();
        if (!is_array($state)) {
            return $default;
        }
        /** @var array<string, mixed> $out */
        $out = array_merge($default, $state);
        $out['status'] = $this->normalizeStatus($out['status'] ?? null);
        $out['stage'] = $this->normalizeStage($out['stage'] ?? null);
        $stageStartedAt = $out['stage_started_at'] ?? 0;
        $stageCompletedAt = $out['stage_completed_at'] ?? 0;
        $out['stage_started_at'] = is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0;
        $out['stage_completed_at'] = is_scalar($stageCompletedAt) ? intval($stageCompletedAt) : 0;
        $out['attempts_by_stage'] = $this->normalizeAttempts($out['attempts_by_stage'] ?? null);
        $out['timings_by_stage'] = $this->normalizeTimings($out['timings_by_stage'] ?? null);
        $out['query_label'] = is_string($out['query_label'] ?? null) ? $out['query_label'] : $this->getViewWarmupStageQueryLabel((string)$out['stage']);
        $out['last_error'] = is_string($out['last_error'] ?? null) ? $out['last_error'] : '';
        $out['logged_stale_by_stage'] = is_array($out['logged_stale_by_stage']) ? $out['logged_stale_by_stage'] : array();
        $out['build_progress_at_stage_start'] = is_array($out['build_progress_at_stage_start'] ?? null)
            ? $out['build_progress_at_stage_start'] : array();
        return $out;
    }

    /** @return array<string, mixed> */
    private function defaultWarmupState(): array {
        return array(
            'status' => 'idle',
            'stage' => 'rows',
            'stage_started_at' => 0,
            'stage_completed_at' => 0,
            'attempts_by_stage' => array('rows' => 0, 'count' => 0),
            'timings_by_stage' => array(
                'rows' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
                'count' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
            ),
            'query_label' => 'getRedirectsForView',
            'last_error' => '',
            'logged_stale_by_stage' => array(),
            'build_progress_at_stage_start' => array(),
        );
    }

    /** @param mixed $status @return string */
    private function normalizeStatus($status): string {
        $normalized = is_string($status) ? $status : 'idle';
        return in_array($normalized, array('idle', 'running', 'ready', 'blocked', 'error'), true)
            ? $normalized : 'idle';
    }

    /** @param mixed $stage @return string */
    private function normalizeStage($stage): string {
        $normalized = is_string($stage) ? $stage : 'rows';
        return in_array($normalized, array('rows', 'count'), true) ? $normalized : 'rows';
    }

    /**
     * @param mixed $attempts
     * @return array<string, int>
     */
    private function normalizeAttempts($attempts): array {
        $attempts = is_array($attempts) ? $attempts : array();
        $attemptsRows = $attempts['rows'] ?? 0;
        $attemptsCount = $attempts['count'] ?? 0;
        return array(
            'rows' => is_scalar($attemptsRows) ? intval($attemptsRows) : 0,
            'count' => is_scalar($attemptsCount) ? intval($attemptsCount) : 0,
        );
    }

    /**
     * @param mixed $timings
     * @return array<string, array<string, mixed>>
     */
    private function normalizeTimings($timings): array {
        $timings = is_array($timings) ? $timings : array();
        return array(
            'rows' => $this->normalizeStageTiming($timings['rows'] ?? null),
            'count' => $this->normalizeStageTiming($timings['count'] ?? null),
        );
    }

    /**
     * @param mixed $timing
     * @return array<string, mixed>
     */
    public function normalizeStageTiming($timing): array {
        $default = array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => '');
        if (!is_array($timing)) {
            return $default;
        }
        $lastMs = $timing['last_ms'] ?? 0;
        $maxMs = $timing['max_ms'] ?? 0;
        $lastCompletedAt = $timing['last_completed_at'] ?? 0;
        $lastError = $timing['last_error'] ?? '';
        return array(
            'last_ms' => is_scalar($lastMs) ? intval($lastMs) : 0,
            'max_ms' => is_scalar($maxMs) ? intval($maxMs) : 0,
            'last_completed_at' => is_scalar($lastCompletedAt) ? intval($lastCompletedAt) : 0,
            'last_error' => is_string($lastError) ? $lastError : '',
        );
    }

    /** @param string $stage @return string */
    public function getViewWarmupStageQueryLabel(string $stage): string {
        return $stage === 'count' ? 'getRedirectsForViewCount' : 'getRedirectsForView';
    }

    /** @param string $stage @return int */
    public function getViewWarmupStageNumber(string $stage): int {
        return $stage === 'count' ? 2 : 1;
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return array(
            'started_at' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('started_at', 0),
            'current_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('current_stage', 0),
            'last_started_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('last_started_stage', 0),
            'last_completed_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('last_completed_stage', 0),
            's2_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s2_high_water', 0),
            's4_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s4_high_water', 0),
            's5_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s5_high_water', 0),
        );
    }

    /**
     * @param mixed $baseline
     * @param array<string, int>|null $current
     * @return bool
     */
    private function viewBuildProgressAdvancedSince($baseline, ?array $current = null): bool {
        if (!is_array($baseline) || empty($baseline)) {
            return false;
        }
        $current = $current ?? $this->getViewBuildProgressFingerprint();
        foreach (array('current_stage', 's2_high_water', 's4_high_water', 's5_high_water') as $key) {
            $before = is_scalar($baseline[$key] ?? null) ? intval($baseline[$key]) : 0;
            $after = is_scalar($current[$key] ?? null) ? intval($current[$key]) : 0;
            if ($after > $before) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param string $stage
     * @param array<string, int>|null $currentProgress
     * @return bool
     */
    public function forgiveWarmupAttemptIfBuildProgressed(array &$state, string $stage, ?array $currentProgress = null): bool {
        if (!$this->viewBuildProgressAdvancedSince($state['build_progress_at_stage_start'] ?? array(), $currentProgress)) {
            return false;
        }
        $attempts = is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
        $rawAttempt = $attempts[$stage] ?? 0;
        $attempts[$stage] = max(0, (is_scalar($rawAttempt) ? intval($rawAttempt) : 0) - 1);
        $state['attempts_by_stage'] = $attempts;
        $state['build_progress_at_stage_start'] = is_array($currentProgress)
            ? $currentProgress : $this->getViewBuildProgressFingerprint();
        return true;
    }

    /**
     * @param string $optionName
     * @return array<string, mixed>
     */
    public function getViewWarmupState(string $optionName): array {
        if (!function_exists('get_option')) {
            return $this->normalizeViewWarmupState(null);
        }
        return $this->normalizeViewWarmupState(get_option($optionName, array()));
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $state
     * @return void
     */
    public function setViewWarmupState(string $optionName, array $state): void {
        if (function_exists('update_option')) {
            update_option($optionName, $state, false);
        } else if (function_exists('add_option')) {
            add_option($optionName, $state, '', false);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param bool $ready
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function formatViewWarmupResponse(array $state, bool $ready, array $extra = array()): array {
        $stageValue = $state['stage'] ?? 'rows';
        $stage = is_string($stageValue) ? $stageValue : 'rows';
        $statusValue = $state['status'] ?? 'idle';
        $status = is_string($statusValue) ? $statusValue : 'idle';
        $stageStartedAt = $state['stage_started_at'] ?? 0;
        $stageCompletedAt = $state['stage_completed_at'] ?? 0;
        $lastError = $state['last_error'] ?? '';
        $response = array(
            'status' => $status,
            'ready' => $ready || $status === 'ready',
            'stage' => $stage,
            'stageNumber' => $this->getViewWarmupStageNumber($stage),
            'queryLabel' => $this->getViewWarmupStageQueryLabel($stage),
            'stageStartedAt' => is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0,
            'stageCompletedAt' => is_scalar($stageCompletedAt) ? intval($stageCompletedAt) : 0,
            'attemptsByStage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'timingsByStage' => is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array(),
            'lastError' => is_string($lastError) ? $lastError : '',
        );
        foreach ($extra as $key => $value) {
            if (is_string($key)) {
                $response[$key] = $value;
            }
        }
        return $response;
    }

    /** @param string $lastError @return bool */
    public function isViewWarmupErrorDiagnostic(string $lastError): bool {
        if ($lastError === '') {
            return false;
        }
        return $lastError !== 'Warmup stage reached the retry limit.'
            && $lastError !== 'Previous warmup stage was killed or stalled too many times.';
    }
}
