<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP-runtime environment probe for the staged view-build pipeline.
 *
 * Detects two host-side constraints that silently destabilize the build on
 * hardened shared hosts (php.ini disable_functions, low memory_limit):
 *
 *   1. `set_time_limit()` in `disable_functions`. The build cannot extend
 *      its time budget mid-stage when the host has revoked it, so the
 *      orchestrator's per-stage budget switches to a tighter cron-tick
 *      mode (yield earlier, rely on the next tick) instead of gambling
 *      on max_execution_time.
 *
 *   2. `memory_limit` below the 128M recommended floor. The S9 hits
 *      aggregate (CREATE TEMPORARY + INSERT ... GROUP BY across logsv2)
 *      can OOM on busy sites with a small PHP-side fetch buffer. Since
 *      `ini_set('memory_limit', ...)` is often blocked, the probe
 *      surfaces a deduplicated admin notice instead of silently failing.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait. Extracted
 * from that trait when it crossed the 1500-line limit; the public probe
 * method is the entry point called from runStagedBuildOnce().
 */
trait ABJ_404_Solution_DataAccess_ViewBuildPhpEnvProbeTrait {

    /**
     * Cached PHP-environment probe result for the current request:
     * function_exists('set_time_limit') AND not in disable_functions, plus
     * memory_limit parsed to bytes. Populated on first call to
     * probePhpEnvironmentForBuild() and consumed by
     * viewBuildPerStageBudgetSeconds() to switch into a tighter cron-tick
     * budget when set_time_limit cannot extend the request mid-stage.
     *
     * @var array<string,mixed>|null
     */
    private $phpEnvironmentProbeCache = null;

    /**
     * Cached filesystem probe result for the current request.
     *
     * @var array<string,mixed>|null
     */
    private $filesystemEnvironmentProbeCache = null;

    /** @return string  Option name for the persisted PHP environment probe. */
    private function phpEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_php_env_probe';
    }

    /**
     * Probe the PHP runtime for environmental constraints that affect the
     * staged view build:
     *
     *   - `set_time_limit()` in `disable_functions`: the build cannot extend
     *     its time budget mid-stage on hardened shared hosts. The orchestrator
     *     consumes this flag in viewBuildPerStageBudgetSeconds() to yield
     *     earlier and rely on the next cron tick.
     *
     *   - `memory_limit` below the 128M recommended floor: the S9 hits
     *     aggregate (CREATE TEMPORARY + INSERT ... GROUP BY across logsv2)
     *     can OOM on busy sites. We cannot bump memory_limit at runtime on
     *     hardened hosts, so surface a deduplicated admin notice instead.
     *
     * Side effects: persists the probe result to an option for post-mortem
     * dashboards, and surfaces a low-memory admin notice (one per 24h via
     * transient dedup) when the floor check fails. Idempotent within a
     * request -- repeat calls return the cached array without re-probing.
     *
     * Filterable via `apply_filters('abj404_php_env_probe', $defaults)` so
     * tests and operators can simulate disable_functions / low memory_limit
     * without mutating the running PHP process. Filter callers may add or
     * widen keys, so the return type is the loose `array<string,mixed>`.
     * Internally guaranteed keys: set_time_limit_available (bool),
     * memory_limit_raw (string), memory_limit_bytes (int), memory_limit_low
     * (bool).
     *
     * @return array<string,mixed>
     */
    public function probePhpEnvironmentForBuild(): array {
        if (is_array($this->phpEnvironmentProbeCache)) {
            return $this->phpEnvironmentProbeCache;
        }

        $rawMemory = (string)ini_get('memory_limit');
        $memoryBytes = $this->parsePhpMemoryLimitToBytes($rawMemory);

        $disabled = $this->phpDisabledFunctionsList();
        $setTimeLimitAvailable = function_exists('set_time_limit')
            && !in_array('set_time_limit', $disabled, true);

        $result = array(
            'set_time_limit_available' => $setTimeLimitAvailable,
            'memory_limit_raw'         => $rawMemory,
            'memory_limit_bytes'       => $memoryBytes,
            // memory_limit_bytes == 0 means unlimited (-1 in php.ini), which
            // is fine and is NOT "low".
            'memory_limit_low'         => ($memoryBytes > 0
                && $memoryBytes < ABJ_404_Solution_ViewBuildConfig::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_php_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        if (empty($result['set_time_limit_available'])) {
            $this->logger->infoMessage(
                '[staged] set_time_limit() unavailable (disable_functions); '
                . 'switching to tighter cron-tick budget mode.'
            );
        }
        if (!empty($result['memory_limit_low'])) {
            $resultMemoryBytes = isset($result['memory_limit_bytes']) && is_numeric($result['memory_limit_bytes'])
                ? (int)$result['memory_limit_bytes'] : 0;
            $this->setLowMemoryLimitAdminNotice($resultMemoryBytes);
        }

        if (function_exists('update_option')) {
            update_option($this->phpEnvironmentProbeOptionName(), $result, false);
        }

        $this->phpEnvironmentProbeCache = $result;
        return $result;
    }

    /**
     * Contract alias: returns just the boolean used by the env-failure tests.
     * Keeps the public surface compact for callers that only need the flag.
     *
     * @return bool
     */
    public function probeSetTimeLimitAvailability(): bool {
        $probe = $this->probePhpEnvironmentForBuild();
        return !empty($probe['set_time_limit_available']);
    }

    /**
     * Contract alias: returns memory_limit in bytes (0 == unlimited) so
     * callers can choose chunking vs. skip without re-parsing the ini value.
     *
     * @return int
     */
    public function probeMemoryLimitForS9(): int {
        $probe = $this->probePhpEnvironmentForBuild();
        $bytes = $probe['memory_limit_bytes'] ?? 0;
        return is_numeric($bytes) ? (int)$bytes : 0;
    }

    /**
     * Parse a php.ini-style memory size (`128M`, `1G`, `262144`, `-1`) into
     * raw bytes. Returns 0 for "unlimited" (-1) or unparseable input.
     *
     * @param string $raw
     * @return int
     */
    private function parsePhpMemoryLimitToBytes(string $raw): int {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }
        $unit = strtoupper(substr($raw, -1));
        $num = (int)$raw;
        if ($num <= 0) {
            return 0;
        }
        switch ($unit) {
            case 'G': return $num * 1073741824;
            case 'M': return $num * 1048576;
            case 'K': return $num * 1024;
            default:
                return is_numeric($raw) ? (int)$raw : 0;
        }
    }

    /**
     * @return array<int,string>  Trimmed list of names from ini disable_functions.
     */
    private function phpDisabledFunctionsList(): array {
        $raw = (string)ini_get('disable_functions');
        if ($raw === '') {
            return array();
        }
        $names = array_map('trim', explode(',', $raw));
        return array_values(array_filter($names, function ($n) { return $n !== ''; }));
    }

    /**
     * Surface a deduplicated admin notice when the host's memory_limit is
     * below the recommended 128M floor. One per 24h per failure type, per
     * the self-healing reliability rules in CLAUDE.md (notices on the
     * plugin's own admin screen, never email, never wp-admin-wide banner).
     *
     * @param int $memoryBytes
     * @return void
     */
    private function setLowMemoryLimitAdminNotice(int $memoryBytes): void {
        $key = 'abj404_view_build_low_memory_limit_notice';
        $payload = array(
            'kind'         => 'low_memory_limit',
            'bytes'        => $memoryBytes,
            'recommended'  => ABJ_404_Solution_ViewBuildConfig::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES,
            'message'      => sprintf(
                'Your PHP memory_limit (%s) is below the recommended 128M; '
                . 'the redirect view rebuild may fail on large sites.',
                $this->formatPhpMemoryBytesHuman($memoryBytes)
            ),
            'when'         => $this->clock()->now(),
        );
        if (function_exists('set_transient')) {
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /**
     * Format a byte count as a php.ini-style suffix string for admin notices.
     *
     * @param int $bytes
     * @return string
     */
    private function formatPhpMemoryBytesHuman(int $bytes): string {
        if ($bytes <= 0) {
            return 'unlimited';
        }
        if ($bytes >= 1073741824) {
            $g = $bytes / 1073741824;
            return ($g == (int)$g ? (string)(int)$g : number_format($g, 1)) . 'G';
        }
        if ($bytes >= 1048576) {
            return (string)(int)round($bytes / 1048576) . 'M';
        }
        if ($bytes >= 1024) {
            return (string)(int)round($bytes / 1024) . 'K';
        }
        return (string)$bytes;
    }

    /** @return void */
    private function clearPhpEnvironmentProbeCache(): void {
        $this->phpEnvironmentProbeCache = null;
        $this->filesystemEnvironmentProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->phpEnvironmentProbeOptionName());
            delete_option($this->filesystemEnvironmentProbeOptionName());
        }
    }

    /** @return string */
    private function filesystemEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_fs_env_probe';
    }

    /**
     * Probe filesystem-side host constraints that can silently degrade or
     * abort the staged view-build pipeline:
     *
     *   - `open_basedir` set and our tmp/upload paths fall outside it: any
     *     `disk_free_space()` / fopen() against those paths returns false
     *     and the build cannot diagnose why.
     *   - `upload_tmp_dir` outside open_basedir: same constraint.
     *   - `@@tmpdir` (MySQL temp dir) on a near-full volume: S9 hits aggregate
     *     can fail with "table is full" or "No space left on device" when the
     *     temp file the optimizer materializes for the GROUP BY exceeds free
     *     bytes.
     *
     * Read-and-warn-only: never throws, never blocks the build. Logs at
     * warning level (per defensive philosophy §8 -- infrastructure issues the
     * plugin can degrade past) and surfaces a deduplicated admin notice so
     * the operator can ask the host to widen open_basedir or clear disk space
     * before the next build attempt.
     *
     * Filterable via `apply_filters('abj404_filesystem_env_probe', $defaults)`
     * so tests and operators can simulate hardened-host scenarios without
     * mutating the running PHP / MySQL process.
     *
     * @return array<string,mixed>
     */
    public function probeFilesystemEnvironmentForBuild(): array {
        if (is_array($this->filesystemEnvironmentProbeCache)) {
            return $this->filesystemEnvironmentProbeCache;
        }

        $rawOpenBasedir = (string)ini_get('open_basedir');
        $rawUploadTmpDir = (string)ini_get('upload_tmp_dir');
        $sysTmpDir = function_exists('sys_get_temp_dir') ? (string)sys_get_temp_dir() : '';

        $pluginTmpCandidates = array_filter(array(
            $sysTmpDir,
            $rawUploadTmpDir,
        ), function ($p) { return $p !== ''; });

        $openBasedirPaths = $this->splitOpenBasedirPaths($rawOpenBasedir);

        $tmpOutsideOpenBasedir = false;
        $uploadTmpOutsideOpenBasedir = false;
        if (!empty($openBasedirPaths)) {
            foreach ($pluginTmpCandidates as $candidate) {
                if (!$this->pathFallsWithinAny($candidate, $openBasedirPaths)) {
                    $tmpOutsideOpenBasedir = true;
                    break;
                }
            }
            if ($rawUploadTmpDir !== ''
                && !$this->pathFallsWithinAny($rawUploadTmpDir, $openBasedirPaths)) {
                $uploadTmpOutsideOpenBasedir = true;
            }
        }

        $tmpDirForCheck = $rawUploadTmpDir !== '' ? $rawUploadTmpDir : $sysTmpDir;
        $tmpFreeBytes = -1;
        if ($tmpDirForCheck !== ''
            && function_exists('disk_free_space')
            && (empty($openBasedirPaths) || $this->pathFallsWithinAny($tmpDirForCheck, $openBasedirPaths))) {
            $prev = function_exists('error_reporting') ? error_reporting(0) : 0;
            try {
                $bytes = @disk_free_space($tmpDirForCheck);
                $tmpFreeBytes = ($bytes === false) ? -1 : (int)$bytes;
            } catch (\Throwable $e) { // allow-silent-catch: best-effort probe; reset error_reporting in finally
                $tmpFreeBytes = -1;
            }
            if (function_exists('error_reporting')) {
                error_reporting($prev);
            }
        }
        $tmpDiskLow = ($tmpFreeBytes >= 0 && $tmpFreeBytes < ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES);

        $result = array(
            'open_basedir_raw'                 => $rawOpenBasedir,
            'open_basedir_paths'               => $openBasedirPaths,
            'upload_tmp_dir_raw'               => $rawUploadTmpDir,
            'sys_tmp_dir'                      => $sysTmpDir,
            'tmp_outside_open_basedir'         => $tmpOutsideOpenBasedir,
            'upload_tmp_outside_open_basedir'  => $uploadTmpOutsideOpenBasedir,
            'tmp_free_bytes'                   => $tmpFreeBytes,
            'tmp_disk_low'                     => $tmpDiskLow,
            'tmp_disk_floor_bytes'             => ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES,
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_filesystem_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        $warnings = array();
        $resultOpenBasedirRaw = isset($result['open_basedir_raw']) && is_scalar($result['open_basedir_raw'])
            ? (string)$result['open_basedir_raw'] : '';
        $resultSysTmpDir = isset($result['sys_tmp_dir']) && is_scalar($result['sys_tmp_dir'])
            ? (string)$result['sys_tmp_dir'] : '';
        $resultUploadTmpDirRaw = isset($result['upload_tmp_dir_raw']) && is_scalar($result['upload_tmp_dir_raw'])
            ? (string)$result['upload_tmp_dir_raw'] : '';
        $resultTmpFreeBytes = isset($result['tmp_free_bytes']) && is_numeric($result['tmp_free_bytes'])
            ? (int)$result['tmp_free_bytes'] : -1;
        if (!empty($result['tmp_outside_open_basedir'])) {
            $warnings[] = sprintf(
                'open_basedir (%s) does not include the system temp directory (%s); '
                . 'PHP-side temp file work may fail.',
                $resultOpenBasedirRaw, $resultSysTmpDir
            );
        }
        if (!empty($result['upload_tmp_outside_open_basedir'])) {
            $warnings[] = sprintf(
                'upload_tmp_dir (%s) is outside open_basedir (%s); ini upload paths cannot be probed.',
                $resultUploadTmpDirRaw, $resultOpenBasedirRaw
            );
        }
        if (!empty($result['tmp_disk_low'])) {
            $warnings[] = sprintf(
                'temp directory (%s) has %d bytes free (< %d MB floor); the S9 hits '
                . 'aggregate or any MySQL temp materialization may fail with "No space left on device".',
                $tmpDirForCheck,
                $resultTmpFreeBytes,
                (int)(ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES / 1048576)
            );
        }
        $result['warnings'] = $warnings;

        foreach ($warnings as $w) {
            $this->logger->warn('[staged] ' . $w);
        }
        if (!empty($warnings)) {
            $this->setFilesystemEnvAdminNotice($result);
        }

        if (function_exists('update_option')) {
            update_option($this->filesystemEnvironmentProbeOptionName(), $result, false);
        }

        $this->filesystemEnvironmentProbeCache = $result;
        return $result;
    }

    /**
     * Split a raw open_basedir value (`PATH_SEPARATOR`-delimited) into a
     * trimmed list of absolute path prefixes. Empty input returns array().
     *
     * @param string $raw
     * @return array<int,string>
     */
    private function splitOpenBasedirPaths(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') { return array(); }
        $sep = defined('PATH_SEPARATOR') ? PATH_SEPARATOR : ':';
        $parts = array_map('trim', explode($sep, $raw));
        return array_values(array_filter($parts, function ($p) { return $p !== ''; }));
    }

    /**
     * True when $candidate falls within at least one of $allowed (string
     * prefix match after normalizing trailing separators). Normalizes both
     * sides via realpath() when available so symlinks resolve consistently.
     *
     * @param string             $candidate
     * @param array<int,string>  $allowed
     * @return bool
     */
    private function pathFallsWithinAny(string $candidate, array $allowed): bool {
        if ($candidate === '' || empty($allowed)) { return true; }
        $normCandidate = $this->normalizePathPrefix($candidate);
        foreach ($allowed as $a) {
            $normA = $this->normalizePathPrefix($a);
            if ($normA === '') { continue; }
            if (strncmp($normCandidate, $normA, strlen($normA)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a path for prefix comparison: realpath() if it exists, else
     * trim trailing separators. Returns '' on bad input.
     *
     * @param string $path
     * @return string
     */
    private function normalizePathPrefix(string $path): string {
        $path = trim($path);
        if ($path === '') { return ''; }
        if (function_exists('realpath')) {
            $real = @realpath($path);
            if (is_string($real)) {
                return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Surface a deduplicated admin notice describing filesystem-side host
     * issues. One per 24h via transient (per CLAUDE.md self-healing rules).
     *
     * @param array<string,mixed> $probe
     * @return void
     */
    private function setFilesystemEnvAdminNotice(array $probe): void {
        $key = 'abj404_view_build_filesystem_env_notice';
        $rawWarnings = isset($probe['warnings']) && is_array($probe['warnings']) ? $probe['warnings'] : array();
        $stringWarnings = array();
        foreach ($rawWarnings as $w) {
            if (is_string($w)) { $stringWarnings[] = $w; }
        }
        $payload = array(
            'kind'     => 'filesystem_env',
            'warnings' => $stringWarnings,
            'message'  => 'The 404 Solution view-build pipeline detected filesystem '
                . 'host constraints that may degrade the next rebuild: '
                . implode(' | ', $stringWarnings),
            'when'     => $this->clock()->now(),
        );
        if (function_exists('set_transient')) {
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }
}
