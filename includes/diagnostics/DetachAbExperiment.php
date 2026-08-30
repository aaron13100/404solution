<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assigns the bounded detach A/B diagnostic for one request workload.
 *
 * A pre-release build and a non-empty browser session are both required. Each
 * session, request part, and payload shape receives an independent six-slot
 * counter, arranged as alternating AB/BA pairs so workload or time drift
 * cannot manufacture a detach result. Every decision includes its normalized
 * scope and build channel so journal readers never infer state from silence.
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
     * The checkpoint journal is site-wide while counters are per session. The
     * hash prevents two tabs' independent ordinal sequences from being paired
     * together, without exposing the browser-supplied opaque identifier. Empty
     * stays empty because "no session" is evidence, not a shared real session.
     */
    public static function sessionKey(string $sessionId): string {
        return $sessionId === '' ? '' : md5($sessionId);
    }

    /** The transient key for one session, part, and payload counter. */
    public static function transientKey(
        string $sessionId,
        string $part = 'all',
        string $payloadKey = ''
    ): string {
        return 'abj404_ab_detach_v2_' . md5(self::normalizedScope(
            $sessionId,
            $part,
            $payloadKey
        ));
    }

    /** Stable seed deciding which mode runs first in one workload scope. */
    public static function assignmentSeed(
        string $sessionId,
        string $part,
        string $payloadKey
    ): string {
        return md5(self::normalizedScope($sessionId, $part, $payloadKey));
    }

    /**
     * Consume the next attempt slot for one workload scope.
     *
     * The transient is intentionally non-atomic: this is a bounded diagnostic
     * sequence, not a security limit. A rare concurrent-tab race is less costly
     * than coupling this diagnostics policy to the database layer.
     */
    public static function nextAttemptIndex(
        string $sessionId,
        string $part = 'all',
        string $payloadKey = ''
    ): int {
        if ($sessionId === '' || !function_exists('get_transient') || !function_exists('set_transient')) {
            return -1;
        }
        $key = self::transientKey($sessionId, $part, $payloadKey);
        $current = ABJ_404_Solution_DetachAbResolutionTracer::traceTransientOperation(
            'get_transient',
            $key,
            static function () use ($key) {
                return get_transient($key);
            }
        );
        $index = is_numeric($current) ? (int)$current : 0;
        $ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        // allow-cache-empty: locally computed attempt counter (always a valid
        // non-negative int), not a fetched query result.
        ABJ_404_Solution_DetachAbResolutionTracer::traceTransientOperation(
            'set_transient',
            $key,
            static function () use ($key, $index, $ttl) {
                // allow-cache-empty: the locally computed attempt counter is a
                // valid non-negative integer, never a fetched result.
                return set_transient($key, $index + 1, $ttl);
            }
        );
        return $index;
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
    public static function resolve(
        string $sessionId,
        string $part = 'all',
        string $payloadKey = ''
    ): array {
        return ABJ_404_Solution_DetachAbResolutionTracer::traceResolution(
            $sessionId,
            $part,
            $payloadKey,
            static function () use ($sessionId, $part, $payloadKey): array {
                $buildChannel = ABJ_404_Solution_PluginReleaseChannel::currentChannel();
                $sessionKey = self::sessionKey($sessionId);
                $part = self::normalizePart($part);
                $payloadKey = self::normalizePayloadKey($payloadKey);
                $diagnosticEnabled = self::isEnabled();
                if (!$diagnosticEnabled) {
                    return self::inertDecision(
                        false,
                        $buildChannel,
                        $sessionKey,
                        $part,
                        $payloadKey
                    );
                }
                $attemptIndex = self::nextAttemptIndex($sessionId, $part, $payloadKey);
                if ($attemptIndex < 0) {
                    return self::inertDecision(
                        true,
                        $buildChannel,
                        $sessionKey,
                        $part,
                        $payloadKey
                    );
                }
                $assignmentSeed = self::assignmentSeed($sessionId, $part, $payloadKey);
                return array(
                    'mode' => self::modeForAttempt($attemptIndex, $assignmentSeed),
                    'attempt_index' => $attemptIndex,
                    'diagnostic_enabled' => true,
                    'build_channel' => $buildChannel,
                    'session_key' => $sessionKey,
                    'part' => $part,
                    'payload_key' => $payloadKey,
                    'ordinal' => $attemptIndex,
                    'pair_ordinal' => intdiv($attemptIndex, 2),
                    'pair_position' => $attemptIndex % 2,
                    'assignment_seed' => $assignmentSeed,
                );
            }
        );
    }

    /** Normalize a supplied payload fingerprint without journaling raw input. */
    private static function normalizePayloadKey(string $payloadKey): string {
        return preg_match('/^[a-f0-9]{40}$/', $payloadKey) === 1
            ? $payloadKey
            : sha1($payloadKey === '' ? 'legacy-payload' : $payloadKey);
    }

    /** Normalize the table endpoint's finite request-part catalog. */
    private static function normalizePart(string $part): string {
        return in_array($part, array('all', 'table', 'counts', 'pagination'), true)
            ? $part
            : 'all';
    }

    /** Build the canonical normalized scope once for keys and assignments. */
    private static function normalizedScope(
        string $sessionId,
        string $part,
        string $payloadKey
    ): string {
        return implode('|', array(
            self::sessionKey($sessionId),
            self::normalizePart($part),
            self::normalizePayloadKey($payloadKey),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function inertDecision(
        bool $diagnosticEnabled,
        string $buildChannel,
        string $sessionKey,
        string $part,
        string $payloadKey
    ): array {
        return array(
            'mode' => ABJ_404_Solution_DetachAbAttempt::MODE_INERT,
            'attempt_index' => -1,
            'diagnostic_enabled' => $diagnosticEnabled,
            'build_channel' => $buildChannel,
            'session_key' => $sessionKey,
            'part' => $part,
            'payload_key' => $payloadKey,
            'ordinal' => -1,
            'pair_ordinal' => -1,
            'pair_position' => -1,
            'assignment_seed' => '',
        );
    }
}
