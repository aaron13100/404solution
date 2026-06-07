<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewSnapshotStore.php';
require_once __DIR__ . '/ViewWarmupStatePolicy.php';
require_once __DIR__ . '/ViewWarmupDiagnostics.php';

interface ABJ_404_Solution_ViewSnapshotCacheHostInterface {
    /** @param string $sub @param array<string, mixed> $tableOptions @return bool */
    public function viewTableSnapshotAvailable($sub, array $tableOptions): bool;
    /** @param string $sub @param array<string, mixed> $tableOptions @return bool */
    public function viewRowsSnapshotAvailable($sub, array $tableOptions): bool;
    /** @param string $sub @param array<string, mixed> $tableOptions @return array<int|string, mixed> */
    public function getRedirectsForView($sub, $tableOptions);
    /** @param string $sub @param array<string, mixed> $tableOptions @return int */
    public function getRedirectsForViewCount(string $sub, array $tableOptions): int;
}

class ABJ_404_Solution_ViewSnapshotCache {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewSnapshotCacheHostInterface|null */
    private $host;

    /** @var ABJ_404_Solution_ViewSnapshotStore */
    private $snapshotStore;

    /** @var ABJ_404_Solution_ViewWarmupStatePolicy */
    private $warmupStatePolicy;

    /** @var ABJ_404_Solution_ViewWarmupDiagnostics */
    private $warmupDiagnostics;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger
    ) {
        $this->logger = $logger;
        $this->snapshotStore = new ABJ_404_Solution_ViewSnapshotStore($dbCore);
        $this->warmupStatePolicy = new ABJ_404_Solution_ViewWarmupStatePolicy($dbCore);
        $this->warmupDiagnostics = new ABJ_404_Solution_ViewWarmupDiagnostics($logger, $this->warmupStatePolicy);
    }

    /**
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host): void {
        $this->host = $host;
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->warmupStatePolicy->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        ABJ_404_Solution_ViewSnapshotStore::setViewSnapshotTableEnsured($value);
    }

    /**
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        return $this->snapshotStore->getViewSnapshotCacheKey($prefix, $sub, $tableOptions);
    }

    /** @return bool */
    public function acquireViewSnapshotWarmupGlobalLock(): bool {
        return $this->snapshotStore->acquireViewSnapshotWarmupGlobalLock();
    }

    /** @return void */
    public function releaseViewSnapshotWarmupGlobalLock(): void {
        $this->snapshotStore->releaseViewSnapshotWarmupGlobalLock();
    }

    /** @param string $cacheKey @return string */
    private function getViewWarmupStateOptionName(string $cacheKey): string {
        return $this->warmupStatePolicy->getViewWarmupStateOptionName($cacheKey);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function canUseViewTableSnapshotCache(array $tableOptions): bool {
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            return false;
        }
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $isLogsMaintenanceSort = ($orderBy === 'logshits' || $orderBy === 'last_used');
        $rawPerpage = $tableOptions['perpage'] ?? 0;
        return absint(is_scalar($rawPerpage) ? $rawPerpage : 0) <= 200 && !$isLogsMaintenanceSort;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewTableWarmupShapeKey(string $sub, array $tableOptions): string {
        return $this->getViewSnapshotCacheKey('abj404_view_table', $sub, $tableOptions);
    }

    /**
     * @param mixed $timing
     * @return array<string, mixed>
     */
    private function normalizeStageTiming($timing): array {
        return $this->warmupStatePolicy->normalizeStageTiming($timing);
    }

    /** @param string $stage @return string */
    private function getViewWarmupStageQueryLabel(string $stage): string {
        return $this->warmupStatePolicy->getViewWarmupStageQueryLabel($stage);
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return $this->warmupStatePolicy->getViewBuildProgressFingerprint();
    }

    /**
     * @param array<string, mixed> $state
     * @param string $stage
     * @param array<string, int>|null $currentProgress
     * @return bool
     */
    private function forgiveWarmupAttemptIfBuildProgressed(array &$state, string $stage, ?array $currentProgress = null): bool {
        return $this->warmupStatePolicy->forgiveWarmupAttemptIfBuildProgressed($state, $stage, $currentProgress);
    }

    /**
     * @param string $optionName
     * @return array<string, mixed>
     */
    private function getViewWarmupState(string $optionName): array {
        return $this->warmupStatePolicy->getViewWarmupState($optionName);
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $state
     * @return void
     */
    private function setViewWarmupState(string $optionName, array $state): void {
        $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
    }

    /**
     * Run the rows-stage warmup query and assert the snapshot landed. An
     * empty result for a filtered query is intentionally NOT cached by
     * AdminViewReadCoordinator (the same filter could match a row the user
     * just inserted but that the staged rebuild has not yet landed;
     * caching the pre-rebuild empty payload would mask the new row for
     * the TTL window). Treat that case as a successful warmup instead of
     * throwing -- there is nothing to cache, the next read will be cheap,
     * and once the rebuild lands the row a fresh read will populate the
     * cache normally.
     *
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     */
    private function runRowsWarmupStage(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host, string $sub, array $tableOptions, array $stageOptions): void {
        $rows = $host->getRedirectsForView($sub, $stageOptions);
        $rowsArray = is_array($rows) ? $rows : array();
        if ($host->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            return;
        }
        if ($this->isEmptyFilteredResult($tableOptions, $rowsArray)) {
            return;
        }
        throw new \Exception('Warmup rows stage completed but the row snapshot was not available afterward.'); // allow-raw-error: pre-existing warmup assertion moved from ViewReadService.php
    }

    /**
     * Count-stage twin of runRowsWarmupStage. A filtered count of 0 is
     * also deliberately uncached and must not be treated as failure.
     *
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     */
    private function runCountWarmupStage(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host, string $sub, array $tableOptions, array $stageOptions): void {
        $countValue = (int)$host->getRedirectsForViewCount($sub, $stageOptions);
        if ($host->viewTableSnapshotAvailable($sub, $tableOptions)) {
            return;
        }
        if ($this->isEmptyFilteredCount($tableOptions, $countValue)) {
            return;
        }
        throw new \Exception('Warmup count stage completed but the full table snapshot was not available afterward.'); // allow-raw-error: pre-existing warmup assertion moved from ViewReadService.php
    }

    /**
     * A filtered query that returns no rows is the mid-rebuild race signal
     * the AdminViewReadCoordinator deliberately refuses to cache (see its
     * shouldSkipFilteredEmptyResultCache for the full rationale).
     *
     * @param array<string, mixed> $tableOptions
     * @param array<int|string, mixed> $rows
     */
    private function isEmptyFilteredResult(array $tableOptions, array $rows): bool {
        if (!empty($rows)) {
            return false;
        }
        $filterText = $tableOptions['filterText'] ?? '';
        return is_string($filterText) && $filterText !== '';
    }

    /**
     * Count-cache twin of isEmptyFilteredResult.
     *
     * @param array<string, mixed> $tableOptions
     */
    private function isEmptyFilteredCount(array $tableOptions, int $countValue): bool {
        if ($countValue > 0) {
            return false;
        }
        $filterText = $tableOptions['filterText'] ?? '';
        return is_string($filterText) && $filterText !== '';
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    public function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        if (!$this->canUseViewTableSnapshotCache($tableOptions)) {
            return array(
                'status' => 'ready',
                'ready' => true,
                'uncached' => true,
                'stage' => 'rows',
                'stageNumber' => 1,
                'queryLabel' => 'getRedirectsForView',
                'message' => 'This table shape is not snapshot-cacheable.',
            );
        }

        $host = $this->host;
        if ($host === null) {
            throw new \RuntimeException('ViewSnapshotCache requires host (call setHost first)'); // allow-raw-error: assertion, should never reach user
        }

        $shapeKey = $this->getViewTableWarmupShapeKey($sub, $tableOptions);
        $optionName = $this->getViewWarmupStateOptionName($shapeKey);
        $state = $this->getViewWarmupState($optionName);
        $now = time();

        if ($host->viewTableSnapshotAvailable($sub, $tableOptions)) {
            $state['status'] = 'ready';
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
            $state['stage_completed_at'] = $now;
            $state['last_error'] = '';
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, true);
        }

        if ($host->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
        } else {
            $state['stage'] = 'rows';
            $state['query_label'] = 'getRedirectsForView';
        }

        $stage = (string)$state['stage'];
        $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
        $attemptCountRaw = $attempts[$stage] ?? 0;
        $attemptCount = is_scalar($attemptCountRaw) ? intval($attemptCountRaw) : 0;

        if ($state['status'] === 'running') {
            $stageStartedAt = $state['stage_started_at'] ?? 0;
            $elapsed = $now - (is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0);
            if ($elapsed <= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS) {
                return $this->formatViewWarmupResponse($state, false);
            }
            $currentBuildProgress = $this->getViewBuildProgressFingerprint();
            if ($this->forgiveWarmupAttemptIfBuildProgressed($state, $stage, $currentBuildProgress)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
                $attemptCountRaw = $attempts[$stage] ?? 0;
                $attemptCount = is_scalar($attemptCountRaw) ? intval($attemptCountRaw) : 0;
            }
            if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
                $state['status'] = 'blocked';
                $previousLastError = $state['last_error'] ?? '';
                $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
                $state['last_error'] = 'Previous warmup stage was killed or stalled too many times.'
                    . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
                $this->logViewWarmupFailure($sub, $tableOptions, $state);
                $this->setViewWarmupState($optionName, $state);
                return $this->formatViewWarmupResponse($state, false);
            }
            $loggedKey = $stage . ':' . (is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0);
            $loggedStaleByStage = is_array($state['logged_stale_by_stage'] ?? null) ? $state['logged_stale_by_stage'] : array();
            if (empty($loggedStaleByStage[$loggedKey])) {
                $this->logStaleViewWarmupStage($sub, $tableOptions, $state, $elapsed, $attemptCount);
                $loggedStaleByStage[$loggedKey] = 1;
                $state['logged_stale_by_stage'] = $loggedStaleByStage;
            }
        }

        if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $previousLastError = $state['last_error'] ?? '';
            $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
            if (!$this->isViewWarmupErrorDiagnostic($previousError)) {
                $attempts[$stage] = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS - 1;
                $state['attempts_by_stage'] = $attempts;
                $attemptCount = is_scalar($attempts[$stage] ?? 0) ? intval($attempts[$stage]) : 0;
            }
        }

        if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $state['status'] = 'blocked';
            $state['last_error'] = 'Warmup stage reached the retry limit.'
                . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        }

        if (!$this->acquireViewSnapshotWarmupGlobalLock()) {
            $state['status'] = 'running';
            $state['last_error'] = 'Another table cache warmup is already running for this site.';
            return $this->formatViewWarmupResponse($state, false, array(
                'locked' => true,
                'lockScope' => 'site',
                'retryAfterMs' => 2500,
            ));
        }

        $attempts[$stage] = $attemptCount + 1;
        $state['status'] = 'running';
        $state['stage_started_at'] = $now;
        $state['stage_completed_at'] = 0;
        $state['attempts_by_stage'] = $attempts;
        $state['query_label'] = $this->getViewWarmupStageQueryLabel($stage);
        $state['last_error'] = '';
        $state['build_progress_at_stage_start'] = $this->getViewBuildProgressFingerprint();
        $this->setViewWarmupState($optionName, $state);

        $stageOptions = $tableOptions;
        $stageOptions['_abj404_query_timeout'] = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
        $stageOptions['_abj404_throw_on_view_query_error'] = true;

        $startMs = microtime(true);
        try {
            if ($stage === 'rows') {
                $this->runRowsWarmupStage($host, $sub, $tableOptions, $stageOptions);
                $state['status'] = 'idle';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            } else {
                $this->runCountWarmupStage($host, $sub, $tableOptions, $stageOptions);
                $state['status'] = 'ready';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            }
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $state['stage_completed_at'] = time();
            $state['last_error'] = '';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->normalizeStageTiming($timingsByStage[$stage] ?? null);
            $timings['last_ms'] = $elapsedMs;
            $timings['max_ms'] = max($timings['max_ms'], $elapsedMs);
            $timings['last_completed_at'] = $state['stage_completed_at'];
            $timings['last_error'] = '';
            $timingsByStage[$stage] = $timings;
            $state['timings_by_stage'] = $timingsByStage;

            $this->logger->debugMessage(sprintf(
                "[warmup] shape=%s stage=%s ms=%d attempts=%d error=",
                substr($shapeKey, 0, 8),
                $stage,
                $elapsedMs,
                $attemptCount + 1
            ));

            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, $state['status'] === 'ready');
        } catch (Throwable $e) {
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $errorMessage = $e->getMessage();
            $state['last_error'] = $errorMessage;
            $state['stage_completed_at'] = time();
            $currentAttempts = $attempts[$stage] ?? 0;
            if ($this->forgiveWarmupAttemptIfBuildProgressed($state, $stage)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : $attempts;
                $rawAttemptCount = $attempts[$stage] ?? 0;
                $currentAttempts = is_scalar($rawAttemptCount) ? intval($rawAttemptCount) : 0;
            }
            $state['status'] = ($currentAttempts >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) ? 'blocked' : 'idle';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->normalizeStageTiming($timingsByStage[$stage] ?? null);
            $timings['last_error'] = $errorMessage;
            $timingsByStage[$stage] = $timings;
            $state['timings_by_stage'] = $timingsByStage;

            $this->logger->debugMessage(sprintf(
                "[warmup] shape=%s stage=%s ms=%d attempts=%d error=%s",
                substr($shapeKey, 0, 8),
                $stage,
                $elapsedMs,
                $attemptCount + 1,
                $errorMessage
            ));

            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        } finally {
            $this->releaseViewSnapshotWarmupGlobalLock();
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param bool $ready
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function formatViewWarmupResponse(array $state, bool $ready, array $extra = array()): array {
        return $this->warmupStatePolicy->formatViewWarmupResponse($state, $ready, $extra);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @param int $elapsed
     * @param int $attemptCount
     * @return void
     */
    private function logStaleViewWarmupStage(string $sub, array $tableOptions, array $state, int $elapsed, int $attemptCount): void {
        $this->warmupDiagnostics->logStaleViewWarmupStage($sub, $tableOptions, $state, $elapsed, $attemptCount);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @return void
     */
    private function logViewWarmupFailure(string $sub, array $tableOptions, array $state): void {
        $this->warmupDiagnostics->logViewWarmupFailure($sub, $tableOptions, $state);
    }

    /** @param string $lastError @return bool */
    private function isViewWarmupErrorDiagnostic(string $lastError): bool {
        return $this->warmupStatePolicy->isViewWarmupErrorDiagnostic($lastError);
    }

    /**
     * @param string $cacheKey
     * @param bool $allowExpired
     * @param bool $respectCooldown
     * @return array<int|string, mixed>|null
     */
    public function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        return $this->snapshotStore->getViewRowsSnapshotFromTable($cacheKey, $allowExpired, $respectCooldown);
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    public function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        $this->snapshotStore->setViewRowsSnapshotToTable($cacheKey, $sub, $rows, $ttlSeconds);
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<int|string, mixed>|null
     */
    public function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
        return $this->snapshotStore->waitForViewRowsSnapshotFromTable($cacheKey, $timeoutMs);
    }

}
