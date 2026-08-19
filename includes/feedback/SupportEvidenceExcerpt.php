<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything a support report carries in its `debug_log_excerpt` field, and
 * the byte contract that field has to stay inside.
 *
 * Seven independent sources feed one string: a manifest of what the collector
 * looked for, the detach A/B experiment's verdict for the session that
 * clicked, the interpretation reconstructed from per-step canary receipts,
 * the per-failing-session diagnostics for the session(s) that actually failed
 * (ABJ_404_Solution_FailingSessionSupportSection), the sanitized debug-log
 * tail, the two durable AJAX diagnostic journals, and the browser's own
 * drained transport buffer. Deciding which of those a report
 * carries, in what order, and how the sum stays under the wire contract is a
 * different job from answering an AJAX request, and it is the job with the
 * interesting failure modes: every one of beta.1's evidence losses happened
 * here, not in the endpoint.
 *
 * Four ordering rules are load-bearing rather than cosmetic:
 *
 *   1. The collection manifest goes FIRST, because bound() cuts the tail. The
 *      one section that must survive a saturated payload is the one that says
 *      what was looked for.
 *   2. The detach A/B verdict follows, ahead of every evidence section, for
 *      the same reason: it is the conclusion drawn FROM that evidence, and a
 *      conclusion that gets cut off the end of a busy session's payload is
 *      exactly the manual join it exists to replace.
 *   3. The receipt-derived canary interpretation follows both independent
 *      conclusions' source ordering: it is derived from the journals but must
 *      survive any raw-evidence tail clamp.
 *   4. The per-failing-session diagnostics follow the click-session verdict,
 *      still ahead of the evidence: they are the same experiment's conclusion
 *      computed for the session(s) that actually failed rather than the tab
 *      that clicked, and they state whether those are even the same session.
 *   5. The journals follow in read order, so a reader walks the session the
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
    const MAX_LOGGER_EXCERPT_LENGTH = 12288;

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
        $clientAttempts = ABJ_404_Solution_ClientTransportReport::attemptOutcomesInDrainedBuffer(
            $clientTelemetry);
        $clientSessionId = self::clientField($client, 'session_id');
        $channels = self::collectChannels($clientAttempts);
        // Ordered by what must survive bound(), which cuts from the END.
        //
        // The client transport buffer is placed HERE, ahead of the bulk
        // sections, and that position is load-bearing rather than cosmetic. It
        // used to be appended after everything else, which made it the first
        // thing the truncation discarded -- and it is the one channel that
        // carries attempts the server never saw at all, the exact evidence
        // beta.1 came back without. Observed on 2026-08-15: an excerpt
        // assembled at 1,095,170 bytes was cut to the 256 KB contract bound and
        // arrived with the whole telemetry block gone, on a report whose
        // storage had failed, which is precisely when that block is the only
        // account of what the browser did.
        //
        // It is also the smallest of the high-value sections and the only one
        // whose size the plugin does not choose, so spending its bytes first
        // costs the report almost nothing. What now absorbs the cut is
        // loggerExcerpt() and the per-channel bulk below it: large, generic,
        // and reconstructible from the site's own debug log.
        $sections = array(
            self::collectionManifest($channels, $clientAttempts),
            self::clientTransportTelemetrySection($clientTelemetry),
            self::detachAbVerdict($clientSessionId),
            self::canonicalSuppression(),
            self::canaryReceiptInterpretation($clientSessionId),
            self::failingSessionDiagnostics($clientAttempts, $clientSessionId),
            self::strandedRequestDiagnostics(),
            self::loggerExcerpt(),
        );
        foreach ($channels as $channel) {
            $sections[] = $channel['collected'];
        }
        return self::bound(self::joinSections($sections));
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
     * be complete before the first read spends its budget. Browser verdicts
     * normally arrive through the checkpoint journal, but a final timeout can
     * survive only in the drained support-request buffer when neither its
     * retry nor its report-only beacon reached PHP. Both browser channels are
     * therefore unioned into the same index before either journal applies its
     * file cap. Without this, the stage trace ranks a request PHP completed and
     * the browser never received as ordinary healthy context -- and that
     * request's stage timings are the evidence for where the response was
     * built before it failed to arrive.
     *
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     * @return array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection: array<string, mixed>}>
     */
    private static function collectChannels(array $clientAttempts): array {
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
        $clientOutcomes = isset($clientAttempts['outcomes']) && is_array($clientAttempts['outcomes'])
            ? $clientAttempts['outcomes'] : array();
        foreach ($clientOutcomes as $requestId => $healthy) {
            if ($healthy === false) {
                $failingIds[(string)$requestId] = true;
            }
        }

        $channels = array();
        if ($trace !== null) {
            $selection = ABJ_404_Solution_DiagnosticJournalFileSelector::select(
                $trace['paths'], $failingIds);
            $trace['file_selection'] = $selection['manifest'];
            $trace['collected'] = ABJ_404_Solution_AjaxTraceJournal::readRecentForSupport(
                $failingIds, $selection);
            $channels[] = $trace;
        }
        if ($checkpoints !== null) {
            $selection = ABJ_404_Solution_DiagnosticJournalFileSelector::select(
                $checkpoints['paths'], $failingIds);
            $checkpoints['file_selection'] = $selection['manifest'];
            $checkpoints['collected'] =
                ABJ_404_Solution_CheckpointJournalReader::readRecentForSupport(
                    $failingIds, $selection);
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
     * @param array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection: array<string, mixed>}> $channels
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     */
    private static function collectionManifest(array $channels, array $clientAttempts): string {
        if (!class_exists('ABJ_404_Solution_DiagnosticCollectionManifest')
                || !class_exists('ABJ_404_Solution_ClientTransportReport')) {
            return 'Diagnostic collection manifest unavailable: the manifest classes could not be loaded'
                . ' on this install, so what the collector checked cannot be stated.';
        }
        return ABJ_404_Solution_DiagnosticCollectionManifest::compose(
            $channels,
            $clientAttempts,
            self::MAX_COLLECTION_MANIFEST_BYTES
        );
    }

    /**
     * The detach A/B experiment's verdict for the session that clicked: the one
     * conclusion computed for the tab that sent the report rather than for the
     * session(s) that failed. Deciding it belongs to
     * ABJ_404_Solution_DetachAbEvidence and rendering it inside a byte budget
     * belongs to ABJ_404_Solution_DetachAbVerdictSupportSection, so this
     * composer stays a section list rather than a grab bag of section bodies.
     *
     * Guarded here the same way every other section is: a support request is the
     * last thing that may be blocked by its own diagnostics, and a corrupt
     * install can be missing any plugin file (safe-autoloader work for error 18).
     */
    private static function detachAbVerdict(string $sessionId): string {
        if (!class_exists('ABJ_404_Solution_DetachAbVerdictSupportSection')) {
            return 'Detach A/B verdict unavailable: ABJ_404_Solution_DetachAbVerdictSupportSection'
                . ' could not be loaded on this install, so the experiment was not decided here.';
        }
        return ABJ_404_Solution_DetachAbVerdictSupportSection::compose($sessionId);
    }

    /**
     * Whether WordPress core would still have canonicalized the URLs this site
     * is capturing, as observed on the site's own front end.
     *
     * Placed with the conclusions rather than the evidence, and ahead of every
     * bulk section, because it is small, it is the answer to a question that
     * otherwise requires writing to the site owner and asking them to run a
     * command, and a conclusion cut off the end of a busy session's payload is
     * exactly the round trip it exists to replace.
     *
     * Deciding it belongs to ABJ_404_Solution_CanonicalRedirectHookCensus and
     * rendering it inside a byte budget belongs to
     * ABJ_404_Solution_CanonicalSuppressionSupportSection, so this composer
     * stays a section list rather than a grab bag of section bodies.
     *
     * Guarded here the same way every other section is: a support request is the
     * last thing that may be blocked by its own diagnostics, and a corrupt
     * install can be missing any plugin file (safe-autoloader work for error 18).
     */
    private static function canonicalSuppression(): string {
        if (!class_exists('ABJ_404_Solution_CanonicalSuppressionSupportSection')) {
            return 'Canonical hook census unavailable:'
                . ' ABJ_404_Solution_CanonicalSuppressionSupportSection could not be loaded on this'
                . ' install, so the census was not rendered here.';
        }
        return ABJ_404_Solution_CanonicalSuppressionSupportSection::compose();
    }

    /**
     * The beta.3-compatible interpretation reconstructed from durable receipts.
     *
     * A partially recovered install can be missing the new section class while
     * still retaining old journals. State that explicitly instead of allowing
     * diagnostics to block the support request that reports the corrupt install.
     */
    private static function canaryReceiptInterpretation(string $sessionId): string {
        if ($sessionId === '') {
            return '';
        }
        if (!class_exists('ABJ_404_Solution_CanaryReceiptSupportSection')) {
            return 'Canary receipt interpretation unavailable: '
                . 'ABJ_404_Solution_CanaryReceiptSupportSection could not be loaded on this install.';
        }
        return ABJ_404_Solution_CanaryReceiptSupportSection::compose($sessionId);
    }

    /**
     * The per-failing-session diagnostics section: the detach verdict and
     * encoded-size basis for the session(s) that actually failed, not just the
     * tab that clicked. The whole section -- sourcing the journals, deriving the
     * failing sessions, computing each verdict, and rendering the bounded block
     * -- lives in ABJ_404_Solution_FailingSessionSupportSection so this
     * composer stays a section list rather than a grab bag of section bodies.
     *
     * Guarded here the same way every other section is: a support request is the
     * last thing that may be blocked by its own diagnostics, and a corrupt
     * install can be missing any plugin file (safe-autoloader work for error 18).
     *
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     */
    private static function failingSessionDiagnostics(array $clientAttempts, string $clientSessionId): string {
        if (!class_exists('ABJ_404_Solution_FailingSessionSupportSection')) {
            return 'Failing-session diagnostics unavailable: ABJ_404_Solution_FailingSessionSupportSection'
                . ' could not be loaded on this install, so per-session verdicts were not computed here.';
        }
        return ABJ_404_Solution_FailingSessionSupportSection::compose($clientAttempts, $clientSessionId);
    }

    /**
     * The stranded-request section: which workers are still in flight far past
     * a plausible lifetime, and which lifecycle segment each was inside when it
     * last managed to record one.
     *
     * Read from the registry rather than from a journal on purpose. Every other
     * evidence section here is derived from byte-capped files that rotate
     * against site traffic and are then sampled by a priority pass, so on a busy
     * site the decisive record can be gone before the admin clicks send. This
     * one cannot be: it is a bounded reading of live state taken at click time.
     * See ABJ_404_Solution_StrandedRequestSupportSection for why that
     * distinction is what this section exists for.
     *
     * Guarded here the same way every other section is: a support request is the
     * last thing that may be blocked by its own diagnostics.
     */
    private static function strandedRequestDiagnostics(): string {
        if (!class_exists('ABJ_404_Solution_StrandedRequestSupportSection')) {
            return 'Stranded-request diagnostics unavailable: ABJ_404_Solution_StrandedRequestSupportSection'
                . ' could not be loaded on this install, so in-flight worker state was not read here.';
        }
        return ABJ_404_Solution_StrandedRequestSupportSection::compose();
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
    private static function clientTransportTelemetrySection(string $raw): string {
        return self::appendClientTransportTelemetry('', $raw);
    }

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
        $kept = substr($excerpt, 0, self::MAX_DEBUG_LOG_EXCERPT_BYTES - strlen($note));

        // Cut on a RECORD boundary, never mid-line. Most of what this carries
        // is JSONL, so a byte-offset cut can leave a half-written record whose
        // trailing brace makes it look complete to a reader, and the reader
        // then fails on the whole excerpt rather than on the one line that was
        // damaged. Observed as "SyntaxError: Unterminated string in JSON at
        // position 7" against a payload whose final line had been sliced
        // mid-string. Dropping the partial line costs one record; keeping it
        // costs the parse.
        $lastBreak = strrpos($kept, "\n");
        if ($lastBreak !== false) {
            $kept = substr($kept, 0, $lastBreak);
        }

        return $kept . $note;
    }
}
