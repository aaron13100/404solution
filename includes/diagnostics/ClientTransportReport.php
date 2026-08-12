<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The browser's half of the request ledger (Bruno timeout cause matrix,
 * coverage req. 6).
 *
 * The server flight recorder can prove what PHP did with a request. It cannot
 * prove that the request ever left the browser, how long it sat in the browser
 * connection queue, whether response headers arrived and the body then stalled,
 * or whether the completion callback lost a race to jQuery's timeout timer.
 * Only the browser can answer those, and only if what it observed gets back
 * here. This class is where it lands.
 *
 * Two arrival routes, both authenticated exactly like a normal table request:
 *
 *   1. Riding the next table request's params (the primary route: the client
 *      attaches its previous attempt's record to the following request, so the
 *      evidence arrives even if the admin never sends a support request).
 *   2. A clientReportOnly beacon fired after the last attempt of a failed
 *      request, which does no table work and returns immediately.
 *
 * Reports are journaled through ABJ_404_Solution_AjaxCheckpointLogger rather
 * than the trace class, for the same reason the server checkpoints are: a
 * defect in the component under investigation must not be able to erase the
 * evidence about it. The payload is treated as untrusted text throughout: it
 * is length-bounded, parsed defensively, and never echoed back to any client.
 */
final class ABJ_404_Solution_ClientTransportReport {

    /**
     * Hard bound on a single client report. The client trims its own record to
     * 4000 characters before sending; this is the server refusing to journal
     * more than that regardless of what actually arrives.
     */
    const MAX_REPORT_BYTES = 4096;

    /**
     * Hard bound on the raw drained buffer BEFORE it is parsed. Only an input
     * guard against an absurd POST; the shipping bound is the caller's budget.
     */
    const MAX_DRAINED_BUFFER_INPUT_BYTES = 131072;

    /**
     * The only attempt outcome that means "did not fail". An allowlist, not a
     * deny-list: 'pending' is an attempt that never finished (the hung request
     * itself) and an unrecognised or absent outcome is an unknown, which is
     * worth more than a known success when something has to be dropped.
     */
    const HEALTHY_OUTCOMES = array('success');

    /**
     * Attempt ids carried into the support-collection manifest. The browser's
     * own ring buffer holds 16 records, so this is that ceiling plus headroom
     * for a buffer that arrives from an older or a modified client.
     */
    const MAX_ATTEMPT_IDS_REPORTED = 32;

    /**
     * Read, bound, and journal whatever the browser said about a previous
     * attempt, plus the build identity of the JavaScript that said it. Never
     * throws: a malformed or absent report must not affect the request that
     * carried it.
     */
    public static function journal(string $requestId): void {
        try {
            $reader = self::requestReader();
            $build = (string)$reader->getPostOrGetSanitize('clientBuild', '');
            $buildModules = (string)$reader->getPostOrGetSanitize('clientBuildModules', '');
            $inflight = (string)$reader->getPostOrGetSanitize('clientInflight', '');
            $tabs = (string)$reader->getPostOrGetSanitize('clientTabs', '');
            $foreignInflight = (string)$reader->getPostOrGetSanitize('clientForeignInflight', '');
            $storageHealth = (string)$reader->getPostOrGetSanitize('clientStorageHealth', '');
            if ($build !== '' || $inflight !== '' || $tabs !== '' || $foreignInflight !== '' ||
                    $storageHealth !== '') {
                // What the client said about ITSELF at send time: which
                // JavaScript is executing, how many other plugin requests that
                // tab already had open, how many admin tabs of the page are
                // open at all, and how much non-plugin AJAX the tab had
                // outstanding. The last two are the browser's half of the
                // same-site contention the server counts in
                // ABJ_404_Solution_SameSiteRequestCensus; neither used to be
                // recorded anywhere, so a cross-tab cause could not even be
                // suspected from the evidence that survived. The previous
                // attempt's story is a separate record below.
                ABJ_404_Solution_AjaxCheckpointLogger::record(
                    $requestId,
                    'client_send_state',
                    array_merge(
                        ABJ_404_Solution_ClientBuildFingerprint::compare($build, $buildModules),
                        array(
                            'inflight' => ctype_digit($inflight) ? (int)$inflight : null,
                            'inflight_ids' => substr(
                                (string)$reader->getPostOrGetSanitize('clientInflightIds', ''), 0, 256),
                            // -1 is the client's own "could not observe this",
                            // and it is preserved rather than folded into null:
                            // an unobservable channel and an absent parameter
                            // are different findings about the client.
                            'open_tabs' => self::signedCountOrNull($tabs),
                            'foreign_inflight' => self::signedCountOrNull($foreignInflight),
                            'storage_health' => self::parseStorageHealth($storageHealth),
                        )
                    )
                );
            }
            $report = self::readReport($reader);
            if ($report === null) {
                return;
            }
            // Nested under one key, never spread across the record: the
            // envelope's own fields (request_id, event, ts, pid) are the join
            // keys the whole journal is read by, and a client that sent a
            // field with one of those names would otherwise overwrite them and
            // forge the identity of its own evidence.
            if (($report['kind'] ?? '') === 'concurrent_control_browser_receipt') {
                self::journalConcurrentControlReceipt($requestId, $report);
                return;
            }
            ABJ_404_Solution_AjaxCheckpointLogger::record(
                $requestId, 'client_prior_attempt', array('report' => $report));
        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'client_report_error', array(
                'message' => substr($e->getMessage(), 0, 200),
            ));
        }
    }

    /**
     * File a concurrent-control browser receipt beside that control's own
     * server trace while retaining which later request delivered it.
     *
     * @param array<string, mixed> $report
     */
    private static function journalConcurrentControlReceipt(string $carrierRequestId, array $report): void {
        $controlRequestId = self::ledgerIdOrEmpty($report['controlRequestId'] ?? '');
        $controlForRequestId = self::ledgerIdOrEmpty($report['controlForRequestId'] ?? '');
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $controlRequestId !== '' ? $controlRequestId : $carrierRequestId,
            'concurrent_control_client_receipt',
            array(
                'carried_by' => $carrierRequestId,
                'control_for_request_id' => $controlForRequestId,
                'control_request_id' => $controlRequestId,
                'report' => $report,
            )
        );
    }

    /**
     * Whether a journal record is complete enough to serve as the required
     * concurrent-control evidence in a support payload.
     *
     * @param array<mixed, mixed> $record
     */
    public static function isCompleteConcurrentControlJournalRecord(array $record): bool {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        return self::hasConcurrentControlJournalJoins($record)
            && self::hasConcurrentControlBrowserEvidence($report);
    }

    /** @param array<mixed, mixed> $record */
    private static function hasConcurrentControlJournalJoins(array $record): bool {
        return ($record['envelope'] ?? '') === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL
            && ($record['event'] ?? '') === 'concurrent_control_client_receipt'
            && is_string($record['carried_by'] ?? null)
            && $record['carried_by'] !== ''
            && is_string($record['control_for_request_id'] ?? null)
            && $record['control_for_request_id'] !== ''
            && is_string($record['control_request_id'] ?? null)
            && $record['control_request_id'] !== ''
            && ($record['request_id'] ?? '') === $record['control_request_id'];
    }

    /** @param array<mixed, mixed> $report */
    private static function hasConcurrentControlBrowserEvidence(array $report): bool {
        $receipt = is_array($report['receipt'] ?? null) ? $report['receipt'] : array();
        $overlap = is_array($report['overlap'] ?? null) ? $report['overlap'] : array();
        $overlapState = $overlap['state'] ?? '';
        $validOverlap = $overlapState === 'unavailable'
            || ($overlapState === 'computed'
                && is_numeric($overlap['durationMs'] ?? null)
                && (int)$overlap['durationMs'] >= 0);
        return ($report['kind'] ?? '') === 'concurrent_control_browser_receipt'
            && $validOverlap
            && is_string($receipt['resourceTimingState'] ?? null)
            && $receipt['resourceTimingState'] !== '';
    }

    /** @param mixed $value */
    private static function ledgerIdOrEmpty($value): string {
        $id = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[a-zA-Z0-9]{8,64}$/', $id) === 1 ? $id : '';
    }

    /**
     * A client-sent count that is allowed to be -1 ("this browser could not
     * observe it"), or null when the parameter was absent or not a count at
     * all. Kept separate from ctype_digit() because -1 is a real reading here
     * and silently discarding it would turn a declared blind spot into a
     * missing field.
     */
    private static function signedCountOrNull(string $raw): ?int {
        return preg_match('/^-?\d{1,9}$/', $raw) === 1 ? (int)$raw : null;
    }

    /**
     * The browser storage adapter's bounded health result. Rebuild the shape
     * field by field because this is untrusted request data; malformed input
     * remains a positive "unparseable" finding rather than blocking the table.
     *
     * @return array<string, mixed>
     */
    private static function parseStorageHealth(string $raw): array {
        if ($raw === '') {
            return array('status' => 'absent', 'raw_length' => 0);
        }
        $decoded = json_decode(substr($raw, 0, 512), true);
        if (!is_array($decoded)) {
            return array('status' => 'unparseable', 'raw_length' => strlen($raw));
        }
        $status = isset($decoded['status']) && is_scalar($decoded['status'])
            ? (string)$decoded['status'] : 'unknown';
        $quota = isset($decoded['quota']) && is_scalar($decoded['quota'])
            ? (string)$decoded['quota'] : 'unknown';
        $fallback = isset($decoded['fallback']) && is_scalar($decoded['fallback'])
            ? (string)$decoded['fallback'] : 'memory';
        return array(
            'status' => in_array($status, array('available', 'unavailable'), true) ? $status : 'unknown',
            'accessible' => is_bool($decoded['accessible'] ?? null) ? $decoded['accessible'] : null,
            'writable' => is_bool($decoded['writable'] ?? null) ? $decoded['writable'] : null,
            'quota' => in_array($quota, array('ok', 'exceeded', 'unknown'), true) ? $quota : 'unknown',
            'last_write_ok' => is_bool($decoded['last_write_ok'] ?? null)
                ? $decoded['last_write_ok'] : null,
            'fallback' => in_array($fallback, array('none', 'memory'), true) ? $fallback : 'memory',
        );
    }

    /**
     * The request reader, straight from the container.
     *
     * Resolved here rather than through
     * ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader(), which
     * returns this same service and belongs to the endpoint layer. Reading
     * request parameters is not an endpoint-only need, and routing through
     * that class made a recorder depend on the presentation surface it exists
     * to observe.
     *
     * @return ABJ_404_Solution_RequestInputNormalizer
     */
    private static function requestReader() {
        /** @var ABJ_404_Solution_RequestInputNormalizer $requestReader */
        $requestReader = abj_service('request_input_normalizer');
        return $requestReader;
    }

    /**
     * The decoded client report, or null when none was sent. Returns a
     * diagnostic stand-in (never null) when a report was sent but could not be
     * decoded: "the client sent something unparseable" is itself a finding
     * about the transport and must not be silently dropped.
     *
     * @param ABJ_404_Solution_RequestInputNormalizer $reader Docblock-typed only:
     *   tests substitute request-reader doubles that are not literally that class.
     * @return array<string, mixed>|null
     */
    private static function readReport($reader): ?array {
        $raw = $reader->getPostOrGetSanitize('clientReport', '');
        if (!is_scalar($raw) || (string)$raw === '') {
            return null;
        }
        $raw = (string)$raw;
        $truncated = strlen($raw) > self::MAX_REPORT_BYTES;
        $decoded = json_decode(substr($raw, 0, self::MAX_REPORT_BYTES), true);
        if (!is_array($decoded)) {
            return array(
                'decoded' => false,
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($raw),
                'raw_head' => substr($raw, 0, 200),
            );
        }
        // Rebuilt key by key rather than passed through: the decoded value is
        // whatever the browser sent, so its keys are only assumed to be
        // strings until they are made so here.
        $report = array();
        foreach ($decoded as $key => $value) {
            $report[(string)$key] = $value;
        }
        $report['decoded'] = true;
        $report['truncated_on_arrival'] = $truncated;
        return $report;
    }

    /**
     * The attempt outcomes the browser says its drained buffer describes.
     *
     * The support payload is the one place both halves of the request ledger
     * meet, so "the browser is reporting attempt X and the collected journals
     * never mention X" is a decisive fact about the COLLECTION rather than
     * about the request -- and it is only available if the ids the browser
     * named are read before the buffer is bounded down to fit the payload.
     * Parsing lives here, next to boundDrainedBuffer(), because this class
     * already owns every rule about what that buffer is; the manifest that
     * consumes this owns none of them.
     *
     * The three statuses are kept distinct on purpose: "the browser sent
     * nothing" and "the browser sent something we could not read" are
     * different findings, and collapsing the second into an empty id list is
     * the same silent-empty defect this whole manifest exists to end.
     *
     * @param string $raw The raw POSTed buffer, already unslashed.
     * A failure is sticky across duplicate records. Browser storage is a
     * ring buffer and a retry can leave more than one account of an attempt;
     * a later success must not erase an earlier timeout, and a later timeout
     * must still override an earlier success. Only the explicit `success`
     * outcome is healthy, matching the ranking rules used after journaling.
     *
     * @return array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>}
     *   status: `absent`, `unparseable`, or `parsed`.
     */
    public static function attemptOutcomesInDrainedBuffer(string $raw): array {
        if ($raw === '') {
            return array(
                'status' => 'absent', 'ids' => array(), 'records' => 0, 'outcomes' => array(),
            );
        }
        $boundedRaw = substr($raw, 0, self::MAX_DRAINED_BUFFER_INPUT_BYTES);
        $decoded = json_decode($boundedRaw, true);
        if (!is_array($decoded) || !self::isJsonArrayDocument($boundedRaw)) {
            return array(
                'status' => 'unparseable', 'ids' => array(), 'records' => 0, 'outcomes' => array(),
            );
        }
        $ids = array();
        $outcomes = array();
        foreach ($decoded as $record) {
            if (!is_array($record) || !isset($record['id']) || !is_scalar($record['id'])) {
                continue;
            }
            $id = (string)$record['id'];
            // The wire contract's own request-id shape. An id that cannot be a
            // server request id cannot be reconciled against one, and letting
            // arbitrary browser text into the manifest would put an unbounded
            // string in a bounded record.
            if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $id) !== 1) {
                continue;
            }
            $ids[$id] = true;
            $outcome = isset($record['outcome']) && is_scalar($record['outcome'])
                ? (string)$record['outcome'] : '';
            $healthy = in_array($outcome, self::HEALTHY_OUTCOMES, true);
            if (!array_key_exists($id, $outcomes) || !$healthy) {
                $outcomes[$id] = $healthy;
            }
        }
        return array(
            'status' => 'parsed',
            'ids' => array_slice(array_keys($ids), 0, self::MAX_ATTEMPT_IDS_REPORTED),
            'records' => count($decoded),
            'outcomes' => $outcomes,
        );
    }

    /**
     * Fit the browser's drained attempt buffer inside a byte budget WITHOUT
     * destroying it.
     *
     * The buffer is a JSON array of per-attempt records, and it can exceed
     * what the support payload will carry: the browser store holds up to 16
     * records / 48 KB. Cutting the serialized array at a byte offset -- which
     * is what both ends used to do -- leaves invalid JSON, so an overflowing
     * buffer arrived as "unparseable" and EVERY attempt was lost rather than
     * the least interesting one. That is the same defect the journal excerpt
     * had, on the one channel that can describe attempts the server never saw
     * at all.
     *
     * So whole records are dropped, not bytes, and the ones kept are chosen:
     * attempts that did not succeed first (oldest first, because the first
     * failure is the one without retry effects), then the rest newest first.
     *
     * @param string $raw The raw POSTed buffer.
     * @param int $budgetBytes Ceiling for the returned JSON.
     * @return array{json: string, parsed: bool, kept: int, dropped: int, raw_length: int, error: string}
     */
    public static function boundDrainedBuffer(string $raw, int $budgetBytes): array {
        $rawLength = strlen($raw);
        $unparseable = array(
            'json' => '', 'parsed' => false, 'kept' => 0, 'dropped' => 0,
            'raw_length' => $rawLength, 'error' => '',
        );
        if ($raw === '') {
            return $unparseable;
        }
        $boundedRaw = substr($raw, 0, self::MAX_DRAINED_BUFFER_INPUT_BYTES);
        $decoded = json_decode($boundedRaw, true);
        if (!is_array($decoded) || !self::isJsonArrayDocument($boundedRaw)) {
            $unparseable['error'] = is_array($decoded)
                ? 'expected a JSON array of attempt records'
                : json_last_error_msg();
            return $unparseable;
        }
        $records = array();
        foreach ($decoded as $record) {
            $records[] = $record;
        }
        if ($rawLength <= self::MAX_DRAINED_BUFFER_INPUT_BYTES && $rawLength <= $budgetBytes) {
            return array(
                'json' => $raw, 'parsed' => true, 'kept' => count($records), 'dropped' => 0,
                'raw_length' => $rawLength, 'error' => '',
            );
        }

        $kept = self::keepWithinBudget($records, $budgetBytes);
        $json = json_encode($kept, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > $budgetBytes) {
            $unparseable['error'] = 'buffer could not be reduced to the support budget';
            return $unparseable;
        }
        return array(
            'json' => $json, 'parsed' => true, 'kept' => count($kept),
            'dropped' => count($records) - count($kept), 'raw_length' => $rawLength, 'error' => '',
        );
    }

    /**
     * Whether decoded JSON came from the buffer's required top-level array.
     *
     * Associative decoding turns both JSON objects and arrays into PHP arrays,
     * so the decoded type alone cannot enforce the wire contract. Inspecting
     * the first non-whitespace byte keeps a valid object from being reported as
     * a successfully parsed empty attempt list.
     */
    private static function isJsonArrayDocument(string $raw): bool {
        return substr(ltrim($raw), 0, 1) === '[';
    }

    /**
     * The records that fit, in their original order, failures first.
     *
     * @param array<int, mixed> $records
     * @return array<int, mixed>
     */
    private static function keepWithinBudget(array $records, int $budgetBytes): array {
        $failed = array();
        $healthy = array();
        foreach ($records as $position => $record) {
            $outcome = is_array($record) && isset($record['outcome']) && is_scalar($record['outcome'])
                ? (string)$record['outcome'] : '';
            if (in_array($outcome, self::HEALTHY_OUTCOMES, true)) {
                $healthy[] = $position;
            } else {
                $failed[] = $position;
            }
        }
        $order = array_merge($failed, array_reverse($healthy));

        // Two brackets and the commas between the records; charged up front so
        // the encoded result cannot creep past the budget on the last record.
        $used = 2;
        $keepPositions = array();
        foreach ($order as $position) {
            $encoded = json_encode($records[$position], JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }
            $cost = strlen($encoded) + ($keepPositions === array() ? 0 : 1);
            if ($used + $cost > $budgetBytes) {
                continue;
            }
            $used += $cost;
            $keepPositions[] = $position;
        }
        sort($keepPositions);

        $kept = array();
        foreach ($keepPositions as $position) {
            $kept[] = $records[$position];
        }
        return $kept;
    }
}
