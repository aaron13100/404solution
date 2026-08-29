<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the server WROTE against what the browser RECEIVED, joined per browser
 * session.
 *
 * The one comparison that names a body rewritten in transit, and the one thing
 * neither side can say alone. The server knows exactly how many bytes it
 * encoded and echoed; only the browser can report how many bytes actually
 * arrived, and it can report that even when the payload will not parse,
 * because Resource Timing's `decodedBodySize` is measured on the wire and not
 * by the JSON parser.
 *
 * Support report 2026-08-27 (plugin 4.3.4, Azure App Service for Linux, nginx
 * + brotli, `wp-content-copy-protector` and `gtranslate` both active) is the
 * case this exists for. The ladder's `stream` step came back `parsererror`
 * with `truncated_on_arrival = false`: the server emitted 3072 bytes, the
 * browser's Resource Timing reported 6089, and the report was read as a
 * mid-response-flush failure for a week. A body that arrives COMPLETE,
 * UNPARSEABLE, and at roughly twice the size the server wrote has been
 * rewritten by an intermediary, and both halves of that comparison were
 * already in the journal. Nothing joined them, so the ladder could only ever
 * say "the step failed".
 *
 * This class owns the join and nothing else: which records belong to one
 * browser session, which emitted count pairs with which delivered count, and
 * the accounting around that. It owns no decision rule -- the tolerance and
 * the verdict live in ABJ_404_Solution_ResponseBodyRewriteVerdict, pure and
 * directly tested -- no transport, and no formatting. That is the same split
 * ABJ_404_Solution_DetachAbEvidence already uses against
 * ABJ_404_Solution_DetachAbVerdict.
 *
 * Three properties are load-bearing rather than defensive:
 *
 *   1. Unknown is never a match. An absent `decodedBodySize` is a finding
 *      about the BROWSER (no Resource Timing entry, an opaque response, a
 *      cleared buffer), not evidence that the body arrived intact. A
 *      comparison with either half missing is reported as such and reaches
 *      no verdict.
 *   2. Emitted means every body byte the server wrote, not just the JSON.
 *      The `stream` step echoes a leading whitespace block OUTSIDE
 *      json_encode(), so on a host where that block is actually emitted the
 *      encoded size understates the body by exactly
 *      AjaxCanaryLadder::STREAM_WHITESPACE_BYTES. That is exact accounting
 *      the journal already carries, not slack to be absorbed by a tolerance.
 *   3. Session scoping. The checkpoint journal is site-wide and two admin
 *      tabs write into one file, so a comparison is only ever built from
 *      records this session's own browser produced.
 */
final class ABJ_404_Solution_ResponseBodyDeliveryEvidence {

    /** The journal event this class writes its joined evidence under. */
    const EVIDENCE_EVENT = 'body_delivery_evidence';

    /** The browser's per-step receipt, written by ABJ_404_Solution_Ajax_CanaryLadder. */
    const RECEIPT_EVENT = 'canary_step_client_receipt';

    /** The post-encode size record written by ABJ_404_Solution_AjaxResponseEmitter. */
    const ENCODE_EVENT = 'json_encode';

    /** The `stream` step's own flush findings, written by ABJ_404_Solution_AjaxCanaryStepRunner. */
    const STREAM_FLUSH_EVENT = 'canary_stream_flush_outcome';

    /** The join was computed from this session's own records. */
    const STATUS_COMPUTED = 'computed';

    /** The client sent no session id, so nothing can be scoped to one browser. */
    const STATUS_NO_SESSION = 'no_session';

    /** The journal could not be read or joined; the reason travels with the record. */
    const STATUS_ERROR = 'error';

    /** Both halves are known, so the pure rule may compare them. */
    const STATE_COMPARABLE = 'comparable';

    /** The server never journaled a size for this response. */
    const STATE_EMITTED_UNKNOWN = 'emitted_unknown';

    /** The browser reported no usable `decodedBodySize` for this response. */
    const STATE_DELIVERED_UNKNOWN = 'delivered_unknown';

    /** Neither half is known. */
    const STATE_BOTH_UNKNOWN = 'both_unknown';

    /**
     * The pseudo-step naming the real admin-table request the ladder run
     * exists to explain. It is not a ladder step and never will be, but it is
     * the response the whole comparison is ultimately about, so it is carried
     * in the same list rather than as a second shape a reader has to join by
     * hand.
     */
    const REAL_REQUEST_STEP = 'real_request';

    /** Emitted = the encoded JSON only. */
    const EMITTED_SOURCE_ENCODE = 'json_encode';

    /** Emitted = the encoded JSON plus the `stream` step's leading whitespace block. */
    const EMITTED_SOURCE_ENCODE_PLUS_STREAM = 'json_encode_plus_stream_whitespace';

    /** Delivered = Resource Timing `decodedBodySize`, the only octet count the browser measures. */
    const DELIVERED_SOURCE_RESOURCE_TIMING = 'resource_timing_decoded_body';

    /** Neither half was available for this response. */
    const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * Bound on how many comparisons ride the journaled record. One ladder run
     * produces at most one per dispatched step plus the real request; the cap
     * only stops a long-lived session from turning one record into a large
     * one.
     */
    const MAX_COMPARISONS_ON_RECORD = 24;

    /**
     * The full emitted-against-delivered join for one browser session, ready
     * to journal and ready to hand to the pure interpretation rule.
     *
     * Never throws and never returns a partial shape: every field is present
     * on every path, and `status` says why the record is what it is.
     *
     * @return array<string, mixed>
     */
    public static function forSession(string $sessionId): array {
        $sessionKey = ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($sessionId);
        try {
            if ($sessionId === '') {
                $record = self::emptyRecord($sessionKey);
                $record['status'] = self::STATUS_NO_SESSION;
                return $record;
            }
            // ONE read of the checkpoint journal, shared by both halves of the
            // comparison. Both halves used to read it independently, and a
            // record appended between the two reads let this method pair an
            // emitted size from one snapshot with a delivery record from
            // another -- a comparison neither read actually observed, reported
            // with the same confidence as a real one. The trace journal is read
            // separately because only the checkpoint half is shared.
            $source = ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource();
            $checkpointLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($source['paths']);
            $traceSource = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $traceLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($traceSource['paths']);
            return self::fromLines(
                $checkpointLines,
                $sessionKey,
                ABJ_404_Solution_EncodedTableResponseSize::fromLines(
                    $traceLines, $checkpointLines, $sessionId)
            );
        } catch (Throwable $e) {
            $record = self::emptyRecord($sessionKey);
            $record['status'] = self::STATUS_ERROR;
            $record['error'] = substr($e->getMessage(), 0, 200);
            return $record;
        }
    }

    /**
     * The same record built from journal lines a caller already holds.
     *
     * ABJ_404_Solution_CanaryReceiptEvidence reconstructs the ladder matrix
     * offline, from exactly these receipts, when the live `interpret`
     * response never reached the browser -- which is the case this whole
     * diagnostic exists for. Recomputing the matrix there WITHOUT this join
     * would silently drop the one verdict that names the incident from the
     * support payload, so both paths go through one implementation rather
     * than through two that can disagree.
     *
     * @param array<int, string> $lines Checkpoint JSONL lines, oldest first.
     * @param string $sessionKey ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey().
     * @param array{bytes: int|null, source: string, request_id: string} $realRequest
     * @return array<string, mixed>
     */
    public static function fromLines(array $lines, string $sessionKey, array $realRequest): array {
        $record = self::emptyRecord($sessionKey);
        $joined = self::comparisonsIn($lines, $sessionKey, $realRequest);
        $record['comparisons'] = array_slice(
            $joined['comparisons'], 0, self::MAX_COMPARISONS_ON_RECORD);
        $record['comparisons_total'] = count($joined['comparisons']);
        $record['stream_flush_reached_sapi'] = $joined['stream_flush_reached_sapi'];
        $record['journal_lines_scanned'] = count($lines);
        // Decided over the WHOLE join, not the truncated copy above: the cap
        // exists to bound one record's size, and a verdict that changed with
        // it would silently depend on how long the browser session had been
        // open.
        $record['verdict'] = ABJ_404_Solution_ResponseBodyRewriteVerdict::fromComparisons(
            $joined['comparisons'], ABJ_404_Solution_AjaxCanaryLadder::MAX_REPORTED_STEP_CHARS);
        return $record;
    }

    /**
     * The record every path starts from: a complete shape with no evidence in
     * it yet, so "nothing to join" can never drift out of step with the shape
     * a joined one has.
     *
     * @return array<string, mixed>
     */
    private static function emptyRecord(string $sessionKey): array {
        return array(
            'status' => self::STATUS_COMPUTED,
            'session_key' => $sessionKey,
            'comparisons' => array(),
            'comparisons_total' => 0,
            // null, never false: "this session journaled no stream step" and
            // "the stream step ran and reached nobody" are opposite findings.
            'stream_flush_reached_sapi' => null,
            'journal_lines_scanned' => 0,
            // Produced by the real rule rather than hand-written as a
            // literal, so a "nothing to join" record can never drift out of
            // step with the shape a decided one has.
            'verdict' => ABJ_404_Solution_ResponseBodyRewriteVerdict::fromComparisons(array()),
        );
    }

    /**
     * One session's emitted-against-delivered comparisons, in ladder order.
     *
     * Pure and side-effect free: it takes journal lines rather than reading
     * them, so every join case (a foreign session, a step with no receipt, a
     * receipt with no Resource Timing, a response the server never sized) is
     * directly assertable without a journal on disk that happens to contain
     * it.
     *
     * The static-asset probe is deliberately absent from the result. It never
     * reaches PHP, so there is no encoded response to compare against and its
     * emitted half is not merely unknown but nonexistent; listing it would put
     * a permanent `emitted_unknown` row in every record.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @param string $sessionKey ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey().
     * @param array{bytes: int|null, source: string, request_id: string} $realRequest
     *   ABJ_404_Solution_EncodedTableResponseSize::forSession(). Unknown fields
     *   are tolerated; only `bytes` and `request_id` are read.
     * @return array{comparisons: array<int, array<string, mixed>>,
     *   stream_flush_reached_sapi: bool|null}
     */
    public static function comparisonsIn(array $lines, string $sessionKey, array $realRequest): array {
        $receipts = self::receiptsInSession($lines, $sessionKey);
        $encoded = self::encodedSizesIn($lines);
        $streamFlush = self::streamFlushOutcomesIn($lines);

        // Scoped by the record's OWN session first, so "did this session's
        // stream step reach the wire" survives a lost browser receipt: that
        // receipt travels on the delivery channel under diagnosis, and making
        // the answer depend on it is the cross-channel join that already cost
        // this investigation a week. The receipt-keyed lookup below is the
        // fallback for records written before the stamp existed.
        $streamReachedSapi = null;
        foreach ($streamFlush as $outcome) {
            if ($sessionKey !== '' && $outcome['session_key'] === $sessionKey) {
                $streamReachedSapi = $outcome['reached_sapi'];
            }
        }

        $comparisons = array();
        foreach (ABJ_404_Solution_AjaxCanaryLadder::STEPS as $step) {
            if (!isset($receipts[$step])) {
                continue;
            }
            $requestId = $receipts[$step]['request_id'];
            $flush = $streamFlush[$requestId] ?? null;
            if ($streamReachedSapi === null
                    && $step === ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM && $flush !== null) {
                $streamReachedSapi = $flush['reached_sapi'];
            }
            $comparisons[] = self::comparison(array(
                'step' => $step,
                'request_id' => $requestId,
                'emitted' => self::emittedBytes($encoded[$requestId] ?? null, $flush),
                'delivered' => $receipts[$step]['delivered_bytes'],
                'timing_state' => $receipts[$step]['resource_timing_state'],
            ));
        }

        $realId = ABJ_404_Solution_AjaxRequestLedger::normalizeId($realRequest['request_id'] ?? null, '');
        $realEmitted = isset($realRequest['bytes']) && is_numeric($realRequest['bytes'])
            && (int)$realRequest['bytes'] > 0 ? (int)$realRequest['bytes'] : null;
        if ($realId !== '' || $realEmitted !== null) {
            $reported = ABJ_404_Solution_DeliveredTableResponseSize::forRequest($lines, $realId);
            $comparisons[] = self::comparison(array(
                'step' => self::REAL_REQUEST_STEP,
                'request_id' => $realId,
                'emitted' => $realEmitted === null
                    ? array('bytes' => null, 'source' => self::SOURCE_UNAVAILABLE)
                    : array('bytes' => $realEmitted, 'source' => self::EMITTED_SOURCE_ENCODE),
                'delivered' => $reported['bytes'],
                'timing_state' => $reported['resource_timing_state'],
            ));
        }
        return array('comparisons' => $comparisons, 'stream_flush_reached_sapi' => $streamReachedSapi);
    }

    /**
     * One comparison row. The known-ness of each half is NAMED here; whether a
     * known pair actually differs is a decision and stays in
     * ABJ_404_Solution_AjaxCanaryLadder::interpretResults().
     *
     * Takes one keyed row rather than positional arguments. `step`,
     * `request_id` and `timing_state` are all strings, so in positional form a
     * caller could transpose any two of them and produce a perfectly typed row
     * that attributes one step's delivery evidence to another. Nothing
     * downstream could detect it: every field would still be a plausible
     * string. Keys make the transposition unwriteable, and PHPStan checks the
     * shape at each call site. (PHP 7.4 is the floor here, so named arguments
     * are not available and a positional value-object constructor would move
     * the same hazard rather than remove it.)
     *
     * @param array{step: string, request_id: string, emitted: array{bytes: int|null, source: string}, delivered: int|null, timing_state: string} $row
     * @return array<string, mixed>
     */
    private static function comparison(array $row): array {
        $emitted = $row['emitted'];
        $delivered = $row['delivered'];
        if ($emitted['bytes'] === null && $delivered === null) {
            $state = self::STATE_BOTH_UNKNOWN;
        } else if ($emitted['bytes'] === null) {
            $state = self::STATE_EMITTED_UNKNOWN;
        } else if ($delivered === null) {
            $state = self::STATE_DELIVERED_UNKNOWN;
        } else {
            $state = self::STATE_COMPARABLE;
        }
        return array(
            'step' => $row['step'],
            'request_id' => $row['request_id'],
            'emitted_bytes' => $emitted['bytes'],
            'emitted_source' => $emitted['source'],
            'delivered_bytes' => $delivered,
            'delivered_source' => $delivered === null
                ? self::SOURCE_UNAVAILABLE : self::DELIVERED_SOURCE_RESOURCE_TIMING,
            'resource_timing_state' => $row['timing_state'],
            'state' => $state,
        );
    }

    /**
     * Every body byte the server wrote for one response, or a named absence.
     *
     * @param int|null $encodedBytes The `json_encode` record's own count.
     * @param array{reached_sapi: bool, whitespace_bytes: int}|null $flush
     * @return array{bytes: int|null, source: string}
     */
    private static function emittedBytes(?int $encodedBytes, ?array $flush): array {
        if ($encodedBytes === null) {
            return array('bytes' => null, 'source' => self::SOURCE_UNAVAILABLE);
        }
        $whitespace = $flush === null ? 0 : max(0, $flush['whitespace_bytes']);
        if ($whitespace === 0) {
            return array('bytes' => $encodedBytes, 'source' => self::EMITTED_SOURCE_ENCODE);
        }
        return array(
            'bytes' => $encodedBytes + $whitespace,
            'source' => self::EMITTED_SOURCE_ENCODE_PLUS_STREAM,
        );
    }

    /**
     * The latest receipt this session's browser filed for each ladder step.
     *
     * Latest wins: a step the client retried reports twice, and the run the
     * `interpret` step is closing is the later one.
     *
     * @param array<int, string> $lines
     * @return array<string, array{request_id: string, delivered_bytes: int|null,
     *   resource_timing_state: string}>
     */
    private static function receiptsInSession(array $lines, string $sessionKey): array {
        $receipts = array();
        foreach ($lines as $line) {
            if ($sessionKey === '' || strpos($line, self::RECEIPT_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::RECEIPT_EVENT
                    || self::scalarField($record, 'session_key') !== $sessionKey) {
                continue;
            }
            $step = self::scalarField($record, 'step');
            $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
                $record['step_request_id'] ?? null, '');
            if ($step === '' || $requestId === ''
                    || !in_array($step, ABJ_404_Solution_AjaxCanaryLadder::STEPS, true)) {
                continue;
            }
            $receipts[$step] = array(
                'request_id' => $requestId,
                'delivered_bytes' => self::positiveIntOrNull($record['decoded_body_bytes'] ?? null),
                'resource_timing_state' => self::scalarField($record, 'resource_timing_state'),
            );
        }
        return $receipts;
    }

    /**
     * Encoded response size by request id, latest write per id.
     *
     * @param array<int, string> $lines
     * @return array<string, int>
     */
    private static function encodedSizesIn(array $lines): array {
        $sizes = array();
        foreach ($lines as $line) {
            if (strpos($line, '"event":"' . self::ENCODE_EVENT . '"') === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::ENCODE_EVENT) {
                continue;
            }
            $requestId = self::scalarField($record, 'request_id');
            $bytes = self::positiveIntOrNull($record['bytes'] ?? null);
            if ($requestId === '' || $bytes === null) {
                continue;
            }
            $sizes[$requestId] = $bytes;
        }
        return $sizes;
    }

    /**
     * The `stream` step's own flush findings by request id.
     *
     * Both fields matter to a different consumer: `whitespace_bytes` corrects
     * the emitted count, and `reached_sapi` is what lets
     * `streamingBufferCausal` require that the streaming step actually
     * streamed instead of assuming it did.
     *
     * @param array<int, string> $lines
     * @return array<string, array{reached_sapi: bool, whitespace_bytes: int, session_key: string}>
     */
    private static function streamFlushOutcomesIn(array $lines): array {
        $outcomes = array();
        foreach ($lines as $line) {
            if (strpos($line, self::STREAM_FLUSH_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::STREAM_FLUSH_EVENT) {
                continue;
            }
            $requestId = self::scalarField($record, 'request_id');
            if ($requestId === '') {
                continue;
            }
            $whitespace = $record['streamWhitespaceBytes'] ?? null;
            $outcomes[$requestId] = array(
                'reached_sapi' => !empty($record['streamFlushReachedSapi']),
                'whitespace_bytes' => is_numeric($whitespace) ? max(0, (int)$whitespace) : 0,
                'session_key' => self::scalarField($record, 'session_key'),
            );
        }
        return $outcomes;
    }

    /**
     * A byte count the browser or server positively measured, or null.
     *
     * Zero and the client's own -1 sentinel are both unknown: Resource Timing
     * reports 0 for an entry it will not disclose (an opaque or
     * timing-restricted response) exactly as it would for an empty body, and
     * the plugin cannot tell those apart. Calling either one "0 bytes
     * delivered" would invent a delivery finding out of a browser's refusal to
     * answer.
     *
     * @param mixed $value
     */
    private static function positiveIntOrNull($value): ?int {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<array-key, mixed> $record
     */
    private static function scalarField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }
}
