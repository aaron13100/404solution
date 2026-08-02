<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The support-payload section carrying the detach A/B experiment's verdict for
 * the session that clicked "send".
 *
 * The experiment's two halves are produced in different places and never in the
 * same record: the server chose each table request's detach mode, and the
 * browser later said whether that request completed. Joining them is
 * ABJ_404_Solution_DetachAbEvidence's job, and until support assembly called it
 * the only trigger was the canary ladder -- which runs ONLY after a foreground
 * table failure. That covers a session where the OFF attempt hung and leaves the
 * primary question uncovered: when a beta session goes WELL, nothing fails, no
 * ladder runs, and the developer receives the raw halves and has to join them by
 * hand. Support-request assembly is the one moment that happens in healthy and
 * failing sessions alike.
 *
 * ABJ_404_Solution_DetachAbEvidence owns deciding the verdict; this class owns
 * only the payload job of asking for it and rendering the answer inside a byte
 * budget, which is the same contract every other *SupportSection here honours.
 */
final class ABJ_404_Solution_DetachAbVerdictSupportSection {

    /**
     * Hard cap on the rendered block. It is a decision plus the bounded list of
     * attempts it was decided from, so it is small by construction; the cap is
     * what keeps it small no matter what a long-lived session put in the
     * journal, and render() sheds the attempt list to fit rather than being cut
     * mid-record. Reclaimed from the checkpoint excerpt budget so the section sum
     * stays inside the report contract -- see
     * ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES and
     * SupportExcerptBudgetContractTest.
     */
    const MAX_DETACH_AB_VERDICT_BYTES = 2048;

    /** The one JSON key the verdict hangs under, so a reader can grep for it. */
    const DETACH_AB_VERDICT_KEY = 'abj404_detach_ab_verdict';

    /**
     * The whole section, ready to join into the support payload.
     *
     * Guarded twice, because a support request is the last thing that may be
     * blocked by its own diagnostics: a partially recovered install can be
     * missing any plugin file (see the safe-autoloader work for error 18), and a
     * journal read that throws must degrade to a stated reason rather than to a
     * fatal in the request the admin is waiting on.
     */
    public static function compose(string $sessionId): string {
        if (!class_exists('ABJ_404_Solution_DetachAbEvidence')) {
            return 'Detach A/B verdict unavailable: ABJ_404_Solution_DetachAbEvidence could not be'
                . ' loaded on this install, so the experiment could not be decided here.';
        }
        try {
            return self::render(ABJ_404_Solution_DetachAbEvidence::verdictForSession($sessionId));
        } catch (Throwable $e) {
            return 'Detach A/B verdict could not be computed: ' . substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * The verdict as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the attempt LIST -- the one reducible part -- and
     * then falls back to the decision alone, rather than being cut at a byte
     * offset: a record cut mid-JSON is unreadable by machine and misleading to a
     * human, which is the same failure the drained client buffer already taught
     * the composer (see SupportEvidenceExcerpt::appendClientTransportTelemetry).
     *
     * @param array<string, mixed> $record ABJ_404_Solution_DetachAbEvidence::verdictForSession().
     */
    private static function render(array $record): string {
        $header = 'Detach A/B verdict -- ' . self::summary($record) . " (JSON):\n";
        $reduced = $record;
        $reduced['attempts'] = array();
        $reduced['attempts_reduced'] = 'over_budget';
        $minimal = array(
            'status' => self::textOf($record, 'status'),
            'session_key' => self::textOf($record, 'session_key'),
            'verdict' => $record['verdict'] ?? array(),
            'reduced' => 'over_budget',
        );
        foreach (array($record, $reduced, $minimal) as $candidate) {
            $line = json_encode(array(self::DETACH_AB_VERDICT_KEY => $candidate));
            if (is_string($line) && strlen($header) + strlen($line) <= self::MAX_DETACH_AB_VERDICT_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The verdict record could not be encoded for this payload.';
    }

    /**
     * The one-line version, so the first thing a reader sees is the decision and
     * how much evidence it was drawn from. The counts are part of the summary
     * rather than decoration: a verdict of 'inconclusive' over zero attempts and
     * one over six attempts are entirely different findings.
     *
     * @param array<string, mixed> $record
     */
    private static function summary(array $record): string {
        $verdict = isset($record['verdict']) && is_array($record['verdict'])
            ? $record['verdict'] : array();
        $named = 'inconclusive';
        foreach (array('detachCausal', 'transientCausal', 'neitherModeHelps') as $quadrant) {
            if (!empty($verdict[$quadrant])) {
                $named = $quadrant;
                break;
            }
        }
        return self::textOf($record, 'status') . ': ' . $named . '; '
            . self::countOf($record, 'attempts_with_mode') . ' attempt(s) with a mode, '
            . self::countOf($record, 'attempts_resolved') . ' resolved by the browser, '
            . self::countOf($record, 'attempts_unresolved') . ' still unreported';
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<string, mixed> $record
     */
    private static function textOf(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * One record field as an integer, or 0 when it is absent or not scalar.
     *
     * @param array<string, mixed> $record
     */
    private static function countOf(array $record, string $field): int {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (int)$value : 0;
    }
}
