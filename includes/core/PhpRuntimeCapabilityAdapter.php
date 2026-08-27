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

    /** Functions the plugin actually invokes and this adapter owns. */
    private const OWNED_FUNCTIONS = array(
        'disk_free_space',
        'disk_total_space',
        'error_log',
        'gethostname',
        'getmypid',
        'getrusage',
        'ini_set',
        'opcache_get_status',
        'opcache_invalidate',
        'pcntl_alarm',
        'pcntl_async_signals',
        'pcntl_signal',
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

    /** @return array<string, mixed>|false */
    public static function opcacheStatus(bool $includeScripts = true) {
        return self::isFunctionAvailable('opcache_get_status')
            ? @opcache_get_status($includeScripts) : false;
    }

    /** Attempt to invalidate one OPcache entry. */
    public static function invalidateOpcache(string $path, bool $force = false): bool {
        return self::isFunctionAvailable('opcache_invalidate')
            && @opcache_invalidate($path, $force);
    }

    /** @return string|false Previous value, or false when unavailable/refused. */
    public static function setIni(string $name, string $value) {
        return self::isFunctionAvailable('ini_set') ? @ini_set($name, $value) : false;
    }

    /** Write the final PHP-log fallback, or return false when no sink exists. */
    public static function writeErrorLog(string $message): bool {
        return self::isFunctionAvailable('error_log') && @error_log($message);
    }

    /** Whether all functions required for the post-response signal budget exist. */
    public static function supportsSignalBudget(): bool {
        return self::isFunctionAvailable('pcntl_async_signals')
            && self::isFunctionAvailable('pcntl_signal')
            && self::isFunctionAvailable('pcntl_alarm');
    }

    /** Enable asynchronous signal dispatch when supported. */
    public static function enableAsyncSignals(): bool {
        return self::isFunctionAvailable('pcntl_async_signals') && pcntl_async_signals(true);
    }

    /** Install one signal handler when supported. */
    public static function installSignalHandler(int $signal, callable $handler): bool {
        return self::isFunctionAvailable('pcntl_signal') && pcntl_signal($signal, $handler);
    }

    /** Set or clear the process alarm; zero means unavailable or no prior alarm. */
    public static function alarm(int $seconds): int {
        if (!self::isFunctionAvailable('pcntl_alarm')) {
            return 0;
        }
        return pcntl_alarm($seconds);
    }

    /**
     * Sorted intersection of php.ini disable_functions and functions this
     * plugin actually calls. The raw directive is never exposed.
     *
     * @return array<int, string>
     */
    public static function disabledFunctions(): array {
        $configured = ini_get('disable_functions');
        if (!is_string($configured) || trim($configured) === '') {
            return array();
        }
        $disabled = array();
        foreach (explode(',', strtolower($configured)) as $name) {
            $name = trim($name);
            if ($name !== '' && in_array($name, self::ownedFunctions(), true)) {
                $disabled[$name] = true;
            }
        }
        $names = array_keys($disabled);
        sort($names, SORT_STRING);
        return $names;
    }
}
