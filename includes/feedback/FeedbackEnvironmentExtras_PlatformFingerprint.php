<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static-identity probes that fingerprint the hosting platform: WHICH
 * managed host (WP Engine, Kinsta, Pantheon, ...), WHICH control panel
 * (cPanel, Plesk, RunCloud, ...), WHICH PHP execution stack (LSWS,
 * mod_lsapi, FPM, mod_php, CGI), WHICH CloudLinux markers are present,
 * and WHICH cache drop-ins/backends own the request caches.
 *
 * Distinct in kind from FeedbackEnvironmentExtras_HostProbes, which
 * answers dynamic runtime questions (how much disk is left, what is
 * the open_basedir RIGHT NOW). Platform fingerprints answer "what is
 * this site permanently sitting on" -- the values rarely change for
 * the life of the install and group reports the same way over time.
 *
 * Both methods follow the same pattern: scan a table of distinctive
 * markers (constants, env vars, paths, classes), return the first
 * match. Keeping them together lets the marker tables evolve as a
 * single editorial concern instead of being scattered.
 *
 * No PII: only matched marker keys are returned. SERVER_SOFTWARE is
 * NOT echoed wholesale; it may include a hostname.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition;
 * see that class's collect() method for the keyed probe registry that
 * wraps each call below in recordProbe() for failure isolation.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint {

    /**
     * Best-effort hosting-class hint. Parses well-known markers from
     * server_software + per-host environment vars + per-host PHP
     * constants. Returns a small object so the server side can
     * distinguish "WP Engine" from "Kinsta" without re-parsing strings.
     *
     * No PII: only matched markers are returned. server_software is NOT
     * echoed wholesale; it may include a hostname.
     *
     * `$runtime` is an optional already-observed runtime snapshot. Production
     * callers normally omit it; diagnostic tests and offline collectors can
     * supply stable values without mutating process-wide PHP state.
     *
     * @param array{php_sapi?: mixed, loaded_extensions?: mixed, cloudlinux_alt_php_present?: mixed} $runtime
     * @return array<string, mixed>
     */
    public function probeHostingClass(array $runtime = array()): array {
        $out = array(
            'host'               => 'unknown',
            'panel'              => 'unknown',
            'php_execution_stack' => 'unknown',
            'cloudlinux_markers' => array(),
            'matched_marker'     => '',
        );
        $sw = '';
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE'])) {
            $sw = strtolower((string)$_SERVER['SERVER_SOFTWARE']);
        }
        $out['server_class'] = $this->classifyServerClass($sw);

        $phpSapi = isset($runtime['php_sapi']) && is_scalar($runtime['php_sapi'])
            ? strtolower(trim((string)$runtime['php_sapi']))
            : strtolower(PHP_SAPI);
        $out['php_execution_stack'] = $this->classifyPhpExecutionStack(
            (string)$out['server_class'],
            $phpSapi
        );

        $out['cloudlinux_markers'] = $this->collectCloudLinuxMarkers($runtime);

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
        if ($out['host'] === 'unknown' && $out['cloudlinux_markers'] !== array()) {
            $out['host'] = 'cloudlinux';
            $out['matched_marker'] = (string)$out['cloudlinux_markers'][0];
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
                // The panel-detection paths (/home/clp, /usr/local/cpanel, /opt/psa,
                // /etc/runcloud, etc.) sit outside the open_basedir of most managed
                // shared-hosting environments. is_dir() raises E_WARNING on every
                // miss. The plugin's NormalErrorHandler reports those warnings
                // (errfile is THIS file, which lives under the plugin folder), so
                // the diagnostic that was supposed to be silent ends up in the
                // admin error inbox as "ABJ404-SOLUTION Normal error handler error:
                // errno: 2, errstr: is_dir(): open_basedir restriction in effect.".
                // Suppress with @ since the probe is intentionally best-effort and
                // a denial here means "not on this host", not a logic bug.
                if (@is_dir($p)) { // allow-silent-error: open_basedir restriction surface; absence here is the answer, not a fault. See production reports 22-39 (4.1.18-4.1.19) flooding the inbox with "is_dir(): open_basedir restriction in effect" for /home/clp probes on p2p-game.com and similar CloudLinux-hosted sites.
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
     * Reduce SERVER_SOFTWARE to a bounded server product without returning
     * versions or hostnames that may appear in the raw value.
     *
     * @param string $serverSoftware Lowercased SERVER_SOFTWARE value.
     * @return string
     */
    private function classifyServerClass(string $serverSoftware): string {
        if (strpos($serverSoftware, 'apache') !== false) {
            return 'apache';
        }
        if (strpos($serverSoftware, 'nginx') !== false) {
            return 'nginx';
        }
        if (strpos($serverSoftware, 'litespeed') !== false) {
            return 'litespeed';
        }
        if (strpos($serverSoftware, 'iis') !== false) {
            return 'iis';
        }
        return $serverSoftware === '' ? 'unknown' : 'other';
    }

    /**
     * Collect only the fixed, non-identifying CloudLinux markers used by
     * support diagnostics.
     *
     * @param array{loaded_extensions?: mixed, cloudlinux_alt_php_present?: mixed} $runtime
     * @return array<int, string>
     */
    private function collectCloudLinuxMarkers(array $runtime): array {
        $loadedExtensions = array_key_exists('loaded_extensions', $runtime)
            ? $runtime['loaded_extensions']
            : get_loaded_extensions();
        $extensionNames = array();
        if (is_array($loadedExtensions)) {
            foreach ($loadedExtensions as $extensionName) {
                if (is_scalar($extensionName)) {
                    $extensionNames[strtolower((string)$extensionName)] = true;
                }
            }
        }

        $markers = array();
        foreach (array('xray', 'clos_ssa') as $cloudLinuxExtension) {
            if (isset($extensionNames[$cloudLinuxExtension])) {
                $markers[] = 'extension:' . $cloudLinuxExtension;
            }
        }
        $altPhpPresent = array_key_exists('cloudlinux_alt_php_present', $runtime)
            ? $runtime['cloudlinux_alt_php_present'] === true
            : @is_dir('/opt/alt/php'); // allow-silent-error: CloudLinux's alt-PHP directory is outside many open_basedir roots; denial means the marker is unavailable.
        if ($altPhpPresent) {
            // Report the marker name, never the absolute path that was probed.
            $markers[] = 'alt_php';
        }

        return $markers;
    }

    /**
     * Identify the request's PHP execution product from the webserver and
     * SAPI pair. In particular, Apache + the `litespeed` SAPI is mod_lsapi,
     * not LiteSpeed Web Server.
     *
     * @param string $serverClass
     * @param string $phpSapi
     * @return string
     */
    private function classifyPhpExecutionStack(string $serverClass, string $phpSapi): string {
        if ($phpSapi === 'litespeed') {
            return $serverClass === 'apache' ? 'mod_lsapi' : 'lsws';
        }
        if (strpos($phpSapi, 'fpm') !== false) {
            return 'fpm';
        }
        if ($phpSapi === 'apache2handler' || $phpSapi === 'apache') {
            return 'mod_php';
        }
        if ($phpSapi === 'cgi' || $phpSapi === 'cgi-fcgi') {
            return 'cgi';
        }
        return 'unknown';
    }

    /**
     * Report whether one of WordPress's two cache drop-ins is installed and
     * the owner declared by its `Plugin Name` header. No file body, path, or
     * other header is returned.
     *
     * The directory searched is WP_CONTENT_DIR (or ABSPATH/wp-content when
     * that constant is absent), passed through the
     * `abj404_cache_dropin_directory` filter so a site can point the probe
     * somewhere else: installs that load their drop-ins from a relocated
     * content directory, and anything that needs the probe scoped away from
     * the live one, would otherwise be reported as having no cache drop-in at
     * all. Same shape as `abj404_host_pressure_probe_paths` and
     * `abj404_ajax_trace_directory`. A non-string or empty return leaves the
     * computed default in force, so a misbehaving filter degrades to today's
     * behaviour rather than probing '/'. Throwing is safe too: every probe
     * runs inside FeedbackEnvironmentExtras::recordProbe(), which records the
     * failure and substitutes the default.
     *
     * @param string $dropinKey One of `advanced_cache` or `object_cache`.
     * @return array{present: bool, owner: string}
     */
    public function probeCacheDropin(string $dropinKey): array {
        $dropinFiles = array(
            'advanced_cache' => 'advanced-cache.php',
            'object_cache' => 'object-cache.php',
        );
        if (!isset($dropinFiles[$dropinKey])) {
            return array('present' => false, 'owner' => '');
        }

        $contentDirectory = defined('WP_CONTENT_DIR')
            ? rtrim((string)WP_CONTENT_DIR, '/\\')
            : rtrim((string)ABSPATH, '/\\') . '/wp-content';
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_cache_dropin_directory', $contentDirectory, $dropinKey);
            if (is_string($filtered) && trim($filtered) !== '') {
                $contentDirectory = rtrim($filtered, '/\\');
            }
        }
        $dropinPath = $contentDirectory . '/' . $dropinFiles[$dropinKey];
        if (!is_file($dropinPath)) {
            return array('present' => false, 'owner' => '');
        }
        if (!function_exists('get_file_data')) {
            return array('present' => true, 'owner' => 'unknown');
        }

        $headers = get_file_data($dropinPath, array('owner' => 'Plugin Name'), 'plugin');
        if (!is_array($headers) || !isset($headers['owner']) || !is_scalar($headers['owner'])) {
            return array('present' => true, 'owner' => 'unknown');
        }
        $owner = trim(strip_tags((string)$headers['owner']));
        $owner = preg_replace('/[[:cntrl:]]+/', ' ', $owner);
        $owner = is_string($owner) ? trim($owner) : '';

        return array(
            'present' => true,
            'owner' => $owner !== '' ? $owner : 'unknown',
        );
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
}
