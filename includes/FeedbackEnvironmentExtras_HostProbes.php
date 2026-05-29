<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Host / runtime environment probes for the feedback payload's
 * `environment_extras` field.
 *
 * Every method in this class reads PHP, OS, or WordPress runtime state
 * with no SQL: filesystem headroom on the uploads dir / system temp,
 * SAPI + opcache + open_basedir, hosting-class fingerprints (WP Engine,
 * Kinsta, Pantheon, cPanel, ...), object-cache backend detection,
 * timezone identity, multisite role, htaccess writability, install +
 * upgrade lifecycle.
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
        if (!function_exists('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $dir = $this->supportDiagnosticsDirectory();
        $v = @disk_free_space($dir);
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
        if (!function_exists('disk_total_space')) {
            throw new \RuntimeException('disk_total_space unavailable');
        }
        $dir = $this->supportDiagnosticsDirectory();
        $v = @disk_total_space($dir);
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
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
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
     * Best-effort hosting-class hint. Parses well-known markers from
     * server_software + per-host environment vars + per-host PHP
     * constants. Returns a small object so the server side can
     * distinguish "WP Engine" from "Kinsta" without re-parsing strings.
     *
     * No PII: only matched markers are returned. server_software is NOT
     * echoed wholesale; it may include a hostname.
     *
     * @return array<string, mixed>
     */
    public function probeHostingClass(): array {
        $out = array(
            'host'           => 'unknown',
            'panel'          => 'unknown',
            'matched_marker' => '',
        );
        $sw = '';
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE'])) {
            $sw = strtolower((string)$_SERVER['SERVER_SOFTWARE']);
        }
        // Webserver class only (no version, no hostname).
        if (strpos($sw, 'apache') !== false)       { $out['server_class'] = 'apache'; }
        elseif (strpos($sw, 'nginx') !== false)    { $out['server_class'] = 'nginx'; }
        elseif (strpos($sw, 'litespeed') !== false){ $out['server_class'] = 'litespeed'; }
        elseif (strpos($sw, 'iis') !== false)      { $out['server_class'] = 'iis'; }
        else                                       { $out['server_class'] = ($sw === '' ? 'unknown' : 'other'); }

        // Managed-host markers: each host publishes a distinctive
        // constant or environment variable.
        $managedHostChecks = array(
            'wp_engine'   => array('const' => array('WPE_APIKEY', 'WPE_PLUGIN_DIR'), 'env' => array('IS_WPE')),
            'kinsta'      => array('const' => array('KINSTA_CACHE_ZONE'), 'env' => array('KINSTA_SERVICE_NAME')),
            'pantheon'    => array('const' => array('PANTHEON_ENVIRONMENT'), 'env' => array('PANTHEON_ENVIRONMENT')),
            'flywheel'    => array('const' => array('FLYWHEEL_CONFIG_DIR', 'FLYWHEEL_PLUGIN_DIR'), 'env' => array()),
            'pressable'   => array('const' => array('PRESSABLE_VERSION'), 'env' => array()),
            'siteground'  => array('const' => array('SG_OPTIMIZER_VERSION'), 'env' => array()),
            'wordpress_com' => array('const' => array('IS_ATOMIC', 'IS_WPCOM'), 'env' => array()),
            'cloudways'   => array('const' => array(), 'env' => array('cw_allowed_ip')),
        );
        foreach ($managedHostChecks as $hostKey => $checks) {
            foreach ((array)$checks['const'] as $c) {
                if (defined($c)) {
                    $out['host'] = $hostKey;
                    $out['matched_marker'] = 'const:' . $c;
                    break 2;
                }
            }
            foreach ((array)$checks['env'] as $e) {
                if (getenv($e) !== false) {
                    $out['host'] = $hostKey;
                    $out['matched_marker'] = 'env:' . $e;
                    break 2;
                }
            }
        }

        // Control-panel markers: cPanel / hPanel / Plesk / DirectAdmin /
        // RunCloud / CloudPanel. These are independent of the managed-host
        // class above: a cPanel site might also be on SiteGround.
        $panelChecks = array(
            'cpanel'      => array('env' => array('CPANEL'), 'path' => array('/usr/local/cpanel')),
            'hpanel'      => array('env' => array('HOSTINGER'), 'path' => array('/usr/local/hostinger')),
            'plesk'       => array('env' => array('PLESK_ADMIN_PASSWORD'), 'path' => array('/usr/local/psa', '/opt/psa')),
            'directadmin' => array('env' => array(), 'path' => array('/usr/local/directadmin')),
            'runcloud'    => array('env' => array(), 'path' => array('/etc/runcloud')),
            'cloudpanel'  => array('env' => array(), 'path' => array('/home/clp')),
        );
        foreach ($panelChecks as $panelKey => $checks) {
            foreach ((array)$checks['env'] as $e) {
                if (getenv($e) !== false) {
                    $out['panel'] = $panelKey;
                    if ($out['matched_marker'] === '') {
                        $out['matched_marker'] = 'env:' . $e;
                    }
                    break 2;
                }
            }
            foreach ((array)$checks['path'] as $p) {
                if (is_dir($p)) {
                    $out['panel'] = $panelKey;
                    if ($out['matched_marker'] === '') {
                        $out['matched_marker'] = 'path:' . $p;
                    }
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * Object-cache backend NAME. The base payload's `object_cache` enum
     * answers "external or default"; this answers "external WHAT": Redis
     * (predis vs phpredis vs Redis Object Cache plugin), Memcached,
     * APCu, W3TC, LiteSpeed, WP Engine native, Pantheon, etc.
     *
     * @return array<string, mixed>
     */
    public function probeObjectCacheBackend(): array {
        $out = array(
            'using_ext_cache' => false,
            'backend'         => 'unknown',
            'backend_detail'  => '',
        );
        if (function_exists('wp_using_ext_object_cache')) {
            $out['using_ext_cache'] = (bool)wp_using_ext_object_cache();
        }
        // Known constants/classes/extensions from popular object-cache
        // drop-ins. Each tuple is (name, type, marker): the first match
        // wins so a Redis Object Cache Pro install is not also tagged
        // as plain Redis.
        $checks = array(
            array('redis_object_cache_pro', 'const', 'WP_REDIS_VERSION'),
            array('redis_object_cache_pro', 'class', 'RedisCachePro\\Plugin'),
            array('redis_object_cache',     'class', 'WP_Object_Cache'),
            array('memcached',              'class', 'Memcached'),
            array('apcu',                   'ext',   'apcu'),
            array('w3_total_cache',         'const', 'W3TC_VERSION'),
            array('litespeed_cache',        'const', 'LSCWP_DIR'),
            array('wp_engine_native',       'const', 'WPE_APIKEY'),
            array('pantheon',               'const', 'PANTHEON_ENVIRONMENT'),
        );
        foreach ($checks as $check) {
            list($name, $type, $marker) = $check;
            if ($type === 'const' && defined($marker)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'const:' . $marker;
                return $out;
            }
            if ($type === 'class' && class_exists($marker, false)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'class:' . $marker;
                return $out;
            }
            if ($type === 'ext' && extension_loaded($marker)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'ext:' . $marker;
                return $out;
            }
        }
        // Default WP object cache used in-memory per request.
        if (!$out['using_ext_cache']) {
            $out['backend'] = 'default';
            $out['backend_detail'] = 'wp_object_cache:in_memory';
        }
        return $out;
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
            $dt = new \DateTime('now', $tz);
            $out['server_utc_offset_seconds'] = (int)$tz->getOffset($dt);
        } catch (\Throwable $e) {
            // allow-silent-catch: server_utc_offset is best-effort; an invalid tz string leaves the default zero in place
            @error_log('404 Solution: probeTimezone offset probe failed: ' . $e->getMessage());
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
            $settings = get_option('abj404_settings', null);
            if (is_array($settings) && isset($settings['DB_VERSION']) && is_scalar($settings['DB_VERSION'])) {
                $out['db_version_option'] = (string)$settings['DB_VERSION'];
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
                @error_log('404 Solution: probeMultisiteRole network-activated check failed: ' . $e->getMessage());
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
        if (!function_exists('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        if ($tmp === '') {
            throw new \RuntimeException('sys_get_temp_dir returned empty');
        }
        $v = @disk_free_space($tmp);
        if ($v === false) {
            throw new \RuntimeException('disk_free_space returned false for ' . $tmp);
        }
        return (int)$v;
    }
}
