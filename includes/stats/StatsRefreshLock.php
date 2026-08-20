<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates stats refresh work through WordPress option locks.
 */
class ABJ_404_Solution_StatsRefreshLock {

    /**
     * Distributed lock lifetime. This must exceed the longest guarded query
     * budget (the 60-second high-impact count) so a live worker is never
     * mistaken for a stale lock while its query is still running.
     *
     * @var int
     */
    const REFRESH_LOCK_COOLDOWN_SECONDS = 120;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @var array<string, string> Cache key to exact row value held by this instance. */
    private $heldValues = array();

    /** @param ABJ_404_Solution_DatabaseCoreInterface $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCoreInterface $dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @param string $cacheKey @return bool */
    public function acquire(string $cacheKey): bool {
        $lockKey = $this->getOptionName($cacheKey);
        $lockRow = $this->lockRow();
        $now = abj_clock()->now();
        $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);

        if ($lockRow->claim(array('optionName' => $lockKey, 'value' => $claimValue))) {
            $this->heldValues[$cacheKey] = $claimValue;
            return true;
        }

        // A row already exists. Read it from the table (not from the options
        // cache, which answers a concurrent request with its own write) and
        // displace it only when it has genuinely aged out. Both the removal and
        // the retry are conditional/atomic, so several requests finding the
        // same expired lock still produce exactly one winner.
        $lockValue = $lockRow->valueOf($lockKey);
        if ($lockValue === '') {
            $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
            $claimed = $lockRow->claim(array('optionName' => $lockKey, 'value' => $claimValue));
            if ($claimed) {
                $this->heldValues[$cacheKey] = $claimValue;
            }
            return $claimed;
        }

        $timestampPart = explode(':', $lockValue, 2)[0];
        $lockTs = is_numeric($timestampPart) ? (int)$timestampPart : 0;
        if ($lockTs > 0 && ($now - $lockTs) > self::REFRESH_LOCK_COOLDOWN_SECONDS) {
            $lockRow->releaseIfValueIs(array('optionName' => $lockKey, 'value' => $lockValue));
            $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
            $claimed = $lockRow->claim(array('optionName' => $lockKey, 'value' => $claimValue));
            if ($claimed) {
                $this->heldValues[$cacheKey] = $claimValue;
            }
            return $claimed;
        }

        return false;
    }

    /** @param string $cacheKey @return void */
    public function release(string $cacheKey): void {
        if (!isset($this->heldValues[$cacheKey])) {
            return;
        }
        $this->lockRow()->releaseIfValueIs(array(
            'optionName' => $this->getOptionName($cacheKey),
            'value' => $this->heldValues[$cacheKey],
        ));
        unset($this->heldValues[$cacheKey]);
    }

    /** The lock row itself. Stateless, so a fresh instance costs nothing.
     * @return ABJ_404_Solution_ExclusiveOptionRow */
    private function lockRow(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }

    /** @param string $cacheKey @return string */
    private function getOptionName(string $cacheKey): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }
}
