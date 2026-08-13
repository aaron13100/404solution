<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single definition of what each admin status-count bucket means.
 *
 * Two consumers need the same answer and used to hold their own copy of it:
 *
 *   1. ABJ_404_Solution_StatusCountsRepository, which aggregates the buckets
 *      with SUM(CASE WHEN ...) over the redirects table;
 *   2. the mutation paths, which adjust the cached buckets by a delta when a
 *      row changes status or is trashed / restored / deleted, so the tab a
 *      user just clicked reflects their own action without re-running the
 *      aggregate (the aggregate is deferred to cron for performance).
 *
 * Two copies of a bucket definition drift, and the drift is invisible: the
 * incremental path and the recompute path would simply disagree, with the
 * recompute silently correcting the delta minutes later. So both are derived
 * from the maps below and nothing else defines a bucket.
 *
 * Bucket semantics, matching the aggregate exactly:
 *   - `all`   counts rows with disabled = 0 whose status is in the scope;
 *   - a named per-status bucket counts rows with disabled = 0 and that status;
 *   - `trash` counts rows with disabled = 1 whose status is in the scope.
 *
 * A `disabled` value that is neither 0 nor 1 contributes to no bucket, which
 * is what the SQL does too (it tests equality, not truthiness).
 *
 * Pure domain logic: no database access, no WordPress calls, no formatting.
 */
class ABJ_404_Solution_StatusCountBuckets {

    /** Redirect-tab scope: manual / automatic / regex redirects. */
    const SCOPE_REDIRECTS = 'redirects';

    /** Captured-tab scope: captured / ignored / later 404s. */
    const SCOPE_CAPTURED = 'captured';

    /** Bucket holding every non-disabled row in a scope. */
    const BUCKET_ALL = 'all';

    /** Bucket holding every disabled row in a scope. */
    const BUCKET_TRASH = 'trash';

    /**
     * Every scope the cached status counts are kept for.
     *
     * @return array<int, string>
     */
    public static function scopes(): array {
        return array(self::SCOPE_REDIRECTS, self::SCOPE_CAPTURED);
    }

    /**
     * The statuses a scope covers, mapped to the bucket each one feeds.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @return array<int, string> status constant => bucket name. Empty for an unknown scope.
     */
    public static function bucketsByStatus(string $scope): array {
        if ($scope === self::SCOPE_REDIRECTS) {
            return array(
                ABJ404_STATUS_MANUAL => 'manual',
                ABJ404_STATUS_AUTO => 'auto',
                ABJ404_STATUS_REGEX => 'regex',
            );
        }
        if ($scope === self::SCOPE_CAPTURED) {
            return array(
                ABJ404_STATUS_CAPTURED => 'captured',
                ABJ404_STATUS_IGNORED => 'ignored',
                ABJ404_STATUS_LATER => 'later',
            );
        }
        return array();
    }

    /**
     * Every bucket name a scope's cached count array carries, in cache order.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @return array<int, string>
     */
    public static function bucketNames(string $scope): array {
        $names = array(self::BUCKET_ALL);
        foreach (self::bucketsByStatus($scope) as $bucket) {
            $names[] = $bucket;
        }
        $names[] = self::BUCKET_TRASH;
        return $names;
    }

    /**
     * A scope's count array with every bucket at zero. Used as the shape a
     * failed or empty aggregate falls back to, and as the delta accumulator.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @return array<string, int>
     */
    public static function zeroCounts(string $scope): array {
        return array_fill_keys(self::bucketNames($scope), 0);
    }

    /**
     * The count deltas implied by a set of rows moving from one (status,
     * disabled) distribution to another.
     *
     * Histograms rather than individual rows, so one bulk mutation costs the
     * same two GROUP BY reads as a single-row one. A row that only exists in
     * `$before` was deleted; one that only exists in `$after` was inserted;
     * one that appears in both under different keys changed status or was
     * trashed / restored.
     *
     * @param array<int, array{status:int,disabled:int,count:int}> $before
     * @param array<int, array{status:int,disabled:int,count:int}> $after
     * @return array<string, array<string, int>> scope => bucket => signed delta.
     *         Scopes whose buckets all cancel out are omitted.
     */
    public static function delta(array $before, array $after): array {
        $deltas = array();
        foreach (self::scopes() as $scope) {
            $deltas[$scope] = self::zeroCounts($scope);
        }

        foreach ($before as $row) {
            self::accumulate($deltas, $row, -1);
        }
        foreach ($after as $row) {
            self::accumulate($deltas, $row, 1);
        }

        foreach ($deltas as $scope => $buckets) {
            if (count(array_filter($buckets)) === 0) {
                unset($deltas[$scope]);
            }
        }
        return $deltas;
    }

    /**
     * The delta for a known number of rows entering (sign 1) or leaving
     * (sign -1) one exact (status, disabled) cell. Callers that already know
     * what they changed use this instead of reading a histogram.
     *
     * @param int $status
     * @param int $disabled
     * @param int $count Number of rows, always positive.
     * @param int $sign 1 for rows added, -1 for rows removed.
     * @return array<string, array<string, int>>
     */
    public static function deltaForRows(int $status, int $disabled, int $count, int $sign): array {
        $row = array('status' => $status, 'disabled' => $disabled, 'count' => abs($count));
        return $sign < 0 ? self::delta(array($row), array()) : self::delta(array(), array($row));
    }

    /**
     * Fold one histogram cell into the accumulating deltas.
     *
     * @param array<string, array<string, int>> $deltas Modified in place.
     * @param array{status:int,disabled:int,count:int} $row
     * @param int $sign
     */
    private static function accumulate(array &$deltas, array $row, int $sign): void {
        $status = isset($row['status']) ? (int)$row['status'] : -1;
        $disabled = isset($row['disabled']) ? (int)$row['disabled'] : -1;
        $count = isset($row['count']) ? (int)$row['count'] : 0;
        if ($count <= 0) {
            return;
        }
        $signed = $sign * $count;

        foreach (self::scopes() as $scope) {
            $statusBuckets = self::bucketsByStatus($scope);
            if (!isset($statusBuckets[$status])) {
                continue;
            }
            if ($disabled === 0) {
                $deltas[$scope][self::BUCKET_ALL] += $signed;
                $deltas[$scope][$statusBuckets[$status]] += $signed;
            } else if ($disabled === 1) {
                $deltas[$scope][self::BUCKET_TRASH] += $signed;
            }
        }
    }
}
