<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where this request's diagnostic journals live.
 *
 * Three channels need the same answer -- the checkpoint logger, the staged
 * trace journal, and the request trace -- and each used to work it out for
 * itself: ask abj404_getUploadsDir(), then filter through
 * `abj404_ajax_trace_directory`. Both legs are WordPress filter dispatches,
 * and apply_filters() fires the `all` hook BEFORE it looks at whether the
 * named hook has any callbacks at all. So one journal record cost two `all`
 * firings, and inside a render scope with an `all` observer installed each of
 * those cost a full hook-registry inspection, which wrote lifecycle records of
 * its own, which resolved the directory again.
 *
 * That loop is measurable and it belongs to the HOST, not to our data. On the
 * owner's localhost (2,063 registered hooks / 4,024 callbacks across ten
 * plugins) one part=all table AJAX request wrote 6,810 records and fired `all`
 * 51,003 times with debug_mode on -- against 23,609 with it off -- while the
 * bare-WP lab fixture with three times the row count measured 606 ms.
 *
 * Resolving once per request is also the more correct semantic, not just the
 * cheaper one: a failing session's evidence belongs in ONE directory, and a
 * path that moved halfway through a request would split the journal in two.
 *
 * WHEN the answer is fixed matters. The plugin records its first checkpoints
 * during its own boot, before the plugins that filter `upload_dir` have
 * registered, so an answer memoized that early would pin the journal to a
 * directory the site does not actually use. The cache is therefore keyed by
 * whether the filters have settled -- `wp_loaded` or `admin_init` has fired,
 * both after every plugin has registered and both before any instrumented
 * handler runs, since admin-ajax.php fires admin_init before dispatching the
 * action. Boot records share one answer, post-boot records share another, and
 * the switch costs exactly one extra resolution per request.
 *
 * Reading `did_action()` rather than registering an invalidation hook is
 * deliberate, and the diagnostics hook-mutation contract enforces it: an
 * add_action() here would be a diagnostics-owned registry mutation outside a
 * lifecycle boundary, and bracketing it would make this leaf depend on the
 * tracer that depends on this leaf to find out where to write. A read has no
 * such cycle.
 *
 * A caller that passes a request-specific context is never cached at all,
 * because the answer is then a function of data this class does not own.
 */
final class ABJ_404_Solution_DiagnosticDirectoryResolver {

    /**
     * Points by which every plugin has had its chance to register a directory
     * filter. Observed, never hooked: see the class comment.
     */
    const SETTLED_HOOKS = array('wp_loaded', 'admin_init');

    /** @var array<string, string> Resolved directory, keyed by site and settledness. */
    private static $resolved = array();

    /**
     * The directory this request's diagnostic journals live in, with a
     * trailing separator, or '' when even the uploads directory is unknown.
     *
     * Never throws and never creates anything: callers that need the directory
     * to exist create it themselves, because "resolved but unusable" and
     * "unknown" are different findings and collapsing them is what made an
     * empty journal unattributable.
     *
     * @param array<string, mixed> $context Request context for the
     *   `abj404_ajax_trace_directory` filter. A non-empty context is resolved
     *   live: it is request data, so memoizing it under a site-wide key would
     *   hand one caller's answer to another.
     */
    public static function resolve(array $context = array()): string {
        if ($context !== array()) {
            return self::resolveThroughFilters($context);
        }
        $key = self::siteKey() . '|' . (self::filtersHaveSettled() ? 'settled' : 'boot');
        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }
        $directory = self::resolveThroughFilters($context);
        self::$resolved[$key] = $directory;
        return $directory;
    }

    /**
     * Discard the memoized answers. The seam the test suite uses to give a
     * PHPUnit worker the end-of-request it never gets
     * (ABJ404_RequestScopedStateReset).
     */
    public static function flush(): void {
        self::$resolved = array();
    }

    /** Test seam, and the request-scoped reset the harness calls by name. */
    public static function resetForTests(): void {
        self::flush();
    }

    /**
     * Have the plugins that filter the uploads directory had their chance to
     * register? Answered by observation so this class registers nothing.
     */
    private static function filtersHaveSettled(): bool {
        if (!function_exists('did_action')) {
            return false;
        }
        foreach (self::SETTLED_HOOKS as $hook) {
            if (did_action($hook) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveThroughFilters(array $context): string {
        $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
        if (function_exists('apply_filters')) {
            $directory = apply_filters('abj404_ajax_trace_directory', $directory, $context);
        }
        // A filter that returns an array or an object without __toString would
        // otherwise warn or fatal on the cast. Anything that is not a scalar
        // reads as "no directory", which every caller already handles.
        $directory = is_scalar($directory) ? (string)$directory : '';
        return $directory === '' ? '' : rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * The uploads directory is per-site on multisite, so a request that
     * switch_to_blog()s must not be handed the previous site's journal path.
     * Read from the global rather than through get_current_blog_id() because
     * this runs during boot, in shutdown handlers, and inside the fatal path,
     * where a function call into WordPress may not be available.
     */
    private static function siteKey(): string {
        $blogId = $GLOBALS['blog_id'] ?? '';
        return is_scalar($blogId) ? (string)$blogId : '';
    }

}
