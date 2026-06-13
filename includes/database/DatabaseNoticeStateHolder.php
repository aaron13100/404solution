<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the plugin's database notice and runtime-flag state.
 *
 * Extracted from DatabaseCore (design-audit M202 "separate state holder"
 * callout). This class is the single home for:
 *   - Runtime flags persisted as transients (with option fallback): cooldown
 *     timestamps, write-block markers, and the admin-notice payload.
 *   - The abj404_plugin_db_notice admin-notice payload lifecycle (set, clear,
 *     clear-if-type).
 *   - Write-block / skip-non-essential-write cooldown detection.
 *   - The per-request server-side-issue tracking booleans
 *     (serverSideIssueNoted / serverSideIssueChecked) that drive notice
 *     auto-clear once the server recovers.
 *
 * This class holds no SQL query pipeline and builds no queries. It depends on
 * a clock (for cooldown comparisons and notice timestamps) and on a
 * quota-cooldown checker callable (supplied by DatabaseCore as a bound closure
 * over its error classifier) so that it needs no DatabaseCore reference and
 * introduces no cyclic coupling. The callable signature is:
 *   function(): bool
 */
class ABJ_404_Solution_DatabaseNoticeStateHolder {

    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = 900;

    /** @var ABJ_404_Solution_Clock|null */
    private $clock = null;

    /** @var callable(): bool */
    private $quotaCooldownChecker;

    /** @var bool Whether a server-side DB issue was noted this request (for auto-clear). */
    private $serverSideIssueNoted = false;

    /** @var bool Whether we already checked for a stale notice transient this request. */
    private $serverSideIssueChecked = false;

    /**
     * @param callable(): bool $quotaCooldownChecker Returns true when a DB quota
     *   cooldown is currently active. Supplied by DatabaseCore as a bound closure
     *   over its error classifier so this class needs no DatabaseCore reference.
     * @param ABJ_404_Solution_Clock|null $clock Optional clock; resolved lazily
     *   from the service container (or a SystemClock fallback) when omitted.
     */
    public function __construct(callable $quotaCooldownChecker, $clock = null) {
        $this->quotaCooldownChecker = $quotaCooldownChecker;
        $this->clock = $clock;
    }

    /**
     * Inject the clock instance for testability.
     *
     * @param ABJ_404_Solution_Clock $clock
     * @return void
     */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->clock = $clock;
    }

    /**
     * Resolve the clock instance (injected, container, or SystemClock fallback).
     *
     * @return ABJ_404_Solution_Clock
     */
    public function clock(): ABJ_404_Solution_Clock {
        if ($this->clock !== null) { return $this->clock; }
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $resolved = ABJ_404_Solution_ServiceContainer::safeGet('clock');
            if ($resolved instanceof ABJ_404_Solution_Clock) {
                $this->clock = $resolved;
                return $this->clock;
            }
        }
        $this->clock = new ABJ_404_Solution_SystemClock();
        return $this->clock;
    }

    /**
     * Persist a runtime flag as a transient (option fallback when transients
     * are unavailable).
     *
     * @param string $key Flag name.
     * @param mixed $value Value to store (admin-notice payload, cooldown
     *   timestamp, or lock-state marker).
     * @param int $ttlSeconds Transient lifetime in seconds.
     * @return void
     */
    public function setRuntimeFlag(string $key, $value, int $ttlSeconds): void {
        if (function_exists('set_transient')) {
            // allow-cache-empty: passthrough helper. Callers store admin-notice payloads, cooldown timestamps, and lock-state markers, not query results.
            set_transient($key, $value, $ttlSeconds);
            return;
        }
        if (function_exists('update_option')) {
            update_option($key, $value, false);
        }
    }

    /**
     * Read a runtime flag (transient, with option fallback).
     *
     * @param string $key Flag name.
     * @return mixed The stored value, or false when unset/unavailable.
     */
    public function getRuntimeFlag(string $key) {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }
        if (function_exists('get_option')) {
            return get_option($key, false);
        }
        return false;
    }

    /**
     * Store the plugin DB admin-notice payload as a runtime flag.
     *
     * @param string $type Notice type discriminator (e.g. 'lock_timeout').
     * @param string $message Already translated admin notice message.
     * @param string $guidance Already translated optional remediation guidance.
     * @param string $errorString Underlying MySQL error string (diagnostic).
     * @return void
     */
    public function setPluginDbNotice(string $type, string $message, string $guidance, string $errorString = ''): void {
        $payload = array(
            'type' => $type,
            'message' => $message,
            'guidance' => $guidance,
            'timestamp' => $this->clock()->now(),
            'error_string' => $errorString,
        );
        $this->setRuntimeFlag('abj404_plugin_db_notice', $payload, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
    }

    /**
     * Clear the plugin DB notice only when its current type matches.
     *
     * @param string $type Notice type to match before clearing.
     * @return void
     */
    public function clearPluginDbNoticeIfType(string $type): void {
        $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($existing)) {
            return;
        }
        $currentType = isset($existing['type']) && is_string($existing['type']) ? $existing['type'] : '';
        if ($currentType !== $type) {
            return;
        }
        $this->clearServerSideDbNotice();
    }

    /**
     * Delete the plugin DB notice and reset the server-side-issue flag.
     *
     * @return void
     */
    public function clearServerSideDbNotice(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_plugin_db_notice');
        } elseif (function_exists('delete_option')) {
            delete_option('abj404_plugin_db_notice');
        }
        $this->serverSideIssueNoted = false;
    }

    /**
     * @return bool True when a write-block cooldown is active (disk full or read-only).
     */
    public function isWriteBlockActive(): bool {
        $rawDiskFlag = $this->getRuntimeFlag('abj404_db_disk_full_until');
        $diskUntil = is_scalar($rawDiskFlag) ? (int)$rawDiskFlag : 0;
        $rawReadOnlyFlag = $this->getRuntimeFlag('abj404_db_read_only_until');
        $readOnlyUntil = is_scalar($rawReadOnlyFlag) ? (int)$rawReadOnlyFlag : 0;
        $now = $this->clock()->now();
        return ($diskUntil > $now || $readOnlyUntil > $now);
    }

    /**
     * @return bool True when non-essential DB writes should be skipped (quota
     *   cooldown or write-block active).
     */
    public function shouldSkipNonEssentialDbWrites(): bool {
        return (($this->quotaCooldownChecker)() || $this->isWriteBlockActive());
    }

    /**
     * Mark that a server-side DB issue was noted this request, so the notice
     * can be auto-cleared once a later query succeeds.
     *
     * @return void
     */
    public function markServerSideIssueNoted(): void {
        $this->serverSideIssueNoted = true;
    }

    /**
     * @return bool Whether a server-side DB issue was noted this request.
     */
    public function isServerSideIssueNoted(): bool {
        return $this->serverSideIssueNoted;
    }

    /**
     * @return bool Whether the stale-notice transient was already checked this request.
     */
    public function isServerSideIssueChecked(): bool {
        return $this->serverSideIssueChecked;
    }

    /**
     * Mark the stale-notice transient as checked for this request.
     *
     * @return void
     */
    public function markServerSideIssueChecked(): void {
        $this->serverSideIssueChecked = true;
    }
}
