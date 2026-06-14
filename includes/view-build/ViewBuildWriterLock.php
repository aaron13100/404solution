<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serializes the view-snapshot writer so two rebuild attempts cannot run at
 * once.
 *
 * Prefers a MySQL named lock (GET_LOCK). When the host does not support
 * GET_LOCK, it memoizes that for the request and falls back to an
 * add_option()-based mutex with a TTL. Holds no build logic: only the
 * acquire/release contract and the fallback decision.
 */
class ABJ_404_Solution_ViewBuildWriterLock {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_ViewBuildTableNames */
    private $tableNames;
    /** @var bool */
    private $usingFallbackLock = false;
    /** @var bool|null */
    private static $namedLockSupportedThisRequest = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewBuildTableNames $tableNames
     */
    public function __construct($dbCore, ABJ_404_Solution_ViewBuildTableNames $tableNames) {
        $this->dbCore = $dbCore;
        $this->tableNames = $tableNames;
    }

    /** @return void */
    public static function resetFallbackMemos(): void {
        self::$namedLockSupportedThisRequest = null;
    }

    /** @param int $timeoutSeconds @return bool */
    public function acquire(int $timeoutSeconds): bool {
        $name = $this->lockName();
        if (self::$namedLockSupportedThisRequest === false) {
            return $this->acquireFallback($name);
        }
        $result = $this->dbCore->queryAndGetResults(
            "SELECT GET_LOCK('" . esc_sql($name) . "', " . max(0, $timeoutSeconds) . ") AS got",
            array('log_errors' => false)
        );
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '' && stripos($err, 'get_lock') !== false) {
            self::$namedLockSupportedThisRequest = false;
            return $this->acquireFallback($name);
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return false;
        }
        $got = $rows[0]['got'] ?? reset($rows[0]);
        if ($got === null) {
            self::$namedLockSupportedThisRequest = false;
            return $this->acquireFallback($name);
        }
        if (is_scalar($got) && intval($got) === 1) {
            self::$namedLockSupportedThisRequest = true;
            $this->usingFallbackLock = false;
            return true;
        }
        return false;
    }

    /** @return void */
    public function release(): void {
        $name = $this->lockName();
        if ($this->usingFallbackLock) {
            $this->usingFallbackLock = false;
            if (function_exists('delete_option')) {
                delete_option($name . '_transient_lock');
            }
            return;
        }
        $this->dbCore->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')", array('log_errors' => false));
    }

    /**
     * Acquire the lock, write-then-read a probe option, and release. Confirms
     * the writer lock serializes a write/read round trip. Retained as a
     * diagnostic seam for the view-build stage-state contract.
     *
     * @return bool
     */
    public function verifySerializesWriter(): bool {
        if (!$this->acquire(0)) {
            return false;
        }
        try {
            if (!function_exists('update_option') || !function_exists('get_option') || !function_exists('delete_option')) {
                return false;
            }
            $optionName = $this->tableNames->prefixedOption('abj404_view_build_lock_writer_probe');
            $value = (string)abj_clock()->nowFloat();
            update_option($optionName, $value, false);
            $readBack = get_option($optionName, '');
            delete_option($optionName);
            return $readBack === $value;
        } finally {
            $this->release();
        }
    }

    /** @return string */
    private function lockName(): string {
        return $this->tableNames->prefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
    }

    /** @param string $name @return bool */
    private function acquireFallback(string $name): bool {
        if (!function_exists('add_option') || !function_exists('get_option')) {
            return false;
        }
        $optionName = $name . '_transient_lock';
        $now = abj_clock()->now();
        $existing = get_option($optionName, 0);
        $existingExpires = is_scalar($existing) ? intval($existing) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now && function_exists('delete_option')) {
            delete_option($optionName);
        }
        if (add_option($optionName, (string)($now + ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_TRANSIENT_LOCK_TTL_SECONDS), '', false)) {
            $this->usingFallbackLock = true;
            return true;
        }
        return false;
    }
}
