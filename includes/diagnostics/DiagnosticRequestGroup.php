<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything one request left behind in a diagnostic journal, and what those
 * records say about how it ended.
 *
 * The journals are append-only streams of independent records, but the unit a
 * developer reads -- and the unit a bounded support payload has to keep whole
 * or drop whole -- is the request. This is that unit: which lines belong to a
 * request, what they cost in bytes, and the three facts that decide whether
 * the request is worth budget (did it reach a terminal record, did it record a
 * failure, and which attempt was it retrying).
 *
 * It holds no ordering and no budget policy; that is
 * ABJ_404_Solution_DiagnosticEvidencePriority's. It reads no files; that is
 * ABJ_404_Solution_DiagnosticJournalExcerpt's.
 */
final class ABJ_404_Solution_DiagnosticRequestGroup {

    /** @var string */
    private $requestId;

    /** @var bool Whether these records carry a usable request id at all. */
    private $joinable;

    /** @var array<int, int> Line indexes into the caller's stream, in stream order. */
    private $indexes = array();

    /** @var int Bytes these lines cost, newlines included. */
    private $bytes = 0;

    /** @var bool */
    private $terminal = false;

    /** @var bool */
    private $failure = false;

    /** @var array<string, bool> Request ids this one recorded as its retry parent. */
    private $parents = array();

    public function __construct(string $requestId, bool $joinable) {
        $this->requestId = $requestId;
        $this->joinable = $joinable;
    }

    public function requestId(): string {
        return $this->requestId;
    }

    /** @return array<int, int> */
    public function indexes(): array {
        return $this->indexes;
    }

    public function bytes(): int {
        return $this->bytes;
    }

    public function recordCount(): int {
        return count($this->indexes);
    }

    public function hasRecords(): bool {
        return $this->indexes !== array();
    }

    /** @return array<int, string> */
    public function parentIds(): array {
        return array_keys($this->parents);
    }

    /**
     * Whether this request is one the investigation is about.
     *
     * "No terminal record" counts: a request that simply stops, with no
     * response and no teardown, IS the symptom under investigation, and it can
     * only be recognised by that absence. Unjoinable lines are never failing --
     * they belong to no request, so they cannot be one that failed.
     */
    public function isFailing(): bool {
        return $this->joinable && ($this->failure || !$this->terminal);
    }

    /**
     * Whether this request's record run must survive INTACT rather than
     * being trimmed to its two ends.
     *
     * A completed request's head and tail really are its two most
     * informative records, because a "how it ended" record exists at the
     * tail. A request with no terminal event never wrote one: every record
     * it still holds, including the middle, is the only account of what it
     * was doing while it stalled (report 193: the 165-second holder had no
     * request_end, and trimming it to head+tail dropped exactly the seven
     * records that showed it was stuck).
     */
    public function isMaximallyDecisive(): bool {
        return $this->joinable && !$this->terminal;
    }

    public function addLine(int $index, int $bytes): void {
        $this->indexes[] = $index;
        $this->bytes += $bytes;
    }

    /**
     * Fold one of this request's own records into the classification.
     *
     * @param array<array-key, mixed> $record
     */
    public function applyRecord(array $record): void {
        $event = isset($record['event']) && is_scalar($record['event']) ? (string)$record['event'] : '';
        if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::TERMINAL_EVENTS, true)) {
            $this->terminal = true;
        }
        if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::FAILURE_EVENTS, true)) {
            $this->markFailed();
        }
        // Any status other than 'complete' -- 'error', a truncated status
        // string, anything a future boundary invents -- is treated as a
        // failure. An allowlist rather than a deny-list, so a new failure
        // status cannot be silently filed as healthy.
        $status = isset($record['status']) && is_scalar($record['status']) ? (string)$record['status'] : '';
        if ($status !== '' && $status !== 'complete') {
            $this->markFailed();
        }
        if ($event === 'selftest' && array_key_exists('ok', $record) && $record['ok'] === false) {
            $this->markFailed();
        }
        if (isset($record['retry_parent_id']) && is_scalar($record['retry_parent_id'])
                && (string)$record['retry_parent_id'] !== '') {
            $this->parents[(string)$record['retry_parent_id']] = true;
        }
    }

    public function markFailed(): void {
        $this->failure = true;
    }
}
