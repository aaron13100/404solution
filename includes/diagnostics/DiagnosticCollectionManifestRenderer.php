<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presentation for the support-collection manifest: one header sentence a human
 * reads first, then the record as a single JSON line so the section stays JSONL
 * like every other section around it.
 *
 * Separate from ABJ_404_Solution_DiagnosticCollectionManifest, which OBSERVES:
 * it stats the candidate files, reconciles the browser's attempts, and decides
 * what is present. This class decides nothing about the site and reads nothing
 * from disk. It answers only "how does this fit in the bytes available", which
 * is the same layer split the project applies everywhere else -- and it is a
 * real one here, because the shedding order below is a presentation policy that
 * changes independently of what the collector looks for.
 *
 * The shedding order is the whole point: over-budget input drops detail in
 * decreasing order of value (per-file stats, then the id lists) rather than
 * being cut at a byte offset, and the last fallback is a record small enough to
 * fit unconditionally. A truncated JSON line would be worse than a smaller
 * complete one, because the manifest exists to be read when everything else in
 * the payload came back empty.
 */
final class ABJ_404_Solution_DiagnosticCollectionManifestRenderer {

    /**
     * The block: a human-readable header sentence, then the record as one JSON
     * line so the section stays JSONL like every other section around it.
     *
     * Over-budget input sheds detail in decreasing order of value (per-file
     * stats, then the id lists) rather than being cut at a byte offset, and the
     * last fallback is a record small enough to fit unconditionally. The counts
     * in the header are the same either way, so a shed manifest still says how
     * much was checked and how much was found.
     *
     * @param array<string, mixed> $manifest
     */
    public static function render(array $manifest, int $budgetBytes): string {
        $header = self::headerLine($manifest);
        foreach (array($manifest, self::withoutIdLists($manifest), self::minimal($manifest)) as $candidate) {
            $line = self::encodeOrEmpty(array(ABJ_404_Solution_DiagnosticCollectionManifest::RECORD_KEY => $candidate));
            if ($line !== '' && strlen($header) + strlen($line) <= $budgetBytes) {
                return $header . $line;
            }
        }
        return $header . self::encodeOrEmpty(array(ABJ_404_Solution_DiagnosticCollectionManifest::RECORD_KEY => array(
            'reduced' => 'encoding_failed',
            'outcome' => self::stringOf($manifest['outcome'] ?? ABJ_404_Solution_DiagnosticCollectionManifest::OUTCOME_EMPTY),
        )));
    }

    /**
     * The scannable one-line version, so the first thing a reader sees is what
     * was looked for and how much of it was there.
     *
     * @param array<string, mixed> $manifest
     */
    private static function headerLine(array $manifest): string {
        $channels = self::arrayOf($manifest['channels'] ?? null);
        $checked = 0;
        $found = 0;
        $lines = 0;
        foreach ($channels as $channel) {
            $described = self::arrayOf($channel);
            $checked += self::intOf($described['candidates_checked'] ?? 0);
            $found += self::intOf($described['candidates_found'] ?? 0);
            $lines += self::intOf($described['collected_lines'] ?? 0);
        }
        return 'Diagnostic collection manifest -- ' . count($channels) . ' channel(s), '
            . $found . ' of ' . $checked . ' candidate files present, ' . $lines . ' lines collected; '
            . self::attemptClause(self::arrayOf($manifest['client_expected_attempts'] ?? null))
            . " (JSONL):\n";
    }

    /** @param array<array-key, mixed> $expected */
    private static function attemptClause(array $expected): string {
        $status = self::stringOf($expected['status'] ?? 'absent');
        if ($status === 'parsed') {
            return 'browser expected ' . self::intOf($expected['expected'] ?? 0) . ' attempt id(s), '
                . self::intOf($expected['found'] ?? 0) . ' present';
        }
        if ($status === 'unparseable') {
            return 'the browser attempt buffer could not be parsed';
        }
        return 'no browser attempt ids were sent';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function withoutIdLists(array $manifest): array {
        $manifest['reduced'] = 'attempt_id_lists_and_file_stats_dropped_for_budget';
        $expected = self::arrayOf($manifest['client_expected_attempts'] ?? null);
        $expected['found_ids'] = array();
        $expected['missing_ids'] = array();
        $manifest['client_expected_attempts'] = $expected;
        $channels = array();
        foreach (self::arrayOf($manifest['channels'] ?? null) as $channel) {
            $described = self::arrayOf($channel);
            unset($described['files']);
            $selection = self::arrayOf($described['file_selection'] ?? null);
            $selection['dropped_file_names'] = array();
            $selection['dropped_request_ids'] = array();
            $described['file_selection'] = $selection;
            $channels[] = $described;
        }
        $manifest['channels'] = $channels;
        return $manifest;
    }

    /**
     * The smallest manifest that is still worth having: who collected, how many
     * files each channel checked and found, and the outcome.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function minimal(array $manifest): array {
        $channels = array();
        foreach (self::arrayOf($manifest['channels'] ?? null) as $channel) {
            $described = self::arrayOf($channel);
            $selection = self::arrayOf($described['file_selection'] ?? null);
            $minimalSelection = array(
                'policy' => self::stringOf($selection['policy'] ?? ''),
            );
            foreach (array(
                'existing_files', 'selected_files', 'known_failure_files',
                'server_failure_files', 'classification_issue_files', 'pinned_files',
                'classification_issues_omitted', 'dropped_files',
                'dropped_file_names_omitted', 'dropped_request_ids_omitted',
            ) as $field) {
                $minimalSelection[$field] = self::intOf($selection[$field] ?? 0);
            }
            $channels[] = array(
                'channel' => self::stringOf($described['channel'] ?? 'unknown'),
                'directory_usable' => !empty($described['directory_usable']),
                'candidates_checked' => self::intOf($described['candidates_checked'] ?? 0),
                'candidates_found' => self::intOf($described['candidates_found'] ?? 0),
                'collected_bytes' => self::intOf($described['collected_bytes'] ?? 0),
                'file_selection' => $minimalSelection,
            );
        }
        return array(
            'reduced' => 'channel_detail_dropped_for_budget',
            'collector' => self::arrayOf($manifest['collector'] ?? null),
            'channels' => $channels,
            'required_evidence_records' =>
                self::arrayOf($manifest['required_evidence_records'] ?? null),
            'outcome' => self::stringOf($manifest['outcome'] ?? ABJ_404_Solution_DiagnosticCollectionManifest::OUTCOME_EMPTY),
        );
    }

    /** @param array<string, mixed> $value */
    public static function encodeOrEmpty(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    /**
     * Typed reads of the assembled record. The manifest is deliberately a
     * loose map (its shape is the wire format, not a PHP contract), so the
     * places that summarize it read through these rather than casting mixed.
     *
     * @param mixed $value
     */
    private static function intOf($value): int {
        return is_numeric($value) ? (int)$value : 0;
    }

    /** @param mixed $value */
    private static function stringOf($value): string {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param mixed $value
     * @return array<array-key, mixed>
     */
    private static function arrayOf($value): array {
        return is_array($value) ? $value : array();
    }
}
