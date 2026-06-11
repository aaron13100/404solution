<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static-identity probes that fingerprint the hosting platform: WHICH
 * managed host (WP Engine, Kinsta, Pantheon, ...), WHICH control panel
 * (cPanel, Plesk, RunCloud, ...), and WHICH object-cache backend (Redis
 * variants, Memcached, APCu, W3TC, LiteSpeed, ...).
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
