<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything a support report carries in its `debug_log_excerpt` field, and
 * the byte contract that field has to stay inside.
 *
 * Four independent sources feed one string: a manifest of what the collector
 * looked for, the sanitized debug-log tail, the two durable AJAX diagnostic
 * journals, and the browser's own drained transport buffer. Deciding which of
 * those a report carries, in what order, and how the sum stays under the wire
 * contract is a different job from answering an AJAX request, and it is the
 * job with the interesting failure modes: every one of beta.1's evidence
 * losses happened here, not in the endpoint.
 *
 * Two ordering rules are load-bearing rather than cosmetic:
 *
 *   1. The collection manifest goes FIRST, because bound() cuts the tail. The
 *      one section that must survive a saturated payload is the one that says
 *      what was looked for.
 *   2. The journals follow in read order, so a reader walks the session the
 *      same way the journals were written.
 *
 * The class takes the browser's buffer as an argument rather than reading
 * $_POST: the request boundary belongs to the handler, and passing it in is
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
     * The whole excerpt, ready for the payload.
     *
     * @param string $clientTelemetry The browser's drained attempt buffer, already unslashed.
     * @return string
     */
    public static function assemble(string $clientTelemetry): string {
        $channels = self::collectChannels();
        $sections = array(
            self::collectionManifest($channels, $clientTelemetry),
            self::loggerExcerpt(),
        );
        foreach ($channels as $channel) {
            $sections[] = $channel['collected'];
        }
        return self::bound(
            self::appendClientTransportTelemetry(self::joinSections($sections), $clientTelemetry));
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
     * @return array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string}>
     */
    private static function collectChannels(): array {
        $channels = array();
        if (class_exists('ABJ_404_Solution_AjaxRequestTrace')) {
            $source = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $source['collected'] = ABJ_404_Solution_AjaxTraceJournal::readRecentForSupport();
            $channels[] = $source;
        }
        if (class_exists('ABJ_404_Solution_AjaxCheckpointLogger')) {
            $source = ABJ_404_Solution_AjaxCheckpointLogger::supportCollectionSource();
            $source['collected'] = ABJ_404_Solution_AjaxCheckpointLogger::readRecentForSupport();
            $channels[] = $source;
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
