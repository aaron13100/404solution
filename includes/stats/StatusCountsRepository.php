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
 *   - The simple total counters (`getCapturedCount`, `getRecordCount`)
 *
 * Extracted in the i805 ViewReadService decomposition. The cache TTLs and
 * key names are reused verbatim from ViewReadRuntimeState so existing
 * invalidation paths continue to delete the same keys.
 */
class ABJ_404_Solution_StatusCountsRepository {

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL;

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     */
    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getRedirectStatusCounts(bool $bypassCache = false): array {
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_REDIRECT_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
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

        $result = $this->dbCore->queryAndGetResults($query);
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

        if (!$hadError && !$bypassCache) {
            set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getCapturedStatusCounts(bool $bypassCache = false): array {
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_CAPTURED_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
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

        $result = $this->dbCore->queryAndGetResults($query);
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

        if (!$hadError && !$bypassCache) {
            set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * @return int Cached count; on timeout returns 0 and schedules a hits-table rebuild
     *             (the cached 0 is overwritten with the real value once rebuild completes).
     */
    public function getHighImpactCapturedCount(): int {
        $cached = get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        if ($cached !== false) {
            return intval(is_scalar($cached) ? $cached : 0);
        }

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = $this->queryBuilder->buildHighImpactCapturedCountQuery();

        $result = $this->dbCore->queryAndGetResults($query, array('timeout' => 60));
        $timedOut = !empty($result['timed_out']);
        $hadError = !empty($result['last_error']) || $timedOut;
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $firstRow = (!empty($rows) && is_array($rows[0] ?? null)) ? $rows[0] : array();
        $count = self::scalarToInt($firstRow['cnt'] ?? 0);

        if ($timedOut) {
            $this->logsRepo->scheduleHitsTableRebuild();
            // allow-cache-empty: timeout self-heal sentinel, 5-minute window. Real value returns once the rebuild completes and the short cache expires.
            set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, 0, self::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL);
            return 0;
        }

        if ($hadError) {
            return 0;
        }

        if ($count === 0 && $this->isHitsTableEmpty()) {
            $this->logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, $count, self::STATUS_CACHE_TTL);

        return $count;
    }

    /** @return int */
    public function getCapturedCount(): int {
        $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);
        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $first = $rows[0];
        $value = is_array($first) ? reset($first) : $first;
        return self::scalarToInt($value);
    }

    /**
     * @param array<int, int> $types Status codes to include (passed through absint).
     * @param int $trashed 0 for live rows, 1 for trashed rows.
     * @return int
     */
    public function getRecordCount(array $types = array(), $trashed = 0): int {
        if (count($types) < 1) {
            return 0;
        }
        $filteredTypes = array_map('absint', $types);
        $typesForSQL = implode(', ', $filteredTypes);
        $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in ("
            . $typesForSQL . "))"
            . " and disabled = " . absint($trashed);

        $result = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
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
     * @param mixed $value
     * @return int
     */
    private static function scalarToInt($value): int {
        return is_scalar($value) ? intval($value) : 0;
    }
}
