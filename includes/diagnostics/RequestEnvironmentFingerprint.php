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
        // One opcode-cache read for the whole request: see
        // ABJ_404_Solution_OpcacheGenerationProbe. The two detailed file
        // fingerprints below and the whole-path module manifest both
        // reconcile against it, so the most expensive probe in this class
        // runs once rather than per consumer.
        $opcache = ABJ_404_Solution_OpcacheGenerationProbe::read();
        $loadedFiles = $opcache->annotate($this->loadedFileFingerprints($handlerClass));
        // The whole diagnostic path, not just this file and the handler:
        // see ABJ_404_Solution_DiagnosticModuleManifest.
        $buildManifest = ABJ_404_Solution_DiagnosticModuleManifest::capture($opcache);
        $cacheProbe = $this->timedCacheProbe($cacheProbeKey);
        $cronDue = $this->cronDueEvents();
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
            'diagnostic_build_id' => defined('ABJ404_DIAGNOSTIC_BUILD_ID')
                ? (string)ABJ404_DIAGNOSTIC_BUILD_ID
                : ABJ_404_Solution_AjaxCheckpointLogger::DIAGNOSTIC_BUILD_ID,
            'plugin_build_hash' => $this->computeBuildHash($loadedFiles, $buildManifest),
            'loaded_files' => $loadedFiles,
            'build_manifest' => $buildManifest,
            'opcache' => $opcache->summary(),
            'request_shape' => $this->requestShape(),
            'admin_user_state' => $this->adminUserState(),
            'ob_inventory' => $obInventory,
            'session_status' => function_exists('session_status') ? session_status() : null,
            'cache_probe_ms' => $cacheProbe['elapsed_ms'],
            'cache_probe_result' => $cacheProbe['result'],
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'wall_clock' => $this->clock->nowFloat(),
            'rusage' => is_array($rusage) ? $rusage : null,
            'host_pressure' => ABJ_404_Solution_HostPressureSampler::capture($cacheProbeKey),
            'cron_doing_transient' => $this->cronDoingTransient(),
            'cron_disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'cron_alternate_wp_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'cron_due_event_count' => $cronDue['count'],
            'cron_due_event_error' => $cronDue['error'],
        ));
    }

    /**
     * Delta in milliseconds from REQUEST_TIME_FLOAT to `$nowFloat`, and the
     * raw REQUEST_TIME_FLOAT itself. Static and dependency-free (no Clock
     * instance) so boot-phase checkpoints -- recorded before the service
     * container exists, let alone this class's constructor dependency --
     * share the exact same formula as capture()'s own boot_delta_ms instead
     * of a second copy that could silently drift from it (see
     * ABJ_404_Solution_BootWaypointRecorder::record()).
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
     * Count of scheduled cron events whose timestamp is already due, plus why
     * that count is unavailable when it is.
     *
     * Every probe here degrades independently: an exception or a malformed
     * filtered return from a third-party plugin (see the
     * `pre_get_ready_cron_jobs` filter) must not break the capture. But
     * degrading is not the same as forgetting -- a `count` of null on a WP 5.0
     * site (wp_get_ready_cron_jobs() arrived in 5.1; this plugin supports 5.0)
     * and a null because a foreign cron filter threw are opposite findings,
     * and the second one is itself cause-D evidence. `error` is what tells
     * them apart, so the reason rides in the payload next to the outcome
     * rather than only in a log the reader may not have.
     *
     * @return array{count: int|null, error: string|null}
     */
    private function cronDueEvents(): array {
        if (!function_exists('wp_get_ready_cron_jobs')) {
            return array('count' => null, 'error' => 'wp_get_ready_cron_jobs-unavailable');
        }
        try {
            $due = wp_get_ready_cron_jobs();
        } catch (Throwable $e) {
            $this->reportProbeFailure('cron-due-events', $e);
            return array(
                'count' => null,
                'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 200),
            );
        }
        if (!is_array($due)) {
            return array('count' => null, 'error' => 'unexpected-shape:' . gettype($due));
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
        return array('count' => $count, 'error' => null);
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
            'locale_error' => null,
        );
        try {
            $userId = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
        } catch (Throwable $e) {
            $this->reportProbeFailure('current-user-id', $e);
            $state['reason'] = 'user-api-exception:' . get_class($e);
            return $state;
        }
        $resolvedLocale = $this->resolvedUserLocale($userId);
        $state['locale'] = $resolvedLocale['locale'];
        $state['locale_error'] = $resolvedLocale['error'];
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

    /**
     * The locale WordPress would render this request in, plus why it is
     * unavailable when it is.
     *
     * Same two-channel convention as cronDueEvents() and timedCacheProbe(),
     * for the same reason: a bare null cannot tell "this site has no locale
     * API at all" apart from "a plugin's locale filter is fatal here", and
     * the second is a finding rather than a shrug. `locale` keeps its own
     * narrow domain (a locale code, or null) so no reader has to parse a
     * failure out of it; the reason travels beside it in `error`, and the
     * full message and code go to the PHP error log via
     * reportProbeFailure().
     *
     * @return array{locale: string|null, error: string|null}
     */
    private function resolvedUserLocale(int $userId): array {
        try {
            if ($userId > 0 && function_exists('get_user_locale')) {
                $locale = get_user_locale($userId);
            } elseif (function_exists('get_locale')) {
                $locale = get_locale();
            } else {
                return array('locale' => null, 'error' => 'locale-api-unavailable');
            }
        } catch (Throwable $e) {
            $this->reportProbeFailure('user-locale', $e);
            return array(
                'locale' => null,
                'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 200),
            );
        }
        if (!is_scalar($locale)) {
            return array('locale' => null, 'error' => 'unexpected-shape:' . gettype($locale));
        }
        return array('locale' => substr((string)$locale, 0, 32), 'error' => null);
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
     * request" (plugin version, the content hash + mtime of every
     * fingerprinted file, and the whole diagnostic module manifest). A build
     * hash that differs between two requests hitting the same deployed
     * version is direct proof of opcache/deploy staleness (cause D in the
     * timeout matrix).
     *
     * The manifest hash is folded in rather than left as a separate scalar so
     * this ONE field answers the question it claims to answer. Before gap GF
     * it covered two files, which meant a stale or half-deployed request
     * driver, journal, response emitter, canary, or support collector left
     * plugin_build_hash completely unchanged -- a build fingerprint that
     * agreed with itself while the code under investigation had drifted.
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<string, mixed> $buildManifest
     */
    private function computeBuildHash(array $files, array $buildManifest): string {
        $parts = array(defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : 'unknown');
        foreach ($files as $file) {
            $hash = $file['hash'] ?? '';
            $mtime = $file['mtime'] ?? '';
            $parts[] = (is_scalar($hash) ? (string)$hash : '') . ':' . (is_scalar($mtime) ? (string)$mtime : '');
        }
        $manifestHash = $buildManifest['hash'] ?? '';
        $parts[] = 'manifest:' . (is_scalar($manifestHash) ? (string)$manifestHash : '');
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
                // Naming the class keeps this field's small readable domain
                // ('unavailable' / 'miss' / 'hit') intact while telling a
                // thrown drop-in apart from every other way the read can
                // fail -- the same 'user-api-exception:' . get_class($e)
                // convention adminUserState() uses above. The full message
                // and code go to the PHP error log, which is the channel
                // that can carry them without a size or PII budget.
                $this->reportProbeFailure('object-cache-read', $e);
                $result = 'error:' . get_class($e);
            }
        }
        return array(
            'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
            'result' => $result,
        );
    }
}
