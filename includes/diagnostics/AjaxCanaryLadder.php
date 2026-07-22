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
        self::STEP_AUTH_ONLY, self::STEP_POST_LIMITER, self::STEP_SUMMARY,
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
}
