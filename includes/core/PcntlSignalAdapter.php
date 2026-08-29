<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability-safe boundary for the pcntl signal functions.
 *
 * Split from ABJ_404_Solution_PhpRuntimeCapabilityAdapter along the line the
 * HOST splits on. Everything that adapter wraps can be removed one function at
 * a time through disable_functions, but pcntl is an extension: it is absent as
 * a unit on most shared hosting and on every non-CLI SAPI build that omits it,
 * so "can this host use signals at all" is one question with one answer rather
 * than four independent probes.
 *
 * These four are also not four capabilities. They are the steps of one
 * protocol -- check support, enable async dispatch, install the handler, arm
 * and disarm the alarm -- and exactly one caller runs it,
 * ABJ_404_Solution_PostResponseWorkerBudget, which is the post-response
 * wall-clock budget itself. Keeping the protocol in one place is what lets
 * supportsSignalBudget() be the single precondition the caller checks instead
 * of each step failing separately halfway through.
 *
 * Depends on the main adapter for the availability check and never the other
 * way round, so the boundary classes stay acyclic.
 */
final class ABJ_404_Solution_PcntlSignalAdapter {

    /** Functions this boundary owns; consumed by the disable-functions lint. */
    private const OWNED_FUNCTIONS = array(
        'pcntl_alarm',
        'pcntl_async_signals',
        'pcntl_signal',
    );

    /** @return array<int, string> Functions whose direct use this boundary owns. */
    public static function ownedFunctions(): array {
        return self::OWNED_FUNCTIONS;
    }

    /**
     * Whether every function the post-response signal budget needs exists.
     *
     * All three, not any: arming an alarm whose handler could not be installed
     * would leave a SIGALRM with the default disposition, which terminates the
     * process rather than ending the budget.
     */
    public static function supportsSignalBudget(): bool {
        foreach (self::OWNED_FUNCTIONS as $function) {
            if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable($function)) {
                return false;
            }
        }
        return true;
    }

    /** Enable asynchronous signal dispatch when supported. */
    public static function enableAsyncSignals(): bool {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('pcntl_async_signals')
            && pcntl_async_signals(true);
    }

    /** Install one signal handler when supported. */
    public static function installSignalHandler(int $signal, callable $handler): bool {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('pcntl_signal')
            && pcntl_signal($signal, $handler);
    }

    /** Set or clear the process alarm; zero means unavailable or no prior alarm. */
    public static function alarm(int $seconds): int {
        if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('pcntl_alarm')) {
            return 0;
        }
        return pcntl_alarm($seconds);
    }
}
