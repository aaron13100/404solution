<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database infrastructure state shared by DatabaseCore helpers.
 *
 * Capability probes are memoized in-process and persisted briefly through
 * WordPress transients so a known host limitation is not rediscovered by
 * every new PHP request.
 */
final class ABJ_404_Solution_DatabaseRuntimeState {

    /** @var int Cooldown when DB query quota is exceeded. */
    const DB_QUOTA_COOLDOWN_SECONDS = 900;

    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = 900;

    /** @var string Cross-request flag for hosts that reject SET STATEMENT. */
    const SET_STATEMENT_WRAPPER_UNSUPPORTED_TRANSIENT = 'abj404_set_statement_wrapper_unsupported';

    /** @var int Re-probe SET STATEMENT support after one hour. */
    const SET_STATEMENT_WRAPPER_UNSUPPORTED_TTL_SECONDS = 3600;

    /** @var bool Whether MariaDB SET STATEMENT wrapper failed and should be skipped. */
    private static $setStatementWrapperUnsupported = false;

    /** @var bool Whether the persisted capability flag was checked this request. */
    private static $setStatementWrapperPersistenceChecked = false;

    /**
     * Update request-local support state and persist confirmed rejection.
     *
     * Passing false resets only request-local memoization. The transient is
     * deliberately left intact so the next request still honors the observed
     * host capability until its short TTL expires.
     *
     * @return void
     */
    public static function setSetStatementWrapperUnsupported(bool $value): void {
        self::$setStatementWrapperUnsupported = $value;
        self::$setStatementWrapperPersistenceChecked = $value;
        if ($value && function_exists('set_transient')) {
            // allow-cache-empty: capability-probe result, not a fallible query result.
            set_transient(
                self::SET_STATEMENT_WRAPPER_UNSUPPORTED_TRANSIENT,
                1,
                self::SET_STATEMENT_WRAPPER_UNSUPPORTED_TTL_SECONDS
            );
        }
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        if (self::$setStatementWrapperUnsupported) {
            return true;
        }
        if (self::$setStatementWrapperPersistenceChecked) {
            return false;
        }

        self::$setStatementWrapperPersistenceChecked = true;
        if (function_exists('get_transient')) {
            $persisted = get_transient(self::SET_STATEMENT_WRAPPER_UNSUPPORTED_TRANSIENT);
            self::$setStatementWrapperUnsupported = in_array($persisted, array(1, '1', true), true);
        }
        return self::$setStatementWrapperUnsupported;
    }

    /**
     * Name the source the next timeout-capability read will consult.
     *
     * This exposes no cache value or key. It exists so query-preflight
     * diagnostics can distinguish an in-process decision from a WordPress
     * transient read that may invoke a third-party object-cache drop-in.
     */
    public static function setStatementWrapperCapabilitySource(): string {
        if (self::$setStatementWrapperUnsupported
                || self::$setStatementWrapperPersistenceChecked) {
            return 'request_local';
        }
        return function_exists('get_transient') ? 'transient' : 'unavailable';
    }
}
