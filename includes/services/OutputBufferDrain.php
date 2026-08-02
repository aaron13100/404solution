<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes output buffers with a guaranteed exit, for every place this plugin
 * has to unwind buffers it opened (the AJAX and admin fatal-error responders,
 * and the admin endpoint's buffer cleanup).
 *
 * WHY THIS EXISTS -- the shape it replaces was:
 *
 *     while (ob_get_level() > $minLevel) { ob_end_flush(); }
 *
 * which assumes every close either lowers the level or throws. Neither is
 * guaranteed. ob_end_flush()/ob_end_clean() return false and leave the level
 * untouched when a handler refuses to be deleted, and -- the case actually
 * observed in production -- flushing a buffer RUNS that buffer's callback,
 * which on a site with other output-buffering plugins can open a fresh buffer
 * while the drain is still walking the stack. Either way the condition never
 * goes false and the loop spins until the worker is killed.
 *
 * That is not hypothetical. Support report 193 (showmetech.com.br, LiteSpeed /
 * PHP 8.4, 4.3.3-beta.6) caught five workers in this loop for roughly three
 * minutes each: sustained ob_close_start/ob_close_end checkpoint pairs at ~39
 * cycles/second, and NOT ONE finish_request or exit_sentinel record in a
 * 25,000-line journal -- positive proof the loop never reached the
 * litespeed_finish_request() call that sits immediately after it. The response
 * was therefore never detached and the admin page never loaded, which is the
 * bug the user reported.
 *
 * WordPress core hit the same hazard and bounds its own drain
 * (wp_ob_end_flush_all(), wp-includes/functions.php) by capturing the level
 * ONCE and running a fixed `for` over it. This class does that and adds a
 * stall check, so a non-decrementing close costs one wasted iteration instead
 * of the whole budget. The response tail no longer drains at all: native FPM
 * and LiteSpeed probes proved their finish-request boundaries own that flush.
 *
 * The return value is deliberately a telemetry array rather than void: the
 * caller and tests can distinguish a complete unwind from a deliberate stop.
 */
final class ABJ_404_Solution_OutputBufferDrain {

    /**
     * Close output buffers until the level reaches $minLevel, the level stops
     * falling, or the one-time budget is spent -- whichever comes first.
     *
     * @param int $minLevel Stop once ob_get_level() is at or below this. Callers
     *   must capture their inherited level before opening or taking ownership
     *   of any buffer, then pass that level here. Global zero is not an owned
     *   floor and is forbidden for production callers.
     * @param callable $closeOne Closes exactly one buffer. Receives no
     *   arguments and its return value is ignored -- progress is measured from
     *   the level reader, never from what the close call claims, because the
     *   failing close returns false and the re-entrant one returns true.
     * @param callable|null $levelReader Returns the current buffer level.
     *   Defaults to ob_get_level(). Injectable because the two failure modes
     *   this class exists to survive (a level that never falls, and a level
     *   that falls and immediately rises again) cannot be staged against the
     *   real output stack from inside a test runner that is itself buffering.
     * @return array{iterations: int, level_before: int, level_after: int,
     *   budget: int, stalled: bool, budget_exhausted: bool} Telemetry. `stalled`
     *   means a close did not lower the level (non-removable handler, or a
     *   callback re-opened one); `budget_exhausted` means the level kept
     *   falling but more buffers appeared than existed when the drain started.
     *   Either flag means buffers are still open on purpose, not by accident.
     */
    public static function drainTo(int $minLevel, callable $closeOne, ?callable $levelReader = null): array {
        $readLevel = $levelReader === null
            ? static function () { return ABJ_404_Solution_OutputBufferDrain::currentLevel(); }
            : static function () use ($levelReader) { return (int)$levelReader(); };

        $minLevel = max(0, $minLevel);
        $levelBefore = $readLevel();
        $budget = max(0, $levelBefore - $minLevel);

        $iterations = 0;
        $stalled = false;
        while ($iterations < $budget) {
            $levelBeforeClose = $readLevel();
            if ($levelBeforeClose <= $minLevel) {
                break;
            }
            $closeOne();
            $iterations++;
            if ($readLevel() >= $levelBeforeClose) {
                // The close did not consume a buffer. Trying again cannot help:
                // nothing about the stack changed, so the next iteration would
                // take the identical branch. Stop and report it.
                $stalled = true;
                break;
            }
        }

        $levelAfter = $readLevel();
        return array(
            'iterations' => $iterations,
            'level_before' => $levelBefore,
            'level_after' => $levelAfter,
            'budget' => $budget,
            'stalled' => $stalled,
            'budget_exhausted' => !$stalled && $iterations >= $budget && $levelAfter > $minLevel,
        );
    }

    /**
     * ob_get_level() is always available in supported PHP, but this class runs
     * inside fatal-error responders where the runtime is already degraded, so
     * an absent function must degrade to "no buffers" rather than fatal a
     * second time inside the handler for the first fatal.
     */
    public static function currentLevel(): int {
        return function_exists('ob_get_level') ? (int)ob_get_level() : 0;
    }
}
