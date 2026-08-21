<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cross-request lock that lets only one hits-table rebuild run at a time.
 *
 * Rebuilding wp_abj404_logs_hits reads the whole logs table and swaps the
 * result in. Two of them at once is wasted work at best, and at worst one
 * rebuild's swap lands under the other's read. Cron, the shutdown listener and
 * an admin page view can all reach the rebuild in the same second, so the lock
 * is what keeps that to one.
 *
 * Occupancy is decided by ABJ_404_Solution_ExclusiveOptionRow, which claims the
 * row with an INSERT that UNIQUE(option_name) satisfies exactly once. This used
 * to be add_option(), on the understanding that it returns false for a name
 * that already exists; WordPress guards add_option() with a cache-served
 * get_option() and, since 6.4, writes with INSERT ... ON DUPLICATE KEY UPDATE,
 * so two concurrent callers could both be told they had added it.
 *
 * What lives HERE rather than in that primitive is the policy: how long a
 * holder may hold, when one may be displaced, and what an unreadable value
 * means. Those differ per lock (the synchronizer breaks on age and releases by
 * owner id; this one expires on a TTL), which is why the primitive deliberately
 * knows none of them.
 */
class ABJ_404_Solution_LogsHitsRebuildLock {

    /** @var string|null Exact row value acquired by this instance. */
    private $heldValue;

    /** How long a holder may hold before a later request may displace it.
     *
     * This is the leak bound, not a budget: nothing expires an option row, so
     * a rebuild killed mid-flight (a fatal, a reaped cron worker) would hold
     * the lock forever without it. It must comfortably exceed a healthy
     * rebuild, or a live rebuild gets displaced by the next request and both
     * then run, which is the thing the lock exists to prevent.
     *
     * @var int
     */
    const TTL_SECONDS = 180;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseCoreInterface $dbCore */
    public function __construct($dbCore) {
        $this->dbCore = $dbCore;
    }

    /** Take the lock, so exactly one rebuild runs.
     *
     * The claim comes FIRST, before anything is read. Reading the row and then
     * deciding whether to write it is the protocol two concurrent requests both
     * pass, because WordPress answers that read from a per-request cache. The
     * read below happens only after a claim has already been attempted and
     * lost, purely to decide whether the holder it lost to has aged out.
     *
     * @return bool true only if this request holds the lock.
     */
    public function acquire(): bool {
        $lockName = $this->optionName();
        $lockRow = $this->lockRow();
        $now = abj_clock()->now();
        $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if ($lockRow->claim(array('optionName' => $lockName, 'value' => $claimValue))) {
            $this->heldValue = $claimValue;
            return true;
        }

        // A row already exists. Only a genuinely expired holder may be
        // displaced, and the retry is another atomic claim, so at most one of
        // several requests that all found the same expired lock takes it.
        if (!$this->releaseStaleHolder($lockRow, $lockName)) {
            return false;
        }

        $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        $claimed = $lockRow->claim(array('optionName' => $lockName, 'value' => $claimValue));
        if ($claimed) {
            $this->heldValue = $claimValue;
        }
        return $claimed;
    }

    /** @return void */
    public function release(): void {
        if ($this->heldValue === null) {
            return;
        }
        $this->lockRow()->releaseIfValueIs(array(
            'optionName' => $this->optionName(),
            'value' => $this->heldValue,
        ));
        $this->heldValue = null;
    }

    /** Refresh the lease only while this instance still owns the row. */
    public function renew(): bool {
        if ($this->heldValue === null) {
            return false;
        }
        $replacementValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue(
            (string)abj_clock()->now()
        );
        $renewed = $this->lockRow()->replaceValueIfMatches(array(
            'optionName' => $this->optionName(),
            'currentValue' => $this->heldValue,
            'replacementValue' => $replacementValue,
        ));
        if ($renewed) {
            $this->heldValue = $replacementValue;
        }
        return $renewed;
    }

    /** Whether a live (non-expired) holder currently has the lock.
     * This observation is deliberately side-effect free; stale-row cleanup is
     * part of acquire(), the operation that needs to replace such a row.
     *
     * @return bool
     */
    public function isHeld(): bool {
        $lockName = $this->optionName();
        $lockRow = $this->lockRow();
        $lockValue = $lockRow->valueOf($lockName);
        if ($lockValue === '') {
            return false;
        }

        return !$this->isStaleValue($lockValue);
    }

    /**
     * Remove an unusable or expired holder after an acquisition attempt lost.
     * Conditional deletion preserves a newer holder that raced with this read.
     */
    private function releaseStaleHolder(
        ABJ_404_Solution_ExclusiveOptionRow $lockRow,
        string $lockName
    ): bool {
        $lockValue = $lockRow->valueOf($lockName);
        if ($lockValue === '') {
            return true;
        }

        if (!$this->isStaleValue($lockValue)) {
            return false;
        }

        return $lockRow->releaseIfValueIs(array(
            'optionName' => $lockName,
            'value' => $lockValue,
        ));
    }

    /** One liveness rule shared by observation and stale-holder eviction. */
    private function isStaleValue(string $lockValue): bool {
        $timestampPart = explode(':', $lockValue, 2)[0];
        return !is_numeric($timestampPart)
            || (int)$timestampPart <= 0
            || (abj_clock()->now() - (int)$timestampPart) > self::TTL_SECONDS;
    }

    /** The option row that holds the lock. Stateless, so a fresh instance
     * costs nothing.
     *
     * @return ABJ_404_Solution_ExclusiveOptionRow
     */
    private function lockRow(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }

    /** @return string */
    private function optionName(): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix()
            . 'abj404_logs_hits_rebuild_lock';
    }
}
