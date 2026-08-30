<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The persisted attempt counter for one detach A/B workload scope.
 *
 * Split out of ABJ_404_Solution_DetachAbExperiment, which decides WHICH mode an
 * attempt slot receives and had no business also owning where the slot number
 * is stored. The experiment is policy; this is the data access behind it.
 *
 * The store is a transient and the read-modify-write is deliberately not
 * atomic. This is a bounded diagnostic sequence, not a security limit: two
 * tabs racing can land on the same slot, which costs one unusable pair in the
 * evidence, and that is cheaper than coupling diagnostics policy to the
 * database layer. The race is recorded here as a known property rather than
 * left for a reader to infer from the absence of a lock.
 */
final class ABJ_404_Solution_DetachAbAttemptCounter {

    /** No slot could be reserved. Callers answer inert rather than guessing one. */
    const NO_SLOT = -1;

    /** How long an idle scope keeps its place in the sequence. */
    const DEFAULT_TTL_SECONDS = 3600;

    /**
     * Reserve and return this scope's next attempt slot.
     *
     * Returns NO_SLOT when the scope cannot carry a counter, when the transient
     * API is unavailable, or when the reservation could not be persisted.
     *
     * That last case used to return the slot anyway. A failed write leaves the
     * stored counter where it was, so every later request in the session read
     * the same value and was handed attempt zero again: the sequence could
     * never reach MAX_ATTEMPTS, never revert to `default`, and the experiment's
     * promise that a probe never permanently degrades an admin session was
     * broken by precisely the infrastructure failure the plugin is built to
     * degrade past. An unreserved slot is not a slot.
     */
    public static function reserveNextSlot(ABJ_404_Solution_DetachAbScope $scope): int {
        if ($scope->isSessionless()
            || !function_exists('get_transient')
            || !function_exists('set_transient')) {
            return self::NO_SLOT;
        }

        $key = $scope->transientKey();
        $stored = ABJ_404_Solution_DetachAbResolutionTracer::traceTransientOperation(
            'get_transient',
            $key,
            static function () use ($key) {
                return get_transient($key);
            }
        );
        $slot = self::storedSlot($stored);

        // allow-cache-empty: locally computed attempt counter (always a valid
        // non-negative int), not a fetched query result.
        $persisted = ABJ_404_Solution_DetachAbResolutionTracer::traceTransientOperation(
            'set_transient',
            $key,
            static function () use ($key, $slot) {
                // allow-cache-empty: the locally computed attempt counter is a
                // valid non-negative integer, never a fetched result.
                return set_transient($key, $slot + 1, self::DEFAULT_TTL_SECONDS);
            }
        );

        return $persisted ? $slot : self::NO_SLOT;
    }

    /**
     * Interpret a stored counter, accepting only a canonical non-negative integer.
     *
     * is_numeric() plus an int cast accepted '2.9' and '1e3' and turned them
     * into 2 and 1000, so a corrupted or foreign value under this key was
     * promoted into a real-looking slot and journalled as A/B coordinates --
     * fabricated evidence, which is worse for a diagnostic than no evidence.
     * Anything that is not a counter starts the sequence over; the write that
     * follows immediately replaces it with a canonical value, so a garbled
     * store self-heals on the next request instead of resetting forever.
     *
     * ABJ_404_Solution_ExactInteger is that rule, already generalised: its own
     * docblock names this experiment's attempt ordinal as one of the two places
     * that discovered the defect independently. This counter is the third, and
     * hand-rolling a fourth narrow pattern here is how there would be a fifth.
     *
     * @param mixed $stored
     */
    private static function storedSlot($stored): int {
        return ABJ_404_Solution_ExactInteger::readOr($stored, 0, 0);
    }
}
