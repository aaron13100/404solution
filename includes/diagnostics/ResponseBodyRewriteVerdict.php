<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether anything between this server and this browser REWROTE a response
 * body.
 *
 * Its input is the joined byte accounting from
 * ABJ_404_Solution_ResponseBodyDeliveryEvidence -- how many body bytes the
 * server wrote for one response against how many the browser's Resource
 * Timing says arrived -- and it has two consumers: the evidence record itself
 * (so a support payload read months later states the conclusion even when the
 * `interpret` response never reached the browser) and
 * ABJ_404_Solution_CanaryLadderInterpretation::interpret(), which folds it into
 * the ladder matrix. Two consumers over one input is what makes it a module
 * rather than a section of the ladder, the same way
 * ABJ_404_Solution_DetachAbVerdict is.
 *
 * It is deliberately NOT part of the ladder's own comparison logic. Every
 * other rule in that matrix infers a cause by comparing two probes against
 * each other; this one is a direct physical measurement of a single response,
 * in bytes, and it is therefore not vetoed by a failed same-phase control: a
 * control that also failed says nothing about whether these two byte counts
 * differ.
 *
 * Pure and side-effect free, so the rule is directly testable; the caller
 * journals the result.
 */
final class ABJ_404_Solution_ResponseBodyRewriteVerdict {

    /**
     * How far the browser's delivered byte count may sit from the server's
     * emitted byte count before the body is called rewritten in transit.
     *
     * ZERO, from first principles rather than from caution. Both numbers count
     * the same thing in the same unit: PHP's `json_encode` result is the exact
     * octet length of the body the plugin wrote, and Resource Timing defines
     * `decodedBodySize` as the octet size of the payload body AFTER any
     * content-codings are removed. Compression, chunked framing and header
     * bytes are all outside both counts by definition, so on an unmodified
     * response they are equal byte for byte, and any tolerance at all would
     * only be a window for a rewrite to hide in.
     *
     * The one thing that legitimately makes the two differ is the canary
     * ladder's `stream` step, whose leading whitespace block is echoed outside
     * json_encode(). That is answered by ACCOUNTING for those bytes
     * (ABJ_404_Solution_ResponseBodyDeliveryEvidence adds the journaled
     * `streamWhitespaceBytes` to the emitted count), never by widening this
     * constant: exact accounting keeps the 2048-byte case detectable, while
     * 2048 bytes of slack would have hidden a 2048-byte rewrite.
     */
    const TOLERANCE_BYTES = 0;

    /**
     * The verdict over a list of emitted/delivered comparisons.
     *
     * A response that arrives complete and unparseable at a different byte
     * count than the server wrote has been altered after this plugin encoded
     * it: by a proxy or CDN that appended to HTML it mistook the JSON for, an
     * optimizer that "minifies" every response, or -- on a SAPI with no
     * connection detach -- another PHP component echoing after our own echo.
     * The verdict deliberately does not try to separate those: what it proves
     * is that the bytes the browser received are not the bytes this plugin
     * wrote, which is the fact that was missing, and the remaining candidates
     * are all outside this plugin either way. Support report 2026-08-27
     * (plugin 4.3.4, Azure App Service) is the case: 3072 bytes emitted for
     * the `stream` canary, 6089 delivered, `parsererror`,
     * `truncated_on_arrival = false`.
     *
     * Every comparison with either half missing is counted as unknown and
     * reaches no verdict. An absent `decodedBodySize` is a finding about the
     * BROWSER -- no Resource Timing entry, a timing-restricted response, a
     * cleared buffer -- and never evidence that the body arrived intact, so
     * unknown can only ever leave `causal` false, never make it true.
     *
     * @param array<int, mixed> $comparisons Rows from
     *   ABJ_404_Solution_ResponseBodyDeliveryEvidence::comparisonsIn(). Only
     *   `step`, `emitted_bytes` and `delivered_bytes` are read; unknown fields
     *   are tolerated so the persisted diagnostic shape stays additive.
     * @param int $maxStepChars Bound on each step name copied into the
     *   verdict, so an unrecognised step reported by a hostile client cannot
     *   grow the record.
     * @return array{bodyRewrittenInTransitCausal: bool,
     *   bodyRewrittenInTransitSteps: string, bodySizeComparisonsMismatched: int,
     *   bodySizeComparisonsMatched: int, bodySizeComparisonsUnknown: int}
     *   Every field is scalar on purpose: the ladder copies only scalars into
     *   the stage trace, and WHICH response was rewritten is the half of this
     *   finding that makes it actionable.
     */
    public static function fromComparisons(array $comparisons, int $maxStepChars = 32): array {
        $rewritten = array();
        $matched = 0;
        $unknown = 0;
        foreach ($comparisons as $comparison) {
            if (!is_array($comparison)) {
                continue;
            }
            $emitted = $comparison['emitted_bytes'] ?? null;
            $delivered = $comparison['delivered_bytes'] ?? null;
            if (!is_numeric($emitted) || !is_numeric($delivered)) {
                $unknown++;
                continue;
            }
            if (abs((int)$delivered - (int)$emitted) > self::TOLERANCE_BYTES) {
                $step = is_scalar($comparison['step'] ?? null) ? (string)$comparison['step'] : '';
                $rewritten[] = substr($step, 0, max(1, $maxStepChars));
                continue;
            }
            $matched++;
        }
        return array(
            'bodyRewrittenInTransitCausal' => $rewritten !== array(),
            'bodyRewrittenInTransitSteps' => implode(',', $rewritten),
            'bodySizeComparisonsMismatched' => count($rewritten),
            'bodySizeComparisonsMatched' => $matched,
            'bodySizeComparisonsUnknown' => $unknown,
        );
    }
}
