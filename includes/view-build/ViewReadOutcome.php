<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classifies the outcome of an admin view row read (getRedirectsForView).
 *
 * A row read can return an empty array for three structurally different
 * reasons, and the admin UI must treat them differently:
 *
 *   - COMPLETE     the source genuinely has no rows for this page; "No
 *                  records" is the truthful thing to render.
 *   - PENDING      the staged view_done build is not serveable yet.
 *   - ERRORED      the staged read threw.
 *   - STALE_EMPTY  the served snapshot returned zero rows while the live
 *                  source count says rows exist for the requested page --
 *                  the "count shows thousands but the table is empty"
 *                  release blocker (i455).
 *
 * Only COMPLETE is trustworthy; the other three are "incomplete" and the
 * renderer shows a still-preparing state while the AJAX endpoint re-engages
 * the view-build poller. This class owns that small state machine plus the
 * pure stale-empty heuristic so the decision lives in one independently
 * testable place rather than spread across the read coordinator.
 */
class ABJ_404_Solution_ViewReadOutcome {

    /** A read outcome has not been recorded yet this request. */
    const STATUS_UNKNOWN = 'unknown';
    /** The rows returned are a trustworthy listing (possibly a genuine empty set). */
    const STATUS_COMPLETE = 'complete';
    /** The staged view_done build is not serveable yet; rows could not be read. */
    const STATUS_PENDING = 'pending';
    /** The staged read threw; rows could not be read. */
    const STATUS_ERRORED = 'errored';
    /** Empty rows while the live source count says rows exist for this page. */
    const STATUS_STALE_EMPTY = 'stale_empty';

    /** @var string One of the STATUS_* constants. */
    private $status = self::STATUS_UNKNOWN;

    /** @return void */
    public function markPending(): void {
        $this->status = self::STATUS_PENDING;
    }

    /** @return void */
    public function markErrored(): void {
        $this->status = self::STATUS_ERRORED;
    }

    /**
     * Classify a successful (non-throwing) row read. A non-empty result is
     * always trustworthy; an empty result is trustworthy only when the live
     * source count agrees there are no rows for the requested page. The count
     * is probed (via $liveCountProbe, expected to return -1 when unavailable)
     * only when the rows are empty. Strict-mode reads
     * (`_abj404_throw_on_view_query_error`) classify pending/error themselves
     * and are never probed here.
     *
     * @param array<int|string, mixed> $rows
     * @param array<string, mixed> $tableOptions
     * @param callable():int $liveCountProbe
     * @return void
     */
    public function classifyRows(array $rows, array $tableOptions, callable $liveCountProbe): void {
        if (!empty($rows) || !empty($tableOptions['_abj404_throw_on_view_query_error'])) {
            // Non-empty rows are trustworthy; strict-mode empties are the
            // caller's own (already-classified) pending/error responsibility.
            $this->status = self::STATUS_COMPLETE;
            return;
        }
        $this->status = self::emptyRowsAreStale((int)$liveCountProbe(), $tableOptions)
            ? self::STATUS_STALE_EMPTY
            : self::STATUS_COMPLETE;
    }

    /**
     * Pure heuristic: do empty rows contradict the live source count? True
     * when the count says rows should appear on the requested page yet none
     * were returned. An empty page past the end of a real result set
     * (offset >= count) is a legitimate empty, not a stale snapshot.
     *
     * @param int $liveCount negative or zero means "no rows / unavailable"
     * @param array<string, mixed> $tableOptions supplies perpage/paged
     * @return bool
     */
    public static function emptyRowsAreStale(int $liveCount, array $tableOptions): bool {
        if ($liveCount <= 0) {
            return false;
        }
        $perpage = isset($tableOptions['perpage']) && is_numeric($tableOptions['perpage'])
            ? max(1, intval($tableOptions['perpage'])) : 25;
        $paged = isset($tableOptions['paged']) && is_numeric($tableOptions['paged'])
            ? max(1, intval($tableOptions['paged'])) : 1;
        return $liveCount > ($paged - 1) * $perpage;
    }

    /**
     * Whether the last classified read is NOT a trustworthy "genuinely empty"
     * listing (pending, errored, or stale-empty).
     *
     * @return bool
     */
    public function wasIncomplete(): bool {
        return in_array($this->status, array(
            self::STATUS_PENDING,
            self::STATUS_ERRORED,
            self::STATUS_STALE_EMPTY,
        ), true);
    }

    /** @return string One of the STATUS_* constants. */
    public function status(): string {
        return $this->status;
    }
}
