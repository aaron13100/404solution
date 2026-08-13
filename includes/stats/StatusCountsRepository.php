<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aggregate "redirects grouped by status" tallies with transient caching.
 *
 * Owns the SUM(CASE WHEN ...) aggregation queries that previously lived
 * inline in ViewReadService:
 *   - Active/manual/auto/regex/trash counts (admin redirects list badges)
 *   - Captured/ignored/later/trash counts (admin captures list badges)
 *   - High-impact captured count (logs-joined; gated by hits table presence)
 *
 * Extracted in the i805 ViewReadService decomposition. The cache TTLs and
 * key names are reused verbatim from ViewReadRuntimeState so existing
 * invalidation paths continue to delete the same keys.
 */
class ABJ_404_Solution_StatusCountsRepository {

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN;
    const CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const STATUS_LAST_KNOWN_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_LAST_KNOWN_CACHE_TTL;

    /**
     * Query budget for an unattended redirect/captured status recompute. The
     * deferred foreground backstop passes a smaller one -- see
     * ABJ_404_Solution_StatusCountsRefreshCoordinator.
     */
    const STATUS_QUERY_TIMEOUT_SECONDS = 20;

    /** Query budget for an unattended high-impact recompute (joins the logs rollup). */
    const HIGH_IMPACT_QUERY_TIMEOUT_SECONDS = 60;

    /** @var callable(string,array<string,mixed>,callable):mixed|null */
    private static $operationTracer = null;

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_TableReadinessGate */
    private $readiness;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     * @param ABJ_404_Solution_TableReadinessGate $readiness
     */
    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder,
        ABJ_404_Solution_TableReadinessGate $readiness
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->queryBuilder = $queryBuilder;
        $this->readiness = $readiness;
    }

    /** @param callable(string,array<string,mixed>,callable):mixed|null $tracer */
    public static function setOperationTracer($tracer): void {
        self::$operationTracer = $tracer;
    }

    /**
     * Read redirect counts without touching the database.
     *
     * @return array{counts: array<string, int>, needs_refresh: bool, incomplete: bool}
     */
    public function readRedirectStatusCountsCache(): array {
        return self::trace(
            'status_cache_read',
            array('scope' => 'redirects'),
            function (): array {
                return $this->readStatusCountsCache(
                    self::CACHE_KEY_REDIRECT_STATUS,
                    self::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN,
                    'redirect_current',
                    'redirect_last_known'
                );
            }
        );
    }

    /**
     * Recompute redirect counts. Called from the cron listener and from the
     * coordinator's shutdown backstop, which passes a smaller query budget.
     *
     * @param int|null $timeoutSeconds Null uses the unattended budget.
     */
    public function recomputeRedirectStatusCounts(?int $timeoutSeconds = null): bool {
        return $this->recomputeScope(
            ABJ_404_Solution_StatusCountBuckets::SCOPE_REDIRECTS,
            self::CACHE_KEY_REDIRECT_STATUS,
            self::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN,
            $timeoutSeconds
        );
    }

    /**
     * Read captured counts without touching the database.
     *
     * @return array{counts: array<string, int>, needs_refresh: bool, incomplete: bool}
     */
    public function readCapturedStatusCountsCache(): array {
        return self::trace(
            'status_cache_read',
            array('scope' => 'captured'),
            function (): array {
                return $this->readStatusCountsCache(
                    self::CACHE_KEY_CAPTURED_STATUS,
                    self::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN,
                    'captured_current',
                    'captured_last_known'
                );
            }
        );
    }

    /**
     * Recompute captured counts. Called from the cron listener and from the
     * coordinator's shutdown backstop, which passes a smaller query budget.
     *
     * @param int|null $timeoutSeconds Null uses the unattended budget.
     */
    public function recomputeCapturedStatusCounts(?int $timeoutSeconds = null): bool {
        return $this->recomputeScope(
            ABJ_404_Solution_StatusCountBuckets::SCOPE_CAPTURED,
            self::CACHE_KEY_CAPTURED_STATUS,
            self::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN,
            $timeoutSeconds
        );
    }

    /**
     * Run one scope's bucket aggregate and cache it.
     *
     * The redirect and captured scopes differ only in which statuses they
     * cover and what the per-status buckets are called, so both the SELECT
     * list and the result mapping are generated from
     * ABJ_404_Solution_StatusCountBuckets. That is deliberate rather than
     * merely tidy: the incremental delta path applied at mutation time reads
     * the same definition, and a second hand-written copy of the bucket rules
     * here could disagree with it without any test noticing (the aggregate
     * would just quietly overwrite the delta on the next cron tick).
     *
     * @param string $scope One of the StatusCountBuckets SCOPE_* constants.
     * @param string $cacheKey
     * @param string $lastKnownKey
     * @param int|null $timeoutSeconds Null uses the unattended budget.
     */
    private function recomputeScope(
        string $scope,
        string $cacheKey,
        string $lastKnownKey,
        ?int $timeoutSeconds
    ): bool {
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return false;
        }

        $query = $this->dbCore->doTableNameReplacements(self::buildScopeAggregateQuery($scope));
        $result = $this->dbCore->queryAndGetResults(
            $query,
            array('timeout' => self::resolveTimeout($timeoutSeconds, self::STATUS_QUERY_TIMEOUT_SECONDS))
        );
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        if ($hadError) {
            return false;
        }

        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $counts = ABJ_404_Solution_StatusCountBuckets::zeroCounts($scope);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            foreach (array_keys($counts) as $bucket) {
                $counts[$bucket] = self::scalarToInt($row[$bucket] ?? 0);
            }
        }

        set_transient($cacheKey, $counts, self::STATUS_CACHE_TTL);
        set_transient($lastKnownKey, $counts, self::STATUS_LAST_KNOWN_CACHE_TTL);
        return true;
    }

    /**
     * The SUM(CASE WHEN ...) aggregate for one scope. Every column is aliased
     * to its bucket name so the result maps straight onto the cached shape.
     *
     * @param string $scope One of the StatusCountBuckets SCOPE_* constants.
     */
    private static function buildScopeAggregateQuery(string $scope): string {
        $statusBuckets = ABJ_404_Solution_StatusCountBuckets::bucketsByStatus($scope);
        $selects = array(
            "SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as `"
                . ABJ_404_Solution_StatusCountBuckets::BUCKET_ALL . "`",
        );
        foreach ($statusBuckets as $status => $bucket) {
            $selects[] = "SUM(CASE WHEN disabled = 0 AND status = " . intval($status)
                . " THEN 1 ELSE 0 END) as `" . $bucket . "`";
        }
        $selects[] = "SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as `"
            . ABJ_404_Solution_StatusCountBuckets::BUCKET_TRASH . "`";

        return "SELECT\n            " . implode(",\n            ", $selects)
            . "\n            FROM {wp_abj404_redirects}"
            . "\n            WHERE status IN ("
            . implode(', ', array_map('intval', array_keys($statusBuckets))) . ")";
    }

    /**
     * Adjust the cached bucket counts by a signed delta instead of recomputing
     * them.
     *
     * Foreground status-count reads are cache-only (the aggregate is a full
     * scan of the redirects table and is deferred to cron), so without this a
     * user who trashes a row watches the Trash tab keep its old number until
     * a background recompute lands minutes later. A mutation knows exactly
     * which rows it moved between buckets, so it can keep the cache correct
     * for free.
     *
     * Both the current and the last-known keys are adjusted: an invalidation
     * has usually just demoted the current value to last-known, and the
     * last-known copy is what a stale read actually serves. Buckets are
     * clamped at zero, since a negative tally is never a truthful answer even
     * if a concurrent writer made the delta double-count. Any residual drift
     * is corrected by the next full recompute.
     *
     * Static and dependency-free (pure transient reads and writes, no DB) for
     * the same reason ViewCacheInvalidator's debounced captured invalidation
     * is: the mutation paths that need it, including the frontend capture hot
     * path, must be able to call it without wiring up a repository.
     *
     * @param array<string, array<string, int>> $delta scope => bucket => signed delta.
     * @return void
     */
    public static function applyDelta(array $delta): void {
        foreach ($delta as $scope => $buckets) {
            if (!is_array($buckets) || count(array_filter($buckets)) === 0) {
                continue;
            }
            foreach (self::cacheKeysForScope((string)$scope) as $key => $ttl) {
                self::applyDeltaToTransient($key, $ttl, $buckets);
            }
        }
    }

    /**
     * Where one scope's counts are cached, and for how long.
     *
     * @param string $scope
     * @return array<string, int> cache key => TTL seconds.
     */
    private static function cacheKeysForScope(string $scope): array {
        if ($scope === ABJ_404_Solution_StatusCountBuckets::SCOPE_REDIRECTS) {
            return array(
                self::CACHE_KEY_REDIRECT_STATUS => self::STATUS_CACHE_TTL,
                self::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN => self::STATUS_LAST_KNOWN_CACHE_TTL,
            );
        }
        if ($scope === ABJ_404_Solution_StatusCountBuckets::SCOPE_CAPTURED) {
            return array(
                self::CACHE_KEY_CAPTURED_STATUS => self::STATUS_CACHE_TTL,
                self::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN => self::STATUS_LAST_KNOWN_CACHE_TTL,
            );
        }
        return array();
    }

    /**
     * Apply bucket deltas to one cached count array, leaving a missing or
     * non-array cache alone (there is nothing to keep consistent, and
     * inventing counts from a delta would report a total that was never
     * measured).
     *
     * @param string $key
     * @param int $ttl
     * @param array<string, int> $buckets
     */
    private static function applyDeltaToTransient(string $key, int $ttl, array $buckets): void {
        $cached = get_transient($key);
        if (!is_array($cached)) {
            return;
        }
        foreach ($buckets as $bucket => $change) {
            if (!is_scalar($change) || (int)$change === 0) {
                continue;
            }
            $current = isset($cached[$bucket]) && is_scalar($cached[$bucket]) ? (int)$cached[$bucket] : 0;
            $cached[$bucket] = max(0, $current + (int)$change);
        }
        // allow-cache-empty: an all-zero-but-shaped count array is a real measured result, not an empty cache.
        set_transient($key, $cached, $ttl);
    }

    /**
     * @return array{counts: array<string, int>, needs_refresh: bool, incomplete: bool}
     */
    private function readStatusCountsCache(
        string $currentKey,
        string $lastKnownKey,
        string $currentFamily,
        string $lastKnownFamily
    ): array {
        $current = self::trace(
            'transient_read',
            array('family' => $currentFamily, 'expected' => 'array'),
            static fn() => get_transient($currentKey)
        );
        if (is_array($current)) {
            /** @var array<string, int> $current */
            return array('counts' => $current, 'needs_refresh' => false, 'incomplete' => false);
        }

        $lastKnown = self::trace(
            'transient_read',
            array('family' => $lastKnownFamily, 'expected' => 'array'),
            static fn() => get_transient($lastKnownKey)
        );
        if (is_array($lastKnown)) {
            /** @var array<string, int> $lastKnown */
            return array('counts' => $lastKnown, 'needs_refresh' => true, 'incomplete' => false);
        }

        return array('counts' => array(), 'needs_refresh' => true, 'incomplete' => true);
    }

    /**
     * Read the high-impact count without touching the database.
     *
     * @return array{count:?int,needs_refresh:bool}
     */
    public function readHighImpactCapturedCountCache(): array {
        return self::trace(
            'status_cache_read',
            array('scope' => 'high_impact'),
            static function (): array {
                $current = self::trace(
                    'transient_read',
                    array('family' => 'high_impact_current', 'expected' => 'numeric'),
                    static fn() => get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED)
                );
                if (is_numeric($current)) {
                    return array('count' => intval($current), 'needs_refresh' => false);
                }

                $lastKnown = self::trace(
                    'transient_read',
                    array('family' => 'high_impact_last_known', 'expected' => 'numeric'),
                    static fn() => get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN)
                );
                if (is_numeric($lastKnown)) {
                    return array('count' => intval($lastKnown), 'needs_refresh' => true);
                }

                return array('count' => null, 'needs_refresh' => true);
            }
        );
    }

    /**
     * Recompute the high-impact count. Called from the cron listener and from
     * the coordinator's shutdown backstop, which passes a smaller query budget.
     *
     * @param int|null $timeoutSeconds Null uses the unattended budget.
     */
    public function recomputeHighImpactCapturedCount(?int $timeoutSeconds = null): bool {
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return false;
        }
        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return false;
        }

        $query = $this->queryBuilder->buildHighImpactCapturedCountQuery();

        $result = $this->dbCore->queryAndGetResults(
            $query,
            array('timeout' => self::resolveTimeout($timeoutSeconds, self::HIGH_IMPACT_QUERY_TIMEOUT_SECONDS))
        );
        $timedOut = !empty($result['timed_out']);
        $hadError = !empty($result['last_error']) || $timedOut;
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $firstRow = (!empty($rows) && is_array($rows[0] ?? null)) ? $rows[0] : array();
        $count = self::scalarToInt($firstRow['cnt'] ?? 0);

        if ($timedOut) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return false;
        }

        if ($hadError) {
            return false;
        }

        if ($count === 0 && $this->isHitsTableEmpty()) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return false;
        }

        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, $count, self::STATUS_CACHE_TTL);
        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN, $count, self::STATUS_LAST_KNOWN_CACHE_TTL);

        return true;
    }

    /**
     * Probe the hits table for "is this rollup empty?" to distinguish a real
     * zero from a not-yet-rebuilt state.
     *
     * @return bool
     */
    private function isHitsTableEmpty(): bool {
        $check = "SELECT 1 FROM {wp_abj404_logs_hits} LIMIT 1";
        $check = $this->dbCore->doTableNameReplacements($check);
        $result = $this->dbCore->queryAndGetResults($check);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return empty($rows);
    }

    /**
     * A caller-supplied budget never exceeds the unattended one: the deferred
     * foreground path may only ask for LESS time, never more.
     */
    private static function resolveTimeout(?int $requested, int $unattended): int {
        if ($requested === null || $requested < 1) {
            return $unattended;
        }
        return min($requested, $unattended);
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function scalarToInt($value): int {
        return is_scalar($value) ? intval($value) : 0;
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private static function trace(string $operation, array $fields, callable $work) {
        if (self::$operationTracer === null) {
            return $work();
        }
        return (self::$operationTracer)($operation, $fields, $work);
    }
}
