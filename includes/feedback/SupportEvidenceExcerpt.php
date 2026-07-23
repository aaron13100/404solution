<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything a support report carries in its `debug_log_excerpt` field, and
 * the byte contract that field has to stay inside.
 *
 * Five independent sources feed one string: a manifest of what the collector
 * looked for, the detach A/B experiment's verdict for the session that
 * clicked, the sanitized debug-log tail, the two durable AJAX diagnostic
 * journals, and the browser's own drained transport buffer. Deciding which of
 * those a report carries, in what order, and how the sum stays under the wire
 * contract is a different job from answering an AJAX request, and it is the
 * job with the interesting failure modes: every one of beta.1's evidence
 * losses happened here, not in the endpoint.
 *
 * Three ordering rules are load-bearing rather than cosmetic:
 *
 *   1. The collection manifest goes FIRST, because bound() cuts the tail. The
 *      one section that must survive a saturated payload is the one that says
 *      what was looked for.
 *   2. The detach A/B verdict follows, ahead of every evidence section, for
 *      the same reason: it is the conclusion drawn FROM that evidence, and a
 *      conclusion that gets cut off the end of a busy session's payload is
 *      exactly the manual join it exists to replace.
 *   3. The journals follow in read order, so a reader walks the session the
 *      same way the journals were written.
 *
 * The class takes the browser's own inputs as an argument rather than reading
 * $_POST: the request boundary belongs to the handler, and passing them in is
 * what lets the same assembly run from anywhere (the report preview, a future
 * CLI dump) without a fabricated superglobal.
 */
final class ABJ_404_Solution_SupportEvidenceExcerpt {

    /**
     * The report contract's own bound on debug_log_excerpt
     * (contracts/schemas/report.schema.json, maxLength). Every section written
     * into that field is bounded so their sum provably fits underneath this,
     * which is what stops a bigger diagnostic budget from turning "the journal
     * reader dropped the evidence" into "the endpoint rejected the payload".
     * SupportExcerptBudgetContractTest proves the arithmetic; the clamp in
     * bound() is the backstop that makes it unconditional.
     */
    const MAX_DEBUG_LOG_EXCERPT_BYTES = 262144;

    /**
     * Hard cap on the sanitized debug-log tail. It is assembled from a bounded
     * NUMBER of entries (15 errors plus 20 recent lines), not a bounded number
     * of BYTES, so a single site that logs a large blob could otherwise push
     * the assembled excerpt past the contract on its own.
     */
    const MAX_LOGGER_EXCERPT_LENGTH = 32768;

    /**
     * Hard cap on the drained client transport telemetry. The buffer is bounded
     * on the browser side too; this is the server refusing to append more than
     * that to the report regardless of what arrives.
     */
    const MAX_CLIENT_TELEMETRY_LENGTH = 32768;

    /**
     * Hard cap on the always-present collection manifest. Small on purpose: it
     * describes the read rather than carrying evidence, and it must never be
     * able to crowd out the evidence it describes.
     * ABJ_404_Solution_DiagnosticCollectionManifest sheds detail to fit this
     * instead of being cut, so an over-budget manifest still states its counts.
     */
    const MAX_COLLECTION_MANIFEST_BYTES = 8192;

    /**
     * Hard cap on the detach A/B verdict block. It is a decision plus the
     * bounded list of attempts it was decided from, so it is small by
     * construction; the cap is what keeps it small no matter what a long-lived
     * session put in the journal, and renderDetachAbVerdict() sheds the attempt
     * list to fit rather than being cut mid-record.
     */
    const MAX_DETACH_AB_VERDICT_BYTES = 2048;

    /** The one JSON key the verdict hangs under, so a reader can grep for it. */
    const DETACH_AB_VERDICT_KEY = 'abj404_detach_ab_verdict';

    /**
     * The whole excerpt, ready for the payload.
     *
     * The browser's two contributions arrive as one named bag rather than as
     * two positional strings: both are opaque browser-supplied text, and a
     * swapped pair would silently produce a verdict about a session id that is
     * really a telemetry buffer while reporting the buffer as unparseable.
     *
     * @param array{telemetry?: string, session_id?: string} $client
     *   telemetry: the drained attempt buffer, already unslashed.
     *   session_id: the browser session id this request was sent from, which is
     *   what the detach A/B verdict is scoped to.
     * @return string
     */
    public static function assemble(array $client): string {
        $clientTelemetry = self::clientField($client, 'telemetry');
        $channels = self::collectChannels();
        $sections = array(
            self::collectionManifest($channels, $clientTelemetry),
            self::detachAbVerdict(self::clientField($client, 'session_id')),
            self::loggerExcerpt(),
        );
        foreach ($channels as $channel) {
            $sections[] = $channel['collected'];
        }
        return self::bound(
            self::appendClientTransportTelemetry(self::joinSections($sections), $clientTelemetry));
    }

    /**
     * One field of the client bag as a string, or '' when it is absent or not
     * scalar. Assembly is total by construction: a caller that omits a field
     * gets the section that field feeds saying so, never a type error inside a
     * support request the admin is waiting on.
     *
     * @param array{telemetry?: string, session_id?: string} $client
     */
    private static function clientField(array $client, string $field): string {
        $value = $client[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Both durable AJAX diagnostic journals: what each one was asked for, and
     * what it gave back.
     *
     * The stage trace and the checkpoint log are separate channels on purpose
     * (a defect in the trace must not be able to erase the evidence about it),
     * so draining only one of them reintroduces the beta.1 failure mode from
     * the read side: a request that never reached its first stage writes
     * nothing to the trace, and its whole story lives in the checkpoints. Each
     * is labeled and independently bounded, so a failure to read one still
     * yields the other.
     *
     * The candidate paths come back paired with the text they produced,
     * because the manifest below has to describe the read that actually
     * happened, not a second guess at what it would have read.
     *
     * Every channel is sourced BEFORE any of them is read, because the two
     * excerpts are ranked from one shared failure index and the index has to
     * be complete before the first read spends its budget. The browser writes
     * its verdicts to the checkpoint journal only, so without this the stage
     * trace ranks a request PHP completed and the browser never received as
     * ordinary healthy context -- and that request's stage timings are the
     * evidence for where the response was built before it failed to arrive.
     *
     * @return array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string}>
     */
    private static function collectChannels(): array {
        $trace = class_exists('ABJ_404_Solution_AjaxRequestTrace')
            ? ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource() : null;
        $checkpoints = class_exists('ABJ_404_Solution_CheckpointJournalReader')
            ? ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource() : null;

        // Built per channel rather than from one merged path list: each
        // channel's read is bounded by its own file count and byte allowance,
        // and an index assembled over a merged list would silently drop the
        // files that fell off the far end of a combined bound.
        $failingIds = array();
        if (class_exists('ABJ_404_Solution_DiagnosticJournalExcerpt')) {
            foreach (array($trace, $checkpoints) as $source) {
                if ($source !== null) {
                    $failingIds += ABJ_404_Solution_DiagnosticJournalExcerpt::failureIndex($source['paths']);
                }
            }
        }

        $channels = array();
        if ($trace !== null) {
            $trace['collected'] = ABJ_404_Solution_AjaxTraceJournal::readRecentForSupport($failingIds);
            $channels[] = $trace;
        }
        if ($checkpoints !== null) {
            $checkpoints['collected'] =
                ABJ_404_Solution_CheckpointJournalReader::readRecentForSupport($failingIds);
            $channels[] = $checkpoints;
        }
        return $channels;
    }

    /**
     * The always-present manifest section.
     *
     * Reading both journals is not enough on its own, which is the other half
     * of beta.1: they came back EMPTY and the payload could not say whether
     * that meant "nothing was written", "the collector looked in the wrong
     * place", or "the read regressed". So this goes out unconditionally.
     *
     * The manifest classes are guarded the same way the journals are, because
     * a partially recovered install can be missing any plugin file (see the
     * safe-autoloader work for error 18). A missing manifest class is reported
     * in the payload rather than silently skipped: an absent manifest is
     * exactly the ambiguity this section exists to remove.
     *
     * @param array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string}> $channels
     */
    private static function collectionManifest(array $channels, string $clientTelemetry): string {
        if (!class_exists('ABJ_404_Solution_DiagnosticCollectionManifest')
                || !class_exists('ABJ_404_Solution_ClientTransportReport')) {
            return 'Diagnostic collection manifest unavailable: the manifest classes could not be loaded'
                . ' on this install, so what the collector checked cannot be stated.';
        }
        return ABJ_404_Solution_DiagnosticCollectionManifest::compose(
            $channels,
            ABJ_404_Solution_ClientTransportReport::attemptIdsInDrainedBuffer($clientTelemetry),
            self::MAX_COLLECTION_MANIFEST_BYTES
        );
    }

    /**
     * The detach A/B experiment's verdict for the session that clicked.
     *
     * The experiment's two halves are produced in different places and never in
     * the same record: the server chose each table request's detach mode, and
     * the browser later said whether that request completed. Joining them is
     * ABJ_404_Solution_DetachAbEvidence's job, and until this call site existed
     * the only trigger for it was the canary ladder -- which runs ONLY after a
     * foreground table failure. That covers a session where the OFF attempt
     * hung and leaves the primary question uncovered: when a beta session goes
     * WELL, nothing fails, no ladder runs, and the developer received the raw
     * halves and had to join them by hand. Support-request assembly is the one
     * moment that happens in healthy and failing sessions alike.
     *
     * Guarded twice, because a support request is the last thing that may be
     * blocked by its own diagnostics: a partially recovered install can be
     * missing any plugin file (see the safe-autoloader work for error 18), and
     * a journal read that throws must degrade to a stated reason rather than to
     * a fatal in the request the admin is waiting on.
     */
    private static function detachAbVerdict(string $sessionId): string {
        if (!class_exists('ABJ_404_Solution_DetachAbEvidence')) {
            return 'Detach A/B verdict unavailable: ABJ_404_Solution_DetachAbEvidence could not be'
                . ' loaded on this install, so the experiment could not be decided here.';
        }
        try {
            return self::renderDetachAbVerdict(
                ABJ_404_Solution_DetachAbEvidence::verdictForSession($sessionId));
        } catch (Throwable $e) {
            return 'Detach A/B verdict could not be computed: ' . substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * The verdict as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the attempt LIST -- the one reducible part -- and
     * then falls back to the decision alone, rather than being cut at a byte
     * offset: a record cut mid-JSON is unreadable by machine and misleading to
     * a human, which is the same failure the drained client buffer already
     * taught this file (see appendClientTransportTelemetry).
     *
     * @param array<string, mixed> $record ABJ_404_Solution_DetachAbEvidence::verdictForSession().
     */
    private static function renderDetachAbVerdict(array $record): string {
        $header = 'Detach A/B verdict -- ' . self::detachAbSummary($record) . " (JSON):\n";
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
     * The one-line version, so the first thing a reader sees is the decision
     * and how much evidence it was drawn from. The counts are part of the
     * summary rather than decoration: a verdict of 'inconclusive' over zero
     * attempts and one over six attempts are entirely different findings.
     *
     * @param array<string, mixed> $record
     */
    private static function detachAbSummary(array $record): string {
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

    /**
     * The sanitized debug-log tail, or a stated reason there is none. Both are
     * sections of the payload: an absent debug log used to be an empty string,
     * which reads identically to a log that exists and says nothing.
     */
    private static function loggerExcerpt(): string {
        return ABJ_404_Solution_SupportLogExcerpt::resolve(
            'Support request', self::MAX_LOGGER_EXCERPT_LENGTH);
    }

    /**
     * Join the non-empty excerpt sections with a blank line between them.
     *
     * A lone section is returned byte-for-byte: trimming it here would
     * silently change what the developer receives.
     *
     * @param array<int, string> $sections
     * @return string
     */
    private static function joinSections(array $sections): string {
        $present = array();
        foreach ($sections as $section) {
            if (is_string($section) && trim($section) !== '') {
                $present[] = $section;
            }
        }
        if (count($present) < 2) {
            return $present === array() ? '' : $present[0];
        }
        $last = array_pop($present);
        return implode("\n\n", array_map('rtrim', $present)) . "\n\n" . $last;
    }

    /**
     * Append the browser's drained transport-attempt buffer.
     *
     * This is the only channel that carries attempts the server never saw at
     * all: a request that never reached PHP leaves no server-side trace to
     * pair with, and beta.1 came back with exactly that -- three client
     * timeouts and no evidence. The records are transport measurements
     * (timings, byte counts, readyState, protocol); they carry no URL, no SQL
     * and no user text, which is why they can ride the same opt-in field as
     * the sanitized log tail.
     *
     * Malformed input is reported rather than dropped: "the client sent
     * something we could not parse" is itself a finding about the client.
     *
     * Over-budget input is reduced a RECORD at a time by ClientTransportReport
     * rather than cut at a byte offset. The browser store holds more than this
     * budget carries, and cutting the serialized array mid-record left invalid
     * JSON -- so a busy session, which is exactly the interesting kind, used to
     * deliver its whole client-side story as "unparseable".
     */
    private static function appendClientTransportTelemetry(string $excerpt, string $raw): string {
        if ($raw === '') {
            return $excerpt;
        }
        $bounded = ABJ_404_Solution_ClientTransportReport::boundDrainedBuffer(
            $raw, self::MAX_CLIENT_TELEMETRY_LENGTH);
        if (!$bounded['parsed']) {
            $block = 'Client transport telemetry (unparseable, ' . $bounded['raw_length'] . ' bytes, '
                . $bounded['error'] . "):\n" . substr($raw, 0, 500);
        } else {
            $label = $bounded['dropped'] > 0
                ? 'Client transport telemetry (JSON, ' . $bounded['kept'] . ' of '
                    . ($bounded['kept'] + $bounded['dropped']) . ' attempts, failures kept first):'
                : 'Client transport telemetry (JSON):';
            $block = $label . "\n" . $bounded['json'];
        }
        return $excerpt === '' ? $block : rtrim($excerpt) . "\n\n" . $block;
    }

    /**
     * Last line of defence on the report contract's maxLength.
     *
     * The section budgets are chosen to sum well under the bound, so this can
     * only fire if one of them is later raised without the arithmetic being
     * rechecked. It cuts rather than letting buildPayload() reject the whole
     * report, and it says so in the payload instead of silently shortening it:
     * a truncation nobody can see is how evidence gets lost in transit, which
     * is the exact failure this whole path was rebuilt to prevent.
     *
     * It is the LAST step of assemble(), which is what makes it a backstop at
     * all. The handler used to clamp the server sections and then append the
     * client buffer afterwards, so the one section that arrives from outside
     * the site -- the only one whose size the plugin does not choose -- was the
     * one section the clamp could not see.
     */
    private static function bound(string $excerpt): string {
        if (strlen($excerpt) <= self::MAX_DEBUG_LOG_EXCERPT_BYTES) {
            return $excerpt;
        }
        $note = "\n\n[404 Solution] Support excerpt truncated from " . strlen($excerpt)
            . ' bytes to fit the report contract.';
        return substr($excerpt, 0, self::MAX_DEBUG_LOG_EXCERPT_BYTES - strlen($note)) . $note;
    }
}
