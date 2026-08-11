<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read side of the AJAX checkpoint journal: everything the support-collection
 * pipeline is served from it.
 *
 * Split out of ABJ_404_Solution_AjaxCheckpointLogger, which is now the
 * checkpoint lifecycle owner and nothing else. The dependency stays
 * one-directional by design: this reader discovers early intents through
 * CheckpointIntentStore and resolves the normal journal through the logger;
 * neither writer calls back into the reader, so a bug in support collection
 * cannot take checkpoint recording down with it.
 *
 * Consumers: ABJ_404_Solution_SupportEvidenceExcerpt (the bounded support
 * payload), ABJ_404_Solution_DetachAbEvidence (verdicts read straight off the
 * journal), and ABJ_404_Solution_DeveloperLogMailer (the unbounded archive).
 */
final class ABJ_404_Solution_CheckpointJournalReader {

    /** Recorder calls slower than this keep their full phase map in support. */
    const RECORDER_PHASE_DETAIL_THRESHOLD_US = 5000;

    /**
     * Share of the support payload's excerpt field this journal may claim.
     *
     * Sized against a measured session, not chosen for tidiness: one table
     * request costs 26-27 records, so 32 KB (the previous value, further
     * halved by an even per-file split) bought about ONE request while a
     * failing session is six failing attempts plus a canary ladder plus polls.
     * The 18 KB above that base used the excerpt contract's remaining section
     * headroom plus 16 KB reallocated from the generic debug-log tail, so eight
     * bounded hook/cache activity samples fit across every prioritized attempt
     * without eliding a failing request's last pre-stall boundary; 4 KB of it
     * was then reallocated to the per-failing-session diagnostics block
     * (ABJ_404_Solution_FailingSessionSupportSection::MAX_FAILING_SESSION_DIAG_BYTES),
     * a computed conclusion about the correct session that is worth more than
     * the raw checkpoint tail it replaces. The receipt reconstruction section
     * is funded from the generic sanitized log tail instead: reducing this
     * checkpoint floor by another 4 KB evicts the first failing request in the
     * measured worst-case session. A further 3 KB then funded the
     * stranded-request block
     * (ABJ_404_Solution_StrandedRequestSupportSection::MAX_STRANDED_DIAG_BYTES),
     * which is the one section that CANNOT be funded from a journal: it is a
     * reading of live registry state, so unlike every byte spent here it cannot
     * be rotated or elided away before the admin clicks send. The remaining
     * budget is still far above the whole-failing-session floor
     * SupportExcerptBudgetContractTest pins, and the per-section budgets are
     * proven to sum inside the report contract by that same test.
     */
    const MAX_SUPPORT_EXCERPT_BYTES = 142336;

    /**
     * Bounded recent checkpoint lines for the support-request payload.
     *
     * Without this the checkpoints are written and never read by anyone: the
     * support payload carried only the stage trace, so a request that died
     * BEFORE its first stage -- the exact beta.1 failure -- reached the
     * developer as an empty excerpt. Every pre-stage boundary (auth, rate
     * limit, trace construction, service resolution) and every post-stage
     * boundary (encode, echo, each ob close, flush, finish-request, exit) is
     * recorded only here, so this is the channel that makes "nothing after
     * authorized" a readable fact instead of an absence.
     *
     * The rotated file is included: a session busy enough to rotate is a
     * session whose oldest evidence is still the most interesting.
     *
     * @param array<string, bool> $knownFailingIds Requests condemned across every
     *   journal, so this excerpt and the stage-trace one rank identically. This
     *   journal already contains its own verdicts; passing the union in is what
     *   makes the two agree rather than each ranking off what it happens to hold.
     * @param array{paths: array<int, string>, manifest: array<string, mixed>}|null $fileSelection
     *   Shared selection plan used by the primary support manifest.
     */
    public static function readRecentForSupport(
        array $knownFailingIds = array(),
        ?array $fileSelection = null
    ): string {
        $source = self::supportCollectionSource();
        $paths = $source['paths'];
        if ($paths === array()) {
            return '';
        }
        $required = self::requiredSupportEvidence($paths);
        $requiredBlock = $required === ''
            ? ''
            : "Required AJAX checkpoint evidence (JSONL):\n" . $required;
        $rankedBudget = self::MAX_SUPPORT_EXCERPT_BYTES
            - ($requiredBlock === '' ? 0 : strlen($requiredBlock) + 1);
        $activePath = ABJ_404_Solution_DurableOperationRecorder::activePath(
            $source['directory']
        );
        $rankedPaths = array_values(array_filter(
            $paths,
            static fn(string $path): bool => $path !== $activePath
        ));
        $rankedSelection = self::withoutPathFromSelection($fileSelection, $activePath);
        $activeLines = $activePath === '' ? array()
            : ABJ_404_Solution_ActiveOperationBreadcrumbs::compactSupportLines(
                ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines(array($activePath))
            );
        $activeClosedIds =
            ABJ_404_Solution_CheckpointIntentCorrelation::closedCheckpointIds($activeLines);
        $ranked = ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
            $rankedPaths,
            max(0, $rankedBudget),
            "Recent AJAX request checkpoints (JSONL):\n",
            $knownFailingIds,
            static function (array $lines) use ($activeClosedIds): array {
                return self::compactForSupport($lines, $activeClosedIds);
            },
            $rankedSelection
        );
        if ($requiredBlock === '') {
            return $ranked;
        }
        return $ranked === '' ? $requiredBlock : $requiredBlock . "\n" . $ranked;
    }

    /**
     * Latest complete record of each evidence type that must bypass ordinary
     * request-group ranking.
     *
     * The selector owns the record schemas. This reader owns byte accounting
     * and applies the same support compaction used by the ranked remainder.
     *
     * @param array<int, string> $paths
     */
    private static function requiredSupportEvidence(array $paths): string {
        $lines = ABJ_404_Solution_DurableOperationRecorder::compactSupportLines(
            ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($paths)
        );
        $required = ABJ_404_Solution_RequiredCheckpointEvidence::select($lines);
        return implode("\n", self::compactRoutinePhaseMaps($required));
    }

    /**
     * The active-state file is reserved in full, so ranking it again would
     * duplicate culprits and spend the lifecycle budget twice.
     *
     * @param array{paths: array<int, string>, manifest: array<string, mixed>}|null $selection
     * @return array{paths: array<int, string>, manifest: array<string, mixed>}|null
     */
    private static function withoutPathFromSelection(?array $selection, string $excludedPath): ?array {
        if ($selection === null || $excludedPath === '') {
            return $selection;
        }
        $selection['paths'] = array_values(array_filter(
            is_array($selection['paths'] ?? null) ? $selection['paths'] : array(),
            static fn(string $path): bool => $path !== $excludedPath
        ));
        if (is_array($selection['manifest'] ?? null)) {
            $selection['manifest']['selected_files'] = count($selection['paths']);
        }
        return $selection;
    }

    /**
     * Drop only intents whose exact checkpoint_id has a terminal non-intent record.
     * Unmatched and malformed intents remain: they are the evidence that
     * enrichment or its final append never completed. Every total call cost
     * remains. The excerpt keeps every slow/failed phase map plus the single
     * slowest baseline in the session; routine maps are removed only from the
     * bounded excerpt, never from the durable journal/archive.
     *
     * @param array<int, string> $lines
     * @param array<string, bool> $additionalClosedIds Exact terminal records
     *   reserved outside the ranked lines, such as active-operation state.
     * @return array<int, string>
     */
    private static function compactForSupport(array $lines, array $additionalClosedIds = array()): array {
        $lines = ABJ_404_Solution_DurableOperationRecorder::compactSupportLines($lines);
        $withoutClosedIntents = ABJ_404_Solution_CheckpointIntentCorrelation::withoutClosedIntents(
            $lines,
            array_merge(
                ABJ_404_Solution_CheckpointIntentCorrelation::closedCheckpointIds($lines),
                $additionalClosedIds
            )
        );
        return self::compactRoutinePhaseMaps(
            ABJ_404_Solution_CheckpointIntentCorrelation::withoutKeyedIntents(
                $withoutClosedIntents
            )
        );
    }

    /**
     * @param array<int, string> $lines
     */
    private static function slowestTelemetryIndex(array $lines): int {
        $slowestIndex = -1;
        $slowestTotalUs = -1;
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $previous = $record['previous_checkpoint_write'] ?? null;
            $totalUs = is_array($previous) && is_numeric($previous['total_us'] ?? null)
                ? (int)$previous['total_us'] : -1;
            if ($totalUs > $slowestTotalUs) {
                $slowestIndex = $index;
                $slowestTotalUs = $totalUs;
            }
        }
        return $slowestIndex;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function compactRoutinePhaseMaps(array $lines): array {
        $slowestIndex = self::slowestTelemetryIndex($lines);
        $decodedByIndex = array();
        $hostPressureSnapshotByRequest = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            $decodedByIndex[$index] = $record;
        }
        foreach ($decodedByIndex as $index => $record) {
            if (!is_array($record)) {
                continue;
            }
            if (($record['event'] ?? '') === 'query_probe') {
                // Support needs source + shape hash, not even redacted SQL
                // text. This also keeps one unmatched probe per failed
                // request from displacing that request's lifecycle.
                unset($record['sql']);
            }
            $record = ABJ_404_Solution_HostPressureSampler::compactRepeatedHostPressureSnapshots(
                $record,
                $hostPressureSnapshotByRequest
            );
            if (!is_array($record['previous_checkpoint_write'] ?? null)) {
                $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $lines[$index] = $encoded;
                }
                continue;
            }
            $previous = $record['previous_checkpoint_write'];
            $totalUs = is_numeric($previous['total_us'] ?? null)
                ? (int)$previous['total_us'] : -1;
            $isSlowest = $slowestIndex === $index;
            $isSlow = $totalUs >= self::RECORDER_PHASE_DETAIL_THRESHOLD_US;
            $failed = ($previous['status'] ?? '') !== 'complete'
                || (($previous['intent_status'] ?? 'complete') !== 'complete');
            if (!$isSlowest && !$isSlow && !$failed && isset($previous['phases_us'])) {
                unset($record['previous_checkpoint_write']['phases_us']);
            }
            // Closed intents are gone at this point, so their correlation IDs
            // have completed their only job. Keep IDs on unmatched intents,
            // but do not spend bounded support bytes repeating them here.
            unset($record['checkpoint_id']);
            unset($record['previous_checkpoint_write']['checkpoint_id']);
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $lines[$index] = $encoded;
            }
        }
        return $lines;
    }

    /**
     * Latest completed table-response encoding size observed for one browser
     * session.
     *
     * The stage trace owns the session-to-request join, while the checkpoint
     * journal owns the exact post-json_encode byte count. Reading only either
     * channel cannot answer this question: the checkpoint records deliberately
     * stay minimal and carry no browser session, and the stage trace records
     * payload shape/timing rather than the encoded bytes sent on the wire.
     *
     * This read is used once, by the size-target canary step after a real table
     * failure. It scans bounded/rotated journals and live pending spools, then
     * returns a complete provenance shape. Missing, malformed, foreign-session,
     * non-table, and zero-byte records remain unavailable rather than becoming
     * a fabricated size.
     *
     * @return array{bytes: int|null, source: string, request_id: string}
     */
    public static function latestEncodedTableResponseForSession(string $sessionId): array {
        $unavailable = array('bytes' => null, 'source' => 'unavailable', 'request_id' => '');
        $sessionId = substr($sessionId, 0, 64);
        if ($sessionId === '') {
            return $unavailable;
        }
        try {
            $traceSource = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $traceLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($traceSource['paths']);
            $tableRequestIds = self::tableRequestIdsInSession($traceLines, $sessionId);
            if ($tableRequestIds === array()) {
                return $unavailable;
            }

            $source = self::supportCollectionSource();
            $checkpointLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($source['paths']);
            return self::latestEncodedSizeForRequests($checkpointLines, $tableRequestIds, $unavailable);
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint response-size lookup failed: ' . $e->getMessage());
            return array('bytes' => null, 'source' => 'journal_error', 'request_id' => '');
        }
    }

    /**
     * @param array<int, string> $traceLines
     * @return array<string, bool>
     */
    private static function tableRequestIdsInSession(array $traceLines, string $sessionId): array {
        $requestIds = array();
        foreach ($traceLines as $line) {
            if (strpos($line, 'ajaxUpdatePaginationLinks') === false
                    || strpos($line, $sessionId) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $action = is_scalar($record['action'] ?? null) ? (string)$record['action'] : '';
            $part = is_scalar($record['part'] ?? null) ? (string)$record['part'] : '';
            $recordSessionId = is_scalar($record['session_id'] ?? null) ? (string)$record['session_id'] : '';
            if ($action !== 'ajaxUpdatePaginationLinks' || $part !== 'table'
                    || $recordSessionId !== $sessionId) {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            if (preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) === 1) {
                $requestIds[$requestId] = true;
            }
        }
        return $requestIds;
    }

    /**
     * @param array<int, string> $checkpointLines
     * @param array<string, bool> $requestIds
     * @param array{bytes: int|null, source: string, request_id: string} $unavailable
     * @return array{bytes: int|null, source: string, request_id: string}
     */
    private static function latestEncodedSizeForRequests(
        array $checkpointLines,
        array $requestIds,
        array $unavailable
    ): array {
        $latest = $unavailable;
        foreach ($checkpointLines as $line) {
            if (strpos($line, '"event":"json_encode"') === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $bytes = is_numeric($record['bytes'] ?? null) ? (int)$record['bytes'] : 0;
            if ($event !== 'json_encode' || !isset($requestIds[$requestId]) || $bytes <= 0) {
                continue;
            }
            $latest = array(
                'bytes' => $bytes,
                'source' => 'session_json_encode',
                'request_id' => $requestId,
            );
        }
        return $latest;
    }

    /**
     * What readRecentForSupport() will look at, whether or not any of it
     * exists, for ABJ_404_Solution_DiagnosticCollectionManifest. The candidate
     * list is the reader's own, so the manifest can never describe a different
     * set of files than the one that was actually read.
     *
     * The directory is reported even when it turned out to be unusable: which
     * path this channel tried is exactly the fact a wrong-node or unwritable
     * uploads directory is diagnosed from.
     *
     * The fixed fallback paths remain available even when the trace directory
     * cannot be resolved or created.
     *
     * @return array{channel: string, directory: string, usable: bool, paths: array<int, string>}
     */
    public static function supportCollectionSource(): array {
        $fallbackPaths = class_exists('ABJ_404_Solution_CheckpointIntentStore')
            ? ABJ_404_Solution_CheckpointIntentStore::paths()
            : array();
        try {
            $directory = self::journalDirectory();
            $journalUsable = $directory !== '';
            $paths = $journalUsable
                ? array_merge(
                    $fallbackPaths,
                    self::supportExcerptPaths($directory),
                    class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')
                        ? array(ABJ_404_Solution_ActiveOperationBreadcrumbs::path($directory))
                        : array()
                )
                : $fallbackPaths;
            return array(
                'channel' => 'ajax_checkpoints',
                'directory' => $journalUsable ? $directory
                    : ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectoryPath(),
                'usable' => $journalUsable || $fallbackPaths !== array(),
                'paths' => array_values(array_unique($paths)),
            );
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint support source resolution failed: ' . $e->getMessage());
            return array('channel' => 'ajax_checkpoints', 'directory' => '',
                'usable' => $fallbackPaths !== array(), 'paths' => $fallbackPaths);
        }
    }

    /**
     * Rotated file then current journal: oldest first, the order the excerpt
     * reader breaks mtime ties on. File names come from the writer, the one
     * owner of the journal's on-disk contract.
     *
     * @param string $directory With a trailing separator.
     * @return array<int, string>
     */
    private static function supportExcerptPaths(string $directory): array {
        return array(
            $directory . ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE,
            $directory . ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE,
        );
    }

    /**
     * Existing journal files, for a channel that carries them WHOLE.
     *
     * The support excerpt is bounded by a byte budget and a ranking, and a
     * budget decision must never again be the single point of loss for a
     * session we only get once. The developer log archive has no such bound,
     * so it carries both journals in full alongside the debug logs.
     *
     * @return array<int, string>
     */
    public static function supportArchivePaths(): array {
        $directory = self::journalDirectory();
        if ($directory === '') {
            return array();
        }
        $paths = array();
        foreach (array(ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE,
                ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE) as $name) {
            if (@is_file($directory . $name)) {
                $paths[] = $directory . $name;
            }
        }
        return $paths;
    }

    /**
     * The journal directory via the writer's resolution, or '' when the
     * writer class itself is unreachable. A corrupt install can be missing
     * any plugin file (the safe autoloader returns silently for a missing
     * class; see the error-18 work), and the read side must degrade to
     * "nothing to read" rather than fatal the support request.
     */
    private static function journalDirectory(): string {
        return class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
            ? ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectory()
            : '';
    }

    private static function reportFailure(string $message): void {
        // Unconditional: abj404_logPhpFallback() is defined at plugin entry
        // (404-solution.php), before any class here can be autoloaded, so a
        // raw error_log() second sink would be unreachable dead weight.
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
