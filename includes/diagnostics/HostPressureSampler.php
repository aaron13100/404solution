<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Samples host-level pressure visible to an unprivileged PHP worker.
 *
 * Each probe returns either a named available result or a named unavailable
 * reason. Empty fields are forbidden because an absent counter must remain
 * distinguishable from a healthy zero when a request dies under transient
 * LiteSpeed or CloudLinux pressure.
 */
final class ABJ_404_Solution_HostPressureSampler {

    /** @return array<string, mixed> */
    public static function capture(): array {
        $procRoot = '/proc';
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('abj404_host_pressure_proc_root', $procRoot);
                $procRoot = is_string($filtered) && $filtered !== '' ? $filtered : $procRoot;
            } catch (Throwable $e) {
                self::reportFailure('proc-root filter failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
            }
        }
        $procRoot = rtrim($procRoot, '/\\');
        $environment = self::processEnvironment();

        return array(
            'sys_loadavg' => self::systemLoadAverage(),
            'proc_loadavg' => self::procLoadAverage($procRoot . '/loadavg'),
            'proc_self_status' => self::procSelfStatus($procRoot . '/self/status'),
            'runtime_identity' => self::runtimeIdentity(),
            'proc_self_limits' => self::procSelfLimits($procRoot . '/self/limits'),
            'proc_self_cgroup' => self::procSelfCgroup($procRoot . '/self/cgroup'),
            'same_uid_processes' => self::sameUidProcesses($procRoot),
            'cloudlinux_lve_server_vars' => self::serverCounters(
                '/^(?:LVE_|CLOUDLINUX_)/i',
                $environment
            ),
            'litespeed_server_vars' => self::serverCounters(
                '/^(?:LSAPI_|LITESPEED_|LSWS_)/i',
                $environment
            ),
            'filesystem_quota_probes' => ABJ_404_Solution_HostFilesystemPressureProbe::capture(),
        );
    }

    /**
     * Remove a request-cached quota snapshot already present earlier in the
     * same support section. Other host-pressure counters remain per-boundary.
     *
     * @param array<string, mixed> $record
     * @param array<string, string> $snapshotByRequest
     * @return array<string, mixed>
     */
    public static function compactRepeatedFilesystemQuotaSnapshot(
        array $record,
        array &$snapshotByRequest
    ): array {
        $requestId = is_string($record['request_id'] ?? null) ? $record['request_id'] : '';
        $hostPressure = $record['host_pressure'] ?? null;
        if ($requestId === '' || !is_array($hostPressure)) {
            return $record;
        }
        $quotaProbes = $hostPressure['filesystem_quota_probes'] ?? null;
        if (!is_array($quotaProbes)) {
            return $record;
        }
        $encoded = json_encode($quotaProbes, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return $record;
        }
        $fingerprint = hash('sha256', $encoded);
        if (($snapshotByRequest[$requestId] ?? '') !== $fingerprint) {
            $snapshotByRequest[$requestId] = $fingerprint;
            return $record;
        }

        unset($hostPressure['filesystem_quota_probes']);
        $record['host_pressure'] = $hostPressure;
        return $record;
    }

    /** @return array<string, mixed> */
    private static function systemLoadAverage(): array {
        if (!function_exists('sys_getloadavg')) {
            return self::unavailable('function_unavailable');
        }
        $load = @sys_getloadavg();
        if (!is_array($load) || count($load) < 3) {
            return self::unavailable('invalid_result');
        }
        return array(
            'status' => 'available',
            'one_min' => (float)$load[0],
            'five_min' => (float)$load[1],
            'fifteen_min' => (float)$load[2],
        );
    }

    /** @return array<string, mixed> */
    private static function procLoadAverage(string $path): array {
        $raw = self::readProbeFile($path);
        if ($raw === null) {
            return self::unavailable('not_readable');
        }
        $parts = preg_split('/\s+/', trim($raw));
        $processes = is_array($parts) && isset($parts[3]) ? explode('/', (string)$parts[3], 2) : array();
        if (!is_array($parts) || count($parts) < 5 || !is_numeric($parts[0])
                || !is_numeric($parts[1]) || !is_numeric($parts[2])
                || count($processes) !== 2 || !is_numeric($processes[0])
                || !is_numeric($processes[1]) || !is_numeric($parts[4])) {
            return self::unavailable('invalid_format');
        }
        return array(
            'status' => 'available',
            'one_min' => (float)$parts[0],
            'five_min' => (float)$parts[1],
            'fifteen_min' => (float)$parts[2],
            'running_processes' => (int)$processes[0],
            'total_processes' => (int)$processes[1],
            'last_pid' => (int)$parts[4],
        );
    }

    /** @return array<string, mixed> */
    private static function procSelfStatus(string $path): array {
        $raw = self::readProbeFile($path);
        if ($raw === null) {
            return self::unavailable('not_readable');
        }
        $wanted = array_flip(array(
            'State', 'Threads', 'VmPeak', 'VmSize', 'VmRSS', 'VmSwap',
            'voluntary_ctxt_switches', 'nonvoluntary_ctxt_switches',
        ));
        $values = array();
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            $pair = explode(':', (string)$line, 2);
            $name = $pair[0] ?? '';
            if (isset($wanted[$name]) && isset($pair[1])) {
                $values[$name] = substr(trim($pair[1]), 0, 64);
            }
        }
        if ($values === array()) {
            return self::unavailable('no_supported_fields');
        }
        return array('status' => 'available', 'values' => $values);
    }

    /** @return array<string, mixed> */
    private static function runtimeIdentity(): array {
        $sapi = php_sapi_name();
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $serverSoftwareProbe = self::unavailable('not_present');
        if (array_key_exists('SERVER_SOFTWARE', $_SERVER)) {
            $readable = self::readableScalar($serverSoftware, 128);
            $serverSoftwareProbe = $readable === null
                ? self::unavailable('not_readable')
                : array('status' => 'available', 'value' => self::serverSoftwareClass($readable));
        }
        return array(
            'php_sapi_name' => array('status' => 'available', 'value' => $sapi),
            'server_software' => $serverSoftwareProbe,
        );
    }

    private static function serverSoftwareClass(string $raw): string {
        $hostMarker = stripos($raw, ' Server at ');
        $withoutHost = $hostMarker === false ? $raw : substr($raw, 0, $hostMarker);
        return substr(trim($withoutHost), 0, 100);
    }

    /** @return array<string, mixed> */
    private static function processEnvironment(): array {
        $environment = getenv();
        $probe = is_array($environment)
            ? array('status' => 'available', 'values' => $environment)
            : self::unavailable('getenv_unavailable');
        if (!function_exists('apply_filters')) {
            return $probe;
        }
        try {
            $filtered = apply_filters(
                'abj404_host_pressure_environment',
                $probe['values'] ?? array()
            );
        } catch (Throwable $e) {
            self::reportFailure('environment filter failed: ' . get_class($e) . ' code=' .
                $e->getCode() . ' message=' . $e->getMessage());
            return self::unavailable('environment_filter_failed');
        }
        return is_array($filtered)
            ? array('status' => 'available', 'values' => $filtered)
            : self::unavailable('environment_filter_invalid');
    }

    /** @return array<string, mixed> */
    private static function procSelfLimits(string $path): array {
        $raw = self::readProbeFile($path);
        if ($raw === null) {
            return self::unavailable('not_readable');
        }
        $names = array(
            'Max processes' => 'RLIMIT_NPROC',
            'Max address space' => 'RLIMIT_AS',
            'Max open files' => 'RLIMIT_NOFILE',
        );
        $limits = array_fill_keys(array_values($names), self::unavailable('not_reported'));
        $recognized = false;
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            foreach ($names as $label => $constant) {
                if (strpos((string)$line, $label) !== 0) {
                    continue;
                }
                $recognized = true;
                $parts = preg_split('/\s+/', trim(substr((string)$line, strlen($label))));
                if (!is_array($parts) || count($parts) !== 3
                        || preg_match('/^(?:unlimited|\d+)$/', $parts[0]) !== 1
                        || preg_match('/^(?:unlimited|\d+)$/', $parts[1]) !== 1
                        || preg_match('/^[a-z]+$/i', $parts[2]) !== 1) {
                    $limits[$constant] = self::unavailable('invalid_format');
                    continue;
                }
                $limits[$constant] = array(
                    'status' => 'available',
                    'soft_limit' => $parts[0],
                    'hard_limit' => $parts[1],
                    'unit' => $parts[2],
                );
            }
        }
        if (!$recognized) {
            return self::unavailable(trim($raw) === '' ? 'empty_file' : 'no_supported_limits');
        }
        return array('status' => 'available', 'limits' => $limits);
    }

    /** @return array<string, mixed> */
    private static function procSelfCgroup(string $path): array {
        $raw = self::readProbeFile($path);
        if ($raw === null) {
            return self::unavailable('not_readable');
        }
        $memberships = array();
        $invalidLines = 0;
        foreach (preg_split('/\R/', trim($raw)) ?: array() as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(':', (string)$line, 3);
            $controllers = count($parts) === 3 && $parts[1] !== '' ? explode(',', $parts[1]) : array();
            $controllersValid = array_filter($controllers, static function ($controller): bool {
                return preg_match('/^[a-z0-9_.=-]+$/i', (string)$controller) !== 1;
            }) === array();
            if (count($parts) !== 3 || preg_match('/^\d+$/', $parts[0]) !== 1
                    || !$controllersValid || strpos($parts[2], '/') !== 0) {
                $invalidLines++;
                continue;
            }
            $memberships[] = array(
                'hierarchy_id' => $parts[0],
                'controllers' => $controllers,
                'path' => self::readableScalar($parts[2], 256),
            );
        }
        if ($memberships === array()) {
            return self::unavailable(trim($raw) === '' ? 'empty_file' : 'invalid_format');
        }
        return array(
            'status' => 'available',
            'memberships' => $memberships,
            'invalid_lines' => $invalidLines,
        );
    }

    /** @return array<string, mixed> */
    private static function sameUidProcesses(string $procRoot): array {
        if (!is_dir($procRoot) || !is_readable($procRoot)) {
            return self::unavailable('proc_root_not_readable');
        }
        $selfStatus = self::readProbeFile($procRoot . '/self/status');
        if ($selfStatus === null) {
            return self::unavailable('self_status_not_readable');
        }
        $effectiveUid = self::effectiveUidFromStatus($selfStatus);
        if ($effectiveUid === null) {
            return self::unavailable('self_effective_uid_unavailable');
        }
        $selfPid = self::numericStatusField($selfStatus, 'Pid');
        if ($selfPid === null) {
            return self::unavailable('self_pid_unavailable');
        }
        $entries = @scandir($procRoot);
        if (!is_array($entries)) {
            return self::unavailable('proc_root_scan_failed');
        }
        $siblings = 0;
        $readable = 0;
        $unreadable = 0;
        foreach ($entries as $entry) {
            if (preg_match('/^\d+$/', (string)$entry) !== 1
                    || !is_dir($procRoot . '/' . $entry)) {
                continue;
            }
            $status = self::readProbeFile($procRoot . '/' . $entry . '/status');
            $uid = $status === null ? null : self::effectiveUidFromStatus($status);
            if ($uid === null) {
                $unreadable++;
                continue;
            }
            $readable++;
            if ($uid === $effectiveUid && $entry !== $selfPid) {
                $siblings++;
            }
        }
        if ($readable === 0) {
            return self::unavailable('no_readable_process_statuses');
        }
        return array(
            'status' => 'available',
            'effective_uid' => $effectiveUid,
            'sibling_count' => $siblings,
            'readable_processes' => $readable,
            'unreadable_processes' => $unreadable,
        );
    }

    private static function effectiveUidFromStatus(string $raw): ?string {
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            if (strpos((string)$line, 'Uid:') !== 0) {
                continue;
            }
            $uids = preg_split('/\s+/', trim(substr((string)$line, 4)));
            return is_array($uids) && isset($uids[1]) && preg_match('/^\d+$/', $uids[1]) === 1
                ? $uids[1]
                : null;
        }
        return null;
    }

    private static function numericStatusField(string $raw, string $field): ?string {
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            if (strpos((string)$line, $field . ':') !== 0) {
                continue;
            }
            $value = trim(substr((string)$line, strlen($field) + 1));
            return preg_match('/^\d+$/', $value) === 1 ? $value : null;
        }
        return null;
    }

    /** @param mixed $value */
    private static function readableScalar($value, int $maxLength): ?string {
        if (!is_scalar($value)) {
            return null;
        }
        $string = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $string);
        if (!is_string($ascii)) {
            return null;
        }
        $truncated = substr($ascii, 0, $maxLength);
        return is_string($truncated) ? $truncated : null;
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private static function serverCounters(string $pattern, array $environment): array {
        $values = array();
        $matched = false;
        $sources = array($_SERVER);
        if (($environment['status'] ?? '') === 'available' && is_array($environment['values'] ?? null)) {
            $sources[] = $environment['values'];
        }
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (!is_string($key) || preg_match($pattern, $key) !== 1) {
                    continue;
                }
                $matched = true;
                $readable = self::readableScalar($value, 64);
                if ($readable !== null) {
                    $values[$key] = $readable;
                }
            }
        }
        ksort($values);
        if ($values !== array()) {
            return array('status' => 'available', 'values' => $values);
        }
        if ($matched) {
            return self::unavailable('no_readable_counters');
        }
        if (($environment['status'] ?? '') !== 'available') {
            $reason = $environment['reason'] ?? null;
            return self::unavailable(is_string($reason) && $reason !== ''
                ? $reason
                : 'environment_unavailable');
        }
        return self::unavailable('no_matching_variables');
    }

    private static function readProbeFile(string $path): ?string {
        if (!@is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        return is_string($raw) ? $raw : null;
    }

    /** @return array{status: string, reason: string} */
    private static function unavailable(string $reason): array {
        return array('status' => 'unavailable', 'reason' => $reason);
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('host-pressure', $message);
        }
    }
}
