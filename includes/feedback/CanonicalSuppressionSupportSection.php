<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The support-payload section carrying what stopped WordPress core from
 * canonicalizing a URL on the reporting site.
 *
 * ABJ_404_Solution_CanonicalRedirectHookCensus owns taking that reading, on the
 * front end, at the moment core's canonical redirect would have run. This class
 * owns only the payload job of asking for it and rendering the answer inside a
 * byte budget, which is the same contract every other *SupportSection here
 * honours.
 *
 * The section is unconditional. A captured 404 whose canonicalization was
 * suppressed is indistinguishable, in every other record, from one that was
 * always broken, so "no census" and "census says the hook is fine" have to be
 * different answers in the report. An omitted section reads as neither.
 */
final class ABJ_404_Solution_CanonicalSuppressionSupportSection {

    /**
     * Hard cap on the rendered block. It is one reading plus a capped suspect
     * roster, so it is small by construction; the cap is what keeps it small no
     * matter how long a site's `template_redirect` chain is, and render() sheds
     * the roster to fit rather than being cut mid-record. Reclaimed from the
     * checkpoint excerpt budget so the section sum stays inside the report
     * contract -- see
     * ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES and
     * SupportExcerptBudgetContractTest.
     *
     * Sized to hold a FULL roster rather than merely to be small. A recorded
     * entry runs about 90 bytes (`{"priority":20,"callback":"Some_Class::method",
     * "origin":"plugin:some-plugin"}`), so
     * ABJ_404_Solution_CanonicalRedirectHookCensus::MAX_CALLBACKS of them plus
     * the reading around them needs roughly 2.7 KB. A 2 KB budget looked
     * conservative and was in fact the worst possible choice: every site long
     * enough to be interesting would have shed its whole roster, leaving the
     * finding "something removed core canonicalization" with nothing named --
     * which is the one thing this section exists to say.
     */
    const MAX_CANONICAL_SUPPRESSION_BYTES = 3072;

    /**
     * How many suspects survive the first shed.
     *
     * The roster is stored in dispatch order, so slicing from the front keeps
     * the callbacks that run EARLIEST -- the ones with the opportunity to
     * remove core's callback before it would have fired. Shedding to a short
     * named list beats shedding to none: a reader with eight names has somewhere
     * to start, and a reader with zero is back to writing to the site owner.
     */
    const REDUCED_CALLBACK_COUNT = 8;

    /** The one JSON key the census hangs under, so a reader can grep for it. */
    const CANONICAL_HOOK_CENSUS_KEY = 'abj404_canonical_hook_census';

    /**
     * The whole section, ready to join into the support payload.
     *
     * Guarded twice, because a support request is the last thing that may be
     * blocked by its own diagnostics: a partially recovered install can be
     * missing any plugin file (see the safe-autoloader work for error 18), and a
     * read that throws must degrade to a stated reason rather than to a fatal in
     * the request the admin is waiting on.
     */
    public static function compose(): string {
        if (!class_exists('ABJ_404_Solution_CanonicalRedirectHookCensus')) {
            return 'Canonical hook census unavailable [CANONICAL_CENSUS_CLASS_UNAVAILABLE]:'
                . ' ABJ_404_Solution_CanonicalRedirectHookCensus could not be loaded on this install,'
                . ' so whether core still canonicalizes on this site was not observed.'
                . ' Reinstall the same plugin version, then reproduce the 404 before generating support data again.';
        }
        try {
            return self::render(ABJ_404_Solution_CanonicalRedirectHookCensus::read());
        } catch (Throwable $e) {
            return 'Canonical hook census could not be read [CANONICAL_CENSUS_READ_FAILED]: '
                . substr($e->getMessage(), 0, 200)
                . '. Reproduce the 404 once, then generate support data again; include this code if it recurs.';
        }
    }

    /**
     * The census as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the suspect ROSTER -- the one reducible part --
     * in two steps, keeping the earliest names before giving them up entirely,
     * and only then falls back to the finding alone. Never cut at a byte offset:
     * a record cut mid-JSON is unreadable by machine and misleading to a human,
     * which is the same failure the drained client buffer already taught the
     * composer (see SupportEvidenceExcerpt::appendClientTransportTelemetry).
     *
     * @param array<string, mixed> $record ABJ_404_Solution_CanonicalRedirectHookCensus::read().
     */
    private static function render(array $record): string {
        if ($record === array()) {
            return 'Canonical hook census -- not yet observed: this site has not served a front-end'
                . ' 404 through this build, so whether WordPress core would still canonicalize was'
                . " never read. Absent, not intact.\n";
        }
        $header = 'Canonical hook census -- ' . self::summary($record) . " (JSON):\n";
        $roster = isset($record['callbacks']) && is_array($record['callbacks'])
            ? $record['callbacks'] : array();
        $shortened = $record;
        $shortened['callbacks'] = array_slice($roster, 0, self::REDUCED_CALLBACK_COUNT);
        $shortened['callbacks_reduced'] = 'earliest_' . self::REDUCED_CALLBACK_COUNT;
        $reduced = $record;
        $reduced['callbacks'] = array();
        $reduced['callbacks_reduced'] = 'over_budget';
        $minimal = array(
            'core_canonical' => self::textOf($record, 'core_canonical'),
            'suppression' => isset($record['suppression']) && is_array($record['suppression'])
                ? $record['suppression'] : array(),
            'core_canonical_priority' => $record['core_canonical_priority'] ?? null,
            'plugin_listener_priority' => $record['plugin_listener_priority'] ?? null,
            'callback_count' => self::countOf($record, 'callback_count'),
            'recorded_at' => self::countOf($record, 'recorded_at'),
            'reduced' => 'over_budget',
        );
        foreach (array($record, $shortened, $reduced, $minimal) as $candidate) {
            $line = json_encode(array(self::CANONICAL_HOOK_CENSUS_KEY => $candidate));
            if (is_string($line)
                    && strlen($header) + strlen($line) <= self::MAX_CANONICAL_SUPPRESSION_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The census record could not be encoded for this payload. JSON error ' .
            (string)json_last_error() . ': ' . json_last_error_msg() .
            '. Check callback names and origins for invalid UTF-8.';
    }

    /**
     * The one-line version, so the first thing a reader sees is the finding and
     * how much of the hook it was drawn from.
     *
     * "attached" with no suppression reasons and "attached" with the plugin
     * running first are entirely different findings -- the second one names this
     * plugin as the reason core never got its turn -- so the reasons are part of
     * the summary rather than buried in the record.
     *
     * @param array<string, mixed> $record
     */
    private static function summary(array $record): string {
        $reasons = isset($record['suppression']) && is_array($record['suppression'])
            ? array_filter($record['suppression'], 'is_scalar') : array();
        return 'core redirect_canonical ' . self::textOf($record, 'core_canonical') . '; '
            . ($reasons === array() ? 'no suppression found' : 'suppression: ' . implode(', ', $reasons))
            . '; ' . self::countOf($record, 'callback_count') . ' callback(s) on '
            . self::textOf($record, 'hook') . '; observed at ' . self::countOf($record, 'recorded_at');
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
