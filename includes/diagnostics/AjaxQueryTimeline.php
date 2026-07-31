<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-query attribution INSIDE an instrumented admin-AJAX work stage
 * (Bruno timeout cause matrix, cause class F; gap-hunt iteration 1, gap G5).
 *
 * A stage boundary can only say "the request stopped somewhere inside
 * table_redirects". That one stage covers SQL execution, the row-formatting
 * loop, URL parsing and normalization, locale handling, and every foreign
 * callback other plugins attached to the filters our render path runs. Those
 * are different causes with different fixes, and a stage name cannot separate
 * them. This class splits the DATABASE half of that window off from the PHP
 * half; ABJ_404_Solution_AjaxRowLoopProgress splits the row-formatting half.
 *
 * ABJ_404_Solution_QueryBudgetInstrumentation already watches the same seam,
 * and is deliberately left alone: it answers a different question (did this
 * request blow a reverse-proxy timeout budget?) on a different trigger, with a
 * different gate and a different sink -- a side file that nothing in the
 * support or feedback path ever reads. Three properties are what make it
 * unusable for the one session this investigation gets, and are therefore the
 * three properties this class is built around:
 *
 *  - ARMED BY THE LEDGER, NOT BY AN ENVIRONMENT VARIABLE. Recording is on for
 *    exactly the requests ABJ_404_Solution_AjaxRequestLedger already scopes
 *    its checkpoints to -- the admin table AJAX endpoint -- and off everywhere
 *    else, so the hot front-end 404 path pays nothing and a beta build needs
 *    no server-side configuration to produce evidence.
 *
 *  - RECORDED BEFORE THE QUERY RUNS, NOT AFTER IT. A query that blocks and
 *    never returns writes nothing at all if the record is emitted on
 *    completion: the last line on disk would describe the PREVIOUS query, and
 *    the shape of the one that actually hung -- the single most decisive fact
 *    about a stall inside a stage -- would be exactly what is missing. Each
 *    probe therefore names the query that is about to execute and carries the
 *    PRECEDING query's duration, so the full timeline is still reconstructable
 *    while the in-flight query is always named. The final query's own duration
 *    arrives with the summary emitted at response time.
 *
 *  - ALWAYS, NOT ONLY ON A BUDGET VIOLATION. A stall assembled from two
 *    hundred individually-fast queries never crosses a slow-query threshold,
 *    and that shape is one of the live hypotheses. Recording only violations
 *    is structurally blind to it.
 *
 * Records go through ABJ_404_Solution_AjaxCheckpointLogger's high-frequency
 * envelope, which means they are joined to the ledger request ID, ranked by
 * ABJ_404_Solution_DiagnosticEvidencePriority, and carried by the support
 * payload and the developer log archive with no extra plumbing.
 *
 * SQL is recorded as a SHAPE only, through the existing redaction helper:
 * quoted literals and numbers become `?` before anything is written, so a
 * user's URLs never reach the journal.
 *
 * @phpstan-type AbjOpenQuery array{q: int, started_at: float|null,
 *     breadcrumb: array<string, mixed>|null}
 * @phpstan-type AbjSlowestQuery array{q: int, ms: float}
 * @phpstan-type AbjTimelineState array{request_id: string, count: int, recorded: int,
 *     db_ms: float, last_ms: float|null, open: AbjOpenQuery|null,
 *     slowest: AbjSlowestQuery|null, capped: bool, summarized: bool}
 */
final class ABJ_404_Solution_AjaxQueryTimeline {

    /**
     * Probe records emitted per request before recording stops.
     *
     * Chosen as a cost ceiling, not as an expected count: a table request
     * issues well under this, so the cap only ever binds on a pathological
     * request -- and on such a request the query COUNT is itself the finding,
     * which the summary still reports truthfully. The transition is announced
     * with its own record rather than happening silently, because an evidence
     * channel that quietly stops is the failure mode this whole subsystem
     * exists to prevent.
     */
    const MAX_RECORDED_QUERIES = 60;

    /**
     * Bytes of redacted SQL shape kept per probe.
     *
     * The redaction helper's own 4000-character ceiling is sized for a single
     * fatal-error report; at up to 60 probes per request it would let one
     * request eat the entire support excerpt. The leading clauses are what
     * identify a query, so the head is what is kept, and `sql_len` states the
     * true length so truncation is never mistaken for a short query.
     */
    const MAX_SHAPE_LENGTH = 400;

    /** Marker for "no query has completed yet", kept distinct from a zero duration. */
    const PREV_NONE = 'none';

    /** The preceding query returned normally and its duration is trustworthy. */
    const PREV_COMPLETE = 'complete';

    /**
     * The preceding query never reported a completion. Either it threw past
     * the executor's recording call or a nested recovery query interleaved
     * with it; in both cases `prev_ms` is withheld rather than guessed.
     */
    const PREV_UNFINISHED = 'unfinished';

    /**
     * @var AbjTimelineState|null
     * @phpstan-var AbjTimelineState|null
     */
    private static $state = null;

    /** @var ABJ_404_Solution_AjaxFailureLogger|null Cached; redaction is stateless. */
    private static $redactor = null;

    /**
     * The ledger ID of the request whose queries are being attributed, or ''
     * when this request is out of scope.
     *
     * Delegated to the ledger rather than re-deriving the scope here, so the
     * per-query channel can never drift out of step with the boundary
     * checkpoints it has to be read alongside.
     */
    public static function armedRequestId(): string {
        return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
    }

    /** Whether this request records per-query attribution at all. */
    public static function isArmed(): bool {
        return self::armedRequestId() !== '';
    }

    /**
     * Record that a query is ABOUT to execute. Never throws.
     *
     * @param string $preparedQuery The final SQL, after table-name replacement,
     *   parameter binding, and timeout wrapping -- i.e. exactly the bytes the
     *   server will see. Redacted to a shape here; never stored raw.
     * @param string $sourceLabel   Stable call-site identifier resolved by
     *   ABJ_404_Solution_DatabaseQueryDiagnostics (SQL filename, `abj404:src`
     *   marker, or Class::method), which is what makes a shape actionable.
     * @param int $timeoutSeconds   The per-query timeout hint actually applied.
     * @param string $preflightId   The completed preflight that led here.
     * @return array{q:int,sql_id:string}|null
     */
    public static function beginQuery(
        string $preparedQuery,
        string $sourceLabel,
        int $timeoutSeconds,
        string $preflightId = ''
    ): ?array {
        $identity = null;
        try {
            $requestId = self::armedRequestId();
            if ($requestId === '') {
                return null;
            }
            $state = self::stateFor($requestId);
            if ($state['summarized']) {
                return null;
            }
            $state['count']++;

            $previous = self::previousQueryFields($state);
            $breadcrumb = null;
            $shape = self::shapeFields($preparedQuery);
            $identity = array(
                'q' => $state['count'],
                'sql_id' => $shape['sql_id'],
            );

            if ($state['count'] > self::MAX_RECORDED_QUERIES) {
                $breadcrumb = array_merge(array(
                    'q' => $state['count'],
                    'stage' => self::currentStage(),
                    'src' => substr($sourceLabel === '' ? 'unknown-source' : $sourceLabel, 0, 200),
                    'timeout_s' => max(0, $timeoutSeconds),
                    'preflight_id' => substr($preflightId, 0, 12),
                ), array(
                    'sql_id' => $shape['sql_id'],
                    'sql_len' => $shape['sql_len'],
                ));
                $state['open'] = array(
                    'q' => $state['count'],
                    'started_at' => self::nowFloat(),
                    'breadcrumb' => $breadcrumb,
                );
                self::$state = $state;
                ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                    $requestId, 'query', 'active', $breadcrumb);
                if (!$state['capped']) {
                    $state['capped'] = true;
                    self::$state = $state;
                    ABJ_404_Solution_AjaxCheckpointBoundaryWriter::record(
                        $requestId,
                        'query_probe_capped',
                        array(
                            'q' => $state['count'],
                            'limit' => self::MAX_RECORDED_QUERIES,
                        )
                    );
                    return $identity;
                }
                self::$state = $state;
                return $identity;
            }

            $state['open'] = array(
                'q' => $state['count'],
                'started_at' => self::nowFloat(),
                'breadcrumb' => null,
            );
            $state['recorded']++;
            self::$state = $state;
            ABJ_404_Solution_AjaxCheckpointBoundaryWriter::record(
                $requestId,
                'query_probe',
                array_merge(array(
                    'q' => $state['count'],
                    'stage' => self::currentStage(),
                    'src' => substr($sourceLabel === '' ? 'unknown-source' : $sourceLabel, 0, 200),
                    'timeout_s' => max(0, $timeoutSeconds),
                    'preflight_id' => substr($preflightId, 0, 12),
                    'db_ms' => round($state['db_ms'], 3),
                ), $shape, $previous)
            );
            return $identity;
        } catch (Throwable $e) {
            self::reportFailure('query probe failed: ' . $e->getMessage());
            return $identity;
        }
    }

    /**
     * Record that the in-flight query returned, with the duration the executor
     * measured. In-memory only: the cost of this fact is already paid by the
     * NEXT probe, which carries it, and by the summary, which carries the last
     * one. Never throws.
     */
    public static function endQuery(float $elapsedMs): void {
        try {
            // No open entry means this query was never announced, so it is not
            // one of ours: an unarmed request running in a worker that served
            // an instrumented one earlier must not have its time folded into
            // that request's totals.
            if (self::$state === null || self::$state['open'] === null) {
                return;
            }
            $state = self::$state;
            $elapsedMs = max(0.0, $elapsedMs);
            $state['db_ms'] += $elapsedMs;
            $state['last_ms'] = $elapsedMs;
            $open = $state['open'];
            if ($open !== null && ($state['slowest'] === null || $elapsedMs > $state['slowest']['ms'])) {
                // Recorded by sequence number only: the shape is already on the
                // probe record that carries the same `q`, so repeating it here
                // would pay for the same bytes twice.
                $state['slowest'] = array('q' => $open['q'], 'ms' => round($elapsedMs, 3));
            }
            if ($open !== null && is_array($open['breadcrumb'] ?? null)) {
                ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                    $state['request_id'], 'query', 'complete', $open['breadcrumb']);
            }
            $state['open'] = null;
            self::$state = $state;
        } catch (Throwable $e) {
            self::reportFailure('query completion failed: ' . $e->getMessage());
        }
    }

    /**
     * Emit the closing summary for this request. Never throws.
     *
     * Called from the single response choke point rather than from the stage
     * runner, so the early-response branches -- the rate-limit 429 and the
     * auth-failure 403, which are also the branches a struggling request is
     * most likely to take -- are covered by construction instead of by
     * discipline. Emitted even when the request ran ZERO queries: "no query
     * ran here" is a finding, and a channel that stays silent when it has
     * nothing to say is indistinguishable from a channel that is broken.
     *
     * @param string $requestId The ledger ID the caller already resolved.
     */
    public static function flushSummary(string $requestId): void {
        try {
            if ($requestId === '') {
                return;
            }
            $state = self::stateFor($requestId);
            if ($state['summarized']) {
                return;
            }
            $state['summarized'] = true;
            self::$state = $state;

            $summary = array(
                'queries' => $state['count'],
                'recorded' => $state['recorded'],
                'dropped' => max(0, $state['count'] - $state['recorded']),
                'db_ms' => round($state['db_ms'], 3),
                'last_ms' => $state['last_ms'] === null ? null : round($state['last_ms'], 3),
                'slowest' => $state['slowest'],
            );
            // A query still open at response time means the executor never
            // reported its completion. Stated rather than smoothed over: it
            // changes how every duration above it should be read.
            $summary['open_query'] = $state['open'] === null ? null : $state['open']['q'];
            $summary['open_ms'] = self::openMs($state['open']);
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($requestId, 'query_timeline_summary', $summary);
        } catch (Throwable $e) {
            self::reportFailure('query timeline summary failed: ' . $e->getMessage());
        }
    }

    /**
     * The per-request buffer, created on demand and restarted whenever a
     * different request ID appears.
     *
     * The reset matters outside tests: a long-lived SAPI worker, WP-CLI, or a
     * request that internally dispatches a second instrumented action would
     * otherwise inherit the previous request's counters and report a query
     * total that never happened.
     *
     * @return AbjTimelineState
     * @phpstan-return AbjTimelineState
     */
    private static function stateFor(string $requestId): array {
        if (is_array(self::$state) && self::$state['request_id'] === $requestId) {
            return self::$state;
        }
        self::$state = array(
            'request_id' => $requestId,
            'count' => 0,
            'recorded' => 0,
            'db_ms' => 0.0,
            'last_ms' => null,
            'open' => null,
            'slowest' => null,
            'capped' => false,
            'summarized' => false,
        );
        return self::$state;
    }

    /**
     * How the preceding query ended, as fields on the probe that follows it.
     *
     * @param AbjTimelineState $state
     * @phpstan-param AbjTimelineState $state
     * @return array{prev_ms: float|null, prev_status: string}
     */
    private static function previousQueryFields(array $state): array {
        if ($state['open'] !== null) {
            return array('prev_ms' => null, 'prev_status' => self::PREV_UNFINISHED);
        }
        if ($state['last_ms'] === null) {
            return array('prev_ms' => null, 'prev_status' => self::PREV_NONE);
        }
        return array('prev_ms' => round($state['last_ms'], 3), 'prev_status' => self::PREV_COMPLETE);
    }

    /**
     * The PII-free identity of one query: its redacted shape, a stable hash of
     * the WHOLE shape, and the shape's true length.
     *
     * The hash is taken before truncation so two queries that differ only past
     * the cut are still distinguishable, and so a shape can be grouped and
     * counted across a session without shipping it more than once.
     *
     * @return array{sql: string, sql_id: string, sql_len: int}
     */
    private static function shapeFields(string $preparedQuery): array {
        $shape = self::redactor()->redactSqlShape($preparedQuery);
        return array(
            'sql' => strlen($shape) > self::MAX_SHAPE_LENGTH ? substr($shape, 0, self::MAX_SHAPE_LENGTH) : $shape,
            'sql_id' => substr(hash('sha256', $shape), 0, 12),
            'sql_len' => strlen($shape),
        );
    }

    /**
     * The stage marker the endpoint last set, so a probe is readable without
     * scanning back to the enclosing stage_start record.
     */
    private static function currentStage(): string {
        $context = $GLOBALS['abj404_ajax_context'] ?? null;
        if (!is_array($context) || !isset($context['stage']) || !is_scalar($context['stage'])) {
            return '';
        }
        return substr((string)$context['stage'], 0, 64);
    }

    /**
     * The shared redaction helper.
     *
     * Constructed directly rather than resolved from the service container:
     * this runs once per query on an admin read path, redaction needs no
     * logger, and a container lookup per query would add a cost to the very
     * path being measured.
     */
    private static function redactor(): ABJ_404_Solution_AjaxFailureLogger {
        if (!(self::$redactor instanceof ABJ_404_Solution_AjaxFailureLogger)) {
            self::$redactor = new ABJ_404_Solution_AjaxFailureLogger();
        }
        return self::$redactor;
    }

    /**
     * Milliseconds an unfinished query has been open, or null when there is
     * no open query or no clock to measure it with.
     *
     * @param array{q: int, started_at: float|null, breadcrumb: array<string, mixed>|null}|null $open
     */
    private static function openMs($open): ?float {
        if ($open === null || $open['started_at'] === null) {
            return null;
        }
        $now = self::nowFloat();
        return $now === null ? null : round(($now - $open['started_at']) * 1000.0, 3);
    }

    /**
     * Seconds as a float from the clock seam, or null when this process has
     * no clock.
     *
     * flushSummary() is called from ABJ_404_Solution_AjaxResponseEmitter, and
     * that path is exercised by the response-tail subprocess probe, which
     * hand-requires a deliberately minimal file set with no service locator
     * and no autoloader. The same shape covers a corrupt plugin directory,
     * where the safe autoloader returns silently for a missing class. Reading
     * the clock must not be able to kill the response it is instrumenting.
     * See ABJ_404_Solution_AjaxCheckpointLogger::nowFloat().
     */
    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-query-timeline', $message);
    }

    /**
     * Reset all internal state. Test-only; production code never calls this.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$state = null;
        self::$redactor = null;
        if (class_exists('ABJ_404_Solution_DatabaseQueryFilterTracer', false)) {
            ABJ_404_Solution_DatabaseQueryFilterTracer::resetForTests();
        }
        if (class_exists('ABJ_404_Solution_DatabaseQueryPreflightTracer', false)) {
            ABJ_404_Solution_DatabaseQueryPreflightTracer::resetForTests();
        }
        if (class_exists('ABJ_404_Solution_AjaxFrequentCheckpointWriter', false)) {
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::resetForTests();
        }
    }
}
