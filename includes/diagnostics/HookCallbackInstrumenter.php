<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reversible WP_Hook callback instrumentation that preserves signatures.
 *
 * Ordinary callbacks can be replaced by a variadic wrapper. A callback with a
 * reference parameter or reference return cannot: changing that signature can
 * change caller-visible behavior. Those callbacks remain registered exactly as
 * supplied, with a value-preserving marker immediately before their WP_Hook
 * entry. Their end records are written only when the owning tracer scope
 * completes successfully. This avoids adding a post-callback filter step,
 * which could overwrite a callback's by-reference mutation.
 *
 * The adapter owns only registry mutation. Callers own record fields, budgets,
 * and persistence through the supplied start/end functions.
 *
 * allow-no-test-found: exercised through real AJAX hook dispatch in tests/OptionPersistenceTracerTest.php, tests/AjaxQueryAttributionTest.php, and tests/TableRendererPreludeTracerTest.php
 *
 * @phpstan-type CallbackIdentity array{callback: string, source: string, has_reference: bool}
 * @phpstan-type WrapperRegistration array{mode: 'wrapper', hook: string, priority: int, id: string, original: callable, wrapper: callable}
 * @phpstan-type MarkerRegistration array{mode: 'marker', hook: string, priority: int, id: string, original: callable, before_id: string, before: callable}
 * @template TToken
 */
final class ABJ_404_Solution_HookCallbackInstrumenter {

    /** @var callable(string, string, int, CallbackIdentity): TToken */
    private $start;

    /** @var callable(TToken): void */
    private $end;

    /** @var array<int, TToken> */
    private $pendingTokens = array();

    /** @var array<string, WrapperRegistration|MarkerRegistration> */
    private $registrations = array();

    /**
     * @param callable(string, string, int, CallbackIdentity): TToken $start
     * @param callable(TToken): void $end
     */
    public function __construct(callable $start, callable $end) {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Instrument every callable entry currently registered on one hook.
     *
     * @return array{callbacks_wrapped: int, callbacks_marked: int, callbacks_unavailable: int}
     */
    public function instrument(string $hookName, object $hookObject): array {
        $counts = array(
            'callbacks_wrapped' => 0,
            'callbacks_marked' => 0,
            'callbacks_unavailable' => 0,
        );
        if (!$hookObject instanceof ArrayAccess || !$hookObject instanceof Traversable) {
            $counts['callbacks_unavailable']++;
            return $counts;
        }

        $this->discardStaleRegistrations($hookName, $hookObject);
        foreach ($hookObject as $priority => $entries) {
            if (!is_array($entries)) {
                $counts['callbacks_unavailable']++;
                continue;
            }
            $instrumented = $this->instrumentPriority(
                $hookName,
                (int)$priority,
                $entries,
                $counts
            );
            $hookObject[$priority] = $instrumented;
        }
        return $counts;
    }

    /**
     * @param ArrayAccess<mixed, mixed>&Traversable<mixed, mixed> $hookObject
     */
    private function discardStaleRegistrations(string $hookName, object $hookObject): void {
        foreach ($this->registrations as $key => $registration) {
            if ($registration['hook'] !== $hookName) {
                continue;
            }
            $entries = isset($hookObject[$registration['priority']])
                ? $hookObject[$registration['priority']]
                : null;
            if (!is_array($entries)) {
                unset($this->registrations[$key]);
                continue;
            }
            $entry = $entries[$registration['id']] ?? null;
            $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
            $beforeEntry = $registration['mode'] === 'marker'
                ? ($entries[$registration['before_id']] ?? null)
                : null;
            $valid = $registration['mode'] === 'wrapper'
                ? $callback === $registration['wrapper']
                : $callback === $registration['original']
                    && is_array($beforeEntry)
                    && ($beforeEntry['function'] ?? null) === $registration['before'];
            if ($valid) {
                continue;
            }
            if ($registration['mode'] === 'marker') {
                $this->removeMarker($entries, $registration);
                $hookObject[$registration['priority']] = $entries;
            }
            unset($this->registrations[$key]);
        }
    }

    /**
     * Restore wrappers and remove markers without overwriting foreign changes.
     */
    public function restore(bool $scopeCompleted = true): void {
        foreach ($this->registrations as $registration) {
            $filters = $GLOBALS['wp_filter'] ?? null;
            $hookObject = is_array($filters)
                ? ($filters[$registration['hook']] ?? null)
                : null;
            if (!$hookObject instanceof ArrayAccess
                    || !isset($hookObject[$registration['priority']])) {
                continue;
            }
            $entries = $hookObject[$registration['priority']];
            if (!is_array($entries)) {
                continue;
            }
            if ($registration['mode'] === 'wrapper') {
                $this->restoreWrapper($entries, $registration);
            } else {
                $this->removeMarker($entries, $registration);
            }
            $hookObject[$registration['priority']] = $entries;
        }
        $this->registrations = array();
        $tokens = $this->pendingTokens;
        $this->pendingTokens = array();
        if ($scopeCompleted) {
            foreach ($tokens as $token) {
                call_user_func($this->end, $token);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param array{callbacks_wrapped: int, callbacks_marked: int, callbacks_unavailable: int} $counts
     * @return array<array-key, mixed>
     */
    private function instrumentPriority(
        string $hookName,
        int $priority,
        array $entries,
        array &$counts
    ): array {
        $result = array();
        foreach ($entries as $id => $entry) {
            if ($this->isOwnedInstrumentationEntry($entry)) {
                $result[$id] = $entry;
                continue;
            }
            if ($this->isInstalledEntry($hookName, $priority, (string)$id, $entry, $entries)) {
                $result[$id] = $entry;
                continue;
            }
            if (!is_array($entry)) {
                $result[$id] = $entry;
                $counts['callbacks_unavailable']++;
                continue;
            }
            $callback = $entry['function'] ?? null;
            if (!is_callable($callback)) {
                $result[$id] = $entry;
                $counts['callbacks_unavailable']++;
                continue;
            }
            $identity = ABJ_404_Solution_HookCallbackIdentity::describe($callback);
            if ($identity['has_reference']) {
                $this->addMarkedEntry(
                    $result,
                    $entries,
                    $hookName,
                    $priority,
                    (string)$id,
                    $entry,
                    $callback,
                    $identity
                );
                $counts['callbacks_marked']++;
                continue;
            }
            $result[$id] = $this->wrappedEntry(
                $hookName,
                $priority,
                (string)$id,
                $entry,
                $callback,
                $identity
            );
            $counts['callbacks_wrapped']++;
        }
        return $result;
    }

    /**
     * @param mixed $entry
     * @param array<array-key, mixed> $entries
     */
    private function isInstalledEntry(
        string $hook,
        int $priority,
        string $id,
        $entry,
        array $entries
    ): bool {
        $key = self::registrationKey($hook, $priority, $id);
        $registration = $this->registrations[$key] ?? null;
        if (!is_array($registration) || !is_array($entry)) {
            return false;
        }
        $callback = $entry['function'] ?? null;
        if ($registration['mode'] === 'wrapper') {
            return $callback === $registration['wrapper'];
        }
        $beforeEntry = $entries[$registration['before_id']] ?? null;
        return $callback === $registration['original']
            && is_array($beforeEntry)
            && ($beforeEntry['function'] ?? null) === $registration['before'];
    }

    /** @param mixed $entry */
    private function isOwnedInstrumentationEntry($entry): bool {
        $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
        foreach ($this->registrations as $registration) {
            foreach (array('wrapper', 'before') as $field) {
                if (isset($registration[$field]) && $callback === $registration[$field]) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param callable $callback
     * @param CallbackIdentity $identity
     * @return array<string, mixed>
     */
    private function wrappedEntry(
        string $hook,
        int $priority,
        string $id,
        array $entry,
        callable $callback,
        array $identity
    ): array {
        $wrapper = function (...$args) use ($hook, $priority, $callback, $identity) {
            $actualHook = self::actualHook($hook, $args);
            $token = call_user_func($this->start, $hook, $actualHook, $priority, $identity);
            $result = call_user_func_array($callback, $args);
            call_user_func($this->end, $token);
            return $result;
        };
        $entry['function'] = $wrapper;
        $key = self::registrationKey($hook, $priority, $id);
        $this->registrations[$key] = array(
            'mode' => 'wrapper',
            'hook' => $hook,
            'priority' => $priority,
            'id' => $id,
            'original' => $callback,
            'wrapper' => $wrapper,
        );
        return $entry;
    }

    /**
     * @param array<array-key, mixed> $result
     * @param array<array-key, mixed> $existing
     * @param array<string, mixed> $entry
     * @param callable $callback
     * @param CallbackIdentity $identity
     */
    private function addMarkedEntry(
        array &$result,
        array $existing,
        string $hook,
        int $priority,
        string $id,
        array $entry,
        callable $callback,
        array $identity
    ): void {
        $before = function ($value = null, ...$args) use (
            $hook,
            $priority,
            $identity
        ) {
            $actualHook = self::actualHook($hook, array_merge(array($value), $args));
            $token = call_user_func($this->start, $hook, $actualHook, $priority, $identity);
            if ($token !== null) {
                $this->pendingTokens[] = $token;
            }
            return $value;
        };
        $beforeId = self::markerId('before', $hook, $priority, $id, $existing, $result);
        $result[$beforeId] = array('function' => $before, 'accepted_args' => 1);
        $result[$id] = $entry;
        $key = self::registrationKey($hook, $priority, $id);
        $this->registrations[$key] = array(
            'mode' => 'marker',
            'hook' => $hook,
            'priority' => $priority,
            'id' => $id,
            'original' => $callback,
            'before_id' => $beforeId,
            'before' => $before,
        );
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param WrapperRegistration $registration
     */
    private function restoreWrapper(array &$entries, array $registration): void {
        $entry = $entries[$registration['id']] ?? null;
        if (is_array($entry) && ($entry['function'] ?? null) === $registration['wrapper']) {
            $entry['function'] = $registration['original'];
            $entries[$registration['id']] = $entry;
        }
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param MarkerRegistration $registration
     */
    private function removeMarker(array &$entries, array $registration): void {
        $id = $registration['before_id'];
        $entry = $entries[$id] ?? null;
        if (is_array($entry) && ($entry['function'] ?? null) === $registration['before']) {
            unset($entries[$id]);
        }
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function actualHook(string $registeredHook, array $args): string {
        return $registeredHook === 'all' && is_string($args[0] ?? null)
            ? $args[0]
            : $registeredHook;
    }

    private static function registrationKey(string $hook, int $priority, string $id): string {
        return $hook . '|' . $priority . '|' . $id;
    }

    /**
     * @param array<array-key, mixed> $existing
     * @param array<array-key, mixed> $result
     */
    private static function markerId(
        string $position,
        string $hook,
        int $priority,
        string $id,
        array $existing,
        array $result
    ): string {
        $base = 'abj404-trace-' . $position . '-'
            . substr(hash('sha256', $hook . '|' . $priority . '|' . $id), 0, 16);
        $candidate = $base;
        $suffix = 0;
        while (array_key_exists($candidate, $existing) || array_key_exists($candidate, $result)) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }
        return $candidate;
    }
}
