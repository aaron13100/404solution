<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single recursion-safe chokepoint for logging-owned scalars stored
 * as keys inside the abj404_settings option row.
 *
 * Every logging code path that needs the debug-file suffix, the last-emailed
 * debug-log line, the weekly heartbeat timestamp, or the user debug-mode flag
 * goes through this adapter. The adapter reaches storage ONLY through the raw,
 * non-normalizing, non-logging accessors getRawSettingValue()/
 * setRawSettingValue() on the options repository. It never calls
 * getOptions()/updateOptions()/normalizeForRead().
 *
 * Why this matters (4.3.0 "broken sites after the latest update" OOM): the
 * runtime logger reads settings to locate its own debug-log file. Routing those
 * reads through getOptions() runs the normalize pipeline, which logs a warning
 * on any schema-validation failure; that warning re-enters the logger, which
 * re-reads options, recursing without bound until memory is exhausted. Reading
 * raw keeps logging strictly downstream of the settings repository so it can
 * never re-enter the settings-read pipeline. Concentrating all logging<->storage
 * access here makes that property a structural invariant of one small class
 * rather than a convention scattered across the logging subsystem.
 *
 * No storage migration: the scalars live as keys inside the abj404_settings
 * option row.
 */
class ABJ_404_Solution_LoggingStateStore {

    /** abj404_settings key holding the debug-file suffix. */
    const DEBUG_FILE_KEY = 'debug_file_key';

    /** abj404_settings key holding the last-emailed debug-log line. */
    const LAST_SENT_LINE = 'last_sent_line';

    /** abj404_settings key holding the last successful weekly heartbeat send time. */
    const LAST_HEARTBEAT_SENT_AT = 'last_heartbeat_sent_at';

    /** abj404_settings key holding the user debug-mode flag (user config; read-only here). */
    const DEBUG_MODE = 'debug_mode';

    /**
     * Options repository exposing the raw, non-normalizing, non-logging
     * accessors getRawSettingValue()/setRawSettingValue(). Typed loosely (not
     * the concrete resolver) so test doubles and a degraded-boot stub can stand
     * in; null is tolerated (the raw accessors no-op) so logging never crashes
     * for want of a repository. Access is guarded by is_object()/method_exists()
     * below.
     *
     * @var object|null
     */
    private $optionsRepo;

    /**
     * @param object|null $optionsRepo Options repository providing
     *        getRawSettingValue(string)/setRawSettingValue(string, mixed), or
     *        null in degraded boot (the raw accessors then no-op).
     */
    public function __construct($optionsRepo) {
        $this->optionsRepo = $optionsRepo;
    }

    /**
     * Resolve the shared logging-state store from the service container, falling
     * back to a fresh instance over the options repository when the container
     * cannot serve it (degraded boot). Always returns a usable, correctly-typed
     * store: the fallback tolerates a missing options repository (its raw
     * accessors no-op), so logging never crashes for want of a state store.
     *
     * @return ABJ_404_Solution_LoggingStateStore
     */
    public static function resolve(): ABJ_404_Solution_LoggingStateStore {
        $store = abj_service_optional('logging_state_store');
        if ($store instanceof self) {
            return $store;
        }
        $optionsRepo = abj_service_optional('options_repository');
        return new self(is_object($optionsRepo) ? $optionsRepo : null);
    }

    /**
     * Current debug-file suffix, or null when unset / not a string.
     *
     * @return string|null
     */
    public function getDebugFileKey(): ?string {
        $v = $this->rawGet(self::DEBUG_FILE_KEY);
        return is_string($v) ? $v : null;
    }

    /**
     * Persist the debug-file suffix (null clears it).
     *
     * @param string|null $key
     * @return void
     */
    public function setDebugFileKey(?string $key): void {
        $this->rawSet(self::DEBUG_FILE_KEY, $key);
    }

    /**
     * Last-emailed debug-log line number, or -1 when unset / not scalar.
     *
     * @return int
     */
    public function getLastSentLine(): int {
        $v = $this->rawGet(self::LAST_SENT_LINE);
        return is_scalar($v) ? (int)$v : -1;
    }

    /**
     * Persist the last-emailed debug-log line number.
     *
     * @param int $line
     * @return void
     */
    public function setLastSentLine(int $line): void {
        $this->rawSet(self::LAST_SENT_LINE, $line);
    }

    /**
     * Last successful weekly heartbeat send time, or 0 when unset / not scalar.
     *
     * @return int
     */
    public function getLastHeartbeatSentAt(): int {
        $v = $this->rawGet(self::LAST_HEARTBEAT_SENT_AT);
        return is_scalar($v) ? (int)$v : 0;
    }

    /**
     * Persist the last successful weekly heartbeat send time.
     *
     * @param int $timestamp Unix seconds.
     * @return void
     */
    public function setLastHeartbeatSentAt(int $timestamp): void {
        $this->rawSet(self::LAST_HEARTBEAT_SENT_AT, $timestamp);
    }

    /**
     * Whether the user has enabled debug mode. Read-only here: debug_mode is
     * user configuration owned by the settings UI; logging only consumes it.
     *
     * @return bool
     */
    public function isDebugMode(): bool {
        return $this->rawGet(self::DEBUG_MODE) == true;
    }

    /**
     * Raw, side-effect-free read of one abj404_settings key. Returns null when
     * the repository cannot serve a raw read (degraded boot / unexpected
     * double), which every caller treats as "unset".
     *
     * @param string $key
     * @return mixed
     */
    private function rawGet(string $key) {
        if (is_object($this->optionsRepo) && method_exists($this->optionsRepo, 'getRawSettingValue')) {
            return $this->optionsRepo->getRawSettingValue($key);
        }
        return null;
    }

    /**
     * Raw, side-effect-free write of one abj404_settings key. No-op when the
     * repository cannot serve a raw write (degraded boot / unexpected double).
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    private function rawSet(string $key, $value): void {
        if (is_object($this->optionsRepo) && method_exists($this->optionsRepo, 'setRawSettingValue')) {
            $this->optionsRepo->setRawSettingValue($key, $value);
        }
    }
}
