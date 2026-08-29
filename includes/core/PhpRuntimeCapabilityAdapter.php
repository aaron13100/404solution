<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability-safe boundary for PHP functions a hosting provider can remove.
 *
 * PHP 8 removes names in disable_functions from the function table, so a
 * direct call raises Error before a return-value check can help. PHP 7.4 emits
 * a warning and returns null for the same configuration. Callers use this
 * adapter so both runtimes degrade to one explicit return contract and the
 * capability decision exists in one place.
 */
final class ABJ_404_Solution_PhpRuntimeCapabilityAdapter {

    /**
     * Functions the plugin actually invokes and THIS adapter owns.
     *
     * Not the whole boundary: the pcntl and OPcache extensions have their own
     * adapters and their own lists, because a host removes those as a unit
     * rather than a function at a time. scripts/lint/lint-disable-functions-boundary.php
     * composes the union across all three, so a raw call to any of them is
     * still flagged.
     */
    private const OWNED_FUNCTIONS = array(
        'disk_free_space',
        'disk_total_space',
        'error_log',
        'gethostname',
        'getmypid',
        'getrusage',
        'ini_set',
        'posix_geteuid',
        'sys_getloadavg',
    );

    /** @var string|null Process-stable fallback when the host withholds its PID. */
    private static $syntheticProcessToken = null;

    /** @return array<int, string> Functions whose direct use this boundary owns. */
    public static function ownedFunctions(): array {
        return self::OWNED_FUNCTIONS;
    }

    /** Whether a PHP function is callable on this host. */
    public static function isFunctionAvailable(string $name): bool {
        return function_exists($name);
    }

    /** The real positive process ID, or null when the host withholds it. */
    public static function processId(): ?int {
        if (!self::isFunctionAvailable('getmypid')) {
            return null;
        }
        $pid = getmypid();
        return is_int($pid) && $pid > 0 ? $pid : null;
    }

    /**
     * Stable identity for lock values and filenames within this interpreter.
     *
     * The healthy path preserves the previous decimal PID shape. The fallback
     * is generated once per interpreter and is deliberately labelled synthetic
     * so it can never be mistaken for a PID. Every uniqueness-sensitive caller
     * also retains its request id, timestamp, or per-claim uniqid suffix, so the
     * fallback replaces process correlation without becoming the sole entropy.
     */
    public static function processToken(): string {
        $pid = self::processId();
        if ($pid !== null) {
            return (string)$pid;
        }
        if (self::$syntheticProcessToken === null) {
            self::$syntheticProcessToken = 'synthetic-'
                . substr(hash('sha256', uniqid('', true)), 0, 20);
        }
        return self::$syntheticProcessToken;
    }

    /**
     * Positive integer for compact internal IDs that historically embedded a
     * PID. A real PID is preserved; the synthetic token is deterministically
     * folded only when the host withholds it.
     */
    public static function processNumericToken(): int {
        $pid = self::processId();
        if ($pid !== null) {
            return $pid;
        }
        return (int)hexdec(substr(hash('sha256', self::processToken()), 0, 7));
    }

    /** Server hostname, or null when unavailable or invalid. */
    public static function hostname(): ?string {
        if (!self::isFunctionAvailable('gethostname')) {
            return null;
        }
        $hostname = gethostname();
        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    /** @return array<string, int>|null Process resource counters, or null when unavailable. */
    public static function resourceUsage(): ?array {
        if (!self::isFunctionAvailable('getrusage')) {
            return null;
        }
        $usage = getrusage();
        return is_array($usage) ? $usage : null;
    }

    /** Effective numeric OS user ID, or null when unavailable. */
    public static function effectiveUserId(): ?int {
        if (!self::isFunctionAvailable('posix_geteuid')) {
            return null;
        }
        return posix_geteuid();
    }

    /** @return float|false */
    public static function diskFreeSpace(string $path) {
        return self::isFunctionAvailable('disk_free_space') ? @disk_free_space($path) : false;
    }

    /** @return float|false */
    public static function diskTotalSpace(string $path) {
        return self::isFunctionAvailable('disk_total_space') ? @disk_total_space($path) : false;
    }

    /** @return array<int, float>|false */
    public static function systemLoadAverage() {
        return self::isFunctionAvailable('sys_getloadavg') ? @sys_getloadavg() : false;
    }

    /**
     * Set one INI directive for the rest of this request.
     *
     * Keyed, not positional. Both parts are strings, and a transposed call is
     * not an error anywhere: ini_set() would be asked to set a directive named
     * "0" and would simply return false, so `display_errors` would stay ON
     * while the caller believed it had turned it off -- which at two of the
     * call sites here means PHP notices printed into an AJAX response body,
     * the exact corruption this plugin's canary ladder exists to diagnose.
     * Shipped code has a PHP 7.4 floor, so a keyed bag is what makes the swap
     * unwriteable.
     *
     * @param array{directive: string, value: string} $setting
     * @return string|false Previous value, or false when unavailable/refused.
     */
    public static function setIni(array $setting) {
        return self::isFunctionAvailable('ini_set')
            ? @ini_set($setting['directive'], $setting['value']) : false;
    }

    /** Write the final PHP-log fallback, or return false when no sink exists. */
    public static function writeErrorLog(string $message): bool {
        return self::isFunctionAvailable('error_log') && @error_log($message);
    }

    /**
     * Which of the given plugin-owned functions are not callable here, sorted.
     *
     * Takes the names rather than reading its own list, so a caller can ask
     * about the union across every capability boundary without this class
     * having to depend on the other two -- the support fingerprint does
     * exactly that, and it must, because a host that removed the pcntl
     * extension is precisely the host whose diagnostics need to say so.
     *
     * Configuration directives are deliberately not consulted or exposed.
     *
     * @param array<int, string> $owned
     * @return array<int, string>
     */
    public static function disabledAmong(array $owned): array {
        $disabled = array();
        foreach ($owned as $name) {
            if (!self::isFunctionAvailable($name)) {
                $disabled[] = $name;
            }
        }
        sort($disabled, SORT_STRING);
        return $disabled;
    }
}
