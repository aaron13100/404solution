<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure logic for the adaptive canary ladder (Bruno timeout cause matrix,
 * coverage req. 7).
 *
 * The server flight recorder and client transport telemetry can each prove
 * what happened on their own side of a failed table request, but neither can
 * prove which EXTERNAL system between them is responsible: browser/network,
 * Cloudflare, LiteSpeed/LVE admission, WordPress boot, the rate limiter, the
 * real query path, response size, compression, or output buffering. The
 * ladder answers that by running seven small, ordered probes after the first
 * failure in a session and comparing which ones succeed.
 *
 * This class owns the step catalog, byte-size shaping, and the pure
 * interpretation matrix. It owns no transport, no auth and no journaling --
 * ABJ_404_Solution_Ajax_CanaryLadder is the AJAX handler that drives each
 * step through the same auth/checkpoint/trace plumbing the real table
 * endpoint uses, so a canary's timing is directly comparable to it.
 */
final class ABJ_404_Solution_AjaxCanaryLadder {

    /**
     * Reuses the real table endpoint's own nonce action. The ladder exists to
     * investigate ajaxUpdatePaginationLinks; gating it behind a second,
     * separately-minted nonce would test a different credential than the one
     * actually failing, and would need its own page-load wiring and refresh
     * support for zero security benefit (same capability, same user).
     */
    const NONCE_ACTION = 'abj404_updatePaginationLink';

    const STEP_CONCURRENT_CONTROL = 'concurrent_control';
    const STEP_AUTH_ONLY = 'auth_only';
    const STEP_POST_LIMITER = 'post_limiter';
    const STEP_SUMMARY = 'summary';
    const STEP_INERT = 'inert';
    const STEP_COMPRESS_ON = 'compress_on';
    const STEP_COMPRESS_OFF = 'compress_off';
    const STEP_STREAM = 'stream';
    const STEP_INTERPRET = 'interpret';

    /** Every server-dispatched step. Step 1 (static asset) never reaches PHP by design and so is not listed here. */
    const STEPS = array(
        self::STEP_CONCURRENT_CONTROL, self::STEP_AUTH_ONLY,
        self::STEP_POST_LIMITER, self::STEP_SUMMARY,
        self::STEP_INERT, self::STEP_COMPRESS_ON, self::STEP_COMPRESS_OFF,
        self::STEP_STREAM, self::STEP_INTERPRET,
    );

    /**
     * Steps that do real work (a DB read, a large buffer, an extra output
     * pass) get a shared abuse ceiling. auth_only must bypass any limiter by
     * design (that is the point of the step), and post_limiter's whole job is
     * measuring the real limiter's own overhead -- adding a second, unrelated
     * ceiling in front of it would blur exactly the thing it isolates.
     */
    const RATE_LIMITED_STEPS = array(
        self::STEP_SUMMARY, self::STEP_INERT, self::STEP_COMPRESS_ON,
        self::STEP_COMPRESS_OFF, self::STEP_STREAM,
    );

    const AUTH_ONLY_BYTES = 1024;
    const STREAM_WHITESPACE_BYTES = 2048;
    const MIN_INERT_BYTES = 64;
    const MAX_INERT_BYTES = 2000000;
    const DEFAULT_INERT_BYTES = 50000;

    /**
     * @param mixed $raw
     */
    public static function normalizeStep($raw): string {
        $candidate = is_scalar($raw) ? (string)$raw : '';
        return in_array($candidate, self::STEPS, true) ? $candidate : '';
    }

    /**
     * Clamp a client-supplied target byte size (the real response's observed
     * size) to a sane range: at least large enough to be meaningful, and
     * bounded well below anything that could turn a diagnostic probe into a
     * memory-exhaustion vector.
     *
     * @param mixed $raw
     */
    public static function clampTargetBytes($raw, int $default = self::DEFAULT_INERT_BYTES): int {
        $value = is_numeric($raw) ? (int)$raw : $default;
        if ($value <= 0) {
            $value = $default;
        }
        return max(self::MIN_INERT_BYTES, min(self::MAX_INERT_BYTES, $value));
    }

    /**
     * A JSON-safe filler payload whose ENCODED size lands as close to
     * $targetBytes as the envelope overhead allows. The filler is inert,
     * repeated content -- the ladder measures size and transport behavior,
     * never anything resembling real redirect/URL data.
     *
     * @return array<string, mixed>
     */
    public static function buildFillerPayload(string $requestId, string $step, int $targetBytes): array {
        $envelope = array('requestId' => $requestId, 'canaryStep' => $step, 'filler' => '');
        $overhead = strlen((string)json_encode($envelope));
        $fillerLength = max(0, $targetBytes - $overhead);
        $envelope['filler'] = str_repeat('a', $fillerLength);
        return $envelope;
    }

    /**
     * The interpretation matrix (matrix coverage req. 7): which single cause
     * space the ladder's outcomes point to. Pure and side-effect free so the
     * comparison logic can be tested directly; the AJAX handler journals the
     * result.
     *
     * @param array<string, mixed> $observations Client-reported per-step
     *   outcomes, keyed by step id: {ok: bool, bytes?: int, ms?: int, gapMs?: int}.
     * @param bool $realRequestFailed Whether the real table request that
     *   triggered this ladder run actually failed. Always true in production
     *   (the ladder only ever runs after a real failure); kept as an
     *   explicit parameter rather than a hard-coded assumption so the
     *   content-inspection rule stays honestly conditional and testable.
     * @return array<string, bool>
     */
    public static function interpretResults(array $observations, bool $realRequestFailed = true): array {
        $entry = static function (array $obs, string $step): array {
            $found = $obs[$step] ?? null;
            return is_array($found) ? $found : array();
        };
        $ok = static function (array $found): bool {
            return !empty($found['ok']);
        };

        $staticAsset = $entry($observations, 'static_asset');
        $authOnly = $entry($observations, self::STEP_AUTH_ONLY);
        $postLimiter = $entry($observations, self::STEP_POST_LIMITER);
        $summary = $entry($observations, self::STEP_SUMMARY);
        $inert = $entry($observations, self::STEP_INERT);
        $compressOn = $entry($observations, self::STEP_COMPRESS_ON);
        $compressOff = $entry($observations, self::STEP_COMPRESS_OFF);
        $stream = $entry($observations, self::STEP_STREAM);
        $streamGapMs = isset($stream['gapMs']) && is_numeric($stream['gapMs']) ? (int)$stream['gapMs'] : 0;

        return array(
            'browserOrNetworkCausal' => !$ok($staticAsset),
            'bootAuthOrDeliveryCausal' => $ok($staticAsset) && !$ok($authOnly),
            'limiterCausal' => $ok($authOnly) && !$ok($postLimiter),
            // req. 7 matrix rule: all server work completes (summary-ok) but
            // an inert response of the SAME size still fails => the failure
            // tracks response size/bandwidth/buffering, not query cost.
            'sizeOrDeliveryCausal' => $ok($summary) && !$ok($inert),
            // Mirror rule: a same-size inert filler succeeds while the real,
            // content-bearing request failed => something inspects or mangles
            // the CONTENT (redirect URLs/HTML), not merely its size.
            'contentInspectionCausal' => $ok($inert) && $realRequestFailed,
            'compressionCausal' => $ok($compressOff) && !$ok($compressOn),
            'streamingBufferCausal' => !$ok($stream) && $streamGapMs > 2000,
        );
    }

    /**
     * The decisive-measurement rule for the detach A/B experiment (Bruno
     * timeout cause matrix, gap G9 / c434;
     * ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode() picks the
     * mode, ABJ_404_Solution_Ajax_AdminEndpointSupport::checkpointedFlushAndFinish()
     * records it per request ID). Kept as its own pure function rather than
     * folded into interpretResults(): two independent verdicts computed from
     * disjoint inputs -- the seven-step ladder's canary observations vs. the
     * real table endpoint's own A/B attempts -- can never confound each
     * other, whereas merging them into one matrix would let an ambiguous
     * quadrant in one leak into the other's conclusion.
     *
     * If every 'on' attempt completed and every 'off' attempt did not, the
     * detach fix is causal. If both modes completed uniformly, detach was
     * never the cause and a transient (or one of the other three things
     * beta.2 also ships) is the better explanation. If neither mode ever
     * completed, something else dominates regardless of detach. Anything
     * else -- mixed outcomes within a mode, or fewer than one full pair
     * observed -- is honestly inconclusive rather than forced into one of
     * the three clean verdicts.
     *
     * @param array<int, array{mode?: mixed, ok?: mixed}> $attempts
     *   Chronological per-request outcomes for the real table endpoint's own
     *   A/B attempts within one session (mode 'on'/'off' as journaled by
     *   detach_ab_mode; ok = whether that attempt completed from the
     *   client's own point of view).
     * @return array<string, mixed>
     */
    public static function interpretDetachAbResults(array $attempts): array {
        $tally = self::tallyDetachAbAttempts($attempts);
        $onCount = $tally['on'];
        $onOkCount = $tally['onOk'];
        $offCount = $tally['off'];
        $offOkCount = $tally['offOk'];

        $haveBothModes = $onCount > 0 && $offCount > 0;
        $allOnOk = $onCount > 0 && $onOkCount === $onCount;
        $noneOnOk = $onCount > 0 && $onOkCount === 0;
        $allOffOk = $offCount > 0 && $offOkCount === $offCount;
        $noneOffOk = $offCount > 0 && $offOkCount === 0;

        $detachCausal = $haveBothModes && $allOnOk && $noneOffOk;
        $transientCausal = $haveBothModes && $allOnOk && $allOffOk;
        $neitherModeHelps = $haveBothModes && $noneOnOk && $noneOffOk;

        return array(
            'detachCausal' => $detachCausal,
            'transientCausal' => $transientCausal,
            'neitherModeHelps' => $neitherModeHelps,
            'inconclusive' => !$detachCausal && !$transientCausal && !$neitherModeHelps,
            'onCount' => $onCount,
            'onOkCount' => $onOkCount,
            'offCount' => $offCount,
            'offOkCount' => $offOkCount,
        );
    }

    /**
     * Count per-mode attempts and completions, split out of
     * interpretDetachAbResults() purely to keep that method's cyclomatic
     * complexity within the project's ceiling -- this loop is one
     * self-contained tally, not logic that needs to be inlined at the call
     * site.
     *
     * @param array<int, array{mode?: mixed, ok?: mixed}> $attempts
     * @return array{on: int, onOk: int, off: int, offOk: int}
     */
    private static function tallyDetachAbAttempts(array $attempts): array {
        $tally = array('on' => 0, 'onOk' => 0, 'off' => 0, 'offOk' => 0);
        foreach ($attempts as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $mode = is_scalar($attempt['mode'] ?? null) ? (string)$attempt['mode'] : '';
            if ($mode !== 'on' && $mode !== 'off') {
                continue;
            }
            $tally[$mode]++;
            if (!empty($attempt['ok'])) {
                $tally[$mode . 'Ok']++;
            }
        }
        return $tally;
    }
}
