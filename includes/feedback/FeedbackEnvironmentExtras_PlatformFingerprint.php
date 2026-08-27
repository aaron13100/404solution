<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static-identity probes that fingerprint the hosting platform: WHICH
 * managed host (WP Engine, Kinsta, Pantheon, ...), WHICH control panel
 * (cPanel, Plesk, RunCloud, ...), WHICH PHP execution stack (LSWS,
 * mod_lsapi, FPM, mod_php, CGI), and WHICH CloudLinux markers are present.
 *
 * WHICH cache implementation owns the request caches is a different subject
 * with a different marker vocabulary, and it lives in
 * ABJ_404_Solution_FeedbackEnvironmentExtras_CacheFingerprint.
 *
 * Distinct in kind from FeedbackEnvironmentExtras_HostProbes, which
 * answers dynamic runtime questions (how much disk is left, what is
 * the open_basedir RIGHT NOW). Platform fingerprints answer "what is
 * this site permanently sitting on" -- the values rarely change for
 * the life of the install and group reports the same way over time.
 *
 * Every detector here follows the same pattern: scan a table of
 * distinctive markers (constants, env vars, paths, container and
 * database-server identity), return the first match. Keeping those tables
 * together lets them evolve as a single editorial concern instead of
 * being scattered.
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
     * @param array{php_sapi?: mixed, loaded_extensions?: mixed, cloudlinux_alt_php_present?: mixed,
     *   env?: mixed, document_root?: mixed, abspath?: mixed, cgroup?: mixed,
     *   db_server_version?: mixed} $runtime
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

        $managedHost = $this->detectManagedHost($runtime);
        if ($managedHost['host'] !== '') {
            $out['host'] = $managedHost['host'];
            $out['matched_marker'] = $managedHost['matched_marker'];
        }
        if ($out['host'] === 'unknown') {
            $infrastructure = $this->detectInfrastructureHost($runtime);
            if ($infrastructure['host'] !== '') {
                $out['host'] = $infrastructure['host'];
                $out['matched_marker'] = $infrastructure['matched_marker'];
            }
        }
        if ($out['host'] === 'unknown' && $out['cloudlinux_markers'] !== array()) {
            $out['host'] = 'cloudlinux';
            $out['matched_marker'] = (string)$out['cloudlinux_markers'][0];
        }

        $panel = $this->detectControlPanel($runtime);
        if ($panel['panel'] !== '') {
            $out['panel'] = $panel['panel'];
            if ($out['matched_marker'] === '') {
                $out['matched_marker'] = $panel['matched_marker'];
            }
        }

        return $out;
    }

    /**
     * Managed hosts that identify themselves outright, each through a
     * distinctive PHP constant or environment variable. First match wins, so
     * declaration order is precedence order.
     *
     * Azure App Service is in this table for the environment route
     * (WEBSITE_SITE_NAME and friends, which App Service always injects into the
     * container); the filesystem/cgroup/database routes that survive an FPM
     * pool built with `clear_env = yes` are in INFRASTRUCTURE_HOST_MARKERS.
     *
     * @param array<string, mixed> $runtime
     * @return array{host: string, matched_marker: string} Empty host when no marker matched.
     */
    private function detectManagedHost(array $runtime): array {
        $managedHostChecks = array(
            'wp_engine'   => array('const' => array('WPE_APIKEY', 'WPE_PLUGIN_DIR'), 'env' => array('IS_WPE')),
            'kinsta'      => array('const' => array('KINSTA_CACHE_ZONE'), 'env' => array('KINSTA_SERVICE_NAME')),
            'pantheon'    => array('const' => array('PANTHEON_ENVIRONMENT'), 'env' => array('PANTHEON_ENVIRONMENT')),
            'flywheel'    => array('const' => array('FLYWHEEL_CONFIG_DIR', 'FLYWHEEL_PLUGIN_DIR'), 'env' => array()),
            'pressable'   => array('const' => array('PRESSABLE_VERSION'), 'env' => array()),
            'siteground'  => array('const' => array('SG_OPTIMIZER_VERSION'), 'env' => array()),
            'wordpress_com' => array('const' => array('IS_ATOMIC', 'IS_WPCOM'), 'env' => array()),
            'cloudways'   => array('const' => array(), 'env' => array('cw_allowed_ip')),
            'azure_app_service' => array('const' => array(), 'env' => array(
                'WEBSITE_SITE_NAME', 'WEBSITE_INSTANCE_ID', 'APPSETTING_WEBSITE_SITE_NAME')),
        );
        foreach ($managedHostChecks as $hostKey => $checks) {
            foreach ((array)$checks['const'] as $c) {
                if (defined($c)) {
                    return array('host' => $hostKey, 'matched_marker' => 'const:' . $c);
                }
            }
            foreach ((array)$checks['env'] as $e) {
                if ($this->environmentValue($runtime, $e) !== null) {
                    return array('host' => $hostKey, 'matched_marker' => 'env:' . $e);
                }
            }
        }
        return array('host' => '', 'matched_marker' => '');
    }

    /**
     * Control panels: cPanel / hPanel / Plesk / DirectAdmin / RunCloud /
     * CloudPanel. Independent of the managed-host class: a cPanel site might
     * also be on SiteGround.
     *
     * @param array<string, mixed> $runtime
     * @return array{panel: string, matched_marker: string} Empty panel when no marker matched.
     */
    private function detectControlPanel(array $runtime): array {
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
                if ($this->environmentValue($runtime, $e) !== null) {
                    return array('panel' => $panelKey, 'matched_marker' => 'env:' . $e);
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
                    return array('panel' => $panelKey, 'matched_marker' => 'path:' . $p);
                }
            }
        }
        return array('panel' => '', 'matched_marker' => '');
    }

    /**
     * Platforms that publish no constant and cannot be relied on to publish an
     * environment variable either, but that DO stamp themselves on three fixed,
     * non-identifying facts about the runtime: where the site is served from,
     * which container hierarchy the worker sits in, and how the database server
     * suffixes its own version string.
     *
     * Azure App Service for Linux is the case this table was built for. Support
     * report 2026-08-27 (plugin 4.3.4) carried `/home/site/wwwroot`, an Antares
     * cgroup path and `8.0.45-azure`, and still reported host="unknown",
     * because an FPM pool with `clear_env = yes` (the App Service default for
     * some images) strips WEBSITE_SITE_NAME before PHP ever sees it. Any ONE of
     * the three is sufficient, which is the point: this platform is only
     * reliably named when the markers are checked independently.
     *
     * Marker KEYS only ever leave the site. The cgroup line and the App Service
     * environment both embed the customer's own site name, so the matched
     * needle is reported and the matched text never is.
     *
     * @var array<string, array{paths: array<int, string>, cgroup: array<int, string>, db_version: array<int, string>}>
     */
    private const INFRASTRUCTURE_HOST_MARKERS = array(
        'azure_app_service' => array(
            'paths' => array('/home/site/wwwroot'),
            'cgroup' => array('antares'),
            'db_version' => array('azure'),
        ),
    );

    /** Ceiling on the cgroup read. The membership lines we match are in the first few. */
    private const MAX_CGROUP_BYTES = 4096;

    /**
     * Name a platform from the fixed runtime markers in
     * INFRASTRUCTURE_HOST_MARKERS, or report no match.
     *
     * Runs only after the constant/environment table above has come back
     * unknown, so a host that identifies itself explicitly keeps its own,
     * more specific marker.
     *
     * @param array<string, mixed> $runtime
     * @return array{host: string, matched_marker: string}
     */
    private function detectInfrastructureHost(array $runtime): array {
        $documentRoot = $this->normalizedDirectory(
            array_key_exists('document_root', $runtime)
                ? $runtime['document_root']
                : ($_SERVER['DOCUMENT_ROOT'] ?? '')
        );
        $installPath = $this->normalizedDirectory(
            array_key_exists('abspath', $runtime)
                ? $runtime['abspath']
                : (defined('ABSPATH') ? ABSPATH : '')
        );
        $cgroup = array_key_exists('cgroup', $runtime)
            ? (is_scalar($runtime['cgroup']) ? (string)$runtime['cgroup'] : '')
            : $this->readProcSelfCgroup();
        $cgroup = strtolower($cgroup);
        $databaseVersion = strtolower(
            array_key_exists('db_server_version', $runtime) && is_scalar($runtime['db_server_version'])
                ? (string)$runtime['db_server_version'] : ''
        );

        foreach (self::INFRASTRUCTURE_HOST_MARKERS as $hostKey => $markers) {
            foreach ($markers['paths'] as $path) {
                if ($this->pathIsWithin($documentRoot, $path) || $this->pathIsWithin($installPath, $path)) {
                    return array('host' => $hostKey, 'matched_marker' => 'path:' . $path);
                }
            }
            foreach ($markers['cgroup'] as $needle) {
                // Bounded by the path separators on both sides so a customer
                // site literally named "antares" cannot match as the platform.
                if ($cgroup !== '' && strpos($cgroup, '/' . $needle . '/') !== false) {
                    return array('host' => $hostKey, 'matched_marker' => 'cgroup:' . $needle);
                }
            }
            foreach ($markers['db_version'] as $needle) {
                // The vendor suffix, not a substring: "8.0.45-azure" matches and
                // a server hosted at azure.example.com does not.
                if ($databaseVersion !== '' && strpos($databaseVersion, '-' . $needle) !== false) {
                    return array('host' => $hostKey, 'matched_marker' => 'db_version:' . $needle);
                }
            }
        }
        return array('host' => '', 'matched_marker' => '');
    }

    /**
     * One environment variable, read from the injected snapshot when the caller
     * supplied one and from the process otherwise. Null means "not set".
     *
     * An injected `env` array is authoritative even when empty: a test or an
     * offline collector that declares the environment has to be able to declare
     * it EMPTY, or the negative control silently reads the developer's own shell.
     *
     * @param array<string, mixed> $runtime
     */
    private function environmentValue(array $runtime, string $name): ?string {
        if (array_key_exists('env', $runtime)) {
            $environment = is_array($runtime['env']) ? $runtime['env'] : array();
            return array_key_exists($name, $environment) && is_scalar($environment[$name])
                ? (string)$environment[$name] : null;
        }
        $value = getenv($name);
        return $value === false ? null : (string)$value;
    }

    /**
     * The worker's cgroup membership text, or '' when this platform has no
     * procfs (macOS, Windows, a hardened open_basedir).
     */
    private function readProcSelfCgroup(): string {
        $path = '/proc/self/cgroup';
        if (!@is_readable($path)) { // allow-silent-error: procfs is absent on non-Linux hosts and outside many open_basedir roots; absence is the answer, not a fault.
            return '';
        }
        $raw = @file_get_contents($path, false, null, 0, self::MAX_CGROUP_BYTES); // allow-silent-error: a readable-but-unreadable procfs entry means the marker is unavailable, which is the same finding as absence.
        return is_string($raw) ? $raw : '';
    }

    /**
     * A directory path with any trailing separator removed, so
     * `/home/site/wwwroot/` and `/home/site/wwwroot` compare equal.
     *
     * @param mixed $value
     */
    private function normalizedDirectory($value): string {
        $path = is_scalar($value) ? (string)$value : '';
        return $path === '' ? '' : rtrim($path, '/\\');
    }

    /** Is $path the marker directory itself, or something inside it? */
    private function pathIsWithin(string $path, string $marker): bool {
        return $path !== '' && ($path === $marker || strpos($path, $marker . '/') === 0);
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
}
