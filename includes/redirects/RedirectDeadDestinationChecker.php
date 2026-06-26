<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks whether specific redirects point at a dead destination, i.e. a
 * destination URL that shows recent failed hits in the logs_hits rollup.
 *
 * Unlike the former global precompute (which scanned every redirect into a
 * transient), this checker is bounded by the caller's id list: it only ever
 * inspects the redirect ids it is handed, so the read never loads more than
 * the caller's working set.
 */
class ABJ_404_Solution_RedirectDeadDestinationChecker {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Per-request memo of rollup readiness (table present AND failed_hits column).
     * Invariant within a request, so the two schema probes run at most once even
     * when matching evaluates a redirect across several URL-normalization passes.
     *
     * @var bool|null
     */
    private $rollupReady = null;

    /**
     * Per-request memo of resolved ids: id => is its destination dead. Lets the
     * repeated matching passes for the same redirect avoid re-querying.
     *
     * @var array<int, bool>
     */
    private $deadByIdMemo = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Returns the subset of the given redirect ids whose destination URL shows
     * recent failed hits (i.e. the redirect points at a dead page). Bounded by
     * the caller's id list: the Page Redirects tab passes the ids it is
     * rendering (~25), and live redirect matching passes the single redirect
     * being evaluated, so the read never loads more than the caller's working
     * set. Fails open (returns empty) when the logs_hits rollup is absent or the
     * query times out, so a redirect is never blocked by an infrastructure gap.
     *
     * @param array<int, int|string> $redirectIds
     * @return array<int, string> the subset of $redirectIds that are dead, as strings
     */
    public function findDeadDestinationIds(array $redirectIds): array {
        $ids = array();
        foreach ($redirectIds as $candidate) {
            if (is_scalar($candidate)) {
                $intId = (int) $candidate;
                if ($intId > 0) {
                    $ids[$intId] = $intId;
                }
            }
        }
        if (empty($ids)) {
            return array();
        }

        // Serve ids already resolved this request from the memo; only query the rest.
        $deadIds = array();
        $toQuery = array();
        foreach ($ids as $intId) {
            if (array_key_exists($intId, $this->deadByIdMemo)) {
                if ($this->deadByIdMemo[$intId]) {
                    $deadIds[] = (string) $intId;
                }
            } else {
                $toQuery[$intId] = $intId;
            }
        }
        if (empty($toQuery)) {
            return $deadIds;
        }

        if (!$this->rollupReady()) {
            // Rollup absent: cannot determine. Do NOT memo as "not dead" -- the
            // rollup may be rebuilt later in the request lifecycle.
            return $deadIds;
        }

        $freshDead = $this->queryDeadDestinationIds($toQuery);
        if ($freshDead === null) {
            // Fail open (timeout/error/throwable): never block a redirect, and do
            // NOT memo -- the answer is unknown, not "not dead".
            return $deadIds;
        }

        // Resolve every queried id (dead or not) so repeated matching passes in
        // the same request never re-query.
        $freshDeadSet = array();
        foreach ($freshDead as $deadIdStr) {
            $freshDeadSet[(int) $deadIdStr] = true;
        }
        foreach ($toQuery as $intId) {
            $isDead = isset($freshDeadSet[$intId]);
            $this->deadByIdMemo[$intId] = $isDead;
            if ($isDead) {
                $deadIds[] = (string) $intId;
            }
        }
        return $deadIds;
    }

    /**
     * Runs the bounded dead-destination read for the given (already-sanitized,
     * positive-int) redirect ids. Returns the dead-id strings on success, or null
     * when the read could not complete (timeout, DAO error, or a rethrown
     * Throwable) -- this method moved from daily cron to request time, so a DB
     * fault must fail open here and never propagate into frontend redirect
     * matching. queryAndGetResults already logs the underlying error.
     *
     * @param array<int, int> $ids
     * @return array<int, string>|null
     */
    private function queryDeadDestinationIds(array $ids): ?array {
        $idList = implode(',', array_map('intval', array_values($ids)));
        // allow-unbounded-select: bounded by the caller's r.id IN (...) working set -- the Page Redirects tab passes the page's row ids, live matching passes the single matched redirect id
        $sql = "SELECT DISTINCT r.id
             FROM {wp_abj404_redirects} r
             INNER JOIN {wp_abj404_logs_hits} h
                 ON h.requested_url = CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
                AND BINARY h.requested_url = BINARY CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
             WHERE r.id IN (" . $idList . ")
               AND h.last_used > %d
               AND h.failed_hits > 0
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";
        try {
            $sql = $this->dbCore->doTableNameReplacements($sql);
            $result = $this->dbCore->queryAndGetResults($sql, array(
                'query_params' => array(abj_clock()->now() - 7 * 86400),
                'timeout' => 5,
            ));
        } catch (Throwable $e) {
            $this->logger->debugMessage(__CLASS__ . '/' . __FUNCTION__
                . ': dead-destination read failed open (' . $e->getMessage() . '); no redirect suspended.');
            return null;
        }

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            $this->logger->debugMessage(__CLASS__ . '/' . __FUNCTION__
                . ': dead-destination read timed out or errored; failing open, no redirect suspended.');
            return null;
        }

        $deadIds = array();
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        foreach ($rows as $row) {
            $value = $this->extractIdValue($row);
            if ($value !== null) {
                $deadIds[] = $value;
            }
        }
        return $deadIds;
    }

    /**
     * Is the logs_hits rollup usable (table present AND failed_hits column)?
     * Memoized for the request. When not ready, schedules a rebuild once (the
     * rollup's own lifecycle in LogsHitsRollupService also schedules rebuilds
     * from the logging and admin paths, so this is an opportunistic top-up, not
     * the primary maintainer). The probes are wrapped so a schema-query throwable
     * fails open rather than breaking a frontend redirect.
     *
     * @return bool
     */
    private function rollupReady(): bool {
        if ($this->rollupReady !== null) {
            return $this->rollupReady;
        }

        try {
            $hitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
            $ready = $this->dbCore->tableNameResolver()->tableExists($hitsTable)
                && $this->logsHitsHasFailedHitsColumn();
        } catch (Throwable $e) {
            $this->logger->debugMessage(__CLASS__ . '/' . __FUNCTION__
                . ': rollup readiness probe failed open (' . $e->getMessage() . ').');
            $ready = false;
        }

        if (!$ready) {
            // Scheduling a rebuild is opportunistic and must also fail open: a
            // throwable from service resolution or scheduling must never escape
            // into frontend redirect matching.
            try {
                /** @var ABJ_404_Solution_LogsRepository|null $logsRepo */
                $logsRepo = abj_service('logs_repository');
                if ($logsRepo !== null) {
                    $logsRepo->scheduleHitsTableRebuild();
                }
            } catch (Throwable $e) {
                $this->logger->debugMessage(__CLASS__ . '/' . __FUNCTION__
                    . ': rollup rebuild scheduling failed open (' . $e->getMessage() . ').');
            }
        }

        $this->rollupReady = $ready;
        return $ready;
    }

    /**
     * @param mixed $row
     * @return string|null
     */
    private function extractIdValue($row): ?string {
        if (is_array($row)) {
            $value = $row['id'] ?? reset($row);
        } elseif (is_object($row)) {
            $value = $row->id ?? null;
        } else {
            $value = $row;
        }

        if (is_scalar($value) && (string)$value !== '') {
            return (string)$value;
        }

        return null;
    }

    private function logsHitsHasFailedHitsColumn(): bool {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $sql = "SELECT 1 FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = %s "
            . "AND column_name = 'failed_hits' LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($tableName),
            'log_errors' => false,
        ));
        if (!empty($result['last_error'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }
}
