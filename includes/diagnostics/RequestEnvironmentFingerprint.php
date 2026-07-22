<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What process, what code, and what runtime state is serving this request.
 *
 * When a request is slow or vanishes, the first question is not "which query
 * was slow" but "is this the expected code on a healthy process". This class
 * captures prior process lifetime, SAPI/host/PID, disk and opcode-cache file
 * fingerprints, size-only request/admin-user state, output buffers, session
 * state, one timed object-cache read, and raw resource counters.
 *
 * Every probe degrades independently: a platform without getrusage() loses
 * that one field, never the whole capture.
 */
final class ABJ_404_Solution_RequestEnvironmentFingerprint {

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    public function __construct(ABJ_404_Solution_Clock $clock) {
        $this->clock = $clock;
    }

    /**
     * Capture the full environment field set.
     *
     * @param string|null $handlerClass Class whose loaded file to fingerprint alongside our own.
     * @param string $cacheProbeKey Request-unique key, so the timed cache read cannot be served from a prior request's entry.
     * @return array<string, mixed>
     */
    public function capture(?string $handlerClass, string $cacheProbeKey): array {
        $loadedFiles = $this->loadedFileFingerprints($handlerClass);
        $opcacheCapture = $this->opcacheCapture($loadedFiles);
        $loadedFiles = $opcacheCapture['loaded_files'];
        $cacheProbe = $this->timedCacheProbe($cacheProbeKey);
        $obInventory = function_exists('ob_get_status') ? ob_get_status(true) : array();
        $rusage = function_exists('getrusage') ? getrusage() : null;

        return array_merge(self::bootDelta($this->clock->nowFloat()), array(
            'sapi' => PHP_SAPI,
            // gethostname() is a core PHP function (always available, no WP
            // dependency), so this is a plain false-check, not a
            // function_exists() guard -- avoids the R6 Brain\Monkey
            // cross-worker stub-leak hazard those guards create in tests.
            'hostname' => gethostname() !== false
                ? (string)gethostname()
                : (is_scalar($_SERVER['SERVER_NAME'] ?? null) ? (string)$_SERVER['SERVER_NAME'] : ''),
            'pid' => getmypid(),
            'plugin_build_hash' => $this->computeBuildHash($loadedFiles),
            'loaded_files' => $loadedFiles,
            'opcache' => $opcacheCapture['summary'],
            'request_shape' => $this->requestShape(),
            'admin_user_state' => $this->adminUserState(),
            'ob_inventory' => $obInventory,
            'session_status' => function_exists('session_status') ? session_status() : null,
            'cache_probe_ms' => $cacheProbe['elapsed_ms'],
            'cache_probe_result' => $cacheProbe['result'],
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'wall_clock' => $this->clock->nowFloat(),
            'rusage' => is_array($rusage) ? $rusage : null,
            'host_pressure' => ABJ_404_Solution_HostPressureSampler::capture(),
            'cron_doing_transient' => $this->cronDoingTransient(),
            'cron_disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'cron_alternate_wp_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'cron_due_event_count' => $this->cronDueEventCount(),
        ));
    }

    /**
     * Delta in milliseconds from REQUEST_TIME_FLOAT to `$nowFloat`, and the
     * raw REQUEST_TIME_FLOAT itself. Static and dependency-free (no Clock
     * instance) so boot-phase checkpoints -- recorded before the service
     * container exists, let alone this class's constructor dependency --
     * share the exact same formula as capture()'s own boot_delta_ms instead
     * of a second copy that could silently drift from it (see
     * ABJ_404_Solution_AjaxCheckpointLogger::recordBootWaypoint()).
     *
     * @return array{request_time_float: float|null, boot_delta_ms: int|null}
     */
    public static function bootDelta(float $nowFloat): array {
        $requestTimeFloatRaw = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $requestTimeFloat = is_numeric($requestTimeFloatRaw) ? (float)$requestTimeFloatRaw : null;
        return array(
            'request_time_float' => $requestTimeFloat,
            'boot_delta_ms' => $requestTimeFloat !== null
                ? max(0, (int)round(($nowFloat - $requestTimeFloat) * 1000))
                : null,
        );
    }

    /**
     * The `doing_cron` transient's raw value (a float timestamp) when a cron
     * run is in progress or was recently spawned, or null otherwise. Bruno
     * timeout matrix cause D: WP hooks wp_cron() on `init` for admin-ajax
     * requests too, and a loopback spawn is a known failure class for this
     * project on a Cloudflare + LiteSpeed stack (see the "Sort-prep tooltip
     * 0%: wp-cron loopback 403" incident). This makes "this request paid for
     * a cron spawn" a readable fact instead of an inference.
     *
     * @return float|null
     */
    private function cronDoingTransient(): ?float {
        if (!function_exists('get_transient')) {
            return null;
        }
        $value = get_transient('doing_cron');
        return $value !== false && is_numeric($value) ? (float)$value : null;
    }

    /**
     * Count of scheduled cron events whose timestamp is already due, or null
     * when wp_get_ready_cron_jobs() is unavailable (added in WP 5.1; this
     * plugin's minimum is WP 5.0). Every probe here degrades independently:
     * an exception or a malformed filtered return from a third-party plugin
     * (see the `pre_get_ready_cron_jobs` filter) must not break the capture.
     */
    private function cronDueEventCount(): ?int {
        if (!function_exists('wp_get_ready_cron_jobs')) {
            return null;
        }
        try {
            $due = wp_get_ready_cron_jobs();
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($due)) {
            return null;
        }
        $count = 0;
        foreach ($due as $cronHooks) {
            if (!is_array($cronHooks)) {
                continue;
            }
            foreach ($cronHooks as $instances) {
                $count += is_array($instances) ? count($instances) : 1;
            }
        }
        return $count;
    }

    /**
     * Fingerprint this class's own loaded file plus the request's handler
     * (path, content hash, mtime, inode, size). Lets a later reader tell
     * "opcode cache serving stale bytecode from a prior deploy" apart from
     * "code is what we think it is and something downstream is slow".
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadedFileFingerprints(?string $handlerClass): array {
        $files = array($this->fileFingerprint('trace', __FILE__));
        if ($handlerClass !== null && class_exists($handlerClass, false)) {
            try {
                $reflection = new ReflectionClass($handlerClass);
                $handlerFile = $reflection->getFileName();
                if (is_string($handlerFile) && $handlerFile !== '') {
                    $files[] = $this->fileFingerprint('handler', $handlerFile);
                }
            } catch (Throwable $e) {
                $files[] = array('role' => 'handler', 'path' => $handlerClass, 'error' => substr($e->getMessage(), 0, 200));
            }
        }
        return $files;
    }

    /** @return array<string, mixed> */
    private function fileFingerprint(string $role, string $path): array {
        $isFile = @is_file($path);
        return array(
            'role' => $role,
            'path' => $path,
            'hash' => $isFile ? @md5_file($path) : null,
            'mtime' => $isFile ? @filemtime($path) : null,
            'inode' => $isFile ? @fileinode($path) : null,
            'size' => $isFile ? @filesize($path) : null,
        );
    }

    /**
     * Reconcile loaded-file disk mtimes with OPcache timestamps; a differing
     * positive timestamp proves executable and filesystem generations differ.
     * @param array<int, array<string, mixed>> $loadedFiles
     * @return array{loaded_files: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    private function opcacheCapture(array $loadedFiles): array {
        $summary = array(
            'reason' => 'opcache-unavailable',
            'validate_timestamps' => $this->iniBoolean(ini_get('opcache.validate_timestamps')),
            'revalidate_freq' => $this->numericInteger(ini_get('opcache.revalidate_freq')),
            'restart_pending' => null,
            'restart_in_progress' => null,
            'start_time' => null,
            'last_restart_time' => null,
            'restart_counts' => array('oom' => null, 'hash' => null, 'manual' => null),
        );
        $loadedFiles = $this->withUnknownOpcacheState($loadedFiles);

        $restrictApi = ini_get('opcache.restrict_api');
        $apiRestricted = function_exists('abj404_opcache_api_is_restricted')
            ? abj404_opcache_api_is_restricted($restrictApi, __FILE__)
            : (is_string($restrictApi) && trim($restrictApi) !== '');
        if ($apiRestricted) {
            $summary['reason'] = 'opcache-api-restricted';
            return array('loaded_files' => $loadedFiles, 'summary' => $summary);
        }
        if (!function_exists('opcache_get_status')) {
            return array('loaded_files' => $loadedFiles, 'summary' => $summary);
        }

        $status = @opcache_get_status(true);
        if (!is_array($status) || (array_key_exists('opcache_enabled', $status) && !$status['opcache_enabled'])) {
            return array('loaded_files' => $loadedFiles, 'summary' => $summary);
        }

        $summary = $this->opcacheSummaryFromStatus($summary, $status);
        $loadedFiles = $this->withOpcacheScriptState($loadedFiles, $status['scripts'] ?? array());
        return array('loaded_files' => $loadedFiles, 'summary' => $summary);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function opcacheSummaryFromStatus(array $summary, array $status): array {
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : array();
        $summary['reason'] = 'available';
        $summary['restart_pending'] = isset($status['restart_pending']) ? (bool)$status['restart_pending'] : null;
        $summary['restart_in_progress'] = isset($status['restart_in_progress']) ? (bool)$status['restart_in_progress'] : null;
        $summary['start_time'] = $this->numericInteger($statistics['start_time'] ?? null);
        $summary['last_restart_time'] = $this->numericInteger($statistics['last_restart_time'] ?? null);
        $summary['restart_counts'] = array(
            'oom' => $this->numericInteger($statistics['oom_restarts'] ?? null),
            'hash' => $this->numericInteger($statistics['hash_restarts'] ?? null),
            'manual' => $this->numericInteger($statistics['manual_restarts'] ?? null),
        );
        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $loadedFiles
     * @param mixed $scripts
     * @return array<int, array<string, mixed>>
     */
    private function withOpcacheScriptState(array $loadedFiles, $scripts): array {
        $scripts = is_array($scripts) ? $scripts : array();
        foreach ($loadedFiles as &$file) {
            $path = is_string($file['path'] ?? null) ? $file['path'] : '';
            $metadata = $path !== '' ? ($scripts[$path] ?? null) : null;
            if (!is_array($metadata)) {
                $file['opcache_cached'] = false;
                continue;
            }
            $timestamp = $this->numericInteger($metadata['timestamp'] ?? null);
            $mtime = $this->numericInteger($file['mtime'] ?? null);
            $file['opcache_cached'] = true;
            $file['opcache_timestamp'] = $timestamp;
            $file['opcache_timestamp_matches_file'] = ($timestamp !== null && $timestamp > 0 && $mtime !== null)
                ? ($timestamp === $mtime) : null;
        }
        unset($file);
        return $loadedFiles;
    }

    /**
     * @param array<int, array<string, mixed>> $loadedFiles
     * @return array<int, array<string, mixed>>
     */
    private function withUnknownOpcacheState(array $loadedFiles): array {
        foreach ($loadedFiles as &$file) {
            $file['opcache_cached'] = null;
            $file['opcache_timestamp'] = null;
            $file['opcache_timestamp_matches_file'] = null;
        }
        unset($file);
        return $loadedFiles;
    }

    /** @param mixed $value */
    private function iniBoolean($value): ?bool {
        if ($value === false || $value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @param mixed $value */
    private function numericInteger($value): ?int {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Approximate the received HTTP header block exactly as `Name: value` CRLF
     * lines, plus raw cookie bytes and pair count. Values never leave memory.
     *
     * @return array{header_bytes: int, header_count: int, cookie_header_bytes: int, cookie_count: int}
     */
    private function requestShape(): array {
        $headerBytes = 0;
        $headerCount = 0;
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = str_replace('_', '-', ucwords(strtolower($key), '_'));
            } else {
                continue;
            }
            $headerBytes += strlen($name . ': ' . (string)$value . "\r\n");
            $headerCount++;
        }

        $cookieHeader = is_scalar($_SERVER['HTTP_COOKIE'] ?? null)
            ? (string)$_SERVER['HTTP_COOKIE'] : '';
        $cookieCount = 0;
        foreach (explode(';', $cookieHeader) as $cookiePair) {
            if (trim($cookiePair) !== '') {
                $cookieCount++;
            }
        }
        return array(
            'header_bytes' => $headerBytes,
            'header_count' => $headerCount,
            'cookie_header_bytes' => strlen($cookieHeader),
            'cookie_count' => $cookieCount,
        );
    }

    /**
     * Fingerprint the current administrator's potentially pathological state.
     * Only aggregate byte sizes and SHA-256 hashes are returned; meta keys and
     * values remain local to the request.
     *
     * @return array<string, mixed>
     */
    private function adminUserState(): array {
        $state = array(
            'reason' => 'user-api-unavailable',
            'wp_user_settings_bytes' => null,
            'wp_user_settings_hash' => null,
            'screen_option_meta_count' => null,
            'screen_option_meta_bytes' => null,
            'screen_option_meta_hash' => null,
            'locale' => null,
        );
        try {
            $userId = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
        } catch (Throwable $e) {
            $this->reportProbeFailure('current-user-id', $e);
            $state['reason'] = 'user-api-exception:' . get_class($e);
            return $state;
        }
        $state['locale'] = $this->resolvedUserLocale($userId);
        if ($userId < 1) {
            $state['reason'] = 'no-current-user';
            return $state;
        }
        if (!function_exists('get_user_meta')) {
            return $state;
        }

        try {
            $allMeta = get_user_meta($userId);
        } catch (Throwable $e) {
            $this->reportProbeFailure('admin-user-state', $e);
            $state['reason'] = 'user-meta-exception:' . get_class($e);
            return $state;
        }
        if (!is_array($allMeta)) {
            $state['reason'] = 'user-meta-invalid-shape';
            return $state;
        }

        $wpSettings = $allMeta['wp_user-settings'] ?? array();
        $wpSettingsBytes = $this->metaValueBytes($wpSettings);
        if ($wpSettingsBytes > 0) {
            $state['wp_user_settings_bytes'] = $wpSettingsBytes;
            $state['wp_user_settings_hash'] = hash('sha256', serialize($wpSettings));
        }

        $screenOptions = array();
        foreach ($allMeta as $key => $values) {
            if (is_string($key) && $this->isScreenOptionMetaKey($key)) {
                $screenOptions[$key] = $values;
            }
        }
        ksort($screenOptions);
        $state['screen_option_meta_count'] = count($screenOptions);
        $state['screen_option_meta_bytes'] = $this->metaValueBytes($screenOptions);
        $state['screen_option_meta_hash'] = $screenOptions !== array()
            ? hash('sha256', serialize($screenOptions)) : null;
        $state['reason'] = 'available';
        return $state;
    }

    private function resolvedUserLocale(int $userId): ?string {
        try {
            if ($userId > 0 && function_exists('get_user_locale')) {
                $locale = get_user_locale($userId);
            } elseif (function_exists('get_locale')) {
                $locale = get_locale();
            } else {
                return null;
            }
            return is_scalar($locale) ? substr((string)$locale, 0, 32) : null;
        } catch (Throwable $e) {
            $this->reportProbeFailure('user-locale', $e);
            return null;
        }
    }

    private function isScreenOptionMetaKey(string $key): bool {
        return preg_match('/(?:_per_page$|^screen_layout_|^metaboxhidden_|^closedpostboxes_|^meta-box-order_|^manage.*columnshidden$)/', $key) === 1;
    }

    /** @param mixed $value */
    private function metaValueBytes($value): int {
        if (is_array($value)) {
            $bytes = 0;
            foreach ($value as $item) {
                $bytes += $this->metaValueBytes($item);
            }
            return $bytes;
        }
        return is_scalar($value) ? strlen((string)$value) : strlen(serialize($value));
    }

    private function reportProbeFailure(string $probe, Throwable $error): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('request-environment', $probe . ' probe failed: '
                . get_class($error) . ' code=' . $error->getCode() . ' message=' . $error->getMessage());
        }
    }

    /**
     * One combined hash summarizing "what code is actually loaded for this
     * request" (plugin version plus the content hash + mtime of every
     * fingerprinted file). A build hash that differs between two requests
     * hitting the same deployed version is direct proof of opcache/deploy
     * staleness (cause D in the timeout matrix).
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function computeBuildHash(array $files): string {
        $parts = array(defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : 'unknown');
        foreach ($files as $file) {
            $hash = $file['hash'] ?? '';
            $mtime = $file['mtime'] ?? '';
            $parts[] = (is_scalar($hash) ? (string)$hash : '') . ':' . (is_scalar($mtime) ? (string)$mtime : '');
        }
        return sha1(implode('|', $parts));
    }

    /** @return array{elapsed_ms: int, result: string} */
    private function timedCacheProbe(string $cacheProbeKey): array {
        $startedAt = $this->clock->nowFloat();
        $result = 'unavailable';
        if (function_exists('wp_cache_get')) {
            try {
                $hit = wp_cache_get($cacheProbeKey, 'abj404');
                $result = $hit === false ? 'miss' : 'hit';
            } catch (Throwable $e) {
                $result = 'error';
            }
        }
        return array(
            'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
            'result' => $result,
        );
    }
}
