<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assigns the bounded detach A/B diagnostic for one request workload.
 *
 * A pre-release build and a non-empty browser session are both required. Each
 * workload scope receives an independent six-slot counter, arranged as
 * alternating AB/BA pairs so workload or time drift cannot manufacture a detach
 * result. Every decision includes its normalized scope and build channel so
 * journal readers never infer state from silence.
 *
 * This class is policy only. ABJ_404_Solution_DetachAbScope owns what a
 * workload scope IS and how its keys are derived, and
 * ABJ_404_Solution_DetachAbAttemptCounter owns where the slot number is stored.
 */
final class ABJ_404_Solution_DetachAbExperiment {

    /** Number of counterbalanced ON/OFF pairs collected per workload scope. */
    const MAX_PAIRS = 3;

    /** Total toggled attempts one workload scope may consume. */
    const MAX_ATTEMPTS = self::MAX_PAIRS * 2;

    /**
     * Whether this build may run the experiment.
     *
     * The default is derived from the build's own version rather than set by a
     * separate wiring step, because that wiring previously failed: the filter
     * default was hardcoded false, no packaging callback changed it, and the
     * experiment shipped inert while tests that registered their own callback
     * still passed. Deriving it from ABJ404_VERSION makes packaging a beta arm
     * it and packaging a stable release disarm it in one unavoidable step.
     *
     * The filter overrides in both directions. A targeted support session on a
     * stable release can turn the experiment on, while a beta tester who needs
     * the detach on every request can turn it off without another build.
     */
    public static function isEnabled(): bool {
        $preRelease = ABJ_404_Solution_PluginReleaseChannel::isPreRelease();
        return (bool)ABJ_404_Solution_ResponseControlFilterTracer::traceDispatch(
            'abj404_should_run_detach_ab_diagnostic',
            static function () use ($preRelease) {
                return apply_filters(
                    'abj404_should_run_detach_ab_diagnostic',
                    $preRelease,
                    array()
                );
            }
        );
    }

    /**
     * Return the deterministic mode for one counter slot.
     *
     * Each adjacent pair contains one ON and one OFF request. Successive pairs
     * reverse order, while a stable seed decides the first pair's order. Once a
     * workload consumes MAX_ATTEMPTS, later requests return to `default`, so a
     * diagnostic probe never permanently degrades an admin session.
     */
    public static function modeForAttempt(int $attemptIndex, string $assignmentSeed = ''): string {
        if ($attemptIndex < 0 || $attemptIndex >= self::MAX_ATTEMPTS) {
            return ABJ_404_Solution_DetachAbAttempt::MODE_DEFAULT;
        }
        $pairOrdinal = intdiv($attemptIndex, 2);
        $position = $attemptIndex % 2;
        $seedByte = hexdec(substr(md5($assignmentSeed), 0, 2));
        $onFirst = (($seedByte + $pairOrdinal) % 2) === 0;
        if ($position === 1) {
            $onFirst = !$onFirst;
        }
        return $onFirst
            ? ABJ_404_Solution_DetachAbAttempt::MODE_ON
            : ABJ_404_Solution_DetachAbAttempt::MODE_OFF;
    }

    /**
     * Create a stable, privacy-safe fingerprint for request-shaping fields.
     *
     * @param array<string, scalar|null> $payload
     */
    public static function payloadKey(array $payload): string {
        ksort($payload);
        return sha1(serialize($payload));
    }

    /**
     * Hash a browser session for joins without journaling its raw value.
     *
     * The name the diagnostics classes reach for. The formula itself belongs to
     * ABJ_404_Solution_DetachAbScope, which is what a hashed session identifies;
     * defining it there and forwarding from here keeps the dependency running
     * one way (policy depends on the scope, never the reverse) while leaving the
     * fourteen production callers that only want the hash where they are.
     */
    public static function sessionKey(string $sessionId): string {
        return ABJ_404_Solution_DetachAbScope::sessionKeyFor($sessionId);
    }

    /**
     * Resolve the gate, normalized workload, counter slot, and assigned mode.
     *
     * `inert` is positive evidence that the experiment did not run. `default`
     * means the bounded experiment finished and the ordinary detach path was
     * restored. Both retain the same journal shape as an active assignment.
     *
     * Build channel travels with every decision because an inert stable build
     * is correct while an inert pre-release build means the experiment failed
     * to arm. Session key, part, payload key, ordinal, and pair coordinates also
     * travel with every result so a reader can join it to the exact counter that
     * produced the slot rather than infer scope from missing fields.
     *
     * @return array<string, mixed>
     */
    public static function resolve(ABJ_404_Solution_DetachAbScope $scope): array {
        return ABJ_404_Solution_DetachAbResolutionTracer::traceResolution(
            $scope,
            static function () use ($scope): array {
                $buildChannel = ABJ_404_Solution_PluginReleaseChannel::currentChannel();
                if (!self::isEnabled()) {
                    return self::inertDecision(false, $buildChannel, $scope);
                }
                $attemptIndex = ABJ_404_Solution_DetachAbAttemptCounter::reserveNextSlot($scope);
                if ($attemptIndex === ABJ_404_Solution_DetachAbAttemptCounter::NO_SLOT) {
                    return self::inertDecision(true, $buildChannel, $scope);
                }
                $assignmentSeed = $scope->assignmentSeed();
                return array(
                    'mode' => self::modeForAttempt($attemptIndex, $assignmentSeed),
                    'attempt_index' => $attemptIndex,
                    'diagnostic_enabled' => true,
                    'build_channel' => $buildChannel,
                    'session_key' => $scope->sessionKey(),
                    'part' => $scope->part(),
                    'payload_key' => $scope->payloadKey(),
                    'ordinal' => $attemptIndex,
                    'pair_ordinal' => intdiv($attemptIndex, 2),
                    'pair_position' => $attemptIndex % 2,
                    'assignment_seed' => $assignmentSeed,
                );
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function inertDecision(
        bool $diagnosticEnabled,
        string $buildChannel,
        ABJ_404_Solution_DetachAbScope $scope
    ): array {
        return array(
            'mode' => ABJ_404_Solution_DetachAbAttempt::MODE_INERT,
            'attempt_index' => ABJ_404_Solution_DetachAbAttemptCounter::NO_SLOT,
            'diagnostic_enabled' => $diagnosticEnabled,
            'build_channel' => $buildChannel,
            'session_key' => $scope->sessionKey(),
            'part' => $scope->part(),
            'payload_key' => $scope->payloadKey(),
            'ordinal' => ABJ_404_Solution_DetachAbAttemptCounter::NO_SLOT,
            'pair_ordinal' => -1,
            'pair_position' => -1,
            'assignment_seed' => '',
        );
    }
}
