<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsCanonicalUrlBackfillCompleteTest

require_once __DIR__ . '/LogsHitsCanonicalUrlJoinHelper.php';
require_once __DIR__ . '/../database/DatabaseCollationHelper.php';

/**
 * Two-phase SQL execution engine for the wp_abj404_logs_hits rollup.
 *
 * Extracted from LogsHitsRollupService (i468) so the service stays focused on
 * rebuild coordination (gate / lock / scheduling), staleness signaling, and
 * freshness/id reads, while this class owns the one cohesive responsibility of
 * materializing the rollup and swapping it in.
 *
 * Given a snapshot id range it:
 *   1. (re)creates and truncates the {wp_abj404_logs_hits}_temp staging table,
 *   2. picks the direct INSERT...SELECT path for small ranges or the chunked
 *      pre-aggregation path (Phase 1 per-id-range -> Phase 2 JOIN) for large
 *      ones,
 *   3. stamps the temp table COMMENT with elapsed|maxLogId and renames it over
 *      the live table in a single transaction,
 *   4. records per-phase success/failure into RebuildHealthState and cleans up
 *      the pre-aggregation temp table.
 *
 * The min/max log-id snapshot is supplied by the caller (not read here) so the
 * tracking test subclass can control the id range without a live logsv2 table,
 * and so the watermark stored in the table COMMENT is the pre-insert value.
 *
 * Pure data-access / execution layer: no admin-notice policy, no scheduling
 * decision, no runtime-flag bookkeeping. Those stay on the service.
 */
class ABJ_404_Solution_LogsHitsTableRebuilder {

    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    /** @var int Direct-path threshold for hits-table rebuild. */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = 5000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    /** @var ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper */
    private $joinHelper;

    /** @var callable():bool|null */
    private $leaseRenewer;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper $joinHelper
     * @param callable():bool|null $leaseRenewer
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger,
        $rebuildHealth,
        ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper $joinHelper,
        $leaseRenewer = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : null;
        $this->joinHelper = $joinHelper;
        if ($leaseRenewer !== null && !is_callable($leaseRenewer)) {
            throw new InvalidArgumentException(
                'LogsHitsTableRebuilder lease renewer must be callable or null.'
            );
        }
        $this->leaseRenewer = $leaseRenewer;
    }

    /**
     * Materialize the rollup over [$minLogId, $maxLogId] into a fresh temp
     * table and rename it over the live wp_abj404_logs_hits table. Records
     * per-phase outcomes into RebuildHealthState and cleans up the
     * pre-aggregation temp table.
     *
     * Expected SQL failures (timeout / last_error) are caught and reported via
     * the return value; unexpected Throwables are recorded and also reported
     * via the return value (never re-thrown) so the caller's lock release runs
     * unconditionally.
     *
     * @param int $minLogId Pre-insert MIN(logsv2.id) snapshot.
     * @param int $maxLogId Pre-insert MAX(logsv2.id) snapshot (stored as the watermark).
     * @return array{refreshed: bool, elapsed_time: float, error: string}
     */
    public function rebuildAndSwap(int $minLogId, int $maxLogId): array {
        $preAggTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_preagg");
        try {
            $finalDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}");
            $tempDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
            $this->renewLeaseOrThrow();
            $this->dbCore->queryAndGetResults("drop table if exists " . $tempDestTable);
            $this->renewLeaseOrThrow();
            $resolvedCollation = $this->joinHelper->resolveHitsJoinCollation();
            $createTempTableQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/createLogsHitsTempTable.sql");
            $createTempTableQuery = $this->dbCore->doTableNameReplacements($createTempTableQuery);
            $createTempTableQuery = $this->applyJoinCharsetCollation(array(
                'ddl' => $createTempTableQuery,
                'rawCollation' => $resolvedCollation,
            ));
            $this->dbCore->queryAndGetResults($createTempTableQuery);
            $this->renewLeaseOrThrow();
            // @cache-write-audit: opt-out - truncates an unpublished temp table before rebuilding it.
            $this->dbCore->queryAndGetResults("truncate table " . $tempDestTable);
            $idRange = $maxLogId - $minLogId;
            $chunkSize = $this->getHitsRebuildChunkSize($idRange);
            $this->renewLeaseOrThrow();
            if ($idRange <= self::HITS_TABLE_DIRECT_PATH_THRESHOLD) { $results = $this->hitsTableInsertDirect($tempDestTable); } else { $results = $this->hitsTableInsertChunked($tempDestTable, $preAggTable, $minLogId, $maxLogId, $chunkSize); }
            $this->renewLeaseOrThrow();
            if ($results === false || !empty($results['timed_out']) || !empty($results['last_error'])) {
                $rawLastError = is_array($results) && isset($results['last_error']) && is_string($results['last_error']) ? $results['last_error'] : '';
                $errorMessage = $results === false ? 'Hits rebuild phase 1 chunk failed.' : ($rawLastError !== '' ? $rawLastError : 'Hits rebuild timed out.');
                $this->recordHitsRebuildFailure($errorMessage);
                if ($idRange > self::HITS_TABLE_DIRECT_PATH_THRESHOLD && $results !== false && (!empty($results['timed_out']) || !empty($results['last_error']))) {
                    $this->recordHitsChunkFailure();
                }
                $this->dropScratchTableIfLeaseOwned($tempDestTable);
                $this->logger->debugMessage(__FUNCTION__ . " INSERT timed out or errored; aborting rebuild.");
                return array('refreshed' => false, 'elapsed_time' => 0.0, 'error' => $errorMessage);
            }
            $rawElapsed = $results['elapsed_time'] ?? 0;
            $elapsedTime = is_numeric($rawElapsed) ? (float)$rawElapsed : 0.0;
            $comment = $elapsedTime . '|' . $maxLogId;
            $this->renewLeaseOrThrow();
            // @utf8-audit: opt-out - rebuild table comment is synthesized from numeric timing and ID values.
            $comment = substr(esc_sql($comment), 0, 2048);
            $this->dbCore->queryAndGetResults(sprintf("ALTER TABLE %s COMMENT '%s'", $tempDestTable, $comment));
            $this->renewLeaseOrThrow();
            $statements = array("drop table if exists " . $finalDestTable, "rename table " . $tempDestTable . ' to ' . $finalDestTable);
            $this->dbCore->executeAsTransaction($statements);
            $this->recordHitsRebuildSuccess($chunkSize);
            $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . " seconds.");
            return array('refreshed' => true, 'elapsed_time' => $elapsedTime, 'error' => '');
        } catch (Throwable $e) {
            $this->recordHitsRebuildFailure($e->getMessage());
            $this->logger->errorMessage(__FUNCTION__ . " failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            return array('refreshed' => false, 'elapsed_time' => 0.0, 'error' => $e->getMessage());
        } finally {
            $this->dropScratchTableIfLeaseOwned($preAggTable);
        }
    }

    /**
     * Fill the {CHARSET} / {COLLATION} pair in a staging-table DDL from the one
     * collation the staging table must honour.
     *
     * The rollup's phase-2 JOIN probes redirects.canonical_url, so requested_url
     * has to carry that column's collation or the index cannot serve the probe.
     * The charset therefore cannot be chosen independently: the DDL used to
     * hard-code CHARACTER SET utf8mb4 next to a column collation read from
     * information_schema, and on an install whose redirects table is still
     * latin1 the engine rejects the CREATE outright ("COLLATION
     * 'latin1_swedish_ci' is not valid for CHARACTER SET 'utf8mb4'"), taking the
     * whole rollup rebuild with it. Both halves now come from one pair.
     *
     * @param array{ddl: string, rawCollation: string} $options
     * @return string DDL with a self-consistent charset/collation pair.
     */
    private function applyJoinCharsetCollation(array $options): string {
        $pair = ABJ_404_Solution_DatabaseCollationHelper::charsetCollationPair(
            $options['rawCollation']
        );
        return str_replace(
            array('{CHARSET}', '{COLLATION}'),
            array($pair['charset'], $pair['collation']),
            $options['ddl']
        );
    }

    /**
     * @param string $tempDestTable
     * @return array<string, mixed>
     */
    private function hitsTableInsertDirect(string $tempDestTable): array {
        $ttSelectQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsForViewTempTable.sql");
        if ($this->joinHelper->isLogsv2CanonicalUrlBackfillComplete()) { $ttSelectQuery = $this->joinHelper->dropLogsv2CanonicalCoalesceWrap($ttSelectQuery); }
        $ttSelectQuery = str_replace('{redirects_canonical_url_join_rhs}', $this->joinHelper->buildDirectJoinRhs(), $ttSelectQuery);
        $ttSelectQuery = $this->dbCore->doTableNameReplacements($ttSelectQuery);
        $ttInsertQuery = "/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertDirect */ insert into " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) \n " . $ttSelectQuery;
        return $this->dbCore->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false, 'timeout' => 60));
    }

    /** @return array<string, mixed>|false */
    private function hitsTableInsertChunked(string $tempDestTable, string $preAggTable, int $minId, int $maxId, int $chunkSize) {
        $logsv2Table = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $resolvedCollation = $this->joinHelper->resolveHitsJoinCollation();
        $startTime = abj_clock()->nowFloat();
        $this->renewLeaseOrThrow();
        $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
        $this->renewLeaseOrThrow();
        $createPreAggQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/createLogsHitsPreAggTempTable.sql");
        $createPreAggQuery = $this->dbCore->doTableNameReplacements($createPreAggQuery);
        $createPreAggQuery = $this->applyJoinCharsetCollation(array(
            'ddl' => $createPreAggQuery,
            'rawCollation' => $resolvedCollation,
        ));
        $this->dbCore->queryAndGetResults($createPreAggQuery);
        $this->renewLeaseOrThrow();
        $logsv2CanonicalExpr = $this->joinHelper->isLogsv2CanonicalUrlBackfillComplete() ? "canonical_url" : "COALESCE(canonical_url, CONCAT('/', TRIM(BOTH '/' FROM requested_url)))";
        for ($start = $minId; $start <= $maxId; $start += $chunkSize) {
            $this->renewLeaseOrThrow();
            $end = $start + $chunkSize;
            $chunkQuery = "/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertChunked#phase1Chunk */ INSERT INTO " . $preAggTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT " . $logsv2CanonicalExpr . ", MIN(id), MAX(timestamp), COUNT(*), SUM(CASE WHEN dest_url = '' OR dest_url IS NULL THEN 1 ELSE 0 END) FROM " . $logsv2Table . " WHERE id >= %d AND id < %d GROUP BY " . $logsv2CanonicalExpr;
            $chunkResult = $this->dbCore->queryAndGetResults($chunkQuery, array('log_too_slow' => false, 'timeout' => 10, 'query_params' => array($start, $end)));
            $this->renewLeaseOrThrow();
            if (!empty($chunkResult['timed_out']) || !empty($chunkResult['last_error'])) { $this->recordHitsChunkFailure(); $this->logger->debugMessage(__FUNCTION__ . " Phase 1 chunk failed at id range [{$start}, {$end}); aborting."); return false; }
        }
        // Defensive form covers legacy and in-progress installs; optimized
        // form lets idx_canonical_url serve the JOIN probe and gets the
        // rebuild under the host's 60s max_statement_time on Bruno-class
        // data (i359). See LogsHitsCanonicalUrlJoinHelper::buildPhase2JoinRhs.
        $joinRhs = $this->joinHelper->buildPhase2JoinRhs($resolvedCollation);
        $this->renewLeaseOrThrow();
        $phase2Query = "/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertChunked#phase2Aggregate */ INSERT INTO " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT a.requested_url, MIN(a.logsid), MAX(a.last_used), SUM(a.logshits), SUM(a.failed_hits) FROM " . $preAggTable . " a INNER JOIN " . $redirectsTable . " r ON a.requested_url = " . $joinRhs . " GROUP BY a.requested_url";
        $results = $this->dbCore->queryAndGetResults($phase2Query, array('log_too_slow' => false, 'timeout' => 60));
        $this->renewLeaseOrThrow();
        $results['elapsed_time'] = round(abj_clock()->nowFloat() - $startTime, 3);
        return $results;
    }

    /** Abort before another request can run beside a worker that lost its lease. */
    private function renewLeaseOrThrow(): void {
        if (!$this->renewLease()) {
            throw new RuntimeException(
                'The logs-hits rebuild lost its exclusive lease; aborting before staging-table work can overlap.'
            );
        }
    }

    /** Return whether this worker still owns (and has renewed) its lease. */
    private function renewLease(): bool {
        return $this->leaseRenewer === null || (bool)call_user_func($this->leaseRenewer);
    }

    /** Never let a superseded worker drop a replacement worker's scratch table. */
    private function dropScratchTableIfLeaseOwned(string $scratchTable): void {
        if (!$this->renewLease()) {
            $this->logger->debugMessage(
                __FUNCTION__ . " skipped cleanup after lease ownership was lost: " . $scratchTable
            );
            return;
        }
        $this->dbCore->queryAndGetResults("drop table if exists " . $scratchTable);
    }

    /** @param int $idRange @return int */
    private function getHitsRebuildChunkSize(int $idRange): int {
        if ($this->rebuildHealth === null) {
            return self::HITS_TABLE_PREAGG_CHUNK_SIZE;
        }
        return $this->rebuildHealth->getHitsChunkSize($idRange);
    }

    /** @return void */
    private function recordHitsChunkFailure(): void {
        if ($this->rebuildHealth !== null) {
            $this->rebuildHealth->recordHitsChunkFailure();
        }
    }

    /** @param int $chunkSize @return void */
    private function recordHitsRebuildSuccess(int $chunkSize): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFullRebuildSuccess($chunkSize);
        $this->rebuildHealth->recordSuccess();
    }

    /** @param string $message @return void */
    private function recordHitsRebuildFailure(string $message): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFailure($message, $this->rebuildHealth->classifyError($message));
    }
}
