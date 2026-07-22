<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accumulates one request's shutdown-sentinel observations and computes the
 * attribution fields that split shutdown time into "spent inside a WordPress
 * 'shutdown' action callback" versus "spent below WordPress".
 *
 * WordPress registers shutdown_action_hook() with register_shutdown_function()
 * while wp-settings.php loads, long before a plugin can register anything, and
 * PHP runs shutdown functions in registration order. So a plugin's own
 * register_shutdown_function sentinels ALWAYS run after the whole 'shutdown'
 * action has completed, and the delta between the WordPress action's end (the
 * PHP_INT_MAX action callback) and a shutdown-function sentinel is, by
 * construction, shutdown work that is NOT a WordPress shutdown-action callback:
 * a raw register_shutdown_function registered by another plugin, an object
 * destructor, a PHP extension's request-shutdown handler, or a session write.
 * PHP exposes no API to enumerate registered shutdown functions, so none of
 * those can be named directly -- this bracket, plus
 * ABJ_404_Solution_ShutdownEnvironmentInventory, is what a reader gets instead
 * of a hole.
 *
 * One instance per request; the trace owns it and feeds it the sentinel
 * timings. It is a value object (no I/O), which is why the bracket math is
 * testable without a journal.
 */
final class ABJ_404_Solution_ShutdownTeardownBracket {

    /** @var float|null When the WordPress 'shutdown' action opened (the PHP_INT_MIN callback). */
    private $wpActionStartedAt = null;
    /** @var float|null When the WordPress 'shutdown' action closed (the PHP_INT_MAX callback). */
    private $wpActionEndedAt = null;
    /** @var int How many teardown sentinels have fired for this request, in observed order. */
    private $teardownCount = 0;
    /** @var int How many register_shutdown_function sentinels have fired. */
    private $shutdownFunctionCount = 0;
    /** @var float|null When the previous register_shutdown_function sentinel ran. */
    private $lastShutdownFunctionAt = null;

    /** Record that the WordPress 'shutdown' action opened (earliest-priority callback). */
    public function noteWpActionStart(float $now): void {
        $this->wpActionStartedAt = $now;
    }

    /** Record that the WordPress 'shutdown' action closed (latest-priority callback). */
    public function noteWpActionEnd(float $now): void {
        $this->wpActionEndedAt = $now;
    }

    /**
     * The attribution fields for one teardown sentinel firing.
     *
     * Both millisecond readings are null rather than 0 when the WordPress
     * shutdown action was never observed (a fatal before it, a worker killed
     * inside it, or shutdown_action_hook removed): a fabricated zero would
     * silently blame every millisecond on the non-WordPress side.
     *
     * @param string $mechanism         The firing sentinel's mechanism vocabulary (echoed back).
     * @param string $armedAt           Where the sentinel was registered (echoed back).
     * @param bool   $isShutdownFunction True when this sentinel is a register_shutdown_function
     *                                   callback (runs below WordPress), false for a WordPress
     *                                   'shutdown' action callback.
     * @param float  $now               The sentinel's firing time.
     * @return array<string, mixed>
     */
    public function attribution(string $mechanism, string $armedAt, bool $isShutdownFunction, float $now): array {
        $attribution = array(
            'mechanism' => $mechanism,
            'armed_at' => $armedAt,
            'teardown_ordinal' => ++$this->teardownCount,
            'shutdown_function_ordinal' => $isShutdownFunction ? ++$this->shutdownFunctionCount : null,
            'wp_shutdown_action_ms' => $this->wpActionStartedAt !== null && $this->wpActionEndedAt !== null
                ? max(0, (int)round(($this->wpActionEndedAt - $this->wpActionStartedAt) * 1000))
                : null,
            'non_wp_shutdown_ms' => $isShutdownFunction && $this->wpActionEndedAt !== null
                ? max(0, (int)round(($now - $this->wpActionEndedAt) * 1000))
                : null,
            'since_previous_shutdown_function_ms' => $isShutdownFunction && $this->lastShutdownFunctionAt !== null
                ? max(0, (int)round(($now - $this->lastShutdownFunctionAt) * 1000))
                : null,
        );
        if ($isShutdownFunction) {
            $this->lastShutdownFunctionAt = $now;
        }
        return $attribution;
    }
}
