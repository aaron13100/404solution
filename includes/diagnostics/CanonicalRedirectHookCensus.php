<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether WordPress core's own canonical redirect was still going to run, taken
 * on the front end at the moment it would have.
 *
 * WHY THIS EXISTS
 *
 * A captured 404 that core would have canonicalized away looks identical, in
 * every record the plugin keeps, to a 404 that was always broken. Telling them
 * apart needs one fact from the site itself: is `redirect_canonical` still
 * attached to `template_redirect`, and if not, what else is on that hook. The
 * alternative was asking the site owner to run curl and paste the headers back,
 * which is not something a plugin gets to ask of the person it is supposed to be
 * helping. So the plugin answers it.
 *
 * WHY IT IS TAKEN ON THE FRONT END AND NOT WHEN THE REPORT IS BUILT
 *
 * The support request is an admin-ajax request. A great many plugins strip
 * `redirect_canonical` inside an `if (!is_admin())` guard, so reading the hook
 * registry from admin-ajax would answer "attached" on exactly the sites whose
 * canonicalization is suppressed. That is a false negative in the one field this
 * class exists to produce, so the reading is taken where the answer is true: on
 * a real front-end 404, inside `template_redirect`, before core's priority 10.
 *
 * WHY IT IS BOUNDED AND NOT AMBIENT
 *
 * This runs on the 404 path of sites that take thousands of hits to a single bad
 * URL. So the hot path computes only a cheap structural fingerprint (no
 * reflection, no HTTP, no write), and the full walk plus the option write happen
 * only when that fingerprint changed or the stored reading has aged out. A
 * site's hook set is identical on every request, so the steady state is zero
 * writes.
 *
 * Rendering the record into a support payload, inside a byte budget, belongs to
 * ABJ_404_Solution_CanonicalSuppressionSupportSection.
 */
final class ABJ_404_Solution_CanonicalRedirectHookCensus {

    /** The option holding the latest reading. Non-autoloaded; read on demand. */
    const OPTION_NAME = 'abj404_canonical_hook_census';

    /** The record format. Additive changes only; a reader tolerates unknown keys. */
    const RECORD_VERSION = 1;

    /**
     * How many `template_redirect` callbacks are named when core's canonical
     * redirect is gone.
     *
     * The list is a suspect roster, not an inventory: past twenty-five entries a
     * reader has the pattern, and the record has to fit inside a support payload
     * that is already at its byte ceiling. `callbacks_truncated` and
     * `callback_count` keep the true size visible so the cap never disguises how
     * long the real chain was.
     */
    const MAX_CALLBACKS = 25;

    /**
     * How long a reading stays fresh before it is taken again even though
     * nothing changed.
     *
     * Without this the record would keep the timestamp of the first 404 the
     * install ever served, and a reader could not tell an observation
     * contemporaneous with the reported incident from one a year older. One day
     * costs one UPDATE per day per site.
     */
    const REFRESH_AFTER_SECONDS = 86400;

    /** The hook core's canonical redirect is registered on. */
    const HOOK_NAME = 'template_redirect';

    /** The core callback whose absence is the finding. */
    const CORE_CANONICAL_CALLBACK = 'redirect_canonical';

    /** This plugin's own front-end listener, so its priority can be reported. */
    const PLUGIN_LISTENER_CALLBACK = 'abj404_404listener';

    /** Core's canonical redirect is no longer attached to the hook at all. */
    const SUPPRESSION_HOOK_REMOVED = 'core-hook-removed';

    /** Something is on the `redirect_canonical` FILTER and can short-circuit it. */
    const SUPPRESSION_FILTER_HOOKED = 'canonical-filter-hooked';

    /**
     * This plugin's listener is registered ahead of core's canonical redirect,
     * so any request the plugin answers and exits on never reaches it. On a
     * default install (plugin at 9, core at 10) this is the expected state and
     * names the plugin itself rather than a third party.
     */
    const SUPPRESSION_PLUGIN_FIRST = 'plugin-runs-first';

    /**
     * Whether this PHP request has already tried to take the reading.
     *
     * The stored-fingerprint check bounds writes ACROSS requests, and it does
     * that only while the option can actually be persisted. On a read-only
     * replica, or a site whose options table is full, the write silently fails,
     * the next read still sees nothing, and every single 404 pays for the full
     * reflection walk again. This flag is the bound that survives that: at most
     * one attempt per request, whatever the storage is doing.
     *
     * @var bool
     */
    private static $attemptedThisRequest = false;

    /**
     * Test seam: forget that this request already took the reading, so a test
     * can simulate a second front-end request in one PHP process. Same seam
     * ABJ_404_Solution_SameSiteRequestCensus exposes for the same reason.
     *
     * @return void
     */
    public static function resetRequestState(): void {
        self::$attemptedThisRequest = false;
    }

    /**
     * Take the reading if it is worth taking, and store it if it is new.
     *
     * Called from the front-end 404 path. Never throws: a visitor's 404 must not
     * fail because a diagnostic could not be written, and a hook registry that
     * is missing or the wrong shape records nothing rather than a fabricated
     * census.
     *
     * @return void
     */
    public static function recordFromFrontend404(): void {
        if (self::$attemptedThisRequest) {
            return;
        }
        self::$attemptedThisRequest = true;
        try {
            $callbacks = self::hookCallbacks(self::HOOK_NAME);
            if ($callbacks === null) {
                return;
            }
            $fingerprint = self::fingerprint($callbacks);
            $stored = self::read();
            $now = self::now();
            $unchanged = isset($stored['fingerprint']) && $stored['fingerprint'] === $fingerprint;
            $fresh = ($now - self::intIn($stored, 'recorded_at', 0)) < self::REFRESH_AFTER_SECONDS;
            if ($unchanged && $fresh) {
                return;
            }
            self::write(self::census($callbacks, $fingerprint, $now, $stored));
        } catch (Throwable $e) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census failed (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    /**
     * The stored reading, or an empty array when nothing has been recorded.
     * Never throws; an unreadable or malformed record reports as absent rather
     * than propagating into the support request that asked for it.
     *
     * @return array<string, mixed>
     */
    public static function read(): array {
        try {
            if (!function_exists('get_option')) {
                return array();
            }
            $raw = get_option(self::OPTION_NAME, '');
            if (!is_string($raw) || $raw === '') {
                return array();
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        } catch (Throwable $e) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census read failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /**
     * The whole reading, ready to store.
     *
     * @param array<int|string, mixed> $callbacks the hook's own callback table.
     * @param array<string, mixed> $previous the record being replaced, so the
     *   first-observed timestamp survives a re-reading.
     * @return array<string, mixed>
     */
    private static function census(array $callbacks, string $fingerprint, int $now, array $previous): array {
        $entries = self::entries($callbacks);
        $corePriority = self::priorityOf($entries, self::CORE_CANONICAL_CALLBACK);
        $pluginPriority = self::priorityOf($entries, self::PLUGIN_LISTENER_CALLBACK);
        $filterEntries = self::entries(self::hookCallbacks(self::CORE_CANONICAL_CALLBACK) ?? array());

        $suppression = array();
        if ($corePriority === null) {
            $suppression[] = self::SUPPRESSION_HOOK_REMOVED;
        }
        if ($filterEntries !== array()) {
            $suppression[] = self::SUPPRESSION_FILTER_HOOKED;
        }
        if ($corePriority !== null && $pluginPriority !== null && $pluginPriority < $corePriority) {
            $suppression[] = self::SUPPRESSION_PLUGIN_FIRST;
        }

        $record = array(
            'version' => self::RECORD_VERSION,
            'recorded_at' => $now,
            'first_recorded_at' => self::intIn($previous, 'first_recorded_at', $now),
            'fingerprint' => $fingerprint,
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'hook' => self::HOOK_NAME,
            'core_canonical' => $corePriority === null ? 'detached' : 'attached',
            'core_canonical_priority' => $corePriority,
            'plugin_listener_priority' => $pluginPriority,
            'callback_count' => count($entries),
            'suppression' => $suppression,
            'canonical_filter' => self::described(array_slice($filterEntries, 0, self::MAX_CALLBACKS)),
        );

        // The full roster is recorded ONLY when core's callback is gone. That is
        // the one branch where a reader has a culprit to find; on an intact hook
        // the same list names nobody and would spend the payload's bytes saying
        // so on every healthy install.
        if ($corePriority === null) {
            $record['callbacks'] = self::described(array_slice($entries, 0, self::MAX_CALLBACKS));
            $record['callbacks_truncated'] = count($entries) > self::MAX_CALLBACKS;
        }
        return $record;
    }

    /**
     * Store the reading. Non-autoloaded on purpose: it is read when a support
     * payload is assembled, not on every page view, and the front-end check that
     * decides whether to write it is already paying for one read.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private static function write(array $record): void {
        if (!function_exists('update_option')) {
            return;
        }
        $encoded = json_encode($record);
        if (!is_string($encoded)) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census could not be encoded: ' . json_last_error_msg());
            return;
        }
        update_option(self::OPTION_NAME, $encoded, false);
    }

    /**
     * One named hook's callback table, or null when the registry is absent or
     * the wrong shape.
     *
     * WordPress presents each hook as a WP_Hook object with a public `callbacks`
     * table; a profiler or a very old install can present a plain array instead.
     * Both are accepted, and anything else is reported as "nothing to read"
     * rather than guessed at.
     *
     * @return array<int|string, mixed>|null
     */
    private static function hookCallbacks(string $hookName): ?array {
        $wpFilter = $GLOBALS['wp_filter'] ?? null;
        $hook = is_array($wpFilter) ? ($wpFilter[$hookName] ?? null) : null;
        if (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) {
            return $hook->callbacks;
        }
        if (is_array($hook)) {
            return $hook;
        }
        return null;
    }

    /**
     * The hook's callbacks flattened to `priority => callable name`, in
     * dispatch order.
     *
     * Names come from the callable itself rather than from WordPress's own index
     * key, because that key is `spl_object_hash($object) . $method` for an object
     * callback and therefore differs on every request. Fingerprinting those keys
     * would make an unchanged site look like it changed on every 404, which is
     * the write amplification this class is built to avoid.
     *
     * @param array<int|string, mixed> $callbacks
     * @return array<int, array{priority: int, callback: string, function: mixed}>
     */
    private static function entries(array $callbacks): array {
        $entries = array();
        ksort($callbacks, SORT_NUMERIC);
        foreach ($callbacks as $priority => $atPriority) {
            if (!is_array($atPriority)) {
                continue;
            }
            foreach ($atPriority as $entry) {
                $function = is_array($entry) ? ($entry['function'] ?? null) : null;
                $entries[] = array(
                    'priority' => (int)$priority,
                    'callback' => self::describeCallable($function),
                    'function' => $function,
                );
            }
        }
        return $entries;
    }

    /**
     * The priority a named callable is registered at, or null when it is not on
     * the hook at all. Null for `redirect_canonical` IS the finding.
     *
     * @param array<int, array{priority: int, callback: string, function: mixed}> $entries
     */
    private static function priorityOf(array $entries, string $callbackName): ?int {
        foreach ($entries as $entry) {
            if ($entry['callback'] === $callbackName) {
                return $entry['priority'];
            }
        }
        return null;
    }

    /**
     * Entries with their owning component resolved, ready to store.
     *
     * Resolving the owner costs one reflection per callback, so it happens here
     * -- on the write path, which runs when the hook set changes -- and never on
     * the fingerprint path that every 404 pays for.
     *
     * @param array<int, array{priority: int, callback: string, function: mixed}> $entries
     * @return array<int, array{priority: int, callback: string, origin: string}>
     */
    private static function described(array $entries): array {
        $described = array();
        foreach ($entries as $entry) {
            $described[] = array(
                'priority' => $entry['priority'],
                'callback' => $entry['callback'],
                'origin' => self::origin($entry['function']),
            );
        }
        return $described;
    }

    /**
     * Which component a callback came from, as `plugin:<dir>`, `mu-plugin:<dir>`,
     * `theme:<dir>`, `wordpress-core`, or `unknown`.
     *
     * The component DIRECTORY is named rather than hashed, because naming the
     * culprit is the entire purpose of this field and a hash would leave the
     * reader exactly where the curl request left them. It discloses nothing new:
     * the same payload already carries `active_plugins` verbatim. What never
     * leaves is the absolute path, which is site-identifying and answers nothing.
     *
     * @param mixed $function
     */
    private static function origin($function): string {
        try {
            $file = self::sourceFileOf($function);
            if ($file === '') {
                return 'unknown';
            }
            $normalized = str_replace('\\', '/', $file);
            $labels = array('plugins' => 'plugin', 'mu-plugins' => 'mu-plugin', 'themes' => 'theme');
            foreach ($labels as $directory => $label) {
                if (preg_match('#/wp-content/' . $directory . '/([^/]+)#i', $normalized, $match) === 1) {
                    return $label . ':' . $match[1];
                }
            }
            if (strpos($normalized, '/wp-includes/') !== false
                    || strpos($normalized, '/wp-admin/') !== false) {
                return 'wordpress-core';
            }
            return 'unknown';
        } catch (Throwable $e) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook callback origin failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return 'unknown';
        }
    }

    /**
     * The file a callable was declared in, or '' when reflection cannot say.
     *
     * @param mixed $function
     * @throws ReflectionException when the callable names a target that does not exist.
     */
    private static function sourceFileOf($function): string {
        if (is_string($function) && strpos($function, '::') !== false) {
            return (string)(new ReflectionMethod($function))->getFileName();
        }
        if (is_string($function)) {
            return function_exists($function)
                ? (string)(new ReflectionFunction($function))->getFileName() : '';
        }
        if (is_array($function) && count($function) === 2
                && (is_object($function[0]) || is_string($function[0]))
                && is_string($function[1])) {
            return (string)(new ReflectionMethod($function[0], $function[1]))->getFileName();
        }
        if ($function instanceof Closure) {
            return (string)(new ReflectionFunction($function))->getFileName();
        }
        if (is_object($function) && method_exists($function, '__invoke')) {
            return (string)(new ReflectionMethod($function, '__invoke'))->getFileName();
        }
        return '';
    }

    /**
     * A stable structural signature of the hook's callbacks: priorities and
     * callable names, in dispatch order.
     *
     * Cheap by construction. This is the only work every 404 pays for, so it
     * does no reflection, touches no option beyond the one read it is compared
     * against, and makes no outbound request.
     *
     * @param array<int|string, mixed> $callbacks
     */
    private static function fingerprint(array $callbacks): string {
        $parts = array();
        foreach (self::entries($callbacks) as $entry) {
            $parts[] = $entry['priority'] . ':' . $entry['callback'];
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * A callable's stable name. Closures collapse to 'Closure' on purpose: two
     * closures cannot be told apart without reflection, and the fingerprint this
     * feeds must stay reflection-free. Their declaring file still reaches the
     * record through origin() on the write path.
     *
     * @param mixed $function
     */
    private static function describeCallable($function): string {
        if (is_string($function)) {
            return $function;
        }
        if (is_array($function) && count($function) === 2) {
            $target = $function[0];
            $owner = is_object($target) ? get_class($target)
                : (is_string($target) ? $target : 'unknown');
            return $owner . '::' . (is_string($function[1]) ? $function[1] : 'unknown');
        }
        if ($function instanceof Closure) {
            return 'Closure';
        }
        if (is_object($function)) {
            return get_class($function) . '::__invoke';
        }
        return 'unknown';
    }

    /**
     * One integer field out of a decoded record, or $fallback when the field is
     * absent or is not something an integer can be read from. The record came
     * out of an options row that any other code, or a hand edit, could have
     * left in any shape, so it is validated rather than trusted.
     *
     * @param array<string, mixed> $record
     */
    private static function intIn(array $record, string $field, int $fallback): int {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (int)$value : $fallback;
    }

    /** Epoch seconds, or 0 when no clock is reachable (a boot-order edge). */
    private static function now(): int {
        if (function_exists('abj_clock')) {
            return (int)abj_clock()->now();
        }
        return function_exists('abj404_now') ? abj404_now() : 0;
    }
}
