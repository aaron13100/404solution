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

    /** Maximum hostile step-name length accepted by the rewrite verdict. */
    private const MAX_REPORTED_STEP_CHARS = 32;

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
        $samePhaseControlFailed = self::samePhaseControlFailed($concurrent, $realRequestFailed);
        $rawComparisons = $deliveryEvidence['comparisons'] ?? null;
        $bodySizes = ABJ_404_Solution_ResponseBodyRewriteVerdict::fromComparisons(
            is_array($rawComparisons) ? $rawComparisons : array(),
            self::MAX_REPORTED_STEP_CHARS
        );
        $streamFlush = self::streamFlushEvidence($deliveryEvidence);

        return array_merge(array(
            'browserOrNetworkCausal' => !$ok($staticAsset),
            'bootAuthOrDeliveryCausal' => $ok($staticAsset) && !$ok($authOnly),
            'limiterCausal' => $ok($authOnly) && !$ok($postLimiter),
            // All server work completes but an inert response of the same
            // size fails: the failure tracks size or delivery, not query cost.
            'sizeOrDeliveryCausal' => !$samePhaseControlFailed && $ok($summary) && !$ok($inert),
            // A same-size inert filler succeeds while the real request fails:
            // the failure tracks content inspection rather than size alone.
            'contentInspectionCausal' => !$samePhaseControlFailed && $ok($inert) && $realRequestFailed,
            'samePhaseControlFailed' => $samePhaseControlFailed,
            'compressionCausal' => $ok($compressOff) && !$ok($compressOn),
            // Positive evidence that the flush crossed the SAPI is required.
            // Unknown cannot mean streamed: a foreign buffer can absorb the
            // flush while the request still reports a long browser-side gap.
            'streamingBufferCausal' => !$ok($stream) && $streamGapMs > 2000
                && $streamFlush === self::STREAM_FLUSH_EVIDENCE_REACHED,
            'streamFlushEvidence' => $streamFlush,
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
     * Require positive overlap before a failed control can veto later causal
     * claims. Missing or malformed browser evidence remains unknown.
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
