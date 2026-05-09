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

    /** Recommended floor for memory_limit in bytes (128M). */
    private const PHP_MEMORY_LIMIT_RECOMMENDED_BYTES = 134217728;

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
     * without mutating the running PHP process.
     *
     * @return array{set_time_limit_available: bool, memory_limit_raw: string, memory_limit_bytes: int, memory_limit_low: bool}
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
                && $memoryBytes < self::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES),
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
            $this->setLowMemoryLimitAdminNotice((int)$result['memory_limit_bytes']);
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
        return (int)($probe['memory_limit_bytes'] ?? 0);
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
            'recommended'  => self::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES,
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
        if (function_exists('delete_option')) {
            delete_option($this->phpEnvironmentProbeOptionName());
        }
    }
}
