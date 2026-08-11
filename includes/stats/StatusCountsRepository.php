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
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return false;
        }

        $query = "SELECT
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_MANUAL . " THEN 1 ELSE 0 END) as manual_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_AUTO . " THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_REGEX . " THEN 1 ELSE 0 END) as regex_count,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash_count
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ", " . ABJ404_STATUS_REGEX . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults(
            $query,
            array('timeout' => self::resolveTimeout($timeoutSeconds, self::STATUS_QUERY_TIMEOUT_SECONDS))
        );
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => self::scalarToInt($row['active_count'] ?? 0),
                'manual' => self::scalarToInt($row['manual_count'] ?? 0),
                'auto' => self::scalarToInt($row['auto_count'] ?? 0),
                'regex' => self::scalarToInt($row['regex_count'] ?? 0),
                'trash' => self::scalarToInt($row['trash_count'] ?? 0)
            );
        }

        if ($hadError) {
            return false;
        }

        set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);
        set_transient(self::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN, $counts, self::STATUS_LAST_KNOWN_CACHE_TTL);
        return true;
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
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return false;
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_CAPTURED . " THEN 1 ELSE 0 END) as captured,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_IGNORED . " THEN 1 ELSE 0 END) as ignored,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_LATER . " THEN 1 ELSE 0 END) as later,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults(
            $query,
            array('timeout' => self::resolveTimeout($timeoutSeconds, self::STATUS_QUERY_TIMEOUT_SECONDS))
        );
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => self::scalarToInt($row['active'] ?? 0),
                'captured' => self::scalarToInt($row['captured'] ?? 0),
                'ignored' => self::scalarToInt($row['ignored'] ?? 0),
                'later' => self::scalarToInt($row['later'] ?? 0),
                'trash' => self::scalarToInt($row['trash'] ?? 0)
            );
        }

        if ($hadError) {
            return false;
        }

        set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);
        set_transient(self::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN, $counts, self::STATUS_LAST_KNOWN_CACHE_TTL);
        return true;
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
