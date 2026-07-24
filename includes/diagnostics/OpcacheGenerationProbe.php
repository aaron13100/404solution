<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Are the opcodes this request is executing the same generation as the files
 * on disk? (Bruno timeout cause matrix, cause D "stale or mixed code".)
 *
 * Disk hashes prove what the filesystem holds. They cannot prove which opcodes
 * PHP actually ran: an opcode cache with `validate_timestamps` off, or one
 * that recompiled some files and not others across a deploy, will happily run
 * last release's bytecode for a file whose disk contents are current. A
 * differing POSITIVE OPcache timestamp is the direct evidence of that split,
 * and it is the only evidence available from inside the request.
 *
 * One read per request, shared by every caller. `opcache_get_status(true)`
 * walks every cached script on the host, which makes it the most expensive
 * probe in the diagnostic path; calling it once here and passing this object
 * around is why ABJ_404_Solution_RequestEnvironmentFingerprint (two detailed
 * file fingerprints) and ABJ_404_Solution_DiagnosticModuleManifest (the whole
 * diagnostic module set) can both reconcile against it without paying twice.
 *
 * Three-valued throughout: "cached and matching", "cached and stale", and
 * "unknown" are different findings, and an unavailable status API must never
 * collapse into "not cached" -- that would read as a fresh deploy on every
 * request of every host with `opcache.restrict_api` set.
 */
final class ABJ_404_Solution_OpcacheGenerationProbe {

    /** @var array<string, mixed>|null Per-script metadata, or null when unavailable. */
    private $scripts;

    /** @var array<string, mixed> */
    private $summary;

    /**
     * @param array<string, mixed>|null $scripts
     * @param array<string, mixed> $summary
     */
    private function __construct(?array $scripts, array $summary) {
        $this->scripts = $scripts;
        $this->summary = $summary;
    }

    /** Read the opcode cache's state for this request. */
    public static function read(): self {
        $summary = self::unavailableSummary();
        $restrictApi = ini_get('opcache.restrict_api');
        $apiRestricted = function_exists('abj404_opcache_api_is_restricted')
            ? abj404_opcache_api_is_restricted($restrictApi, __FILE__)
            : (is_string($restrictApi) && trim($restrictApi) !== '');
        if ($apiRestricted) {
            $summary['reason'] = 'opcache-api-restricted';
            return new self(null, $summary);
        }
        if (!function_exists('opcache_get_status')) {
            return new self(null, $summary);
        }

        $status = @opcache_get_status(true);
        if (!is_array($status) || (array_key_exists('opcache_enabled', $status) && !$status['opcache_enabled'])) {
            return new self(null, $summary);
        }
        return new self(self::stringKeyed($status['scripts'] ?? null),
            self::summaryFromStatus($summary, $status));
    }

    /**
     * Build a probe over a known per-script map. The named constructor the
     * real read() delegates to, and the seam a test uses to drive a specific
     * mixed-generation scenario without needing a host whose opcode cache is
     * in that state.
     *
     * @param array<string, mixed>|null $scripts
     */
    public static function forScripts(?array $scripts): self {
        $summary = self::unavailableSummary();
        if ($scripts !== null) {
            $summary['reason'] = 'available';
        }
        return new self($scripts === null ? null : self::stringKeyed($scripts), $summary);
    }

    /**
     * Constant-cost OPcache evidence for one boundary module.
     *
     * The full request_start probe walks the host's complete script map.
     * Early boot checkpoints cannot pay that unbounded cost, so they record
     * only whether this one module is cached plus the timestamp-validation
     * policy that controls its freshness. The compiled build marker beside
     * this snapshot provides the exact generation comparison.
     *
    * @return array<string, bool|int|string|null>
     */
    public static function boundarySnapshot(string $path): array {
        $reason = 'opcache-unavailable';
        $restrictApi = ini_get('opcache.restrict_api');
        $apiRestricted = function_exists('abj404_opcache_api_is_restricted')
            ? abj404_opcache_api_is_restricted($restrictApi, __FILE__)
            : (is_string($restrictApi) && trim($restrictApi) !== '');
        if ($apiRestricted) {
            $reason = 'opcache-api-restricted';
        }
        $cached = null;
        if (!$apiRestricted && function_exists('opcache_is_script_cached')) {
            $cached = @opcache_is_script_cached($path);
            $reason = 'available';
        }
        return array(
            'reason' => $reason,
            'cached' => $cached,
            'validate_timestamps' => self::iniBoolean(ini_get('opcache.validate_timestamps')),
            'revalidate_freq' => self::numericInteger(ini_get('opcache.revalidate_freq')),
        );
    }

    /**
     * The per-script map with its keys made string-typed. Array keys are int
     * or string, and a file path that looks numeric ("/8080.php" cannot, but a
     * relative "8080" key from a filtered value can) would otherwise arrive as
     * an int and never match a path lookup.
     *
     * @param mixed $scripts
     * @return array<string, mixed>|null
     */
    private static function stringKeyed($scripts): ?array {
        if (!is_array($scripts)) {
            return null;
        }
        $keyed = array();
        foreach ($scripts as $path => $metadata) {
            $keyed[(string)$path] = $metadata;
        }
        return $keyed;
    }

    /**
     * The per-request summary for the journal.
     *
     * @return array<string, mixed>
     */
    public function summary(): array {
        return $this->summary;
    }

    /** Whether per-script state is available at all. False means every answer is "unknown". */
    public function hasPerScriptData(): bool {
        return $this->scripts !== null;
    }

    /**
     * This file's opcode-cache state.
     *
     * `cached` is null (not false) when there is no per-script data, so an
     * unavailable status API is never reported as an uncached file.
     * `matches_file` is null when the cache reports a zero or absent timestamp
     * (validate_timestamps off, so there is nothing to compare) rather than
     * false, which would fire on every request of every production host.
     *
     * @return array{cached: bool|null, timestamp: int|null, matches_file: bool|null}
     */
    public function stateFor(string $path, ?int $mtime): array {
        if ($this->scripts === null) {
            return array('cached' => null, 'timestamp' => null, 'matches_file' => null);
        }
        $metadata = $path !== '' ? ($this->scripts[$path] ?? null) : null;
        if (!is_array($metadata)) {
            return array('cached' => false, 'timestamp' => null, 'matches_file' => null);
        }
        $timestamp = isset($metadata['timestamp']) && is_numeric($metadata['timestamp'])
            ? (int)$metadata['timestamp'] : null;
        return array(
            'cached' => true,
            'timestamp' => $timestamp,
            'matches_file' => ($timestamp !== null && $timestamp > 0 && $mtime !== null)
                ? ($timestamp === $mtime) : null,
        );
    }

    /**
     * Annotate loaded-file fingerprints with their opcode-cache state, keyed
     * by the `path` and `mtime` each entry already carries.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public function annotate(array $files): array {
        foreach ($files as &$file) {
            $state = $this->stateFor(
                is_string($file['path'] ?? null) ? $file['path'] : '',
                is_numeric($file['mtime'] ?? null) ? (int)$file['mtime'] : null);
            $file['opcache_cached'] = $state['cached'];
            $file['opcache_timestamp'] = $state['timestamp'];
            $file['opcache_timestamp_matches_file'] = $state['matches_file'];
        }
        unset($file);
        return $files;
    }

    /** @return array<string, mixed> */
    private static function unavailableSummary(): array {
        return array(
            'reason' => 'opcache-unavailable',
            'validate_timestamps' => self::iniBoolean(ini_get('opcache.validate_timestamps')),
            'revalidate_freq' => self::numericInteger(ini_get('opcache.revalidate_freq')),
            'restart_pending' => null,
            'restart_in_progress' => null,
            'start_time' => null,
            'last_restart_time' => null,
            'restart_counts' => array('oom' => null, 'hash' => null, 'manual' => null),
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function summaryFromStatus(array $summary, array $status): array {
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : array();
        $summary['reason'] = 'available';
        $summary['restart_pending'] = isset($status['restart_pending']) ? (bool)$status['restart_pending'] : null;
        $summary['restart_in_progress'] = isset($status['restart_in_progress']) ? (bool)$status['restart_in_progress'] : null;
        $summary['start_time'] = self::numericInteger($statistics['start_time'] ?? null);
        $summary['last_restart_time'] = self::numericInteger($statistics['last_restart_time'] ?? null);
        $summary['restart_counts'] = array(
            'oom' => self::numericInteger($statistics['oom_restarts'] ?? null),
            'hash' => self::numericInteger($statistics['hash_restarts'] ?? null),
            'manual' => self::numericInteger($statistics['manual_restarts'] ?? null),
        );
        return $summary;
    }

    /** @param mixed $value */
    private static function iniBoolean($value): ?bool {
        if ($value === false || $value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @param mixed $value */
    private static function numericInteger($value): ?int {
        return is_numeric($value) ? (int)$value : null;
    }
}
