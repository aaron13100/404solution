<?php

// allow-no-test-found: behavior covered through ViewSnapshotCache->warmViewTableSnapshotStage() facade; DataAccessViewSnapshotCacheTest and ViewWarmupInstrumentationTest exercise every warmup stage, locking path, attempt-count forgiveness, and diagnostic shape through that public entry

if (!defined('ABSPATH')) {
    exit;
}

/**
 * State machine for the multi-attempt, multi-stage, lock-protected warmup of
 * view_table snapshots.
 *
 * Twin of the staged-rebuild pipeline orchestrator: this one drives the
 * rows-then-count two-stage warmup of the in-memory snapshot cache that the
 * admin tables read on every page request. The rebuild orchestrator drives
 * the multi-stage RENAME-swap build of view_done; this orchestrator drives
 * the much smaller two-stage warmup of the per-shape snapshot.
 *
 * Owns:
 *
 *   1. {@see warmViewTableSnapshotStage()} - the entry point called by the
 *      warmup AJAX endpoint via ViewSnapshotCache. Maintains a per-shape
 *      state record (status, stage, attempt counts per stage, timings,
 *      last error, build-progress fingerprint, logged-stale dedup) and
 *      dispatches the appropriate stage on each call.
 *
 *   2. The two-stage dispatch ({@see dispatchWarmupStage()},
 *      {@see runRowsWarmupStage()}, {@see runCountWarmupStage()}). Each
 *      stage runs the underlying host callback, asserts the resulting
 *      snapshot is available, and short-circuits when the filtered query
 *      legitimately returns zero rows (see {@see isEmptyFilteredResult()},
 *      {@see isEmptyFilteredCount()}).
 *
 *   3. Coordination with the three peer collaborators:
 *
 *      - ViewSnapshotStore: cache-key derivation, snapshot read/write,
 *        global warmup lock acquire/release.
 *      - ViewWarmupStatePolicy: state record read/write, attempt-count
 *        forgiveness when the underlying build has progressed, query-label
 *        bookkeeping, response shaping.
 *      - ViewWarmupDiagnostics: stale-stage and failure logging.
 *
 * Split out 2026-06-08 from ViewSnapshotCache (which previously held both
 * the facade pass-throughs and this state machine in one 544-line file).
 * ViewSnapshotCache now exposes warmViewTableSnapshotStage as a thin
 * delegating entry point for the AJAX warmup endpoint and AdminViewReadCoordinator;
 * the state machine itself lives here.
 */
class ABJ_404_Solution_ViewSnapshotWarmupOrchestrator {

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
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ViewSnapshotStore $snapshotStore
     * @param ABJ_404_Solution_ViewWarmupStatePolicy $warmupStatePolicy
     * @param ABJ_404_Solution_ViewWarmupDiagnostics $warmupDiagnostics
     */
    public function __construct(
        $logger,
        ABJ_404_Solution_ViewSnapshotStore $snapshotStore,
        ABJ_404_Solution_ViewWarmupStatePolicy $warmupStatePolicy,
        ABJ_404_Solution_ViewWarmupDiagnostics $warmupDiagnostics
    ) {
        $this->logger = $logger;
        $this->snapshotStore = $snapshotStore;
        $this->warmupStatePolicy = $warmupStatePolicy;
        $this->warmupDiagnostics = $warmupDiagnostics;
    }

    /**
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host): void {
        $this->host = $host;
    }

    /**
     * Drive the next warmup stage for the given table shape. Maintains the
     * per-shape state record and returns the structured response the AJAX
     * endpoint passes back to the browser.
     *
     * Caller (ViewSnapshotCache) is responsible for the canUseViewTableSnapshotCache
     * gate. By the time we are here the shape IS snapshot-cacheable.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    public function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        $host = $this->host;
        if ($host === null) {
            throw new \RuntimeException('ViewSnapshotWarmupOrchestrator requires host (call setHost first)'); // allow-raw-error: assertion, should never reach user
        }

        $shapeKey = $this->snapshotStore->getViewSnapshotCacheKey('abj404_view_table', $sub, $tableOptions);
        $optionName = $this->warmupStatePolicy->getViewWarmupStateOptionName($shapeKey);
        $state = $this->warmupStatePolicy->getViewWarmupState($optionName);
        $now = time();

        if ($host->viewTableSnapshotAvailable($sub, $tableOptions)) {
            $state['status'] = 'ready';
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
            $state['stage_completed_at'] = $now;
            $state['last_error'] = '';
            $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
            return $this->warmupStatePolicy->formatViewWarmupResponse($state, true);
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
                return $this->warmupStatePolicy->formatViewWarmupResponse($state, false);
            }
            $currentBuildProgress = $this->warmupStatePolicy->getViewBuildProgressFingerprint();
            if ($this->warmupStatePolicy->forgiveWarmupAttemptIfBuildProgressed($state, $stage, $currentBuildProgress)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
                $attemptCountRaw = $attempts[$stage] ?? 0;
                $attemptCount = is_scalar($attemptCountRaw) ? intval($attemptCountRaw) : 0;
            }
            if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
                $state['status'] = 'blocked';
                $previousLastError = $state['last_error'] ?? '';
                $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
                $state['last_error'] = 'Previous warmup stage was killed or stalled too many times.'
                    . ($this->warmupStatePolicy->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
                $this->warmupDiagnostics->logViewWarmupFailure($sub, $tableOptions, $state);
                $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
                return $this->warmupStatePolicy->formatViewWarmupResponse($state, false);
            }
            $loggedKey = $stage . ':' . (is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0);
            $loggedStaleByStage = is_array($state['logged_stale_by_stage'] ?? null) ? $state['logged_stale_by_stage'] : array();
            if (empty($loggedStaleByStage[$loggedKey])) {
                $this->warmupDiagnostics->logStaleViewWarmupStage($sub, $tableOptions, $state, $elapsed, $attemptCount);
                $loggedStaleByStage[$loggedKey] = 1;
                $state['logged_stale_by_stage'] = $loggedStaleByStage;
            }
        }

        if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $previousLastError = $state['last_error'] ?? '';
            $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
            if (!$this->warmupStatePolicy->isViewWarmupErrorDiagnostic($previousError)) {
                $attempts[$stage] = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS - 1;
                $state['attempts_by_stage'] = $attempts;
                $attemptCount = is_scalar($attempts[$stage] ?? 0) ? intval($attempts[$stage]) : 0;
            }
        }

        if ($attemptCount >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $state['status'] = 'blocked';
            $state['last_error'] = 'Warmup stage reached the retry limit.'
                . ($this->warmupStatePolicy->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
            $this->warmupDiagnostics->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
            return $this->warmupStatePolicy->formatViewWarmupResponse($state, false);
        }

        if (!$this->snapshotStore->acquireViewSnapshotWarmupGlobalLock()) {
            $state['status'] = 'running';
            $state['last_error'] = 'Another table cache warmup is already running for this site.';
            return $this->warmupStatePolicy->formatViewWarmupResponse($state, false, array(
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
        $state['query_label'] = $this->warmupStatePolicy->getViewWarmupStageQueryLabel($stage);
        $state['last_error'] = '';
        $state['build_progress_at_stage_start'] = $this->warmupStatePolicy->getViewBuildProgressFingerprint();
        $this->warmupStatePolicy->setViewWarmupState($optionName, $state);

        $stageOptions = $tableOptions;
        $stageOptions['_abj404_query_timeout'] = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
        $stageOptions['_abj404_throw_on_view_query_error'] = true;

        $startMs = microtime(true);
        try {
            $this->dispatchWarmupStage($host, $sub, $tableOptions, $stageOptions, $stage, $state);
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $state['stage_completed_at'] = time();
            $state['last_error'] = '';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->warmupStatePolicy->normalizeStageTiming($timingsByStage[$stage] ?? null);
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

            $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
            return $this->warmupStatePolicy->formatViewWarmupResponse($state, $state['status'] === 'ready');
        } catch (Throwable $e) {
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $errorMessage = $e->getMessage();
            $state['last_error'] = $errorMessage;
            $state['stage_completed_at'] = time();
            $currentAttempts = $attempts[$stage] ?? 0;
            if ($this->warmupStatePolicy->forgiveWarmupAttemptIfBuildProgressed($state, $stage)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : $attempts;
                $rawAttemptCount = $attempts[$stage] ?? 0;
                $currentAttempts = is_scalar($rawAttemptCount) ? intval($rawAttemptCount) : 0;
            }
            $state['status'] = ($currentAttempts >= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) ? 'blocked' : 'idle';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->warmupStatePolicy->normalizeStageTiming($timingsByStage[$stage] ?? null);
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

            $this->warmupDiagnostics->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->warmupStatePolicy->setViewWarmupState($optionName, $state);
            return $this->warmupStatePolicy->formatViewWarmupResponse($state, false);
        } finally {
            $this->snapshotStore->releaseViewSnapshotWarmupGlobalLock();
        }
    }

    /**
     * Run the warmup stage that is current for this attempt and update
     * `$state['status']` / `$state['stage']` / `$state['query_label']`
     * to reflect the outcome.
     *
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     * @param array<string, mixed> $state
     */
    private function dispatchWarmupStage(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host, string $sub, array $tableOptions, array $stageOptions, string $stage, array &$state): void {
        if ($stage === 'rows') {
            $emptyFilteredSkip = $this->runRowsWarmupStage($host, $sub, $tableOptions, $stageOptions);
            // The filtered query returned zero rows; the rows cache is
            // intentionally not populated. Skip the count stage and finish
            // the warmup as ready: re-running rows would hit the same skip
            // path, and viewTableSnapshotAvailable (top-of-function in
            // warmViewTableSnapshotStage) would never flip to true because
            // the rows snapshot stays missing by design. Without this
            // short-circuit the warmup loops to MAX_ATTEMPTS and surfaces
            // "Could not refresh the redirects table" to the admin for a
            // filter that legitimately matches no rows (e.g. paged>1 past
            // the end of a filtered set).
            $state['status'] = $emptyFilteredSkip ? 'ready' : 'idle';
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
            return;
        }
        $this->runCountWarmupStage($host, $sub, $tableOptions, $stageOptions);
        $state['status'] = 'ready';
        $state['stage'] = 'count';
        $state['query_label'] = 'getRedirectsForViewCount';
    }

    /**
     * Run the rows-stage warmup query and check the snapshot landed. An
     * empty result for a filtered query is intentionally NOT cached by
     * AdminViewReadCoordinator (the same filter could match a row the user
     * just inserted but that the staged rebuild has not yet landed;
     * caching the pre-rebuild empty payload would mask the new row for
     * the TTL window). Treat that case as a successful warmup, there is
     * nothing to cache, the next read will be cheap, and once the rebuild
     * lands the row a fresh read will populate the cache normally.
     *
     * When the query returned non-empty rows but the snapshot is not
     * visible afterward, the storage / transient layer dropped the write
     * (transient evicted under memory pressure, disk full, custom object
     * cache backend offline). Production reports observed at sites running
     * Memcached LRU and CloudLinux quotas. This is infrastructure, not a
     * logic bug. Log at warning level and report success: the next admin
     * read populates the cache via the direct query path. Throwing here
     * generated a "Table cache warmup failed" error report on every
     * affected request, with no actionable signal for the admin.
     *
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     * @return bool True when the rows stage completed via the empty-filtered
     *   skip path (the snapshot was deliberately not cached). False when the
     *   rows snapshot is now available, or when the snapshot is missing due
     *   to infrastructure failure (treated as recoverable).
     */
    private function runRowsWarmupStage(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host, string $sub, array $tableOptions, array $stageOptions): bool {
        $rows = $host->getRedirectsForView($sub, $stageOptions);
        $rowsArray = is_array($rows) ? $rows : array();
        if ($host->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            return false;
        }
        if ($this->isEmptyFilteredResult($tableOptions, $rowsArray)) {
            return true;
        }
        $this->logger->warn(sprintf(
            '[warmup] rows stage ran (%d row(s) returned) but snapshot not visible; treating as recoverable (likely transient cache eviction or storage layer dropped the write). sub=%s rows=%d',
            count($rowsArray),
            (string)$sub,
            count($rowsArray)
        ));
        return false;
    }

    /**
     * Count-stage twin of runRowsWarmupStage. A filtered count of 0 is
     * also deliberately uncached and must not be treated as failure.
     * A non-zero count with no visible snapshot afterward is treated as
     * recoverable infrastructure failure, see the rows-stage docblock for
     * the rationale.
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
        $this->logger->warn(sprintf(
            '[warmup] count stage ran (count=%d) but full table snapshot not visible; treating as recoverable (likely transient cache eviction or storage layer dropped the write). sub=%s count=%d',
            $countValue,
            (string)$sub,
            $countValue
        ));
    }

    /**
     * A filtered query that returns no rows is the mid-rebuild race signal
     * the AdminViewReadCoordinator deliberately refuses to cache.
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
}
