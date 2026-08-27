<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the support-request collector LOOKED FOR, stated whether or not it
 * found anything.
 *
 * beta.1's support payload came back with an empty stage-trace section, and
 * nothing in the report could separate the three very different things that
 * produce that outcome: the site genuinely wrote no journal, the collector
 * resolved a different directory (or ran on a different node) than the
 * requests it is supposed to describe, or the read side itself regressed.
 * ABJ_404_Solution_DiagnosticJournalExcerpt cannot close that gap on its own:
 * an excerpt that finds nothing has nothing to hang its accounting line on,
 * and a reader that is broken is the last thing that should be trusted to
 * describe its own breakage.
 *
 * So this manifest is composed INDEPENDENTLY of the read. It stats the
 * candidate files itself instead of reporting what the excerpt reader
 * observed, for the same reason the checkpoint journal is independent of the
 * stage trace it records: a defect in the component under investigation must
 * not be able to erase the evidence about it. It is always present in the
 * payload, even when every channel is silent, and the collector places it
 * FIRST so the report contract's clamp can never be the thing that removes it.
 *
 * It answers, always: which process collected (hostname, PID, SAPI, effective
 * uid), which directory each channel resolved and whether that directory
 * exists, is readable, is writable, and which filesystem node it sits on;
 * which candidate files were checked and their existence, size and mtime; how
 * many bytes and lines each channel actually yielded; and, when the browser
 * sent its drained attempt buffer, whether the attempt ids it expects to be
 * described are present in what was read.
 */
final class ABJ_404_Solution_DiagnosticCollectionManifest {

    /** The one JSON key the whole record hangs under, so a reader can grep for it. */
    const RECORD_KEY = 'abj404_collection_manifest';

    /** Every channel yielded nothing readable. */
    const OUTCOME_EMPTY = 'no_evidence_collected';

    /** At least one channel yielded bytes. */
    const OUTCOME_COLLECTED = 'evidence_collected';

    /**
     * Evidence whose absence must be stated rather than inferred from silence.
     *
     * The detach verdict is assembled independently ahead of this manifest,
     * so this catalog covers the journal-sourced browser receipt whose loss
     * could otherwise look exactly like a ladder that never ran.
     */
    const REQUIRED_EVIDENCE_RECORDS = array(
        'canary_step_client_receipt',
        'concurrent_control_client_receipt',
    );

    /**
     * Compose the manifest block. Never returns an empty string: a manifest
     * that could not be built says so, because "no manifest" is the exact
     * silence this class exists to end.
     *
     * @param array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection?: array<string, mixed>}> $channels
     *   One entry per journal the collector actually read, carrying the
     *   candidate paths it used and the text it got back.
     * @param array{status: string, ids: array<int, string>, records: int} $clientAttempts
     *   ABJ_404_Solution_ClientTransportReport::attemptOutcomesInDrainedBuffer().
     * @param int $budgetBytes Hard ceiling for the returned string.
     * @return string
     */
    public static function compose(array $channels, array $clientAttempts, int $budgetBytes): string {
        try {
            $described = array();
            $collected = '';
            foreach ($channels as $channel) {
                $described[] = self::describeChannel($channel);
                $collected .= isset($channel['collected']) ? (string)$channel['collected'] : '';
            }
            $manifest = array(
                'collector' => self::collector(),
                'channels' => $described,
                'client_expected_attempts' => self::reconcileAttempts($clientAttempts, $collected),
                'required_evidence_records' => self::reconcileRequiredEvidence($collected),
                'outcome' => self::outcome($described),
            );
            return ABJ_404_Solution_DiagnosticCollectionManifestRenderer::render(
                $manifest, $budgetBytes);
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic collection manifest failed: ' . $e->getMessage());
            return "Diagnostic collection manifest could not be built:\n"
                . ABJ_404_Solution_DiagnosticCollectionManifestRenderer::encodeOrEmpty(
                    array(self::RECORD_KEY => array(
                        'error' => substr($e->getMessage(), 0, 200),
                        'outcome' => self::OUTCOME_EMPTY,
                    )));
        }
    }

    /**
     * The process doing the reading.
     *
     * The journals record the same identity per request (see
     * ABJ_404_Solution_RequestEnvironmentFingerprint), so a collector on a
     * different host or under a different effective uid than the writer is
     * readable as a mismatch rather than as an absence.
     *
     * @return array<string, mixed>
     */
    private static function collector(): array {
        $hostname = gethostname();
        return array(
            'hostname' => $hostname !== false ? $hostname : '',
            'pid' => getmypid(),
            'sapi' => PHP_SAPI,
            'euid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
        );
    }

    /**
     * One channel's read attempt, described from the filesystem rather than
     * from whatever the reader reported about itself.
     *
     * A channel that knows whether its own WRITER was armed says so
     * (`writer_arming`). The stats below can prove the collector looked in the
     * right place; only the writer's policy can separate "there was nothing to
     * record" from "recording was never switched on", and those are the same
     * zero files on disk.
     *
     * @param array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection?: array<string, mixed>, writer_arming?: array<string, mixed>} $channel
     * @return array<string, mixed>
     */
    private static function describeChannel(array $channel): array {
        $directory = isset($channel['directory']) ? (string)$channel['directory'] : '';
        $collected = isset($channel['collected']) ? (string)$channel['collected'] : '';
        $paths = isset($channel['paths']) && is_array($channel['paths']) ? $channel['paths'] : array();
        $fileSelection = isset($channel['file_selection']) && is_array($channel['file_selection'])
            ? $channel['file_selection'] : array();

        $files = array();
        $found = 0;
        foreach ($paths as $path) {
            $file = self::describeFile((string)$path);
            if ($file['exists']) {
                $found++;
            }
            $files[] = $file;
        }

        return array_merge(
            array(
                'channel' => isset($channel['channel']) ? (string)$channel['channel'] : 'unknown',
                'directory' => $directory,
                'directory_resolved' => $directory !== '',
                'directory_usable' => !empty($channel['usable']),
            ),
            isset($channel['writer_arming']) && is_array($channel['writer_arming'])
                ? array('writer_arming' => $channel['writer_arming'])
                : array(),
            self::describeDirectory($directory),
            array(
                'candidates_checked' => count($files),
                'candidates_found' => $found,
                'files' => $files,
                'file_selection' => $fileSelection,
                'collected_bytes' => strlen($collected),
                'collected_lines' => self::countLines($collected),
            )
        );
    }

    /**
     * Existence, permissions and filesystem-node identity of a directory.
     *
     * The device and inode are what make "the collector read a different
     * mount than the writer wrote to" a fact instead of a theory; the count of
     * this plugin's own files in the directory separates "our files are
     * elsewhere" from "our files were never written".
     *
     * @return array<string, mixed>
     */
    private static function describeDirectory(string $directory): array {
        if ($directory === '') {
            return array(
                'directory_exists' => false,
                'directory_readable' => false,
                'directory_writable' => false,
                'directory_real' => null,
                'directory_device' => null,
                'directory_inode' => null,
                'plugin_files_present' => null,
            );
        }
        $exists = @is_dir($directory);
        $stat = $exists ? @stat($directory) : false;
        $real = $exists ? @realpath($directory) : false;
        $matches = $exists ? @glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'abj404_*') : false;
        return array(
            'directory_exists' => $exists,
            'directory_readable' => $exists && @is_readable($directory),
            'directory_writable' => $exists && @is_writable($directory),
            'directory_real' => is_string($real) ? $real : null,
            'directory_device' => is_array($stat) && isset($stat['dev']) ? (int)$stat['dev'] : null,
            'directory_inode' => is_array($stat) && isset($stat['ino']) ? (int)$stat['ino'] : null,
            'plugin_files_present' => is_array($matches) ? count($matches) : null,
        );
    }

    /**
     * One candidate file. The basename is reported rather than the full path:
     * the directory is already stated once per channel, and repeating it per
     * file would spend the manifest's budget on the same string four times.
     *
     * @return array{name: string, exists: bool, size: int|null, mtime: int|null, readable: bool}
     */
    private static function describeFile(string $path): array {
        $exists = @is_file($path);
        $size = $exists ? @filesize($path) : false;
        $modified = $exists ? @filemtime($path) : false;
        return array(
            'name' => basename($path),
            'exists' => $exists,
            'size' => is_int($size) ? $size : null,
            'mtime' => is_int($modified) ? $modified : null,
            'readable' => $exists && @is_readable($path),
        );
    }

    /**
     * Which of the attempt ids the browser says it is reporting are present in
     * what the collector actually read.
     *
     * A missing id is the single most decisive line in the manifest: the
     * browser observed the attempt, so the attempt happened, and the collector
     * did not find it. That is a collection failure, not healthy silence.
     *
     * Ids are matched in their quoted JSON form. An attempt id is its logical
     * request id plus a part/attempt suffix, so an unquoted substring search
     * would report a logical id as "found" on the strength of a different
     * attempt's record.
     *
     * @param array{status: string, ids: array<int, string>, records: int} $clientAttempts
     * @return array<string, mixed>
     */
    private static function reconcileAttempts(array $clientAttempts, string $collected): array {
        $ids = isset($clientAttempts['ids']) && is_array($clientAttempts['ids'])
            ? $clientAttempts['ids'] : array();
        $found = array();
        $missing = array();
        foreach ($ids as $id) {
            $id = (string)$id;
            if (strpos($collected, '"' . $id . '"') !== false) {
                $found[] = $id;
            } else {
                $missing[] = $id;
            }
        }
        return array(
            'status' => isset($clientAttempts['status']) ? (string)$clientAttempts['status'] : 'absent',
            'buffer_records' => isset($clientAttempts['records']) ? (int)$clientAttempts['records'] : 0,
            'expected' => count($ids),
            'found' => count($found),
            'found_ids' => $found,
            'missing_ids' => $missing,
        );
    }

    /**
     * @return array<string, array{status: string, reason?: string}>
     */
    private static function reconcileRequiredEvidence(string $collected): array {
        $records = array();
        $available = array();
        foreach (explode("\n", $collected) as $line) {
            if (strpos($line, '"event":"canary_step_client_receipt"') === false
                    && strpos($line, '"event":"concurrent_control_client_receipt"') === false) {
                continue;
            }
            $record = json_decode(trim($line), true);
            if (!is_array($record)) {
                continue;
            }
            if (self::isAvailableCanaryReceipt($record)) {
                $available['canary_step_client_receipt'] = true;
            }
            if (ABJ_404_Solution_ConcurrentControlReceipt::isCompleteJournalRecord($record)) {
                $available['concurrent_control_client_receipt'] = true;
            }
        }
        foreach (self::REQUIRED_EVIDENCE_RECORDS as $name) {
            if (isset($available[$name])) {
                $records[$name] = array('status' => 'available');
                continue;
            }
            $records[$name] = array(
                'status' => 'unavailable',
                'reason' => 'record_not_collected',
            );
        }
        return $records;
    }

    /** @param array<mixed, mixed> $record */
    private static function isAvailableCanaryReceipt(array $record): bool {
        return self::isFullCarriedReceipt($record, 'canary_step_client_receipt')
            && is_string($record['step_request_id'] ?? null)
            && $record['step_request_id'] !== '';
    }

    /**
     * @param array<mixed, mixed> $record
     */
    private static function isFullCarriedReceipt(array $record, string $event): bool {
        return ($record['envelope'] ?? '') === 'full'
            && ($record['event'] ?? '') === $event
            && is_string($record['carried_by'] ?? null)
            && $record['carried_by'] !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $described
     */
    private static function outcome(array $described): string {
        foreach ($described as $channel) {
            $collectedBytes = $channel['collected_bytes'] ?? 0;
            if (is_numeric($collectedBytes) && (int)$collectedBytes > 0) {
                return self::OUTCOME_COLLECTED;
            }
        }
        return self::OUTCOME_EMPTY;
    }

    private static function countLines(string $text): int {
        if (trim($text) === '') {
            return 0;
        }
        $lines = 0;
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $lines++;
            }
        }
        return $lines;
    }

    private static function reportFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('support-collection-manifest', $message);
    }
}
