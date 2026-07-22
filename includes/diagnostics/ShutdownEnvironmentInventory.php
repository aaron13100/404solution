<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Describes the process's shutdown-time environment for the AJAX trace's
 * once-per-rotation inventory record: the WordPress $wp_filter['shutdown']
 * callback roster and the loaded PHP extension list.
 *
 * This is the shutdown-time analog of ABJ_404_Solution_RequestEnvironmentFingerprint
 * (which describes the request-start environment): pure runtime introspection
 * that turns "something in shutdown was slow" into a list of named candidates.
 * It holds no request state, so it is safe to call from any diagnostic that
 * needs to know what runs at shutdown on this host, not only the AJAX trace.
 *
 * Everything it returns is a callback name, a hook priority, or an extension
 * name -- it carries no value from the site's data.
 */
final class ABJ_404_Solution_ShutdownEnvironmentInventory {

    /** Enumerated extensions are bounded; a truncation is stated, never silent. */
    const MAX_EXTENSIONS = 200;

    /**
     * The full shutdown-time environment as two serializable lists.
     *
     * @return array{callbacks: array<int, string>, extensions: array<int, string>}
     */
    public static function capture(): array {
        return array(
            'callbacks' => self::describeShutdownCallbacks(),
            'extensions' => self::describeLoadedExtensions(),
        );
    }

    /**
     * The $wp_filter['shutdown'] callback roster, as `priority:callable`
     * strings. This names WordPress shutdown-ACTION callbacks only; raw
     * register_shutdown_function callbacks, destructors, and extension
     * request-shutdown handlers do not appear here (PHP exposes no API for
     * them), which is exactly why describeLoadedExtensions() exists alongside it.
     *
     * @return array<int, string>
     */
    private static function describeShutdownCallbacks(): array {
        $wpFilter = $GLOBALS['wp_filter'] ?? null;
        $hook = is_array($wpFilter) ? ($wpFilter['shutdown'] ?? null) : null;
        $callbacks = null;
        if (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) {
            $callbacks = $hook->callbacks;
        } elseif (is_array($hook)) {
            $callbacks = $hook;
        }
        if ($callbacks === null) {
            return array('unavailable');
        }
        $described = array();
        foreach ($callbacks as $priority => $priorityCallbacks) {
            if (!is_array($priorityCallbacks)) {
                continue;
            }
            foreach ($priorityCallbacks as $entry) {
                $function = is_array($entry) ? ($entry['function'] ?? null) : null;
                $described[] = $priority . ':' . self::describeCallable($function);
            }
        }
        return $described;
    }

    /** @param mixed $function */
    private static function describeCallable($function): string {
        if (is_string($function)) {
            return $function;
        }
        if (is_array($function) && count($function) === 2) {
            $target = $function[0];
            $targetName = is_object($target) ? get_class($target) : (is_string($target) ? $target : 'unknown');
            return $targetName . '::' . (is_string($function[1]) ? $function[1] : 'unknown');
        }
        if ($function instanceof Closure) {
            return 'Closure';
        }
        if (is_object($function)) {
            return get_class($function);
        }
        return 'unknown';
    }

    /**
     * Loaded PHP extensions, sorted. An extension's request-shutdown handler
     * is one of the named cause-class-G suspects and cannot be timed or
     * enumerated from userland at all; listing what is loaded at least lets a
     * reader name the candidates (Redis object cache, ionCube, Monarx, APM
     * agents) behind an unexplained non_wp_shutdown_ms.
     *
     * @return array<int, string>
     */
    private static function describeLoadedExtensions(): array {
        if (!function_exists('get_loaded_extensions')) {
            return array('unavailable');
        }
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        $described = array();
        foreach ($extensions as $name) {
            if (count($described) >= self::MAX_EXTENSIONS) {
                $described[] = 'truncated:' . (count($extensions) - self::MAX_EXTENSIONS) . '-more';
                break;
            }
            $described[] = substr((string)$name, 0, 64);
        }
        return $described;
    }
}
