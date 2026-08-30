<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure causal interpretation policy for completed canary ladder observations.
 *
 * This class reads no journals and emits no response. Callers supply browser
 * observations and the already-joined server delivery evidence.
 */
final class ABJ_404_Solution_CanaryLadderInterpretation {

    /** The stream step's flush was observed crossing the SAPI boundary. */
    const STREAM_FLUSH_EVIDENCE_REACHED = 'reached_sapi';

    /** The stream step ran and its flush provably reached nobody. */
    const STREAM_FLUSH_EVIDENCE_NOT_REACHED = 'did_not_reach_sapi';

    /** No journaled flush outcome exists for the stream step. */
    const STREAM_FLUSH_EVIDENCE_UNKNOWN = 'unknown';

    /** The same-phase control ran and failed alongside the real request. */
    const CONTROL_EVIDENCE_FAILED = 'failed';

    /** The same-phase control ran and did not fail: claims below are vetted. */
    const CONTROL_EVIDENCE_DID_NOT_FAIL = 'did_not_fail';

    /**
     * The control produced no usable overlap, so whether it failed is unknown.
     *
     * Published beside the older `samePhaseControlFailed` boolean rather than
     * replacing it: that field is already in support payloads, and a reader
     * that knows only the boolean must keep working. The boolean cannot
     * express this third state, which is exactly how "we never found out"
     * came to be read as "it did not fail".
     */
    const CONTROL_EVIDENCE_UNKNOWN = 'unknown';

    /** Maximum hostile step-name length accepted by the rewrite verdict. */
    private const MAX_REPORTED_STEP_CHARS = 32;

    /**
     * The steps this class reads, which is NOT CanaryLadderStep::DISPATCHED.
     *
     * static_asset is a browser-only fetch with no server dispatch, so it is
     * absent from DISPATCHED while being the very first thing interpreted;
     * reusing that list would silently drop the step whose absence the
     * observedSteps field exists to reveal.
     */
    private const INTERPRETED_STEPS = array(
        ABJ_404_Solution_CanaryLadderStep::STATIC_ASSET,
        ABJ_404_Solution_CanaryLadderStep::CONCURRENT_CONTROL,
        ABJ_404_Solution_CanaryLadderStep::AUTH_ONLY,
        ABJ_404_Solution_CanaryLadderStep::POST_LIMITER,
        ABJ_404_Solution_CanaryLadderStep::SUMMARY,
        ABJ_404_Solution_CanaryLadderStep::INERT,
        ABJ_404_Solution_CanaryLadderStep::COMPRESS_ON,
        ABJ_404_Solution_CanaryLadderStep::COMPRESS_OFF,
        ABJ_404_Solution_CanaryLadderStep::STREAM,
    );

    /**
     * The interpretation matrix: which single cause space the ladder's
     * outcomes point to. Pure and side-effect free so the comparison logic
     * can be tested directly; the AJAX orchestration journals the result.
     *
     * @param array<string, mixed> $observations Client-reported outcomes keyed by step ID.
     * @param bool $realRequestFailed Whether the real request that armed the ladder failed.
     * @param array<string, mixed> $deliveryEvidence Server-resolved emitted/delivered evidence.
     * @return array<string, mixed>
     */
    public static function interpret(
        array $observations,
        bool $realRequestFailed = true,
        array $deliveryEvidence = array()
    ): array {
        $entry = static function (array $obs, string $step): array {
            $found = $obs[$step] ?? null;
            return is_array($found) ? $found : array();
        };
        // Whether the browser reported this step AT ALL, which is a different
        // question from whether it succeeded. Every `ok` test on an unreported
        // step answers false, so without this the ladder cannot tell "the step
        // failed" from "the step never ran" -- and it published the first
        // answer for the second, manufacturing a causal claim about a user's
        // site out of an empty observation map. Each verdict below therefore
        // requires positive evidence that the steps it reasons about were
        // actually measured.
        $observed = static function (array $obs, string $step): bool {
            return is_array($obs[$step] ?? null);
        };
        $ok = static function (array $found): bool {
            return !empty($found['ok']);
        };

        $staticAsset = $entry($observations, ABJ_404_Solution_CanaryLadderStep::STATIC_ASSET);
        $authOnly = $entry($observations, ABJ_404_Solution_CanaryLadderStep::AUTH_ONLY);
        $postLimiter = $entry($observations, ABJ_404_Solution_CanaryLadderStep::POST_LIMITER);
        $summary = $entry($observations, ABJ_404_Solution_CanaryLadderStep::SUMMARY);
        $inert = $entry($observations, ABJ_404_Solution_CanaryLadderStep::INERT);
        $compressOn = $entry($observations, ABJ_404_Solution_CanaryLadderStep::COMPRESS_ON);
        $compressOff = $entry($observations, ABJ_404_Solution_CanaryLadderStep::COMPRESS_OFF);
        $stream = $entry($observations, ABJ_404_Solution_CanaryLadderStep::STREAM);
        $streamGapMs = isset($stream['gapMs']) && is_numeric($stream['gapMs']) ? (int)$stream['gapMs'] : 0;
        $concurrent = $entry($observations, ABJ_404_Solution_CanaryLadderStep::CONCURRENT_CONTROL);
        $controlEvidence = self::samePhaseControlEvidence($concurrent, $realRequestFailed);
        $samePhaseControlFailed = $controlEvidence === self::CONTROL_EVIDENCE_FAILED;
        $rawComparisons = $deliveryEvidence['comparisons'] ?? null;
        $bodySizes = ABJ_404_Solution_ResponseBodyRewriteVerdict::fromComparisons(
            is_array($rawComparisons) ? $rawComparisons : array(),
            self::MAX_REPORTED_STEP_CHARS
        );
        $streamFlush = self::streamFlushEvidence($deliveryEvidence);

        return array_merge(array(
            'browserOrNetworkCausal' => $observed($observations, ABJ_404_Solution_CanaryLadderStep::STATIC_ASSET)
                && !$ok($staticAsset),
            'bootAuthOrDeliveryCausal' => $ok($staticAsset)
                && $observed($observations, ABJ_404_Solution_CanaryLadderStep::AUTH_ONLY)
                && !$ok($authOnly),
            'limiterCausal' => $ok($authOnly)
                && $observed($observations, ABJ_404_Solution_CanaryLadderStep::POST_LIMITER)
                && !$ok($postLimiter),
            // All server work completes but an inert response of the same
            // size fails: the failure tracks size or delivery, not query cost.
            // A conclusively failed control vetoes these two: if the control
            // failed in the same phase, everything was failing and neither
            // finding is about size or content. An UNKNOWN control does not
            // veto, deliberately -- withholding the claim would make `false`
            // mean either "not this cause" or "could not tell", which is the
            // same two-meanings-in-one-value defect this file is fixing
            // elsewhere. The claim stands and `samePhaseControlEvidence` says
            // whether anything vetted it.
            'sizeOrDeliveryCausal' => !$samePhaseControlFailed && $ok($summary)
                && $observed($observations, ABJ_404_Solution_CanaryLadderStep::INERT)
                && !$ok($inert),
            // A same-size inert filler succeeds while the real request fails:
            // the failure tracks content inspection rather than size alone.
            'contentInspectionCausal' => !$samePhaseControlFailed && $ok($inert) && $realRequestFailed,
            'samePhaseControlFailed' => $samePhaseControlFailed,
            'samePhaseControlEvidence' => $controlEvidence,
            'compressionCausal' => $ok($compressOff)
                && $observed($observations, ABJ_404_Solution_CanaryLadderStep::COMPRESS_ON)
                && !$ok($compressOn),
            // Positive evidence that the flush crossed the SAPI is required.
            // Unknown cannot mean streamed: a foreign buffer can absorb the
            // flush while the request still reports a long browser-side gap.
            'streamingBufferCausal' => !$ok($stream) && $streamGapMs > 2000
                && $streamFlush === self::STREAM_FLUSH_EVIDENCE_REACHED,
            'streamFlushEvidence' => $streamFlush,
            // Which steps actually reported. Without this a `false` causal flag
            // means either "this step ran and cleared the cause" or "this step
            // never ran", and a reader cannot tell a healthy ladder from a
            // ladder that barely executed. Published as a list so it stays
            // additive for readers that do not know the field.
            'observedSteps' => self::observedSteps($observations),
        ), $bodySizes, self::baselineTrend($observations));
    }

    /**
     * Name flush evidence as a tri-state instead of collapsing unknown to a
     * boolean that would silently claim a measurement was made.
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
     * The ladder steps the browser actually reported, in a stable order.
     *
     * Reads the same key names the verdicts above read, so a step that stops
     * being interpreted cannot keep appearing here as though it were.
     *
     * @param array<string, mixed> $observations
     * @return array<int, string>
     */
    private static function observedSteps(array $observations): array {
        $reported = array();
        foreach (self::INTERPRETED_STEPS as $step) {
            if (is_array($observations[$step] ?? null)) {
                $reported[] = $step;
            }
        }
        return $reported;
    }

    /**
     * Whether the same-phase control failed, did not fail, or never said.
     *
     * This used to answer a bare bool, and its docblock claimed missing or
     * malformed evidence "remains unknown". The consumption is what made that
     * false: an unknown control returned false, the callers read
     * `!$samePhaseControlFailed` as permission, and a claim nothing had vetted
     * shipped indistinguishable from one a healthy control had cleared.
     * Absence of a veto is not permission.
     *
     * Both non-unknown answers require the SAME positive evidence -- a computed
     * overlap with a real duration -- because that overlap is what makes the
     * control same-phase at all. Without it there is no control, only a
     * request that happened nearby.
     *
     * @param array<string, mixed> $concurrent
     * @return self::CONTROL_EVIDENCE_*
     */
    private static function samePhaseControlEvidence(
        array $concurrent,
        bool $realRequestFailed
    ): string {
        $receipt = is_array($concurrent['receipt'] ?? null) ? $concurrent['receipt'] : array();
        $overlap = is_array($concurrent['overlap'] ?? null) ? $concurrent['overlap'] : array();
        $tableOutcome = is_scalar($concurrent['tableOutcome'] ?? null)
            ? (string)$concurrent['tableOutcome'] : '';
        $overlapState = is_scalar($overlap['state'] ?? null) ? (string)$overlap['state'] : '';

        $overlapMeasured = $overlapState === ABJ_404_Solution_ConcurrentControlReceipt::OVERLAP_COMPUTED
            && ABJ_404_Solution_ExactInteger::readOr($overlap['durationMs'] ?? null, 1, 0) > 0;
        if (!$overlapMeasured) {
            return self::CONTROL_EVIDENCE_UNKNOWN;
        }
        if ($realRequestFailed && $tableOutcome !== 'success' && empty($receipt['ok'])) {
            return self::CONTROL_EVIDENCE_FAILED;
        }
        return self::CONTROL_EVIDENCE_DID_NOT_FAIL;
    }

    /**
     * Summarize repeated fixed-size controls in chronological order. A
     * changing control is drift, never a step effect.
     *
     * @param array<string, mixed> $observations
     * @return array<string, int>
     */
    private static function baselineTrend(array $observations): array {
        $raw = $observations[ABJ_404_Solution_CanaryLadderStep::BASELINE_CONTROL] ?? array();
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
