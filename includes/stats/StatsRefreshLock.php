<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates stats refresh work through WordPress option locks.
 */
class ABJ_404_Solution_StatsRefreshLock {

    /** @var int Cooldown for distributed refresh locks. */
    const REFRESH_LOCK_COOLDOWN_SECONDS = 30;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseCoreInterface $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCoreInterface $dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @param string $cacheKey @return bool */
    public function acquire(string $cacheKey): bool {
        if (!function_exists('add_option')) {
            return true;
        }

        $lockKey = $this->getOptionName($cacheKey);
        if (add_option($lockKey, abj_clock()->now(), '', false)) {
            return true;
        }

        if (!function_exists('get_option')) {
            return false;
        }

        $lockValue = get_option($lockKey, false);
        if ($lockValue === false || $lockValue === '' || $lockValue === null) {
            return (bool)add_option($lockKey, abj_clock()->now(), '', false);
        }

        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (abj_clock()->now() - $lockTs) > self::REFRESH_LOCK_COOLDOWN_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($lockKey);
            }
            return (bool)add_option($lockKey, abj_clock()->now(), '', false);
        }

        return false;
    }

    /** @param string $cacheKey @return void */
    public function release(string $cacheKey): void {
        if (function_exists('delete_option')) {
            delete_option($this->getOptionName($cacheKey));
        }
    }

    /** @param string $cacheKey @return string */
    private function getOptionName(string $cacheKey): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }
}
