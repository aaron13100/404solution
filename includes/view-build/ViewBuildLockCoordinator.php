<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates cross-request writer serialization for staged view builds.
 *
 * Owns the native MySQL named lock path, the option-row fallback used when
 * GET_LOCK is unavailable, and the write-side verification probe for hosts
 * with split lock and writer routing.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildLockCoordinator extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Per-request memo of whether session-scoped GET_LOCK is supported on
     * this host. null = not yet probed; true = a prior probe returned got=1;
     * false = a prior probe returned NULL or a recognized unsupported error.
     *
     * @var bool|null
     */
    private static $namedLockSupportedThisRequest = null;

    /**
     * Fires the fallback log line once per request.
     *
     * @var bool
     */
    private static $fallbackLockLoggedThisRequest = false;

    /**
     * Tracks whether the most recent successful acquire used the option-row
     * fallback so releaseViewBuildLock releases the matching primitive.
     *
     * @var bool
     */
    private $usingTransientFallbackLock = false;

    /** @var string Last detected reason for falling back; surfaced in the notice. */
    private $lastNamedLockUnsupportedReason = '';
    /** @var string Last detected error string from GET_LOCK; surfaced in the notice. */
    private $lastNamedLockUnsupportedError = '';

    /**
     * @param int $timeoutSeconds GET_LOCK wait-time. 0 is the normal
     *   non-blocking acquire; positive values are reserved for diagnostics
     *   and force-rebuild paths.
     * @return bool
     */
    public function acquireViewBuildLock(int $timeoutSeconds = 0): bool {
        $name = $this->host->dataBoundary()->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;

        if (self::$namedLockSupportedThisRequest === false) {
            $this->ensureFallbackLockNoticeAndLog();
            return $this->acquireTransientFallbackLock($name);
        }

        $timeout = max(0, $timeoutSeconds);
        $sql = "SELECT GET_LOCK('" . esc_sql($name) . "', " . $timeout . ") AS got";
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, array('log_errors' => false));

        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '' && $this->isNamedLockUnsupportedError($err)) {
            self::$namedLockSupportedThisRequest = false;
            $this->lastNamedLockUnsupportedReason = 'function_unsupported';
            $this->lastNamedLockUnsupportedError = $err;
            $this->ensureFallbackLockNoticeAndLog();
            return $this->acquireTransientFallbackLock($name);
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (!empty($rows) && is_array($rows[0]) && array_key_exists('got', $rows[0])) {
            $got = $rows[0]['got'];
            if ($got === null) {
                self::$namedLockSupportedThisRequest = false;
                $this->lastNamedLockUnsupportedReason = 'returned_null';
                $this->lastNamedLockUnsupportedError = '';
                $this->ensureFallbackLockNoticeAndLog();
                return $this->acquireTransientFallbackLock($name);
            }
            $intGot = is_scalar($got) ? intval($got) : 0;
            if ($intGot === 1) {
                if (self::$namedLockSupportedThisRequest === null) {
                    self::$namedLockSupportedThisRequest = true;
                }
                $this->usingTransientFallbackLock = false;
                return true;
            }
            return false;
        }

        return false;
    }

    /** @return void */
    public function releaseViewBuildLock(): void {
        $name = $this->host->dataBoundary()->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        if ($this->usingTransientFallbackLock) {
            $this->usingTransientFallbackLock = false;
            if (function_exists('delete_option')) {
                delete_option($this->transientFallbackLockOptionName($name));
            }
            return;
        }
        // @utf8-audit: opt-out - $name is system-controlled (table prefix from $wpdb plus a
        // compile-time constant lock name). No user input reaches this string.
        $this->host->dataBoundary()->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')",
            array('log_errors' => false));
    }

    /**
     * Acquire the option-row advisory lock that stands in for GET_LOCK on
     * hosts where named locks are unavailable.
     *
     * @param string $name Already-prefixed lock identifier shared with GET_LOCK.
     * @return bool
     */
    public function acquireTransientFallbackLock(string $name): bool {
        if (!function_exists('add_option') || !function_exists('get_option')) {
            return false;
        }
        $optionName = $this->transientFallbackLockOptionName($name);
        $now = time();
        $ttl = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_TRANSIENT_LOCK_TTL_SECONDS;
        $expiresAt = $now + $ttl;

        $existing = get_option($optionName, 0);
        $existingExpires = is_scalar($existing) ? intval($existing) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now && function_exists('delete_option')) {
            delete_option($optionName);
        }

        $added = add_option($optionName, (string)$expiresAt, '', false);
        if ($added) {
            $this->usingTransientFallbackLock = true;
            return true;
        }
        return false;
    }

    /** @param string $name @return string */
    public function transientFallbackLockOptionName(string $name): string {
        return $name . '_transient_lock';
    }

    /**
     * @param string $err
     * @return bool
     */
    public function isNamedLockUnsupportedError(string $err): bool {
        $errLow = strtolower($err);
        if (strpos($errLow, 'get_lock') === false) {
            return false;
        }
        return strpos($errLow, 'does not exist') !== false
            || strpos($errLow, 'unknown function') !== false
            || strpos($errLow, 'er_sp_does_not_exist') !== false
            || strpos($errLow, 'is not allowed') !== false
            || strpos($errLow, 'not allowed in this context') !== false;
    }

    /** @return void */
    public function ensureFallbackLockNoticeAndLog(): void {
        if (function_exists('set_transient')) {
            // allow-cache-empty: notice must exist even when the host returns no named-lock error text.
            set_transient(
                'abj404_view_build_get_lock_unsupported_notice',
                array(
                    'reason'  => $this->lastNamedLockUnsupportedReason !== ''
                        ? $this->lastNamedLockUnsupportedReason : 'unknown',
                    'error'   => substr($this->lastNamedLockUnsupportedError, 0, 500),
                    'when'    => time(),
                    'message_key' => 'view.build_named_lock_unsupported',
                    'message_params' => array(),
                ),
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        }
        if (!self::$fallbackLockLoggedThisRequest) {
            self::$fallbackLockLoggedThisRequest = true;
            $message = '[staged] view-build lock: GET_LOCK unsupported on this host '
                . '(reason=' . ($this->lastNamedLockUnsupportedReason !== ''
                    ? $this->lastNamedLockUnsupportedReason : 'unknown')
                . '); using option-row fallback.';
            if (is_object($this->host->dataBoundary()->logger()) && method_exists($this->host->dataBoundary()->logger(), 'infoMessage')) {
                $this->host->dataBoundary()->logger()->infoMessage($message);
            } elseif (is_object($this->host->dataBoundary()->logger()) && method_exists($this->host->dataBoundary()->logger(), 'debugMessage')) {
                $this->host->dataBoundary()->logger()->debugMessage($message);
            }
        }
    }

    /** @return void */
    public static function resetViewBuildLockFallbackMemos(): void {
        self::$namedLockSupportedThisRequest = null;
        self::$fallbackLockLoggedThisRequest = false;
    }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool {
        if (!$this->acquireViewBuildLock(0)) {
            return false;
        }
        try {
            if (!function_exists('update_option') || !function_exists('get_option')) {
                return false;
            }
            $optionName = $this->host->dataBoundary()->getLowercasePrefix() . 'abj404_view_build_lock_writer_probe';
            try {
                $nonce = bin2hex(random_bytes(8));
            } catch (\Throwable $t) { // allow-silent-catch: random_bytes unavailable on some hosts; mt_rand fallback is sufficient for a disposable write-probe nonce
                $nonce = (string)mt_rand() . '_' . (string)microtime(true);
            }
            update_option($optionName, $nonce, false);
            $readBack = get_option($optionName, '');
            if (function_exists('delete_option')) {
                delete_option($optionName);
            }
            return is_string($readBack) && $readBack === $nonce;
        } finally {
            $this->releaseViewBuildLock();
        }
    }
}
