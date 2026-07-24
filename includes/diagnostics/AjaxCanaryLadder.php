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
 * ladder answers that by running small ordered probes after the first
 * failure in a session and comparing which ones succeed. Armed pre-releases
 * interleave a repeated fixed-size baseline to expose time drift.
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

    /**
     * The client-side probe that deliberately never reaches PHP. It is not a
     * dispatchable step, but the browser can still report having received it,
     * so it is a legal step name on a receipt and nowhere else.
     */
    const STEP_STATIC_ASSET = 'static_asset';

    const STEP_CONCURRENT_CONTROL = 'concurrent_control';
    const STEP_BASELINE_CONTROL = 'baseline_control';
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
        self::STEP_CONCURRENT_CONTROL, self::STEP_BASELINE_CONTROL, self::STEP_AUTH_ONLY,
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

    /**
     * Hard bound on the raw `canaryStepReceipts` parameter BEFORE it is
     * parsed. The client caps itself at ten fixed-shape records (~1.8 KB
     * worst case); this is the server refusing to parse more than that
     * regardless of what actually arrives.
     */
    const MAX_STEP_RECEIPTS_BYTES = 4096;

    /** Hard bound on how many receipts one request is allowed to carry. */
    const MAX_STEP_RECEIPTS = 12;

    /** Longest transport status string kept on a receipt ('parsererror' etc). */
    const MAX_TEXT_STATUS_CHARS = 32;

    /** Longest step name kept verbatim when this build does not recognise it. */
    const MAX_REPORTED_STEP_CHARS = 32;

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
     * The browser's receipt confirmations for ladder steps that finished
     * BEFORE the request carrying them (Bruno timeout cause matrix, gap-hunt
     * iteration 2 gap GE / Codex #7).
     *
     * Each ladder step is already its own traced PHP request, so the server
     * can always prove a step EXECUTED. What only the browser can supply is
     * whether that step's response ever arrived, and that used to reach here
     * solely inside the final `interpret` POST -- so one lost request (a hang
     * on the very host under diagnosis, a closed tab, an interrupted script)
     * erased the receipt side of the evidence for the whole ladder at once.
     * Riding each receipt on the NEXT step's request is the same route
     * ABJ_404_Solution_ClientTransportReport already uses for table requests.
     *
     * The payload is untrusted text throughout: length-bounded, parsed
     * defensively, count-bounded, and never echoed back to any client. An
     * input that cannot be decoded returns a diagnostic stand-in rather than
     * an empty list -- "the browser sent something unreadable" is a finding
     * about the transport under investigation, and silently dropping it is
     * the exact evidence-loss shape this whole mechanism exists to end.
     *
     * @param mixed $raw The raw POSTed parameter.
     * @return array<int, array<string, mixed>> Normalized receipts, in the
     *   order the browser reported them.
     */
    public static function parseStepReceipts($raw): array {
        $text = is_scalar($raw) ? (string)$raw : '';
        if ($text === '') {
            return array();
        }
        $truncated = strlen($text) > self::MAX_STEP_RECEIPTS_BYTES;
        $decoded = json_decode(substr($text, 0, self::MAX_STEP_RECEIPTS_BYTES), true);
        if (!is_array($decoded)) {
            return array(array(
                'decoded' => false,
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($text),
                'raw_head' => substr($text, 0, 200),
            ));
        }
        // A single receipt sent unwrapped is accepted as readily as a list:
        // an older or hand-modified client that sends one record is a
        // tolerable input, not a reason to discard the only evidence it had
        // (Defensive Coding #10, fix the consumer rather than the input).
        if (array_key_exists('step', $decoded)) {
            $decoded = array($decoded);
        }
        $receipts = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $receipts[] = self::normalizeStepReceipt($entry, $truncated);
            if (count($receipts) >= self::MAX_STEP_RECEIPTS) {
                break;
            }
        }
        return $receipts;
    }

    /**
     * One receipt, rebuilt field by field from whatever the browser sent.
     *
     * Nothing is passed through: the decoded value's keys and types are only
     * assumed until they are made so here, and a field that cannot be read as
     * what it claims to be becomes null rather than zero -- an unreadable
     * duration and a zero-millisecond step are opposite findings.
     *
     * @param array<mixed, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizeStepReceipt(array $entry, bool $truncated): array {
        $rawStep = isset($entry['step']) && is_scalar($entry['step']) ? (string)$entry['step'] : '';
        $known = $rawStep === self::STEP_STATIC_ASSET || in_array($rawStep, self::STEPS, true);
        $requestId = isset($entry['requestId']) && is_scalar($entry['requestId']) ? (string)$entry['requestId'] : '';
        // The wire contract's own request-id shape. A value that cannot be a
        // server request id cannot be reconciled against one, and letting
        // arbitrary browser text become a journal key would let a client
        // forge the identity of its own evidence.
        if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $requestId) !== 1) {
            $requestId = '';
        }
        $status = isset($entry['textStatus']) && is_scalar($entry['textStatus']) ? (string)$entry['textStatus'] : '';
        return array(
            'decoded' => true,
            'step' => $known ? $rawStep : '',
            // What the browser actually claimed, kept bounded even when this
            // build has never heard of it: a step name from a newer or older
            // client is version-skew evidence, and erasing it would turn a
            // readable mismatch into an unexplained blank.
            'reported_step' => substr($rawStep, 0, self::MAX_REPORTED_STEP_CHARS),
            'step_request_id' => $requestId,
            'ok' => !empty($entry['ok']),
            'ms' => isset($entry['ms']) && is_numeric($entry['ms']) ? (int)$entry['ms'] : null,
            'bytes' => isset($entry['bytes']) && is_numeric($entry['bytes']) ? (int)$entry['bytes'] : null,
            'text_status' => substr($status, 0, self::MAX_TEXT_STATUS_CHARS),
            'truncated_on_arrival' => $truncated,
        );
    }

    /**
     * A JSON-safe filler payload whose ENCODED size lands as close to
     * $targetBytes as the envelope overhead allows. The filler is inert,
     * repeated content -- the ladder measures size and transport behavior,
     * never anything resembling real redirect/URL data.
     *
     * @return array{requestId: string, canaryStep: string, filler: string}
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
     * @return array<string, mixed>
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

        return array_merge(array(
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
        ), self::baselineTrend($observations));
    }

    /**
     * Summarize the repeated fixed-size controls in chronological order.
     * A changing control is reported as drift, never reinterpreted as a step effect.
     * @param array<string, mixed> $observations
     * @return array<string, int>
     */
    private static function baselineTrend(array $observations): array {
        $raw = $observations[self::STEP_BASELINE_CONTROL] ?? array();
        $baselines = is_array($raw) ? $raw : array();
        $count = 0;
        $okCount = 0;
        $firstMs = null;
        $lastMs = null;
        foreach ($baselines as $baseline) {
            if (!is_array($baseline)) {
                continue;
            }
            $count++;
            if (!empty($baseline['ok'])) {
                $okCount++;
            }
            if (isset($baseline['ms']) && is_numeric($baseline['ms'])) {
                $ms = (int)$baseline['ms'];
                if ($firstMs === null) {
                    $firstMs = $ms;
                }
                $lastMs = $ms;
            }
        }
        return array(
            'baselineControlCount' => $count,
            'baselineControlOkCount' => $okCount,
            'baselineControlFirstMs' => $firstMs ?? -1,
            'baselineControlLastMs' => $lastMs ?? -1,
            'baselineControlTrendMs' => $firstMs !== null && $lastMs !== null
                ? $lastMs - $firstMs : 0,
        );
    }

    /**
     * The decisive-measurement rule for the detach A/B experiment (Bruno
     * timeout cause matrix, gap G9 / c434;
     * ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode() picks the
     * mode, ABJ_404_Solution_Ajax_AdminEndpointSupport::checkpointedFlushAndFinish()
     * records it per request ID). Kept as its own pure function rather than
     * folded into interpretResults(): two independent verdicts computed from
     * disjoint inputs -- the ladder's canary observations vs. the
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
     * @param array<int, array<string, mixed>> $attempts
     *   Chronological per-request outcomes for the real table endpoint's own
     *   workload-matched A/B attempts (mode 'on'/'off', workload scope, and
     *   ordinal as journaled by detach_ab_mode; ok = whether that attempt
     *   completed from the client's own point of view).
     * @return array<string, mixed>
     */
    public static function interpretDetachAbResults(array $attempts): array {
        $tally = self::tallyDetachAbAttempts($attempts);
        $onCount = $tally['on'];
        $onOkCount = $tally['onOk'];
        $offCount = $tally['off'];
        $offOkCount = $tally['offOk'];

        $pairs = self::matchedDetachAbPairs($attempts);
        $pairCount = count($pairs);
        $onFirstPairs = 0;
        $offFirstPairs = 0;
        $detachPairs = 0;
        $transientPairs = 0;
        $neitherPairs = 0;
        foreach ($pairs as $pair) {
            $pair['on_first'] ? $onFirstPairs++ : $offFirstPairs++;
            if ($pair['on_ok'] && !$pair['off_ok']) {
                $detachPairs++;
            } else if ($pair['on_ok'] && $pair['off_ok']) {
                $transientPairs++;
            } else if (!$pair['on_ok'] && !$pair['off_ok']) {
                $neitherPairs++;
            }
        }

        $orderCounterbalanced = $onFirstPairs > 0 && $offFirstPairs > 0;
        $detachCausal = $pairCount >= 2 && $orderCounterbalanced && $detachPairs === $pairCount;
        $transientCausal = $pairCount > 0 && $transientPairs === $pairCount;
        $neitherModeHelps = $pairCount > 0 && $neitherPairs === $pairCount;

        return array(
            'detachCausal' => $detachCausal,
            'transientCausal' => $transientCausal,
            'neitherModeHelps' => $neitherModeHelps,
            'inconclusive' => !$detachCausal && !$transientCausal && !$neitherModeHelps,
            'onCount' => $onCount,
            'onOkCount' => $onOkCount,
            'offCount' => $offCount,
            'offOkCount' => $offOkCount,
            'matchedPairCount' => $pairCount,
            'onFirstPairCount' => $onFirstPairs,
            'offFirstPairCount' => $offFirstPairs,
            'orderCounterbalanced' => $orderCounterbalanced,
        );
    }

    /**
     * Complete, workload-matched pairs only. Missing partners, legacy records
     * without scope fields, duplicated positions, and malformed mode pairs
     * remain visible in raw attempt accounting but cannot decide causality.
     *
     * @param array<int, array<string, mixed>> $attempts
     * @return array<int, array{on_ok: bool, off_ok: bool, on_first: bool}>
     */
    private static function matchedDetachAbPairs(array $attempts): array {
        $grouped = array();
        $duplicates = array();
        foreach ($attempts as $attempt) {
            $slot = self::detachAbPairSlot($attempt);
            if ($slot === null) {
                continue;
            }
            if (isset($grouped[$slot['key']][$slot['position']])) {
                $duplicates[$slot['key']] = true;
                continue;
            }
            $grouped[$slot['key']][$slot['position']] = array(
                'mode' => $slot['mode'],
                'ok' => $slot['ok'],
            );
        }

        $pairs = array();
        foreach ($grouped as $key => $positions) {
            if (isset($duplicates[$key]) || !isset($positions[0], $positions[1])
                    || $positions[0]['mode'] === $positions[1]['mode']) {
                continue;
            }
            $on = $positions[0]['mode'] === 'on' ? $positions[0] : $positions[1];
            $off = $positions[0]['mode'] === 'off' ? $positions[0] : $positions[1];
            $pairs[] = array(
                'on_ok' => $on['ok'],
                'off_ok' => $off['ok'],
                'on_first' => $positions[0]['mode'] === 'on',
            );
        }
        return $pairs;
    }

    /**
     * Validate one evidence record and derive pair coordinates from ordinal.
     * Supplemental pair metadata is journaled but never overrides the ordinal.
     * @param mixed $attempt
     * @return array{key: string, position: int, mode: string, ok: bool}|null
     */
    private static function detachAbPairSlot($attempt): ?array {
        if (!is_array($attempt)) {
            return null;
        }
        $part = is_scalar($attempt['part'] ?? null) ? (string)$attempt['part'] : '';
        $payloadKey = is_scalar($attempt['payload_key'] ?? null)
            ? (string)$attempt['payload_key'] : '';
        $ordinal = isset($attempt['ordinal']) && is_numeric($attempt['ordinal'])
            ? (int)$attempt['ordinal'] : -1;
        $mode = is_scalar($attempt['mode'] ?? null) ? (string)$attempt['mode'] : '';
        if ($part === '' || $payloadKey === '' || $ordinal < 0
                || ($mode !== 'on' && $mode !== 'off')) {
            return null;
        }
        return array(
            'key' => $part . '|' . $payloadKey . '|' . intdiv($ordinal, 2),
            'position' => $ordinal % 2,
            'mode' => $mode,
            'ok' => !empty($attempt['ok']),
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
