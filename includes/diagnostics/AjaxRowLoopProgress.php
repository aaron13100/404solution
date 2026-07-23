<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded intra-stage progress for a table's row-formatting loop
 * (Bruno timeout cause matrix, cause class F; gap-hunt iteration 1, gap G5).
 *
 * ABJ_404_Solution_AjaxQueryTimeline accounts for the database half of a work
 * stage. This accounts for the other half. Once the rows are in memory the
 * stage still runs URL parsing and normalization, destination resolution,
 * locale-dependent formatting, and every foreign callback other plugins have
 * attached to the filters the row templates go through -- all of it inside a
 * single `foreach`, and all of it invisible between `stage_start` and a
 * `stage_end` that never arrives.
 *
 * A hang caused by ONE pathological row is the specific case this localizes.
 * With per-query records showing every query completed and the row loop
 * stopping at row 17 of 25, the stall is attributable to PHP-side work on a
 * specific row rather than to the database, which is a different fix.
 *
 * Cost is bounded by construction, not by hope. The tick interval is derived
 * from the row count so a 25-row page and a 500-row page both emit at most
 * MAX_PROGRESS_RECORDS progress records, and the record envelope is the
 * checkpoint logger's high-frequency one.
 *
 * The loop is ticked BEFORE each row is formatted, for the same reason the
 * query probe is written before the query runs: work that never finishes has
 * to have been announced before it started, or it leaves no trace at all.
 *
 * PII: only a SHA-256 prefix of the row's primary key is emitted. Row URLs,
 * destinations, and freeform content never reach the journal -- the whole row
 * is accepted only so the key lookup lives here instead of being repeated at
 * every call site.
 */
final class ABJ_404_Solution_AjaxRowLoopProgress {

    /**
     * Progress records emitted per loop, excluding the start and end pair.
     *
     * Eight is enough to place a stall inside a default 25-row page to within
     * about four rows while costing a fraction of the boundary checkpoints
     * already written for the same request. The interval scales with the row
     * count, so a 500-row page costs the same as a 25-row one.
     */
    const MAX_PROGRESS_RECORDS = 8;

    /** Row-key columns, in the order they are consulted. */
    const ROW_KEY_COLUMNS = array('id', 'log_id');

    /** @var string Ledger request ID, or '' when this loop is not instrumented. */
    private $requestId;

    /** @var string */
    private $label;

    /** @var int */
    private $total;

    /** @var int Rows between progress records; always at least 1. */
    private $interval;

    /** @var int Rows seen so far. */
    private $index = 0;

    /** @var int Progress records emitted so far. */
    private $emitted = 0;

    /** @var float */
    private $startedAt;

    /**
     * Open a progress-tracked row loop. Returns a live tracker on an
     * instrumented admin-AJAX request and an inert one everywhere else, so
     * call sites need no conditional and the front-end 404 path pays nothing
     * beyond one static call per table render.
     *
     * @param string $label Loop identity, e.g. 'redirects_rows'.
     * @param int $totalRows Number of rows the loop is about to format.
     */
    public static function begin(string $label, int $totalRows): self {
        $progress = new self($label, $totalRows);
        if ($progress->requestId === '') {
            return $progress;
        }
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($progress->requestId, 'row_loop_start', array(
                'loop' => $progress->label,
                'rows' => $progress->total,
                'every' => $progress->interval,
            ));
        } catch (Throwable $e) {
            self::reportFailure('row loop start failed: ' . $e->getMessage());
        }
        return $progress;
    }

    private function __construct(string $label, int $totalRows) {
        $this->label = substr($label, 0, 64);
        $this->total = max(0, $totalRows);
        $this->interval = max(1, (int)ceil($this->total / self::MAX_PROGRESS_RECORDS));
        $this->startedAt = abj_clock()->nowFloat();
        $this->requestId = self::resolveRequestId();
    }

    /**
     * Announce the row that is about to be formatted. Never throws.
     *
     * @param array<string, mixed> $row The row being formatted. Only its
     *   primary key is read, and only as a hash.
     */
    public function tick(array $row): void {
        $this->index++;
        if ($this->requestId === '' || $this->emitted >= self::MAX_PROGRESS_RECORDS) {
            return;
        }
        // Rows 1, 1+interval, 1+2*interval ... so the FIRST row is always
        // announced: a loop that hangs immediately is otherwise reported as a
        // loop that never started, which points at the wrong half of the stage.
        if (($this->index - 1) % $this->interval !== 0) {
            return;
        }
        $this->emitted++;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($this->requestId, 'row_loop_progress', array(
                'loop' => $this->label,
                'row' => $this->index,
                'rows' => $this->total,
                'rid' => self::hashedRowKey($row),
                'ms' => self::elapsedMs($this->startedAt),
            ));
        } catch (Throwable $e) {
            self::reportFailure('row loop progress failed: ' . $e->getMessage());
        }
    }

    /**
     * Close the loop, recording how many rows it actually formatted. Never
     * throws. The absence of this record next to a present `row_loop_start` is
     * itself the finding: the loop was entered and did not come back.
     */
    public function finish(): void {
        if ($this->requestId === '') {
            return;
        }
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($this->requestId, 'row_loop_end', array(
                'loop' => $this->label,
                'rows' => $this->total,
                'rows_done' => $this->index,
                'ms' => self::elapsedMs($this->startedAt),
            ));
        } catch (Throwable $e) {
            self::reportFailure('row loop end failed: ' . $e->getMessage());
        }
    }

    /** Rows formatted so far. Test-visible accounting; production reads the journal. */
    public function rowsSeen(): int {
        return $this->index;
    }

    private static function resolveRequestId(): string {
        try {
            return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
        } catch (Throwable $e) {
            self::reportFailure('row loop arming failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * A short SHA-256 prefix of the row's primary key, or '' when the row has
     * no usable key.
     *
     * Hashed rather than emitted plainly because the journal's standing
     * guarantee is that it carries no row-level site data, and a stable hash
     * is enough for what this field is for: telling whether two attempts
     * stopped on the SAME row.
     *
     * @param array<string, mixed> $row
     */
    private static function hashedRowKey(array $row): string {
        foreach (self::ROW_KEY_COLUMNS as $column) {
            if (isset($row[$column]) && is_scalar($row[$column]) && (string)$row[$column] !== '') {
                return substr(hash('sha256', (string)$row[$column]), 0, 12);
            }
        }
        return '';
    }

    private static function elapsedMs(float $startedAt): int {
        return max(0, (int)round((abj_clock()->nowFloat() - $startedAt) * 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-row-loop', $message);
    }
}
