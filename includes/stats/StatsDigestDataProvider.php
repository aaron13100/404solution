<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides digest-shaped stats rows consumed by email notifications.
 */
class ABJ_404_Solution_StatsDigestDataProvider {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;
    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepo;
    /** @var ABJ_404_Solution_StatsReadRepository */
    private $statsReadRepository;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepo
     * @param ABJ_404_Solution_StatsReadRepository $statsReadRepository
     * @param ABJ_404_Solution_Logging $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_LogsRepositoryInterface $logsRepo,
        ABJ_404_Solution_StatsReadRepository $statsReadRepository,
        $logging
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->statsReadRepository = $statsReadRepository;
        $this->logger = $logging;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getTopCapturedForDigest(int $limit): array {
        $limit = max(1, $limit);

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->warn('getTopCapturedForDigest: logs_hits rollup unavailable; '
                . 'digest top-captured table will be empty until rebuild completes. '
                . 'EmailDigest pre-checks via logsHitsTableExists() to render an "unavailable" message instead.');
            $this->logsRepo->scheduleHitsTableRebuild();
            return array();
        }

        $query = $this->buildTopCapturedForDigestQuery($limit);
        $result = $this->dbCore->queryAndGetResults($query, array('timeout' => 60));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            $errRaw = $result['last_error'] ?? '';
            $errMsg = is_string($errRaw) ? $errRaw : '';
            $timedOut = !empty($result['timed_out']);
            $this->logger->warn('getTopCapturedForDigest: query failed against present rollup; '
                . 'digest top-captured table will be empty. timed_out=' . ($timedOut ? '1' : '0')
                . ', error=' . ($errMsg !== '' ? $errMsg : '(none)'));
            return array();
        }

        $rawRows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $rows = array();
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param int $limit @return string */
    public function buildTopCapturedForDigestQuery(int $limit): string {
        $limit = max(1, $limit);
        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
        // allow-unbounded-select: bounded by a runtime LIMIT (the query string is split across an ABJ404_STATUS_CAPTURED concatenation; the LIMIT $limit clause follows in the next literal)
        $query = "SELECT r.url, COALESCE(h.logshits, 0) AS logshits, r.timestamp AS created
            FROM {wp_abj404_redirects} r
            LEFT JOIN {wp_abj404_logs_hits} h
                ON h.requested_url =
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
               AND BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
            ORDER BY logshits DESC, r.url ASC
            LIMIT " . $limit;
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * @param callable|null $countProvider Optional facade count method for subclass compatibility.
     * @return array{total_captured: int, total_manual: int, total_auto: int}
     */
    public function getDigestSummaryStats($countProvider = null): array {
        $zero = array(
            'total_captured' => 0,
            'total_manual' => 0,
            'total_auto' => 0,
        );

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $count = is_callable($countProvider)
            ? $countProvider
            : array($this->statsReadRepository, 'getStatsCount');

        try {
            $total_captured = call_user_func(
                $count,
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_CAPTURED)
            );
            $total_manual = call_user_func(
                $count,
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_MANUAL)
            );
            $total_auto = call_user_func(
                $count,
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_AUTO)
            );
        } catch (Throwable $e) {
            $this->logger->warn(
                'getRedirectsBreakdownStats failed; returning zero counts: '
                . $e->getMessage()
            );
            return $zero;
        }

        return array(
            'total_captured' => intval($total_captured),
            'total_manual' => intval($total_manual),
            'total_auto' => intval($total_auto),
        );
    }

    /** @return int */
    public function getCapturedCountForNotification(): int {
        $viewRead = abj_service('view_read_service');
        return $viewRead->getRecordCount(array(ABJ404_STATUS_CAPTURED));
    }
}
