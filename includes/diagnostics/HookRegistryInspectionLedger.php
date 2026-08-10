<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which hook registries this instrumentation scope has already inspected, and
 * whether inspecting one again would see anything new.
 *
 * An `all` observer runs before EVERY named hook, so a render scope's observer
 * is asked to instrument a registry once per firing rather than once per hook.
 * Measured on the owner's plugin-heavy localhost on 2026-08-09 (2,063 registered
 * hooks / 4,024 callbacks across ten plugins): one part=all table AJAX request
 * fired `all` 23,609 times across 1,925 distinct hooks. Inspecting per firing
 * meant ~92% of those inspections re-walked a registry that had not changed
 * since the previous firing, and each walk wrote its own lifecycle records,
 * which resolved the diagnostic directory, which fired more hooks.
 *
 * This ledger is what makes the walk once-per-shape instead of once-per-firing.
 * It answers from a shape string cheap enough to compute on every firing:
 * the hook object's identity plus its entry count per priority.
 *
 * TWO INVARIANTS, and both are load-bearing:
 *
 *   1. The probe NEVER executes foreign code and NEVER mutates. It reads
 *      $GLOBALS['wp_filter'] as a plain array and reaches the callback table
 *      through get_object_vars(), which returns public properties without
 *      invoking __get(). It does not iterate the hook object, because a foreign
 *      hook object's iterator is foreign code -- and running foreign code on
 *      every firing is the cost this class exists to remove.
 *   2. A registry it cannot read that way is UNPROBEABLE, never "unchanged".
 *      Unprobeable always re-inspects, so an unusual hook object degrades to
 *      exactly the fully lifecycle-traced walk it got before this class existed.
 *      Guessing "probably unchanged" would silently drop foreign-callback
 *      coverage, which is the whole point of the instrumentation.
 *
 * WHAT THE SHAPE CANNOT SEE, stated because a support engineer reading an
 * incomplete attribution deserves to know: a registry that swaps one callback
 * for another at the same priority between two firings keeps its count, so the
 * replacement is not wrapped until something else changes the shape. Counting is
 * what keeps the probe O(priorities); comparing identities would restore the
 * per-firing walk this removes. spl_object_id() narrows it further by catching a
 * wholesale replacement of the hook object, though ids are recycled once an
 * object is freed, so an identically-shaped replacement that inherits the freed
 * id also reads as unchanged.
 */
final class ABJ_404_Solution_HookRegistryInspectionLedger {

    /** The shape of a hook name that is not registered at all. */
    const ABSENT = 'absent';

    /**
     * The shape of a registry that cannot be read without running foreign code.
     * Empty on purpose: a real shape always begins with an object id, so this
     * value can never compare equal to one.
     */
    const UNPROBEABLE = '';

    /** @var array<string, string> Registry shape at last inspection, by hook name. */
    private $shapes = array();

    /**
     * True when the caller must walk this hook's registry: either it changed
     * since the last inspection, or its shape cannot be read cheaply.
     */
    public function needsInspection(string $hookName): bool {
        $shape = self::shape($hookName);
        return $shape === self::UNPROBEABLE
            || ($this->shapes[$hookName] ?? null) !== $shape;
    }

    /**
     * Remember the shape a completed inspection leaves behind. Read after the
     * walk rather than before it, because instrumenting a by-reference callback
     * adds a marker entry and therefore changes the very count being recorded.
     */
    public function recordInspected(string $hookName): void {
        $shape = self::shape($hookName);
        if ($shape !== self::UNPROBEABLE) {
            $this->shapes[$hookName] = $shape;
        }
    }

    /** Forget everything: the scope that made these observations has ended. */
    public function clear(): void {
        $this->shapes = array();
    }

    /**
     * The current shape of one hook's callback registry, or UNPROBEABLE.
     *
     * Pure array reads only, per invariant 1 above.
     */
    private static function shape(string $hookName): string {
        $filters = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($filters)) {
            return self::UNPROBEABLE;
        }
        if (!array_key_exists($hookName, $filters)) {
            return self::ABSENT;
        }
        $hookObject = $filters[$hookName];
        if (!is_object($hookObject)) {
            return self::UNPROBEABLE;
        }
        $callbacks = get_object_vars($hookObject)['callbacks'] ?? null;
        if (!is_array($callbacks)) {
            return self::UNPROBEABLE;
        }
        $shape = (string)spl_object_id($hookObject);
        foreach ($callbacks as $priority => $entries) {
            $shape .= '|' . $priority . ':' . (is_array($entries) ? count($entries) : -1);
        }
        return $shape;
    }
}
