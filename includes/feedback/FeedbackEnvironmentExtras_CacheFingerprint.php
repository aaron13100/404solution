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
     * How the presence of one object-cache backend is detected. A closed set,
     * because the alternative -- a bare string beside two other bare strings --
     * lets one typo silently switch a probe off. These values are also the
     * `backend_detail` prefix, so they are part of what a support payload says.
     */
    const MARKER_CONSTANT = 'const';
    const MARKER_CLASS = 'class';
    const MARKER_EXTENSION = 'ext';

    /**
     * The detector for each marker kind.
     *
     * A registry rather than three `if ($kind === ...)` branches: adding a
     * fourth kind is then one entry that cannot be half-wired, and
     * FeedbackEnvironmentExtras_CacheFingerprintTest asserts every kind used by
     * probeObjectCacheBackend() has an entry here, so a marker whose kind has
     * no detector fails a test instead of quietly never matching.
     *
     * @return array<string, callable(string): bool>
     */
    private static function markerDetectors(): array {
        return array(
            self::MARKER_CONSTANT => static function (string $marker): bool {
                return defined($marker);
            },
            // Autoloading is deliberately off: a support probe must observe what
            // is already loaded, not cause a class to load as a side effect.
            self::MARKER_CLASS => static function (string $marker): bool {
                return class_exists($marker, false);
            },
            self::MARKER_EXTENSION => static function (string $marker): bool {
                return extension_loaded($marker);
            },
        );
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
        // drop-ins. The first match wins, so a Redis Object Cache Pro install
        // is not also tagged as plain Redis.
        //
        // Keyed rather than a (name, kind, marker) tuple, and the kind is one
        // of the MARKER_* constants rather than a bare string, because a
        // mistyped kind used to fail OPEN: no branch matched, the check simply
        // never ran, and the probe reported "no object cache" for a host that
        // has one. A detector that silently stops detecting is worse than one
        // that reports nothing, since the answer still looks like an answer.
        $checks = array(
            array('backend' => 'redis_object_cache_pro', 'kind' => self::MARKER_CONSTANT, 'marker' => 'WP_REDIS_VERSION'),
            array('backend' => 'redis_object_cache_pro', 'kind' => self::MARKER_CLASS, 'marker' => 'RedisCachePro\\Plugin'),
            array('backend' => 'redis_object_cache', 'kind' => self::MARKER_CLASS, 'marker' => 'WP_Object_Cache'),
            array('backend' => 'memcached', 'kind' => self::MARKER_CLASS, 'marker' => 'Memcached'),
            array('backend' => 'apcu', 'kind' => self::MARKER_EXTENSION, 'marker' => 'apcu'),
            array('backend' => 'w3_total_cache', 'kind' => self::MARKER_CONSTANT, 'marker' => 'W3TC_VERSION'),
            array('backend' => 'litespeed_cache', 'kind' => self::MARKER_CONSTANT, 'marker' => 'LSCWP_DIR'),
            array('backend' => 'wp_engine_native', 'kind' => self::MARKER_CONSTANT, 'marker' => 'WPE_APIKEY'),
            array('backend' => 'pantheon', 'kind' => self::MARKER_CONSTANT, 'marker' => 'PANTHEON_ENVIRONMENT'),
        );
        $detectors = self::markerDetectors();
        foreach ($checks as $check) {
            $detector = $detectors[$check['kind']];
            if ($detector($check['marker'])) {
                $out['backend'] = $check['backend'];
                $out['backend_detail'] = $check['kind'] . ':' . $check['marker'];
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
