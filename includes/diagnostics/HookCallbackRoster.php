<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What is actually registered on one WordPress hook right now, described so a
 * human can act on it.
 *
 * WordPress exposes `$wp_filter[$hook]->callbacks` and nothing else: a table of
 * priorities holding entries whose `function` may be a string, a `[$object,
 * 'method']` pair, a static pair, a closure, or an invokable. Three things make
 * that raw table unusable as evidence, and this class owns all three:
 *
 *   1. ORDER. The table is not sorted, so reading it walks callbacks in
 *      insertion order rather than the order WordPress will dispatch them.
 *   2. IDENTITY. WordPress's own registry key is `spl_object_hash($object) .
 *      $method` for an object callback, so it is a different string on every
 *      request; the resolved callable, meanwhile, reads `Closure` for anything a
 *      profiler or an APM agent has wrapped. Either one alone lies. Both are
 *      kept, so a caller can tell "removed" from "wrapped".
 *   3. OWNERSHIP. "Which plugin put this here" is the question a hook roster
 *      exists to answer, and PHP will only tell you via reflection.
 *
 * Cost is split deliberately, because the caller in front of this is on a hot
 * request path: {@see forHook} and {@see fingerprint} do no reflection at all,
 * and only {@see describeEntriesWithOrigins} pays for it.
 *
 * ABJ_404_Solution_HookCallbackIdentity answers a different question about the
 * same subject: it produces PRIVACY-HASHED identities for callbacks the plugin
 * instruments. This class produces NAMED ones for callbacks the plugin reports
 * on, which is what makes a support answer actionable rather than an opaque
 * digest. Neither is a substitute for the other.
 */
final class ABJ_404_Solution_HookCallbackRoster {

    /**
     * One named hook's callbacks, in dispatch order, or null when the registry
     * is absent or is not a shape this can read.
     *
     * Null and empty are different answers and both are returned honestly: an
     * empty array means the hook is there with nothing on it, and null means
     * there was nothing to read. A caller that conflates them would report a
     * missing registry as "every callback has been removed".
     *
     * @return array<int, array{priority: int, index: string, callback: string, function: mixed}>|null
     */
    public static function forHook(string $hookName): ?array {
        $callbacks = self::hookCallbacks($hookName);
        return $callbacks === null ? null : self::entries($callbacks);
    }

    /**
     * A stable structural signature of a roster: priorities and resolved
     * callable names, in dispatch order.
     *
     * Reflection-free on purpose. This is what a caller on a hot path computes
     * to decide whether anything changed since it last looked, so it must be
     * cheap enough to run on every request and stable enough not to differ
     * between two identical ones. That rules out WordPress's registry keys,
     * which carry a per-request object hash.
     *
     * @param array<int, array{priority: int, index: string, callback: string, function: mixed}> $entries
     */
    public static function fingerprint(array $entries): string {
        $parts = array();
        foreach ($entries as $entry) {
            $parts[] = $entry['priority'] . ':' . $entry['callback'];
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * The priority a named callable is registered at, or null when it is not on
     * the hook at all.
     *
     * Decided against BOTH the registry key and the resolved callable. A hook
     * entry still keyed `redirect_canonical` is one WordPress will still
     * dispatch, whatever a profiler wrapped the value with; matching only the
     * resolved side would report a wrapped core callback as gone.
     *
     * @param array<int, array{priority: int, index: string, callback: string, function: mixed}> $entries
     */
    public static function priorityOf(array $entries, string $callbackName): ?int {
        foreach ($entries as $entry) {
            if ($entry['callback'] === $callbackName || $entry['index'] === $callbackName) {
                return $entry['priority'];
            }
        }
        return null;
    }

    /**
     * Entries with their owning component resolved, ready to store or ship.
     *
     * This is the reflecting half, kept separate from everything above so a
     * caller can fingerprint a hook on every request and only pay for
     * attribution when it has decided the answer is worth keeping. $limit bounds
     * both the reflection cost and the size of the result.
     *
     * A wrapped entry additionally carries `registered_as`: the name WordPress
     * still has it filed under, when that differs from what the value resolves
     * to. `{"callback":"Closure","registered_as":"redirect_canonical"}` is a
     * complete account of a profiler-wrapped core callback, where either half
     * alone is misleading. Volatile keys are dropped rather than reported --
     * WordPress builds an object callback's key from spl_object_hash(), which
     * would say nothing to a reader and differ on every request.
     *
     * @param array<int, array{priority: int, index: string, callback: string, function: mixed}> $entries
     * @param int $limit how many entries to describe, from the front (earliest
     *   dispatch order) since those are the ones with the opportunity to change
     *   what the later ones see.
     * @return array<int, array<string, mixed>>
     */
    public static function describeEntriesWithOrigins(array $entries, int $limit): array {
        $described = array();
        foreach (array_slice($entries, 0, max(0, $limit)) as $entry) {
            $record = array(
                'priority' => $entry['priority'],
                'callback' => $entry['callback'],
                'origin' => self::origin($entry['function']),
            );
            if ($entry['index'] !== $entry['callback'] && self::isStableIndex($entry['index'])) {
                $record['registered_as'] = $entry['index'];
            }
            $described[] = $record;
        }
        return $described;
    }

    /**
     * One named hook's raw callback table, or null when the registry is absent
     * or the wrong shape.
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
        if (!is_array($wpFilter)) {
            return null;
        }
        if (!array_key_exists($hookName, $wpFilter)) {
            return array();
        }
        $hook = $wpFilter[$hookName];
        if (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) {
            return $hook->callbacks;
        }
        if (is_array($hook)) {
            return $hook;
        }
        throw new UnexpectedValueException(
            'Malformed WordPress hook registry entry for hook ' . $hookName . '.'
        );
    }

    /**
     * The callback table flattened to one entry per callback, in dispatch order.
     *
     * The sort runs on a by-value copy. That is load-bearing rather than
     * incidental: sorting the live WP_Hook table would reorder the callbacks
     * WordPress is about to run, turning a read-only diagnostic into a
     * site-wide behavior change.
     *
     * @param array<int|string, mixed> $callbacks
     * @return array<int, array{priority: int, index: string, callback: string, function: mixed}>
     */
    private static function entries(array $callbacks): array {
        $entries = array();
        ksort($callbacks, SORT_NUMERIC);
        foreach ($callbacks as $priority => $atPriority) {
            if ((!is_int($priority) && preg_match('/^-?[0-9]+$/', $priority) !== 1)
                    || !is_array($atPriority)) {
                throw new UnexpectedValueException(
                    'Malformed WordPress hook callback priority bucket: ' . (string)$priority . '.'
                );
            }
            foreach ($atPriority as $index => $entry) {
                if (!is_array($entry) || !array_key_exists('function', $entry)
                        || !self::isDescribableCallable($entry['function'])) {
                    throw new UnexpectedValueException(
                        'Malformed WordPress hook callback entry at priority ' . (string)$priority
                        . ' with registry key ' . (string)$index . '.'
                    );
                }
                $function = $entry['function'];
                $entries[] = array(
                    'priority' => (int)$priority,
                    'index' => (string)$index,
                    'callback' => self::describeCallable($function),
                    'function' => $function,
                );
            }
        }
        return $entries;
    }

    /**
     * Whether a registry value carries enough identity to be recorded without
     * turning malformed data into an invented `unknown` callback.
     *
     * This checks shape, not callability: WordPress can retain a named callback
     * whose class has not loaded yet, and that name is still truthful evidence.
     *
     * @param mixed $function
     */
    private static function isDescribableCallable($function): bool {
        if (is_string($function) && $function !== '') {
            return true;
        }
        if ($function instanceof Closure) {
            return true;
        }
        if (is_object($function)) {
            return true;
        }
        return is_array($function) && count($function) === 2
            && (is_object($function[0]) || (is_string($function[0]) && $function[0] !== ''))
            && is_string($function[1]) && $function[1] !== '';
    }

    /**
     * Whether a registry key is worth reporting: a plain function name or a
     * `Class::method` pair, rather than the spl_object_hash-prefixed key
     * WordPress builds for an object callback.
     */
    private static function isStableIndex(string $index): bool {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(::[A-Za-z_][A-Za-z0-9_]*)?$/', $index) === 1;
    }

    /**
     * Which component a callback came from, as `plugin:<dir>`, `mu-plugin:<dir>`,
     * `theme:<dir>`, `wordpress-core`, or `unknown`.
     *
     * The component DIRECTORY is named rather than hashed, because naming the
     * owner is the entire purpose of this field and a hash would leave the
     * reader exactly where they started. It discloses nothing new: a support
     * payload already carries `active_plugins` verbatim. What never leaves is
     * the absolute path, which is site-identifying and answers nothing.
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
            abj404_logPhpFallback('hook-callback-roster',
                'hook callback origin failed (code ' . $e->getCode() . '): ' . $e->getMessage()
                . '. Recovery: verify that the registered callback target is loaded and callable, then retry the census.');
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
     * A callable's stable name. Closures collapse to 'Closure' on purpose: two
     * closures cannot be told apart without reflection, and {@see fingerprint}
     * must stay reflection-free. Their declaring file still reaches a caller
     * through origin() on the describing path.
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
}
