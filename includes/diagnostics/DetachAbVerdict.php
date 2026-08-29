<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which conclusion a counterbalanced detach A/B experiment actually supports
 * (Bruno timeout cause matrix, gap G9 / c434).
 *
 * Its input is the request ledger's own per-attempt journal -- the real table
 * endpoint's workload-matched 'on'/'off' attempts, as recorded by
 * ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode() and
 * ABJ_404_Solution_AjaxAdminEndpointSupport::checkpointedFlushAndFinish() --
 * and its consumer is ABJ_404_Solution_DetachAbEvidence, which assembles the
 * record this verdict travels in. Neither of those is the canary ladder.
 *
 * It lived on ABJ_404_Solution_AjaxCanaryLadder while that class was the only
 * home for the matrix's pure rules, but the two verdicts were always
 * deliberately disjoint: the ladder reads the BROWSER's per-step
 * observations, this reads the SERVER's per-attempt outcomes, and keeping
 * them apart is what stops an ambiguous quadrant in one leaking into the
 * other's conclusion. Separate inputs and separate consumers make it a
 * separate module, not a section of one.
 *
 * Pure and side-effect free, so the comparison logic is directly testable;
 * the caller journals the result.
 */
final class ABJ_404_Solution_DetachAbVerdict {

    /** Detaching is what fixes it: every counterbalanced pair says so. */
    const VERDICT_DETACH_CAUSAL = 'detachCausal';

    /** Both modes succeeded in every pair, so the failure was transient. */
    const VERDICT_TRANSIENT_CAUSAL = 'transientCausal';

    /** Both modes failed in every pair, so the mode is not the variable. */
    const VERDICT_NEITHER_MODE_HELPS = 'neitherModeHelps';

    /**
     * Not enough matched, counterbalanced evidence to say. The default, and
     * deliberately reachable: a single mode with no pair to compare against
     * must never be forced into one of the three findings above.
     */
    const VERDICT_INCONCLUSIVE = 'inconclusive';

    /**
     * The decisive-measurement rule for the detach A/B experiment (Bruno
     * timeout cause matrix, gap G9 / c434;
     * ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode() picks the
     * mode, ABJ_404_Solution_AjaxAdminEndpointSupport::checkpointedFlushAndFinish()
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
     * @param array<int, ABJ_404_Solution_DetachAbAttempt> $attempts
     *   Chronological per-request outcomes for the real table endpoint's own
     *   workload-matched A/B attempts, already parsed at the journal boundary
     *   by ABJ_404_Solution_DetachAbAttempt::fromJournalRecord(). This rule
     *   re-inspects nothing: the mode is MODE_ON or MODE_OFF by construction,
     *   and an attempt either has pair coordinates or does not.
     * @return array<string, mixed>
     */
    public static function fromAttempts(array $attempts): array {
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
        // ONE discriminant, then the flags read off it. Written as four
        // independent booleans this was a shape in which "detach is the cause"
        // and "neither mode helps" could both be true, or all four false: not
        // reachable through today's arithmetic, but nothing prevented it, and a
        // reader had to re-derive which single outcome was meant. The support
        // renderer was already doing exactly that -- looping the flag names to
        // recover one word -- which is the shape asking to be a discriminant.
        $verdict = self::VERDICT_INCONCLUSIVE;
        if ($pairCount >= 2 && $orderCounterbalanced && $detachPairs === $pairCount) {
            $verdict = self::VERDICT_DETACH_CAUSAL;
        } else if ($pairCount > 0 && $transientPairs === $pairCount) {
            $verdict = self::VERDICT_TRANSIENT_CAUSAL;
        } else if ($pairCount > 0 && $neitherPairs === $pairCount) {
            $verdict = self::VERDICT_NEITHER_MODE_HELPS;
        }

        return array(
            'verdict' => $verdict,
            // Derived, never decided here: exactly one is true on every path,
            // by construction rather than by the arithmetic happening to agree.
            'detachCausal' => $verdict === self::VERDICT_DETACH_CAUSAL,
            'transientCausal' => $verdict === self::VERDICT_TRANSIENT_CAUSAL,
            'neitherModeHelps' => $verdict === self::VERDICT_NEITHER_MODE_HELPS,
            'inconclusive' => $verdict === self::VERDICT_INCONCLUSIVE,
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
     * @param array<int, ABJ_404_Solution_DetachAbAttempt> $attempts
     * @return array<int, array{on_ok: bool, off_ok: bool, on_first: bool}>
     */
    private static function matchedDetachAbPairs(array $attempts): array {
        $grouped = array();
        $duplicates = array();
        foreach ($attempts as $attempt) {
            $slot = $attempt->pairSlot();
            if ($slot === null) {
                continue;
            }
            if (isset($grouped[$slot['key']][$slot['position']])) {
                $duplicates[$slot['key']] = true;
                continue;
            }
            $grouped[$slot['key']][$slot['position']] = $attempt;
        }

        $pairs = array();
        foreach ($grouped as $key => $positions) {
            if (isset($duplicates[$key]) || !isset($positions[0], $positions[1])
                    || $positions[0]->mode() === $positions[1]->mode()) {
                continue;
            }
            $onFirst = $positions[0]->mode() === ABJ_404_Solution_DetachAbAttempt::MODE_ON;
            $on = $onFirst ? $positions[0] : $positions[1];
            $off = $onFirst ? $positions[1] : $positions[0];
            $pairs[] = array(
                'on_ok' => $on->completed() === true,
                'off_ok' => $off->completed() === true,
                'on_first' => $onFirst,
            );
        }
        return $pairs;
    }

    /**
     * Count per-mode attempts and completions, split out of
     * fromAttempts() purely to keep that method's cyclomatic
     * complexity within the project's ceiling -- this loop is one
     * self-contained tally, not logic that needs to be inlined at the call
     * site.
     *
     * @param array<int, ABJ_404_Solution_DetachAbAttempt> $attempts
     * @return array{on: int, onOk: int, off: int, offOk: int}
     */
    private static function tallyDetachAbAttempts(array $attempts): array {
        $tally = array('on' => 0, 'onOk' => 0, 'off' => 0, 'offOk' => 0);
        foreach ($attempts as $attempt) {
            // Named explicitly rather than built by concatenating the mode onto
            // 'Ok'. A constructed key is a second, unchecked spelling of the
            // protocol: rename a mode and the tally silently starts counting
            // into a field nothing reads, with every total reading zero.
            $completed = $attempt->completed() === true;
            if ($attempt->mode() === ABJ_404_Solution_DetachAbAttempt::MODE_ON) {
                $tally['on']++;
                $tally['onOk'] += $completed ? 1 : 0;
                continue;
            }
            $tally['off']++;
            $tally['offOk'] += $completed ? 1 : 0;
        }
        return $tally;
    }
}
