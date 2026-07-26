<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable registry identities for reversible hook instrumentation entries.
 *
 * The instrumenter owns mutation and restoration. This collaborator owns only
 * collision-safe keys for remembering those mutations.
 */
final class ABJ_404_Solution_HookInstrumentationRegistration {

    public static function key(string $hook, int $priority, string $id): string {
        return $hook . '|' . $priority . '|' . $id;
    }

    /**
     * @param array<array-key, mixed> $existing
     * @param array<array-key, mixed> $result
     */
    public static function markerId(
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
