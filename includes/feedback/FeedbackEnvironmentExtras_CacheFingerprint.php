<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which cache implementation owns this site's request caches.
 *
 * Two questions with two different answers, and support reports need both.
 * A drop-in file declares WHO INSTALLED the cache (`advanced-cache.php` and
 * `object-cache.php` carry a `Plugin Name` header); the running constants,
 * classes and extensions declare WHAT IS ACTUALLY RUNNING. They disagree more
 * often than not on a site that has switched caching plugins without cleaning
 * up, and that disagreement is itself the finding.
 *
 * Split from ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint,
 * which answers a different question about a different subject: what the site
 * is permanently sitting ON (host, panel, PHP execution stack). Cache
 * ownership changes whenever an admin installs a plugin; hosting identity does
 * not change for the life of the install, and the two marker vocabularies have
 * nothing in common.
 *
 * No PII: only presence booleans, the declared plugin name from a drop-in
 * header, and matched marker keys are returned. No file body and no path.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition; see that
 * class's collect() method for the keyed probe registry that wraps each call
 * below in recordProbe() for failure isolation.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_CacheFingerprint {

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
