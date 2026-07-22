<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What process, what code, and what runtime state is serving this request.
 *
 * When a request is slow or vanishes, the first question is not "which of
 * our queries was slow" but "is this even the code we think it is, on a
 * healthy process". This class answers that: how long the process had
 * already been alive before we got control, which SAPI and host and PID
 * served it, the content hash / mtime / inode of every plugin file actually
 * loaded (so an opcode cache serving bytecode from a prior deploy is visible
 * rather than inferred), what output buffers and session state were already
 * in place, how long a single object-cache read took, and the raw resource
 * counters.
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
            'ob_inventory' => $obInventory,
            'session_status' => function_exists('session_status') ? session_status() : null,
            'cache_probe_ms' => $cacheProbe['elapsed_ms'],
            'cache_probe_result' => $cacheProbe['result'],
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'wall_clock' => $this->clock->nowFloat(),
            'rusage' => is_array($rusage) ? $rusage : null,
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
