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
 * This class owns the step catalog and pure interpretation matrix, exposing
 * compatibility delegates for payload shaping and receipt parsing. It owns
 * no transport, no auth and no journaling --
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
    const STEP_SIZE_TARGET = 'size_target';
    const STEP_SIZE_PROBE = 'size_probe';
    const STEP_INERT = 'inert';
    const STEP_COMPRESS_ON = 'compress_on';
    const STEP_COMPRESS_OFF = 'compress_off';
    const STEP_STREAM = 'stream';
    const STEP_INTERPRET = 'interpret';

    /** Every server-dispatched step. Step 1 (static asset) never reaches PHP by design and so is not listed here. */
    const STEPS = array(
        self::STEP_CONCURRENT_CONTROL, self::STEP_BASELINE_CONTROL, self::STEP_AUTH_ONLY,
        self::STEP_POST_LIMITER, self::STEP_SUMMARY,
        self::STEP_SIZE_TARGET, self::STEP_SIZE_PROBE,
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
        self::STEP_SUMMARY, self::STEP_SIZE_TARGET, self::STEP_SIZE_PROBE,
        self::STEP_INERT, self::STEP_COMPRESS_ON,
        self::STEP_COMPRESS_OFF, self::STEP_STREAM,
    );

    /**
     * Hard bound on the raw `canaryStepReceipts` parameter BEFORE it is
     * parsed. The client caps itself at 24 fixed-shape records (including
     * size/encoding evidence); this is the server refusing to parse more than
     * that regardless of what actually arrives.
     */
    const MAX_STEP_RECEIPTS_BYTES = 16384;

    /** Hard bound on how many receipts one request is allowed to carry. */
    const MAX_STEP_RECEIPTS = 32;

    /** Longest transport status string kept on a receipt ('parsererror' etc). */
    const MAX_TEXT_STATUS_CHARS = 32;

    /** Longest step name kept verbatim when this build does not recognise it. */
    const MAX_REPORTED_STEP_CHARS = 32;

    const PAYLOAD_VARIANT_COMPRESSIBLE = 'compressible';
    const PAYLOAD_VARIANT_INCOMPRESSIBLE = 'incompressible';
    const TARGET_SOURCE_SESSION_JSON = 'session_json_encode';
    const TARGET_SOURCE_BROWSER = 'browser_response';
    const TARGET_SOURCE_DEFAULT = 'default_unavailable';

    const AUTH_ONLY_BYTES = 1024;
    const STREAM_WHITESPACE_BYTES = 2048;

    /** The stack was unbuffered, so an echo reaches the SAPI directly. */
    const STREAM_REASON_UNBUFFERED = 'unbuffered_output';
    /** The plugin's own managed buffer is the only one, so ob_flush() reaches the SAPI. */
    const STREAM_REASON_PLUGIN_OWNS_ONLY_BUFFER = 'plugin_owns_the_only_buffer';
    /** A buffer the plugin does not own sits beneath its own; ob_flush() lands there, not on the wire. */
    const STREAM_REASON_FOREIGN_BUFFER_BELOW = 'foreign_output_buffer_below';
    /** Something opened buffers above the plugin's own, so the top buffer is not ours to flush. */
    const STREAM_REASON_NOT_TOP_BUFFER = 'plugin_does_not_own_top_buffer';
    /** Output-buffer management is filtered off for this request. */
    const STREAM_REASON_MANAGEMENT_OFF = 'output_buffer_management_off';
    const MIN_INERT_BYTES = 64;
    const MAX_INERT_BYTES = 2000000;
    const DEFAULT_INERT_BYTES = 50000;

    /** The `stream` step's flush was observed crossing the SAPI boundary. */
    const STREAM_FLUSH_EVIDENCE_REACHED = 'reached_sapi';

    /** The `stream` step ran and its flush provably reached nobody. */
    const STREAM_FLUSH_EVIDENCE_NOT_REACHED = 'did_not_reach_sapi';

    /** No journaled flush outcome for this session's `stream` step. */
    const STREAM_FLUSH_EVIDENCE_UNKNOWN = 'unknown';

    /**
     * Whether the `stream` step's mid-response flush can actually reach the
     * client, decided from the output-buffer stack this request found.
     *
     * ob_flush() flushes the CURRENT buffer into its PARENT, not to the SAPI.
     * With any foreign buffer beneath the plugin's own managed buffer the
     * whitespace block therefore never reaches the wire at all: it lands one
     * level down, in a buffer the plugin does not own and deliberately will
     * not reclaim (AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput()
     * drains only to `ob_level_before`, so its containment reports "no stray
     * output" while those bytes are already past it). The step then measures
     * nothing about streaming while still prefixing the response body with
     * STREAM_WHITESPACE_BYTES of non-JSON, which is two variables changed at
     * once in the one experiment that is supposed to isolate streaming --
     * so a failure there cannot be attributed to buffering, which is the
     * only conclusion the step exists to support.
     *
     * Emitting a block that provably cannot be flushed buys nothing and
     * costs the comparison against the `inert` step of the same shape, so on
     * such a host the step emits nothing and reports why.
     *
     * Keyed rather than positional: `ob_level_before` and `ob_level_now` are
     * adjacent ints, so a transposed call stays type-correct and answers a
     * different question. (0, 2) means "a handler opened a buffer above the
     * plugin's own", so no flush; swapped to (2, 0) it reads as an unbuffered
     * host and the step flushes 2048 bytes that land in a foreign buffer,
     * which is precisely the failure this plan exists to prevent.
     *
     * @param array{manage_output_buffer: bool, ob_level_before: int, ob_level_now: int} $observation
     *   `manage_output_buffer`: the `abj404_should_manage_output_buffer` decision.
     *   `ob_level_before`: buffer depth found before the plugin opened its own.
     *   `ob_level_now`: buffer depth at the moment of the flush.
     * @return array{stream: bool, reason: string}
     */
    public static function resolveStreamFlushPlan(array $observation): array {
        $reason = self::streamFlushReason($observation);
        // Derived, never carried alongside: the reason exists to explain the
        // boolean, so a plan able to say "streaming, because a foreign buffer
        // sits below us" would be a diagnosis contradicting itself while
        // reading as evidence.
        return array('stream' => self::reasonAllowsFlush($reason), 'reason' => $reason);
    }

    /**
     * Whether a flush reaches the client under this reason. The single source
     * of truth for the pairing, so no caller can invent a different one.
     */
    public static function reasonAllowsFlush(string $reason): bool {
        $allows = array(
            self::STREAM_REASON_MANAGEMENT_OFF => false,
            self::STREAM_REASON_UNBUFFERED => true,
            self::STREAM_REASON_PLUGIN_OWNS_ONLY_BUFFER => true,
            self::STREAM_REASON_FOREIGN_BUFFER_BELOW => false,
            self::STREAM_REASON_NOT_TOP_BUFFER => false,
        );
        // An unknown reason must not read as "go ahead and flush": the only
        // safe default for a state nobody enumerated is to emit nothing.
        return array_key_exists($reason, $allows) ? $allows[$reason] : false;
    }

    /**
     * @param array{manage_output_buffer: bool, ob_level_before: int, ob_level_now: int} $observation
     */
    private static function streamFlushReason(array $observation): string {
        $obLevelBefore = $observation['ob_level_before'];
        $obLevelNow = $observation['ob_level_now'];
        if (!$observation['manage_output_buffer']) {
            return self::STREAM_REASON_MANAGEMENT_OFF;
        }
        if ($obLevelNow <= 0) {
            return self::STREAM_REASON_UNBUFFERED;
        }
        if ($obLevelBefore <= 0 && $obLevelNow === 1) {
            return self::STREAM_REASON_PLUGIN_OWNS_ONLY_BUFFER;
        }
        if ($obLevelBefore > 0) {
            return self::STREAM_REASON_FOREIGN_BUFFER_BELOW;
        }
        return self::STREAM_REASON_NOT_TOP_BUFFER;
    }

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
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::clampTargetBytes($raw, $default);
    }

    /** @param mixed $raw */
    public static function normalizePayloadVariant($raw): string {
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::normalizeVariant($raw);
    }

    /** @param mixed $raw */
    public static function normalizePayloadRungPercent($raw): int {
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::normalizeRungPercent($raw);
    }

    /** @param mixed $raw */
    public static function normalizeTargetBytesSource($raw): string {
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::normalizeTargetSource($raw);
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
        return ABJ_404_Solution_AjaxCanaryReceiptParser::parse($raw);
    }

    /**
     * A JSON-safe filler payload whose ENCODED size lands as close to
     * $targetBytes as the envelope overhead allows. The filler is inert,
     * repeated content -- the ladder measures size and transport behavior,
     * never anything resembling real redirect/URL data.
     *
     * @param array<string, mixed> $extraFields Step-specific findings folded
     *   into the envelope before the filler is sized, so the response still
     *   lands on $targetBytes.
     * @return array{requestId: string, canaryStep: string, filler: string, ...}
     */
    public static function buildFillerPayload(
        string $requestId,
        string $step,
        int $targetBytes,
        array $extraFields = array()
    ): array {
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::buildFiller(
            $requestId, $step, $targetBytes, $extraFields);
    }

    /**
     * A matched-size compressible or high-entropy JSON payload for the
     * geometric size ladder.
     *
     * The incompressible body is deterministic, printable SHA-256 output:
     * no random source can fail, no invalid UTF-8 can break json_encode, and
     * unlike one repeated digest it does not introduce a short repeating
     * period that gzip can collapse. Metadata is part of the envelope before
     * filler length is calculated, so paired variants differ in
     * compressibility rather than decoded response size.
     *
     * @param array{request_id: string, target_bytes: int, variant: string,
     *   rung_percent: int, target_source: string} $options
     * @return array<string, mixed>
     */
    public static function buildPayloadVariant(array $options): array {
        return ABJ_404_Solution_AjaxCanaryPayloadFactory::buildVariant($options);
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
     * @param array<string, mixed> $deliveryEvidence The server-resolved join
     *   of what each response EMITTED against what the browser RECEIVED,
     *   from ABJ_404_Solution_ResponseBodyDeliveryEvidence::forSession().
     *   Kept as its own parameter rather than folded into $observations
     *   because provenance is the point: everything in $observations is what
     *   an untrusted browser said, while these facts were read out of the
     *   server's own durable journal. Unknown keys are ignored and an absent
     *   bag leaves every rule below it unknown, so an older client or an
     *   unreadable journal degrades to "no verdict" rather than to a wrong
     *   one.
     * @return array<string, mixed>
     */
    public static function interpretResults(
        array $observations,
        bool $realRequestFailed = true,
        array $deliveryEvidence = array()
    ): array {
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
        $concurrent = $entry($observations, self::STEP_CONCURRENT_CONTROL);
        $samePhaseControlFailed = self::samePhaseControlFailed($concurrent, $realRequestFailed);
        $rawComparisons = $deliveryEvidence['comparisons'] ?? null;
        $bodySizes = ABJ_404_Solution_ResponseBodyRewriteVerdict::fromComparisons(
            is_array($rawComparisons) ? $rawComparisons : array(), self::MAX_REPORTED_STEP_CHARS);
        $streamFlush = self::streamFlushEvidence($deliveryEvidence);

        return array_merge(array(
            'browserOrNetworkCausal' => !$ok($staticAsset),
            'bootAuthOrDeliveryCausal' => $ok($staticAsset) && !$ok($authOnly),
            'limiterCausal' => $ok($authOnly) && !$ok($postLimiter),
            // req. 7 matrix rule: all server work completes (summary-ok) but
            // an inert response of the SAME size still fails => the failure
            // tracks response size/bandwidth/buffering, not query cost.
            'sizeOrDeliveryCausal' => !$samePhaseControlFailed && $ok($summary) && !$ok($inert),
            // Mirror rule: a same-size inert filler succeeds while the real,
            // content-bearing request failed => something inspects or mangles
            // the CONTENT (redirect URLs/HTML), not merely its size.
            'contentInspectionCausal' => !$samePhaseControlFailed && $ok($inert) && $realRequestFailed,
            'samePhaseControlFailed' => $samePhaseControlFailed,
            'compressionCausal' => $ok($compressOff) && !$ok($compressOn),
            // Requires POSITIVE proof that the step streamed at all. On the
            // 2026-08-27 Azure host ob_flush() emptied the plugin's buffer
            // into a foreign one below it, so nothing crossed the SAPI
            // boundary and the step measured nothing about streaming -- yet
            // its failure read as a streaming failure for a week. Unknown is
            // therefore not "it streamed": with no journaled flush outcome
            // this rule stays false and the run says why through
            // streamFlushEvidence.
            'streamingBufferCausal' => !$ok($stream) && $streamGapMs > 2000
                && $streamFlush === self::STREAM_FLUSH_EVIDENCE_REACHED,
            'streamFlushEvidence' => $streamFlush,
        ), $bodySizes, self::baselineTrend($observations));
    }

    /**
     * Whether this session's `stream` step provably crossed the SAPI
     * boundary, named as a tri-state rather than collapsed to a boolean.
     *
     * @param array<string, mixed> $deliveryEvidence
     */
    private static function streamFlushEvidence(array $deliveryEvidence): string {
        $reached = $deliveryEvidence['stream_flush_reached_sapi'] ?? null;
        if (!is_bool($reached)) {
            return self::STREAM_FLUSH_EVIDENCE_UNKNOWN;
        }
        return $reached
            ? self::STREAM_FLUSH_EVIDENCE_REACHED : self::STREAM_FLUSH_EVIDENCE_NOT_REACHED;
    }

    /**
     * A positive overlap is required before one failed control can veto later
     * causal claims. Missing or malformed browser evidence remains unknown.
     *
     * @param array<string, mixed> $concurrent
     */
    private static function samePhaseControlFailed(array $concurrent, bool $realRequestFailed): bool {
        $receipt = is_array($concurrent['receipt'] ?? null) ? $concurrent['receipt'] : array();
        $overlap = is_array($concurrent['overlap'] ?? null) ? $concurrent['overlap'] : array();
        $tableOutcome = is_scalar($concurrent['tableOutcome'] ?? null)
            ? (string)$concurrent['tableOutcome'] : '';
        $overlapState = is_scalar($overlap['state'] ?? null) ? (string)$overlap['state'] : '';
        return $realRequestFailed
            && $tableOutcome !== 'success'
            && empty($receipt['ok'])
            && $overlapState === ABJ_404_Solution_ConcurrentControlReceipt::OVERLAP_COMPUTED
            && is_numeric($overlap['durationMs'] ?? null)
            && (int)$overlap['durationMs'] > 0;
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

}
