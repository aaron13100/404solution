<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the cached admin status counts consistent with a redirect mutation.
 *
 * Foreground count reads are cache-only: the SUM(CASE) aggregate behind the
 * tab badges is a full scan of the redirects table, so it was moved to cron.
 * That left every mutation's own effect invisible to the user who caused it --
 * trash a captured 404 and the Trash badge kept its old number until a
 * background recompute landed, which on a host with a cron backlog is minutes.
 *
 * The fix is not to re-run the aggregate but to apply the delta the mutation
 * caused. A mutation is bracketed by two reads of the affected rows'
 * (status, disabled) distribution; the difference is the exact delta, so
 * inserts, status changes, trash, restore and deletes all work through the
 * same path without this class needing to know which one happened. The reads
 * are scoped to the rows the mutation already touches, never table-wide.
 *
 * Any residual drift is corrected by the next full recompute, so a failed
 * read degrades to the previous invalidate-only behavior rather than to a
 * wrong number.
 */
class ABJ_404_Solution_StatusCountsMutationSync {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseQueryInterface $dbCore */
    public function __construct($dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * The (status, disabled) distribution of the rows a condition selects.
     * Take this immediately before a mutation and hand it to syncSince().
     *
     * @param string $whereClause SQL WHERE body. Plugin-controlled text only:
     *        every caller-supplied value must travel in $params.
     * @param array<int, mixed> $params Bound parameters for $whereClause.
     * @return array<int, array{status:int,disabled:int,count:int}>|null Null when the
     *         read failed, which makes the paired sync a no-op.
     */
    public function snapshot(string $whereClause, array $params = array()): ?array {
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $query = "SELECT status, disabled, COUNT(*) as row_count FROM `" . $redirectsTable . "`"
            . " WHERE " . $whereClause . " GROUP BY status, disabled";
        $options = array();
        if ($params !== array()) {
            $options['query_params'] = $params;
        }
        $result = $this->dbCore->queryAndGetResults($query, $options);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            return null;
        }

        $histogram = array();
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $histogram[] = array(
                'status' => isset($row['status']) && is_scalar($row['status']) ? (int)$row['status'] : -1,
                'disabled' => isset($row['disabled']) && is_scalar($row['disabled']) ? (int)$row['disabled'] : -1,
                'count' => isset($row['row_count']) && is_scalar($row['row_count']) ? (int)$row['row_count'] : 0,
            );
        }
        return $histogram;
    }

    /**
     * Apply the delta between a snapshot and the current state of the same
     * rows. A no-op when either read failed, so a mutation never publishes a
     * count it could not derive.
     *
     * @param array<int, array{status:int,disabled:int,count:int}>|null $before From snapshot().
     * @param string $whereClause Same condition the snapshot used.
     * @param array<int, mixed> $params Same parameters the snapshot used.
     * @return void
     */
    public function syncSince(?array $before, string $whereClause, array $params = array()): void {
        if ($before === null) {
            return;
        }
        $after = $this->snapshot($whereClause, $params);
        if ($after === null) {
            return;
        }
        ABJ_404_Solution_StatusCountsRepository::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::delta($before, $after)
        );
    }

    /**
     * Apply the delta for rows that are simply gone, for a mutation whose
     * after-state needs no second read (an unconditional DELETE of exactly
     * the snapshotted set).
     *
     * @param array<int, array{status:int,disabled:int,count:int}>|null $removed From snapshot().
     * @return void
     */
    public function syncRemoved(?array $removed): void {
        if ($removed === null || $removed === array()) {
            return;
        }
        ABJ_404_Solution_StatusCountsRepository::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::delta($removed, array())
        );
    }

    /**
     * Apply the delta for one freshly inserted row. An insert needs no reads
     * at all: the row that just landed is the whole delta, which matters on
     * the frontend capture hot path where an extra query per 404 is not free.
     *
     * @param int $status
     * @param int $disabled
     * @return void
     */
    public function syncInserted(int $status, int $disabled): void {
        ABJ_404_Solution_StatusCountsRepository::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::deltaForRows($status, $disabled, 1, 1)
        );
    }
}
