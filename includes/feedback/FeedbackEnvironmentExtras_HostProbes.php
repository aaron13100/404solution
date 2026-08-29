<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';
require_once dirname(__DIR__) . '/services/PostResponseWorkerBudget.php';

/**
 * Host / runtime environment probes for the feedback payload's
 * `environment_extras` field.
 *
 * Every method in this class reads dynamic PHP, OS, or WordPress
 * runtime state with no SQL: filesystem headroom on the uploads dir /
 * system temp, opcache settings, open_basedir, timezone identity,
 * multisite role, htaccess writability, install + upgrade lifecycle.
 *
 * Static-identity platform fingerprinting (hosting class, control
 * panel, object-cache backend) lives in the sibling class
 * FeedbackEnvironmentExtras_PlatformFingerprint: those values rarely
 * change for the life of the install and are kept together so the
 * marker tables evolve as a single editorial concern.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition;
 * see that class's collect() method for the keyed probe registry that
 * wraps each call below in recordProbe() for failure isolation.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_HostProbes {

    /**
     * Free bytes available on the WP uploads directory's filesystem. Used
     * to triage "Table is full" reports (the logical table-full condition
     * is rare, disk quota is common). Throws when disk_free_space() is
     * disabled (open_basedir, hardened hosts) so the caller's tryInt
     * wrapper records null rather than a misleading zero.
     *
     * @return int
     */
    public function diskFreeBytesOrThrow(): int {
        if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $dir = $this->supportDiagnosticsDirectory();
        $v = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::diskFreeSpace($dir);
        if ($v === false) {
            throw new \RuntimeException('disk_free_space returned false for ' . $dir);
        }
        return (int)$v;
    }

    /**
     * Total bytes on the same filesystem. Combined with disk_free_bytes,
     * lets the server-side report show "8% free" rather than a raw byte
     * count that is hard to interpret across hosts.
     *
     * @return int
     */
    public function diskTotalBytesOrThrow(): int {
        if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('disk_total_space')) {
            throw new \RuntimeException('disk_total_space unavailable');
        }
        $dir = $this->supportDiagnosticsDirectory();
        $v = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::diskTotalSpace($dir);
        if ($v === false) {
            throw new \RuntimeException('disk_total_space returned false for ' . $dir);
        }
        return (int)$v;
    }

    /**
     * Best directory to probe for the plugin's filesystem headroom. The
     * uploads dir is the most useful target (the debug log and any
     * cron-scratch files land there), but it may not be writable in
     * locked-down installs. Falls back to ABSPATH and finally __DIR__.
     *
     * @return string
     */
    private function supportDiagnosticsDirectory(): string {
        if (function_exists('wp_upload_dir')) {
            $info = wp_upload_dir(null, false);
            if (is_array($info) && isset($info['basedir']) && is_string($info['basedir']) && $info['basedir'] !== '') {
                return $info['basedir'];
            }
        }
        if (defined('ABSPATH') && is_string(ABSPATH) && ABSPATH !== '') {
            return ABSPATH;
        }
        return __DIR__;
    }

    /** @return bool */
    public function opcacheEnabled(): bool {
        if (ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('opcache_get_status')) {
            $st = ABJ_404_Solution_OpcacheAdapter::status(false);
            if (is_array($st) && isset($st['opcache_enabled'])) {
                return (bool)$st['opcache_enabled'];
            }
        }
        if (function_exists('ini_get')) {
            $v = ini_get('opcache.enable');
            if ($v === false) {
                return false;
            }
            return ((int)$v === 1 || strtolower((string)$v) === 'on');
        }
        return false;
    }

    /**
     * Timezone identity for the WP install, PHP runtime, and OS. The
     * canonical "off-by-N-hours" cron-window bug class is when WP thinks
     * it is in pt_BR while PHP is in UTC; capturing all three lets us
     * detect drift retroactively.
     *
     * @return array<string, mixed>
     */
    public function probeTimezone(): array {
        $out = array(
            'wp_timezone'              => '',
            'wp_gmt_offset'            => 0,
            'php_timezone'             => '',
            'server_utc_offset_seconds' => 0,
        );
        if (function_exists('get_option')) {
            $tz = get_option('timezone_string', '');
            if (is_scalar($tz)) { $out['wp_timezone'] = (string)$tz; }
            $off = get_option('gmt_offset', 0);
            if (is_scalar($off)) { $out['wp_gmt_offset'] = (int)round((float)$off * 3600); }
        }
        if (function_exists('date_default_timezone_get')) {
            $out['php_timezone'] = (string)date_default_timezone_get();
        }
        try {
            $tz = new \DateTimeZone($out['php_timezone'] !== '' ? $out['php_timezone'] : 'UTC');
            $dt = new \DateTime('@' . abj_clock()->now());
            $dt->setTimezone($tz);
            $out['server_utc_offset_seconds'] = (int)$tz->getOffset($dt);
        } catch (\Throwable $e) {
            // allow-silent-catch: server_utc_offset is best-effort; an invalid tz string leaves the default zero in place
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeTimezone offset probe failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Install + upgrade timeline. The single most useful bifurcator for
     * "started after upgrade Tuesday" vs "always broken since install."
     * Read-only from plugin options the upgrade path already writes;
     * no new SQL, no new options.
     *
     * Fields:
     *   installed_at:     int|null  unix seconds, from abj404_installed_time
     *   current_version:  string    ABJ404_VERSION (live)
     *   db_version_option string|null abj404_settings['DB_VERSION'] (the value
     *                                 stamped at the last upgrade; equals
     *                                 current_version after the upgrade path
     *                                 ran, mismatches between upgrade tick
     *                                 and DB_VERSION write on lock contention)
     *
     * @return array<string, mixed>
     */
    public function probePluginLifecycle(): array {
        $out = array(
            'installed_at'      => null,
            'current_version'   => defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : '',
            'db_version_option' => null,
        );
        if (function_exists('get_option')) {
            $t = get_option('abj404_installed_time', null);
            if (is_scalar($t) && is_numeric($t)) {
                $out['installed_at'] = (int)$t;
            }
        }
        $optionsRepository = function_exists('abj_service_optional') ? abj_service_optional('options_repository') : null;
        if (is_object($optionsRepository) && method_exists($optionsRepository, 'getOptions')) {
            try {
                $settings = $optionsRepository->getOptions(true);
                if (isset($settings['DB_VERSION']) && is_scalar($settings['DB_VERSION'])) {
                    $out['db_version_option'] = (string)$settings['DB_VERSION'];
                }
            } catch (\Throwable $e) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probePluginLifecycle options_repository probe failed: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * opcache detail fields beyond the on/off enum. Each value is
     * explicitly nullable: ini_get() returns false when the directive
     * is unknown, and "we couldn't read it" is materially different
     * from a stamped 0/false the host configured deliberately.
     *
     * Shape:
     *   { revalidate_freq: int|null,
     *     validate_timestamps: bool|null,
     *     enable_cli: bool|null }
     *
     * @return array<string, mixed>
     */
    public function probeOpcacheSettings(): array {
        $out = array(
            'revalidate_freq'     => null,
            'validate_timestamps' => null,
            'enable_cli'          => null,
        );
        if (!function_exists('ini_get')) {
            return $out;
        }
        $rf = ini_get('opcache.revalidate_freq');
        if ($rf !== false) {
            $out['revalidate_freq'] = (int)$rf;
        }
        $vt = ini_get('opcache.validate_timestamps');
        if ($vt !== false) {
            $out['validate_timestamps'] = ((int)$vt === 1 || strtolower((string)$vt) === 'on');
        }
        $ec = ini_get('opcache.enable_cli');
        if ($ec !== false) {
            $out['enable_cli'] = ((int)$ec === 1 || strtolower((string)$ec) === 'on');
        }
        return $out;
    }

    /**
     * open_basedir restriction string, or null when not configured.
     * Returned wholesale (path list) so the server side can match it
     * against the plugin's known write targets; the value is not PII
     * and the per-host shapes vary enough that any normalization here
     * would lose signal.
     *
     * @return string|null
     */
    public function probeOpenBasedir(): ?string {
        if (!function_exists('ini_get')) {
            return null;
        }
        $v = ini_get('open_basedir');
        if (!is_string($v) || $v === '') {
            return null;
        }
        return $v;
    }

    /**
     * Multisite identity for the request the report originates from.
     * When `is_multisite()` is false the rest of the shape is omitted
     * rather than emitted as nulls per probe (a single-site install
     * has no blog_id/network_id and the keys would be misleading).
     *
     * Shape (multisite):
     *   { is_multisite: true,
     *     is_main_site: bool|null,
     *     blog_id: int|null,
     *     network_id: int|null,
     *     network_activated: bool|null }
     *
     * Shape (single-site):
     *   { is_multisite: false }
     *
     * @return array<string, mixed>
     */
    public function probeMultisiteRole(): array {
        $isMultisite = function_exists('is_multisite') && (bool)is_multisite();
        $out = array('is_multisite' => $isMultisite);
        if (!$isMultisite) {
            return $out;
        }
        $out['is_main_site'] = function_exists('is_main_site') ? (bool)is_main_site() : null;
        $out['blog_id'] = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : null;
        $out['network_id'] = function_exists('get_current_network_id') ? (int)get_current_network_id() : null;

        $networkActivated = null;
        if (function_exists('is_plugin_active_for_network') && function_exists('plugin_basename') && defined('ABJ404_FILE')) {
            try {
                $networkActivated = (bool) is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
            } catch (\Throwable $e) {
                // allow-silent-catch: best-effort multisite probe; is_plugin_active_for_network requires wp-admin context that may not be loaded on front-end / cron paths, leave null
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeMultisiteRole network-activated check failed: ' . $e->getMessage());
                $networkActivated = null;
            }
        }
        $out['network_activated'] = $networkActivated;
        return $out;
    }

    /**
     * Whether the .htaccess at the WP home path is writable by the
     * plugin. Differentiates "Apache rule install will succeed" from
     * "must use the DB-only redirect handler". Falls back to ABSPATH
     * when get_home_path() is unavailable (front-end / cron context
     * loads it on demand from wp-admin/includes/file.php).
     *
     * @return bool
     */
    public function probeHtaccessWritable(): bool {
        $path = $this->resolveHtaccessPath();
        if ($path === '') {
            return false;
        }
        // is_writable() returns false on a non-existent file too,
        // which matches the install-method intent: if the file does
        // not yet exist and we cannot write the directory either, the
        // Apache-rule path cannot succeed.
        return @is_writable($path);
    }

    /**
     * Best path to test for .htaccess writability. Prefers
     * get_home_path() (which honors WordPress in-subdir installs);
     * falls back to ABSPATH for early-boot / front-end contexts where
     * wp-admin/includes/file.php has not been loaded.
     *
     * @return string
     */
    private function resolveHtaccessPath(): string {
        if (function_exists('get_home_path')) {
            $home = (string) get_home_path();
            if ($home !== '') {
                return rtrim($home, "/\\") . '/.htaccess';
            }
        }
        if (defined('ABSPATH') && ABSPATH !== '') {
            return rtrim(ABSPATH, "/\\") . '/.htaccess';
        }
        return '';
    }

    /**
     * Free bytes on the system temp directory's filesystem. Some
     * shared hosts mount /tmp as a separate quota from the WP install
     * path; the disk_free_bytes probe (which targets the uploads dir)
     * cannot see /tmp exhaustion. Throws when disk_free_space is
     * disabled so the caller's tryInt wrapper records null rather
     * than a misleading zero.
     *
     * @return int
     */
    public function probeTmpFreeBytesOrThrow(): int {
        if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        if ($tmp === '') {
            throw new \RuntimeException('sys_get_temp_dir returned empty');
        }
        $v = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::diskFreeSpace($tmp);
        if ($v === false) {
            throw new \RuntimeException('disk_free_space returned false for ' . $tmp);
        }
        return (int)$v;
    }

    /**
     * The PHP runtime's own self-report: which SAPI is executing, what the
     * hardening and buffering ini settings are, how much memory this request
     * peaked at, and which output-buffer handlers own the response right now.
     *
     * A flat map rather than a set of registered probes, because none of it can
     * fail: every value is a constant, an `ini_get()`, or a function guarded by
     * `function_exists()`, so there is no error to isolate and nothing for a
     * `<probe>_error` marker to say. Keeping it here instead of inline in
     * FeedbackEnvironmentExtras::collect() leaves that method a uniform probe
     * registry with no special case in the middle of it.
     *
     * PHP_SAPI, not php_sapi_name(): the constant is defined by the engine on
     * every SAPI and cannot be removed, while the function is on the
     * disable_functions hardening lists some shared/CloudLinux hosts ship,
     * where the guarded call silently degrades to ''. This is the field that
     * identified Bruno's litespeed SAPI (and with it the FPM-only
     * fastcgi_finish_request() no-op), so losing it loses the diagnosis. Same
     * accessor the flight recorder uses (RequestEnvironmentFingerprint).
     *
     * @return array<string, mixed>
     */
    public function collectPhpRuntimeIdentity(): array {
        $obHandlerNames = array();
        foreach (function_exists('ob_get_status') ? ob_get_status(true) : array() as $obStatus) {
            if (is_array($obStatus) && isset($obStatus['name']) && is_string($obStatus['name'])) {
                $obHandlerNames[] = $obStatus['name'];
            }
        }
        return array(
            'php_sapi' => PHP_SAPI,
            'php_disable_functions' => function_exists('ini_get')
                ? (string)ini_get('disable_functions') : '',
            'php_post_response_budget_armable' =>
                ABJ_404_Solution_PostResponseWorkerBudget::isSupported(),
            'php_memory_peak_bytes' => function_exists('memory_get_peak_usage')
                ? (int)memory_get_peak_usage(true) : 0,
            'php_opcache_enabled' => $this->opcacheEnabled(),
            'php_max_input_vars' => function_exists('ini_get') ? (int)ini_get('max_input_vars') : 0,
            'php_output_buffering' => function_exists('ini_get')
                ? (string)ini_get('output_buffering') : '',
            'php_zlib_output_compression' => function_exists('ini_get')
                ? (string)ini_get('zlib.output_compression') : '',
            'php_ob_level_at_collect' => array(
                'level' => function_exists('ob_get_level') ? (int)ob_get_level() : 0,
                // Handler names identify stack ownership. Other status fields,
                // especially byte counts, are unnecessary diagnostic surface.
                'handlers' => $obHandlerNames,
            ),
            'php_realpath_cache_size_bytes' => function_exists('realpath_cache_size')
                ? (int)realpath_cache_size() : 0,
        );
    }
}
