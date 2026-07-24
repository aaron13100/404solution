<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The detach A/B verdict and encoded-size basis for the session(s) that
 * actually FAILED, computed from the failing evidence rather than from whoever
 * happened to click "send debug log to developer" (Bruno timeout gap-hunt
 * iteration 5, Opus gap 4).
 *
 * Browser session identity is deliberately per browser tab: the checkpoint
 * journal is site-wide while the A/B attempt counters and the encoded-size
 * lookup are both scoped to one session, so two admin tabs write two
 * independent sequences into one file. The support-request handler reads only
 * the CLICKING tab's session id, and both server-side conclusions
 * (ABJ_404_Solution_DetachAbEvidence::verdictForSession and
 * ABJ_404_Solution_CheckpointJournalReader::latestEncodedTableResponseForSession)
 * were then scoped to that one session -- even when the failed request ids
 * carried in the same support payload belong to another tab, or to a tab the
 * admin has already closed. Multi-tab failure is established for Bruno, so the
 * raw per-attempt evidence can survive in the journals while the automated
 * verdict reports "zero matched attempts" for a session that never failed.
 *
 * This class closes that gap. It derives the relevant session ids FROM the
 * failing evidence -- the request ids condemned across the journals plus the
 * clicking tab's own drained-buffer failures -- maps each one back to the
 * browser session that owned it, and computes a bounded verdict and
 * encoded-size basis per failing session. It then states the relationship
 * between the clicking session and the failing session(s) explicitly, so a
 * "the click came from a different tab" situation is a stated finding rather
 * than a silently empty verdict.
 *
 * Three properties are load-bearing, not defensive:
 *
 *   1. The trace journal is the ONLY channel that carries the raw session id
 *      alongside the request id (the checkpoint journal deliberately stays
 *      minimal and carries only a HASHED session key). So the failing-id ->
 *      session join is read there, and the raw session id it yields is what
 *      both downstream lookups need as their input.
 *   2. A failing id the clicking tab reported from its OWN drained buffer, but
 *      that the server never traced, belongs to the clicking session by
 *      construction: the browser only holds its own tab's buffer. Those are
 *      attributed to the click session rather than dropped as unresolved.
 *   3. Unattributable is not failure. A condemned request id with no trace
 *      record at all (never reached PHP, or its trace rotated out) is listed
 *      separately, never folded into a session it cannot be shown to belong
 *      to. Inventing a session would manufacture a per-session verdict out of
 *      an id that has none.
 */
final class ABJ_404_Solution_FailingSessionEvidence {

    /** At least one failing request id was found; per-session diagnostics follow. */
    const STATUS_COMPUTED = 'computed';

    /** No request id was condemned anywhere, so there is no failing session to diagnose. */
    const STATUS_NO_FAILING_REQUESTS = 'no_failing_requests';

    /** The join or a per-session computation threw; the reason travels with the record. */
    const STATUS_ERROR = 'error';

    /** No failing id could be attributed to any browser session. */
    const REL_NO_FAILING_SESSIONS = 'no_failing_sessions';

    /** Failing sessions exist, but the support request carried no session id to compare them to. */
    const REL_NO_CLICK_SESSION = 'no_click_session';

    /** Every failing session is the clicking session: the click came from a tab that failed. */
    const REL_CLICK_SESSION_FAILING = 'click_session_failing';

    /** No failing session is the clicking session: the failures belong to other/closed tabs. */
    const REL_FOREIGN_SESSIONS_ONLY = 'foreign_sessions_only';

    /** Some failing sessions are the clicking session and some are not. */
    const REL_MIXED = 'mixed';

    /**
     * Sessions a full verdict + encoded-size is computed for. Each one costs a
     * bounded journal read pair, so this caps the work a single support request
     * pays; sessions past it are still counted toward the click-vs-failing
     * relationship and reported as omitted, never silently dropped.
     */
    const MAX_DIAGNOSED_SESSIONS = 4;

    /** Failing request ids listed inside one session entry. */
    const MAX_FAILING_IDS_PER_SESSION = 8;

    /** Failing request ids with no resolvable session, listed once on the record. */
    const MAX_UNRESOLVED_IDS = 12;

    /**
     * The full per-session failing-evidence record, ready to journal into the
     * support payload.
     *
     * Never throws and never returns a partial shape: every field is present on
     * every path, and `status` says why the record is what it is. The same
     * principle DetachAbEvidence follows -- a "nothing to decide" record is
     * positive evidence, not an absence a reader has to infer.
     *
     * @param array<string, bool> $failingIds Every request id condemned across
     *   the journals unioned with the clicking tab's drained-buffer failures,
     *   as ABJ_404_Solution_SupportEvidenceExcerpt already assembled it for the
     *   journal excerpts. Keyed by id.
     * @param array<string, bool> $clientFailingIds The subset of the above that
     *   came from the clicking tab's OWN drained buffer. Keyed by id. These
     *   belong to the click session even when the server never traced them.
     * @param string $clickSessionId The browser session the support request was
     *   sent from, bounded to the ledger's 64-character field width here.
     * @return array<string, mixed>
     */
    public static function forSupport(
        array $failingIds,
        array $clientFailingIds,
        string $clickSessionId
    ): array {
        $clickSessionId = substr($clickSessionId, 0, 64);
        $clickSessionKey = ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($clickSessionId);
        $record = self::emptyRecord($clickSessionKey);
        try {
            $failing = self::normalizeIdSet($failingIds);
            if ($failing === array()) {
                return $record;
            }
            $record['status'] = self::STATUS_COMPUTED;
            $record['failing_request_count'] = count($failing);

            $traceSource = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $traceLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($traceSource['paths']);
            $sessionByRequestId = self::sessionsForFailingIds($traceLines, $failing);

            $grouped = self::groupBySession(
                $failing, $sessionByRequestId, $clientFailingIds, $clickSessionId);
            return self::diagnose($record, $grouped['sessions'], $grouped['unresolved'], $clickSessionKey);
        } catch (Throwable $e) {
            $record['status'] = self::STATUS_ERROR;
            $record['error'] = substr($e->getMessage(), 0, 200);
            return $record;
        }
    }

    /**
     * The record every path starts from: a complete shape saying nothing
     * failed, which the "no failing requests" path returns verbatim.
     *
     * @return array<string, mixed>
     */
    private static function emptyRecord(string $clickSessionKey): array {
        return array(
            'status' => self::STATUS_NO_FAILING_REQUESTS,
            'click_session_key' => $clickSessionKey,
            'click_vs_failing' => self::REL_NO_FAILING_SESSIONS,
            'failing_request_count' => 0,
            'sessions_resolved' => 0,
            'sessions_omitted' => 0,
            'sessions' => array(),
            'unresolved_failing_request_ids' => array(),
            'unresolved_failing_request_count' => 0,
        );
    }

    /**
     * The raw session id that owns each failing request id, read from the trace
     * journal -- the only channel that carries request id and raw session id on
     * the same record.
     *
     * Pure and side-effect free: it takes lines rather than reading them, so
     * every case (a foreign session, an untraced id, a session-less record) is
     * directly assertable. First writer wins, and the trace stream is oldest
     * first, so a request's session is fixed by its earliest record and a
     * later record for the same id cannot move it.
     *
     * @param array<int, string> $traceLines JSONL lines, oldest first.
     * @param array<string, bool> $failing Failing ids to resolve, keyed by id.
     * @return array<string, string> Request id to raw session id, for the
     *   failing ids that had a trace record naming a non-empty session.
     */
    public static function sessionsForFailingIds(array $traceLines, array $failing): array {
        $sessionByRequestId = array();
        foreach ($traceLines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $requestId = self::scalarField($record, 'request_id');
            if ($requestId === '' || !isset($failing[$requestId])
                    || isset($sessionByRequestId[$requestId])) {
                continue;
            }
            $sessionId = substr(self::scalarField($record, 'session_id'), 0, 64);
            if ($sessionId !== '') {
                $sessionByRequestId[$requestId] = $sessionId;
            }
        }
        return $sessionByRequestId;
    }

    /**
     * Split the failing ids into per-session buckets and an unresolved list.
     *
     * A traced id lands in its own session's bucket. An untraced id that the
     * clicking tab reported from its own drained buffer is attributed to the
     * click session (property 2). Everything else is unresolved (property 3).
     *
     * @param array<string, bool> $failing
     * @param array<string, string> $sessionByRequestId
     * @param array<string, bool> $clientFailingIds
     * @return array{sessions: array<string, array<int, string>>, unresolved: array<string, bool>}
     */
    private static function groupBySession(
        array $failing,
        array $sessionByRequestId,
        array $clientFailingIds,
        string $clickSessionId
    ): array {
        $sessions = array();
        $unresolved = array();
        foreach (array_keys($failing) as $id) {
            $id = (string)$id;
            if (isset($sessionByRequestId[$id])) {
                $sessions[$sessionByRequestId[$id]][] = $id;
            } elseif ($clickSessionId !== '' && isset($clientFailingIds[$id])) {
                $sessions[$clickSessionId][] = $id;
            } else {
                $unresolved[$id] = true;
            }
        }
        return array('sessions' => $sessions, 'unresolved' => $unresolved);
    }

    /**
     * Compute a bounded verdict and encoded-size basis for each failing
     * session, in a deterministic order, and state the click-vs-failing
     * relationship.
     *
     * @param array<string, mixed> $record
     * @param array<string, array<int, string>> $idsBySession Raw session id to its failing ids.
     * @param array<string, bool> $unresolved
     * @return array<string, mixed>
     */
    private static function diagnose(
        array $record,
        array $idsBySession,
        array $unresolved,
        string $clickSessionKey
    ): array {
        $keyOf = array();
        foreach (array_keys($idsBySession) as $rawSessionId) {
            $keyOf[$rawSessionId] = ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($rawSessionId);
        }
        uksort($idsBySession, static function ($a, $b) use ($keyOf) {
            return strcmp($keyOf[$a], $keyOf[$b]);
        });

        $sessions = array();
        $omitted = 0;
        $sawClick = false;
        $sawForeign = false;
        foreach ($idsBySession as $rawSessionId => $ids) {
            $sessionKey = $keyOf[$rawSessionId];
            $isClick = $clickSessionKey !== '' && $sessionKey === $clickSessionKey;
            $isClick ? $sawClick = true : $sawForeign = true;
            if (count($sessions) >= self::MAX_DIAGNOSED_SESSIONS) {
                $omitted++;
                continue;
            }
            sort($ids);
            $sessions[] = array(
                'session_key' => $sessionKey,
                'is_click_session' => $isClick,
                'failing_request_count' => count($ids),
                'failing_request_ids' => array_slice($ids, 0, self::MAX_FAILING_IDS_PER_SESSION),
                'detach' => self::compactVerdict(
                    ABJ_404_Solution_DetachAbEvidence::verdictForSession($rawSessionId)),
                'encoded_size' =>
                    ABJ_404_Solution_CheckpointJournalReader::latestEncodedTableResponseForSession(
                        $rawSessionId),
            );
        }

        $unresolvedIds = array_keys($unresolved);
        sort($unresolvedIds);
        $record['sessions_resolved'] = count($idsBySession);
        $record['sessions_omitted'] = $omitted;
        $record['sessions'] = $sessions;
        $record['unresolved_failing_request_ids'] = array_slice($unresolvedIds, 0, self::MAX_UNRESOLVED_IDS);
        $record['unresolved_failing_request_count'] = count($unresolvedIds);
        $record['click_vs_failing'] = self::relationship(
            $clickSessionKey, $idsBySession !== array(), $sawClick, $sawForeign);
        return $record;
    }

    /**
     * The stated relationship between the clicking session and the failing
     * session(s) -- the fact the whole class exists to make explicit.
     *
     * @param string $clickSessionKey
     * @param bool $anyFailingSession Whether any failing id resolved to a session.
     * @param bool $sawClick Whether any failing session is the clicking session.
     * @param bool $sawForeign Whether any failing session is a different session.
     */
    private static function relationship(
        string $clickSessionKey,
        bool $anyFailingSession,
        bool $sawClick,
        bool $sawForeign
    ): string {
        if (!$anyFailingSession) {
            return self::REL_NO_FAILING_SESSIONS;
        }
        if ($clickSessionKey === '') {
            return self::REL_NO_CLICK_SESSION;
        }
        if ($sawClick && $sawForeign) {
            return self::REL_MIXED;
        }
        return $sawClick ? self::REL_CLICK_SESSION_FAILING : self::REL_FOREIGN_SESSIONS_ONLY;
    }

    /**
     * The decision plus its accounting from a full DetachAbEvidence verdict,
     * without the per-attempt list. The clicking session's attempts already
     * ride the primary detach A/B verdict block; here the quadrant and the
     * counts are what identify whose data the verdict was drawn from.
     *
     * @param array<string, mixed> $verdict ABJ_404_Solution_DetachAbEvidence::verdictForSession().
     * @return array<string, mixed>
     */
    private static function compactVerdict(array $verdict): array {
        return array(
            'status' => self::scalarField($verdict, 'status'),
            'verdict' => isset($verdict['verdict']) && is_array($verdict['verdict'])
                ? $verdict['verdict'] : array(),
            'attempts_with_mode' => self::intField($verdict, 'attempts_with_mode'),
            'attempts_resolved' => self::intField($verdict, 'attempts_resolved'),
            'attempts_unresolved' => self::intField($verdict, 'attempts_unresolved'),
        );
    }

    /**
     * Keep only well-formed request ids that are present, so a hostile or
     * malformed key can never reach a journal scan or a session bucket.
     *
     * @param array<string, bool> $ids
     * @return array<string, bool>
     */
    private static function normalizeIdSet(array $ids): array {
        $result = array();
        foreach ($ids as $id => $present) {
            $id = (string)$id;
            if ($present && preg_match('/^[A-Za-z0-9]{1,64}$/', $id) === 1) {
                $result[$id] = true;
            }
        }
        return $result;
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

    /**
     * One record field as an integer, or 0 when it is absent or not scalar.
     *
     * @param array<array-key, mixed> $record
     */
    private static function intField(array $record, string $field): int {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (int)$value : 0;
    }
}
