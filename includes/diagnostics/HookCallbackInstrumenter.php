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
 * Registry mutation is lifecycle-traced here; callers own callback records.
 *
 * allow-no-test-found: exercised through real AJAX hook dispatch in tests/OptionPersistenceTracerTest.php, tests/AjaxHookCallbackAttributionTest.php, and tests/TableRendererPreludeTracerTest.php
 *
 * @phpstan-type CallbackIdentity array{callback: string, source: string, has_reference: bool}
 * The registration shapes are defined by the class that stores them, so a
 * change to what a registration carries cannot leave the two files disagreeing.
 *
 * @phpstan-import-type WrapperRegistration from ABJ_404_Solution_HookInstrumentationRegistry
 * @phpstan-import-type MarkerRegistration from ABJ_404_Solution_HookInstrumentationRegistry
 * @phpstan-import-type Registration from ABJ_404_Solution_HookInstrumentationRegistry
 * @phpstan-type LifecycleToken array{operation_id: string, phase: string, component: string, hook: string, priority: int|null, callback_ordinal: int}
 * @phpstan-type InstrumentationCounts array{callbacks_wrapped: int, callbacks_marked: int, callbacks_unavailable: int, registry_status: string, registry_reason: string}
 * @template TToken
 */
final class ABJ_404_Solution_HookCallbackInstrumenter {

    /** @var callable(string, string, int, CallbackIdentity, int): TToken */
    private $start;

    /** @var callable(TToken): void */
    private $end;

    /** @var array<int, TToken> */
    private $pendingTokens = array();

    /** @var ABJ_404_Solution_HookInstrumentationRegistry */
    private $registry;

    /** @var ABJ_404_Solution_HookRegistryInspectionLedger */
    private $inspections;

    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    /**
     * @param callable(string, string, int, CallbackIdentity, int): TToken $start
     * @param callable(TToken): void $end
     */
    public function __construct(
        callable $start,
        callable $end,
        ABJ_404_Solution_HookInstrumentationLifecycleTracer $lifecycleTracer
    ) {
        $this->start = $start;
        $this->end = $end;
        $this->lifecycleTracer = $lifecycleTracer;
        $this->registry = new ABJ_404_Solution_HookInstrumentationRegistry();
        $this->inspections = new ABJ_404_Solution_HookRegistryInspectionLedger();
    }

    /**
     * Ensure every callable entry currently registered on one hook is
     * instrumented, and report what this call added.
     *
     * Idempotent for a given registry shape: an `all` observer calls this once
     * per hook FIRING, and a scope's hooks fire tens of thousands of times, so a
     * hook whose registry has not changed since its last inspection is answered
     * from ABJ_404_Solution_HookRegistryInspectionLedger without a walk. The
     * counts are of what this call newly instrumented, which is why the
     * unchanged answer is zeros rather than the earlier call's totals.
     *
     * @return InstrumentationCounts registry_status is 'unchanged' when the
     *   registry was already instrumented and has not changed since.
     */
    public function instrument(string $hookName): array {
        $counts = array(
            'callbacks_wrapped' => 0,
            'callbacks_marked' => 0,
            'callbacks_unavailable' => 0,
            'registry_status' => 'ready',
            'registry_reason' => '',
        );
        if (!$this->inspections->needsInspection($hookName)) {
            $counts['registry_status'] = 'unchanged';
            return $counts;
        }
        $this->inspectRegistry($hookName, $counts);
        $this->inspections->recordInspected($hookName);
        return $counts;
    }

    /**
     * Walk one hook's live registry and instrument what is not instrumented yet.
     *
     * Registry lookup is owned here so the lifecycle start is durable before
     * the first global or hook-object access.
     *
     * @param InstrumentationCounts $counts
     */
    private function inspectRegistry(string $hookName, array &$counts): void {
        $lifecycleToken = $this->lifecycleTracer->begin('install', $hookName);
        $filters = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($filters)) {
            $counts['registry_status'] = 'unavailable';
            $counts['registry_reason'] = 'hook_registry_unavailable';
            $this->lifecycleTracer->complete(
                $lifecycleToken,
                'unavailable',
                'hook_registry_unavailable'
            );
            return;
        }
        if (!array_key_exists($hookName, $filters)) {
            $counts['registry_status'] = 'absent';
            $this->lifecycleTracer->complete($lifecycleToken, 'absent', 'hook_not_registered');
            return;
        }
        $hookObject = $filters[$hookName];
        if (!$hookObject instanceof ArrayAccess || !$hookObject instanceof Traversable) {
            $counts['callbacks_unavailable']++;
            $counts['registry_status'] = 'malformed';
            $counts['registry_reason'] = 'hook_object_unavailable';
            $this->lifecycleTracer->complete(
                $lifecycleToken,
                'malformed',
                'hook_object_unavailable'
            );
            return;
        }

        $this->discardStaleRegistrations($hookName, $hookObject, $lifecycleToken);
        foreach ($hookObject as $priority => $entries) {
            $lifecycleToken = $this->lifecycleTracer->advance(
                $lifecycleToken,
                (int)$priority,
                0
            );
            if (!is_array($entries)) {
                $counts['callbacks_unavailable']++;
                continue;
            }
            $instrumented = $this->instrumentPriority(
                $hookName,
                (int)$priority,
                $entries,
                $counts,
                $lifecycleToken
            );
            $lifecycleToken = $this->lifecycleTracer->advance(
                $lifecycleToken,
                (int)$priority,
                count($entries)
            );
            $hookObject[$priority] = $instrumented;
        }
        $this->lifecycleTracer->complete($lifecycleToken);
    }

    /**
     * @param ArrayAccess<mixed, mixed>&Traversable<mixed, mixed> $hookObject
     * @param LifecycleToken $lifecycleToken
     */
    private function discardStaleRegistrations(
        string $hookName,
        object $hookObject,
        array &$lifecycleToken
    ): void {
        foreach ($this->registry->forHook($hookName) as $key => $registration) {
            $lifecycleToken = $this->lifecycleTracer->advance(
                $lifecycleToken,
                $registration['priority'],
                $registration['ordinal']
            );
            $entries = isset($hookObject[$registration['priority']])
                ? $hookObject[$registration['priority']]
                : null;
            if (!is_array($entries)) {
                $this->registry->forget($key);
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
            $this->registry->forget($key);
        }
    }

    /**
     * Restore wrappers and remove markers without overwriting foreign changes.
     */
    public function restore(bool $scopeCompleted = true): void {
        foreach ($this->registry->all() as $registration) {
            $lifecycleToken = $this->lifecycleTracer->begin('restore', $registration['hook']);
            $lifecycleToken = $this->lifecycleTracer->advance(
                $lifecycleToken,
                $registration['priority'],
                $registration['ordinal']
            );
            $filters = $GLOBALS['wp_filter'] ?? null;
            if (!is_array($filters)) {
                $this->lifecycleTracer->complete(
                    $lifecycleToken,
                    'unavailable',
                    'hook_registry_unavailable'
                );
                continue;
            }
            $hookObject = $filters[$registration['hook']] ?? null;
            if (!$hookObject instanceof ArrayAccess
                    || !isset($hookObject[$registration['priority']])) {
                $this->lifecycleTracer->complete(
                    $lifecycleToken,
                    'absent',
                    'hook_or_priority_absent'
                );
                continue;
            }
            $entries = $hookObject[$registration['priority']];
            if (!is_array($entries)) {
                $this->lifecycleTracer->complete(
                    $lifecycleToken,
                    'malformed',
                    'priority_entries_unavailable'
                );
                continue;
            }
            if ($registration['mode'] === 'wrapper') {
                $this->restoreWrapper($entries, $registration);
            } else {
                $this->removeMarker($entries, $registration);
            }
            $hookObject[$registration['priority']] = $entries;
            $this->lifecycleTracer->complete($lifecycleToken);
        }
        $this->registry->clear();
        $this->inspections->clear();
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
     * @param InstrumentationCounts $counts
     * @param LifecycleToken $lifecycleToken
     * @return array<array-key, mixed>
     */
    private function instrumentPriority(
        string $hookName,
        int $priority,
        array $entries,
        array &$counts,
        array &$lifecycleToken
    ): array {
        $result = array();
        $callbackOrdinal = 0;
        foreach ($entries as $id => $entry) {
            $callbackOrdinal++;
            $lifecycleToken = $this->lifecycleTracer->advance(
                $lifecycleToken,
                $priority,
                $callbackOrdinal
            );
            if ($this->registry->ownsCallback(is_array($entry) ? ($entry['function'] ?? null) : null)) {
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
            if (self::isInternalDiagnosticObserver($callback)) {
                $result[$id] = $entry;
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
                    $identity,
                    $callbackOrdinal
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
                $identity,
                $callbackOrdinal
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
        $key = ABJ_404_Solution_HookInstrumentationRegistration::key($hook, $priority, $id);
        $registration = $this->registry->get($key);
        if ($registration === null || !is_array($entry)) {
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

    /** @param callable $callback */
    private static function isInternalDiagnosticObserver($callback): bool {
        return is_array($callback)
            && is_object($callback[0] ?? null)
            && $callback[0] instanceof ABJ_404_Solution_DiagnosticInternalHookObserver;
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
        array $identity,
        int $ordinal
    ): array {
        $wrapper = function (...$args) use ($hook, $priority, $callback, $identity, $ordinal) {
            $actualHook = self::actualHook($hook, $args);
            $token = call_user_func($this->start, $hook, $actualHook, $priority, $identity, $ordinal);
            $result = call_user_func_array($callback, $args);
            call_user_func($this->end, $token);
            return $result;
        };
        $entry['function'] = $wrapper;
        $key = ABJ_404_Solution_HookInstrumentationRegistration::key($hook, $priority, $id);
        $this->registry->add($key, array(
            'mode' => 'wrapper',
            'hook' => $hook,
            'priority' => $priority,
            'ordinal' => $ordinal,
            'id' => $id,
            'original' => $callback,
            'wrapper' => $wrapper,
        ));
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
        array $identity,
        int $ordinal
    ): void {
        $before = function ($value = null, ...$args) use (
            $hook,
            $priority,
            $identity, $ordinal
        ) {
            $actualHook = self::actualHook($hook, array_merge(array($value), $args));
            $token = call_user_func($this->start, $hook, $actualHook, $priority, $identity, $ordinal);
            if ($token !== null) {
                $this->pendingTokens[] = $token;
            }
            return $value;
        };
        $beforeId = ABJ_404_Solution_HookInstrumentationRegistration::markerId(
            'before',
            $hook,
            $priority,
            $id,
            $existing,
            $result
        );
        $result[$beforeId] = array('function' => $before, 'accepted_args' => 1);
        $result[$id] = $entry;
        $key = ABJ_404_Solution_HookInstrumentationRegistration::key($hook, $priority, $id);
        $this->registry->add($key, array(
            'mode' => 'marker',
            'hook' => $hook,
            'priority' => $priority,
            'ordinal' => $ordinal,
            'id' => $id,
            'original' => $callback,
            'before_id' => $beforeId,
            'before' => $before,
        ));
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

}
