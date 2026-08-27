<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How many bytes the server actually encoded for a browser session's most
 * recent completed admin-table response.
 *
 * The one question that needs BOTH durable journals and can be answered by
 * neither alone. The stage trace owns the session-to-request join; the
 * checkpoint journal owns the exact post-json_encode byte count. Joining them
 * is a distinct responsibility from reading either channel for the support
 * payload, which is why it lives here rather than inside
 * ABJ_404_Solution_CheckpointJournalReader: that class answers "what does this
 * journal say", and this one answers a question about the site.
 *
 * Consumed once, by the size_target canary step, so the ladder's size probes
 * are calibrated against the real failing response rather than a default.
 *
 * Every absence is NAMED rather than collapsed into one word. Support report
 * 2026-08-27 (Azure App Service, plugin 4.3.4) shipped
 * `{bytes: null, source: "unavailable"}` for a session the browser definitely
 * had, and nothing in the payload could separate "the client sent no session"
 * from "the trace journal names no table request for it" (what actually
 * happened -- the trace writer was never armed) from "the table request ran and
 * journaled no size". A fabricated size is never returned in any of the three.
 */
final class ABJ_404_Solution_EncodedTableResponseSize {

    /**
     * The size for one browser session, or a named absence.
     *
     * Scans the bounded/rotated journals and the live pending spools of both
     * channels. Missing, malformed, foreign-session, non-table and zero-byte
     * records all resolve to a named absence rather than to a number.
     *
     * An absence here is not the end of the ladder's size axis: the step falls
     * through to a live measurement
     * (ABJ_404_Solution_MeasuredTableResponseSize) and carries this channel's
     * own answer alongside it as `realResponseRecordedSource`, so the reason
     * the recorded number was unavailable survives even when a measured one
     * replaces it. Naming the absence precisely is what makes that pair
     * readable; see ABJ_404_Solution_AjaxCanaryStepRunner::resolveSizeTarget().
     *
     * @return array{bytes: int|null, source: string, request_id: string}
     */
    public static function forSession(string $sessionId): array {
        $sessionId = substr($sessionId, 0, 64);
        if ($sessionId === '') {
            return self::noEncodedSize('unavailable');
        }
        try {
            $traceSource = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $traceLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($traceSource['paths']);
            $tableRequestIds = self::tableRequestIdsInSession($traceLines, $sessionId);
            if ($tableRequestIds === array()) {
                return self::noEncodedSize('session_not_traced');
            }

            $source = ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource();
            $checkpointLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($source['paths']);
            return self::latestEncodedSizeForRequests(
                $checkpointLines, $tableRequestIds, self::noEncodedSize('no_encoded_size_recorded'));
        } catch (Throwable $e) {
            // Unconditional; abj404_logPhpFallback() is defined at plugin
            // entry, before any class here can be autoloaded.
            abj404_logPhpFallback(
                'ajax-checkpoint', 'Encoded table response-size lookup failed: ' . $e->getMessage());
            return self::noEncodedSize('journal_error');
        }
    }

    /**
     * The no-size answer, carrying the reason it is the answer.
     *
     * @return array{bytes: int|null, source: string, request_id: string}
     */
    private static function noEncodedSize(string $reason): array {
        return array('bytes' => null, 'source' => $reason, 'request_id' => '');
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
}
