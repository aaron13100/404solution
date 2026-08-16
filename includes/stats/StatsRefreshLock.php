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

    /** @param ABJ_404_Solution_DatabaseCoreInterface $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCoreInterface $dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @param string $cacheKey @return bool */
    public function acquire(string $cacheKey): bool {
        $lockKey = $this->getOptionName($cacheKey);
        $lockRow = $this->lockRow();

        if ($lockRow->claim($lockKey, (string)abj_clock()->now())) {
            return true;
        }

        // A row already exists. Read it from the table (not from the options
        // cache, which answers a concurrent request with its own write) and
        // displace it only when it has genuinely aged out. Both the removal and
        // the retry are conditional/atomic, so several requests finding the
        // same expired lock still produce exactly one winner.
        $lockValue = $lockRow->valueOf($lockKey);
        if ($lockValue === '') {
            return $lockRow->claim($lockKey, (string)abj_clock()->now());
        }

        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (abj_clock()->now() - $lockTs) > self::REFRESH_LOCK_COOLDOWN_SECONDS) {
            $lockRow->releaseIfValueIs($lockKey, $lockValue);
            return $lockRow->claim($lockKey, (string)abj_clock()->now());
        }

        return false;
    }

    /** @param string $cacheKey @return void */
    public function release(string $cacheKey): void {
        $this->lockRow()->release($this->getOptionName($cacheKey));
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
