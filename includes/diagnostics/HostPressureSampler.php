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

        return array(
            'sys_loadavg' => self::systemLoadAverage(),
            'proc_loadavg' => self::procLoadAverage($procRoot . '/loadavg'),
            'proc_self_status' => self::procSelfStatus($procRoot . '/self/status'),
            'cloudlinux_lve_server_vars' => self::serverCounters('/^(?:LVE_|CLOUDLINUX_)/i'),
            'litespeed_server_vars' => self::serverCounters('/^(?:LSAPI_|LITESPEED_|LSWS_)/i'),
        );
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
    private static function serverCounters(string $pattern): array {
        $values = array();
        $matched = false;
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || preg_match($pattern, $key) !== 1) {
                continue;
            }
            $matched = true;
            if (is_scalar($value) && is_numeric($value)) {
                $values[$key] = substr((string)$value, 0, 64);
            }
        }
        ksort($values);
        if ($values !== array()) {
            return array('status' => 'available', 'values' => $values);
        }
        return self::unavailable($matched ? 'no_readable_counters' : 'no_matching_variables');
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
