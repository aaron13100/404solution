<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsHitsRollupServiceInterface.php';
require_once __DIR__ . '/LogsHitsCanonicalUrlJoinHelper.php';

/**
 * wp_abj404_logs_hits rollup lifecycle (existence checks, scheduling,
 * locking, rebuild pipelines, staleness signaling, max/min id reads,
 * runtime-flag state).
 *
 * Extracted from LogsRepository under M201. LogsRepository now forwards
 * the rollup-related interface methods to this service. Internal log read
 * and write paths reach the rollup via LogsRepository's facade as well.
 */
class ABJ_404_Solution_LogsHitsRollupService implements ABJ_404_Solution_LogsHitsRollupServiceInterface {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300;
    /** @var int Minimum interval between hits-table rebuild schedules (server-side dedupe). */
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = 30;
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = 180;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    /** @var int Direct-path threshold for hits-table rebuild. */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = 5000;

    /** @var string Runtime flag: last time we checked whether logs-hits needs rebuild. */
    const HITS_TABLE_LAST_CHECKED_FLAG = 'abj404_logs_hits_last_checked_at';
    /** @var string Runtime flag: last time we scheduled a rebuild. */
    const HITS_TABLE_LAST_SCHEDULED_FLAG = 'abj404_logs_hits_last_scheduled_at';
    /** @var string Runtime flag: last schedule decision. */
    const HITS_TABLE_LAST_DECISION_FLAG = 'abj404_logs_hits_last_decision';
    /** @var string Runtime flag: last successful hits-table rebuild completion. */
    const HITS_TABLE_LAST_REFRESHED_FLAG = 'abj404_logs_hits_last_refreshed_at';
    /** @var string Runtime flag: first stale detection timestamp. */
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = 'abj404_logs_hits_first_stale_detected_at';
    /** @var string Deduplicated admin-notice transient for stale logs_hits rollup. */
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = 'abj404_logs_hits_rollup_stale';
    /** @var int Minimum age (seconds) of stale gap before surfacing admin notice. */
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = 3600;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /**
     * Reset per-request scheduling state. Test seam: lets tests run multiple
     * scenarios in a single process without the static cooldown flag leaking
     * between them.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$hitsTableRebuildScheduled = false;
    }

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper */
    private $joinHelper;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder|null $noticeState
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logging = null,
        $rebuildHealth = null,
        $noticeState = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->noticeState = $noticeState !== null ? $noticeState : $dbCore->noticeState();
        $this->joinHelper = new ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper($dbCore);
    }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    private function resolveRebuildHealthState(): ?ABJ_404_Solution_RebuildHealthState {
        if (function_exists('abj_service')
            && class_exists('ABJ_404_Solution_ServiceContainer')
            && ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
            try {
                $service = abj_service('rebuild_health');
                if ($service instanceof ABJ_404_Solution_RebuildHealthState) {
                    return $service;
                }
            } catch (Throwable $t) {
                $this->logger->debugMessage(__FUNCTION__ . ' rebuild_health service unavailable: ' . $t->getMessage());
            }
        }
        return null;
    }

    /**
     * Resolve the collation from the abj404_redirects.canonical_url column,
     * the actual join partner for the hits rebuild phase2 JOIN. Delegates
     * to the canonical-url join helper.
     *
     * @return string Sanitized collation identifier.
     */
    public function resolveHitsJoinCollation(): string {
        return $this->joinHelper->resolveHitsJoinCollation();
    }

    // =========================================================================
    // Staleness signaling
    // =========================================================================

    /** @inheritDoc */
    public function recordLogsHitsRollupStalenessSignal(): void {
        $currentMaxLogId = $this->getMaxLogId();
        $storedMaxLogId = $this->getStoredMaxLogId();
        if ($currentMaxLogId <= $storedMaxLogId) { $this->clearLogsHitsRollupStaleSignal(); return; }
        $rawFirstStale = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG);
        $firstStale = is_scalar($rawFirstStale) ? (int)$rawFirstStale : 0;
        if ($firstStale <= 0) { $this->noticeState->setRuntimeFlag(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG, time(), 86400); return; }
        $age = time() - $firstStale;
        if ($age >= self::HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS) { $this->setLogsHitsRollupStaleNotice($age); }
    }

    private function clearLogsHitsRollupStaleSignal(): void {
        if (function_exists('delete_transient')) { delete_transient(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG); delete_transient(self::HITS_TABLE_STALE_NOTICE_TRANSIENT); return; }
        if (function_exists('delete_option')) { delete_option(self::HITS_TABLE_FIRST_STALE_DETECTED_FLAG); delete_option(self::HITS_TABLE_STALE_NOTICE_TRANSIENT); }
    }

    /** @param int $ageSeconds @return void */
    private function setLogsHitsRollupStaleNotice(int $ageSeconds): void {
        if (!function_exists('set_transient')) { return; }
        $key = self::HITS_TABLE_STALE_NOTICE_TRANSIENT;
        if (function_exists('get_transient') && get_transient($key) !== false) { return; }
        $hours = max(1, intval(floor($ageSeconds / 3600)));
        $template = $this->noticeState->localizeOrDefault('The 404 Solution redirects-hits rollup has been behind MAX(logsv2.id) for at least %d hour(s). The cron-driven rebuild event (abj404_updateLogsHitsTableAction) does not appear to be firing, so the redirects list will show stale "hits" and "last hit" columns until cron resumes. To resolve: if DISABLE_WP_CRON is set in wp-config.php either remove it, or configure a system cron job that requests wp-cron.php periodically. To force a rebuild right now in your browser, open the 404 Solution Redirects page with ?abj404_force_view_rebuild=1 appended to the URL.');
        $payload = array('type' => 'logs_hits_rollup_stale', 'message' => sprintf($template, $hours), 'timestamp' => time(), 'error_string' => '', 'age_hours' => $hours);
        // allow-cache-empty: intentional notice payload; error_string is empty by definition for stale-rollup state.
        set_transient($key, $payload, 86400);
    }

    // =========================================================================
    // Rebuild readiness queries
    // =========================================================================

    /** @inheritDoc */
    public function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();
        if ($currentMaxId != $storedMaxId) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)"); return true; }
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) { $age = time() - $lastUpdated; if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)"); return true; } }
        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;
        $query = "SELECT create_time FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] == null || empty($results['rows'])) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $dateValue = is_array($statusRow) ? ($statusRow['update_time'] ?? ($statusRow['create_time'] ?? '')) : '';
                if ($dateValue !== '') { $fallbackTimestamp = strtotime(is_string($dateValue) ? $dateValue : ''); if ($fallbackTimestamp !== false) { if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $fallbackTimestamp) { return $runtimeRefreshedAt; } return $fallbackTimestamp; } }
            }
            return $runtimeRefreshedAt;
        }
        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? $hitsRows[0] : array();
        $row = array_change_key_case($row);
        $createTime = $row['create_time'] ?? null;
        if ($createTime === null) { return $runtimeRefreshedAt; }
        $schemaTimestamp = strtotime(is_string($createTime) ? $createTime : '');
        if ($schemaTimestamp === false) { return $runtimeRefreshedAt; }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) { return $runtimeRefreshedAt; }
        return $schemaTimestamp;
    }

    /** @return array<string, mixed> */
    private function getLogsHitsTableStatusRow() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) { return array(); }
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $query = $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName);
        if ($query === null) { return array(); }
        $results = $this->dbCore->queryAndGetResults($query, array('log_errors' => false));
        if (!is_array($results['rows']) || empty($results['rows']) || !is_array($results['rows'][0])) { return array(); }
        return array_change_key_case($results['rows'][0], CASE_LOWER);
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastUpdatedHuman() {
        $timestamp = $this->getLogsHitsTableLastUpdated();
        if ($timestamp === null) { return ''; }
        $diff = time() - $timestamp;
        if ($diff < 60) { return __('Just now', '404-solution'); }
        elseif ($diff < 3600) { $minutes = (int)floor($diff / 60); return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes); }
        elseif ($diff < 86400) { $hours = (int)floor($diff / 3600); return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours); }
        else { $days = (int)floor($diff / 86400); return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days); }
    }

    // =========================================================================
    // Rebuild pipeline (direct + chunked)
    // =========================================================================

    /** @inheritDoc */
    public function createRedirectsForViewHitsTable(): bool {
        $wasRefreshed = false;
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed.");
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return false;
        }
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return false; }
        if (!$this->acquireHitsTableRebuildLock()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400); return false; }
        $preAggTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_preagg");
        try {
            $finalDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}");
            $tempDestTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
            $this->dbCore->queryAndGetResults("drop table if exists " . $tempDestTable);
            $resolvedCollation = $this->resolveHitsJoinCollation();
            $createTempTableQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/createLogsHitsTempTable.sql");
            $createTempTableQuery = $this->dbCore->doTableNameReplacements($createTempTableQuery);
            $createTempTableQuery = str_replace('{COLLATION}', $resolvedCollation, $createTempTableQuery);
            $this->dbCore->queryAndGetResults($createTempTableQuery);
            // @cache-write-audit: opt-out - truncates an unpublished temp table before rebuilding it.
            $this->dbCore->queryAndGetResults("truncate table " . $tempDestTable);
            $maxLogIdSnapshot = $this->getMaxLogId();
            $minLogId = $this->getMinLogId();
            $idRange = $maxLogIdSnapshot - $minLogId;
            $chunkSize = $this->getHitsRebuildChunkSize($idRange);
            if ($idRange <= self::HITS_TABLE_DIRECT_PATH_THRESHOLD) { $results = $this->hitsTableInsertDirect($tempDestTable); } else { $results = $this->hitsTableInsertChunked($tempDestTable, $preAggTable, $minLogId, $maxLogIdSnapshot, $chunkSize); }
            if ($results === false || !empty($results['timed_out']) || !empty($results['last_error'])) {
                $errorMessage = $results === false ? 'Hits rebuild phase 1 chunk failed.' : (string)($results['last_error'] ?? 'Hits rebuild timed out.');
                $this->recordHitsRebuildFailure($errorMessage);
                if ($idRange > self::HITS_TABLE_DIRECT_PATH_THRESHOLD && $results !== false && (!empty($results['timed_out']) || !empty($results['last_error']))) {
                    $this->recordHitsChunkFailure();
                }
                $this->dbCore->queryAndGetResults("drop table if exists " . $tempDestTable); $this->logger->debugMessage(__FUNCTION__ . " INSERT timed out or errored; aborting rebuild."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return false;
            }
            $elapsedTime = $results['elapsed_time'];
            $comment = $elapsedTime . '|' . $maxLogIdSnapshot;
            // @utf8-audit: opt-out - rebuild table comment is synthesized from numeric timing and ID values.
            $comment = substr(esc_sql($comment), 0, 2048);
            $this->dbCore->queryAndGetResults(sprintf("ALTER TABLE %s COMMENT '%s'", $tempDestTable, $comment));
            $statements = array("drop table if exists " . $finalDestTable, "rename table " . $tempDestTable . ' to ' . $finalDestTable);
            $this->dbCore->executeAsTransaction($statements);
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, time(), 86400);
            $this->recordHitsRebuildSuccess($chunkSize);
            $this->clearLogsHitsRollupStaleSignal();
            $wasRefreshed = true;
            $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . " seconds.");
        } catch (Throwable $e) {
            $this->recordHitsRebuildFailure($e->getMessage());
            $this->logger->errorMessage(__FUNCTION__ . " failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
        } finally {
            $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
            $this->releaseHitsTableRebuildLock();
        }
        return $wasRefreshed;
    }

    /** @param string $tempDestTable @return array<string, mixed> */
    private function hitsTableInsertDirect(string $tempDestTable): array {
        $ttSelectQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsForViewTempTable.sql");
        if ($this->joinHelper->isLogsv2CanonicalUrlBackfillComplete()) { $ttSelectQuery = $this->joinHelper->dropLogsv2CanonicalCoalesceWrap($ttSelectQuery); }
        if ($this->joinHelper->isRedirectsCanonicalUrlBackfillComplete()) { $ttSelectQuery = $this->joinHelper->dropRedirectsCanonicalCoalesceWrap($ttSelectQuery); }
        $ttSelectQuery = $this->dbCore->doTableNameReplacements($ttSelectQuery);
        $ttInsertQuery = "/* abj404:src=LogsHitsRollupService::hitsTableInsertDirect */ insert into " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) \n " . $ttSelectQuery;
        return $this->dbCore->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false, 'timeout' => 60));
    }

    /** @return array<string, mixed>|false */
    private function hitsTableInsertChunked(string $tempDestTable, string $preAggTable, int $minId, int $maxId, int $chunkSize) {
        $logsv2Table = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $resolvedCollation = $this->resolveHitsJoinCollation();
        $startTime = microtime(true);
        $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
        $createPreAggQuery = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/createLogsHitsPreAggTempTable.sql");
        $createPreAggQuery = $this->dbCore->doTableNameReplacements($createPreAggQuery);
        $createPreAggQuery = str_replace('{COLLATION}', $resolvedCollation, $createPreAggQuery);
        $this->dbCore->queryAndGetResults($createPreAggQuery);
        $logsv2CanonicalExpr = $this->joinHelper->isLogsv2CanonicalUrlBackfillComplete() ? "canonical_url" : "COALESCE(canonical_url, CONCAT('/', TRIM(BOTH '/' FROM requested_url)))";
        for ($start = $minId; $start <= $maxId; $start += $chunkSize) {
            $end = $start + $chunkSize;
            $chunkQuery = "/* abj404:src=LogsHitsRollupService::hitsTableInsertChunked#phase1Chunk */ INSERT INTO " . $preAggTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT " . $logsv2CanonicalExpr . ", MIN(id), MAX(timestamp), COUNT(*), SUM(CASE WHEN dest_url = '' OR dest_url IS NULL THEN 1 ELSE 0 END) FROM " . $logsv2Table . " WHERE id >= %d AND id < %d GROUP BY " . $logsv2CanonicalExpr;
            $chunkResult = $this->dbCore->queryAndGetResults($chunkQuery, array('log_too_slow' => false, 'timeout' => 10, 'query_params' => array($start, $end)));
            if (!empty($chunkResult['timed_out']) || !empty($chunkResult['last_error'])) { $this->recordHitsChunkFailure(); $this->logger->debugMessage(__FUNCTION__ . " Phase 1 chunk failed at id range [{$start}, {$end}); aborting."); return false; }
        }
        // Defensive form covers legacy and in-progress installs; optimized
        // form lets idx_canonical_url serve the JOIN probe and gets the
        // rebuild under the host's 60s max_statement_time on Bruno-class
        // data (i359). See LogsHitsCanonicalUrlJoinHelper::buildPhase2JoinRhs.
        $joinRhs = $this->joinHelper->buildPhase2JoinRhs($resolvedCollation);
        $phase2Query = "/* abj404:src=LogsHitsRollupService::hitsTableInsertChunked#phase2Aggregate */ INSERT INTO " . $tempDestTable . " (requested_url, logsid, last_used, logshits, failed_hits) SELECT a.requested_url, MIN(a.logsid), MAX(a.last_used), SUM(a.logshits), SUM(a.failed_hits) FROM " . $preAggTable . " a INNER JOIN " . $redirectsTable . " r ON a.requested_url = " . $joinRhs . " GROUP BY a.requested_url";
        $results = $this->dbCore->queryAndGetResults($phase2Query, array('log_too_slow' => false, 'timeout' => 60));
        $results['elapsed_time'] = round(microtime(true) - $startTime, 3);
        $this->dbCore->queryAndGetResults("drop table if exists " . $preAggTable);
        return $results;
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

    // =========================================================================
    // Table existence + scheduling + locking
    // =========================================================================

    /** @inheritDoc */
    public function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] != null && !empty($results['rows'])) { return true; }
        if (!empty($results['last_error'])) { return $this->logsHitsTableExistsViaShowTables(); }
        return false;
    }

    /** @return bool */
    private function logsHitsTableExistsViaShowTables(): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) { return false; }
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $showTablesQuery = $wpdb->prepare("SHOW TABLES LIKE %s", $tableName);
        if ($showTablesQuery === null) { return false; }
        $fallback = $this->dbCore->queryAndGetResults($showTablesQuery, array('log_errors' => false));
        if (empty($fallback['rows'])) { return false; }
        $fbRows = is_array($fallback['rows']) ? $fallback['rows'] : array();
        $firstRow = isset($fbRows[0]) ? $fbRows[0] : null;
        if (!is_array($firstRow)) { return false; }
        $value = reset($firstRow);
        return ((string)$value === (string)$tableName);
    }

    /** @inheritDoc */
    public function scheduleHitsTableRebuild(): void {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->mayStartExpensiveRebuild()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return; }
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400); return; }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->isHitsTableRebuildLocked()) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400); return; }
            $rawScheduledFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (time() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown."); $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'cooldown', 86400); return; }
            self::$hitsTableRebuildScheduled = true;
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, time(), 86400);
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'scheduled', 86400);
            if ($this->shouldScheduleHitsTableRebuildViaCron()) { $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron."); if (function_exists('wp_schedule_single_event')) { wp_schedule_single_event(time() + 5, 'abj404_updateLogsHitsTableAction'); } return; }
            $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild for shutdown hook.");
            add_action('shutdown', function(): void { $this->createRedirectsForViewHitsTable(); });
        }
    }

    /** @return bool */
    private function shouldScheduleHitsTableRebuildViaCron(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { return true; }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') { return true; }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }

    private function getHitsTableRebuildLockOptionName(): string { return $this->dbCore->getLowercasePrefix() . 'abj404_logs_hits_rebuild_lock'; }

    /** @return bool */
    private function isHitsTableRebuildLocked(): bool {
        if (!function_exists('get_option')) { return false; }
        $lockValue = get_option($this->getHitsTableRebuildLockOptionName(), false);
        if ($lockValue === false || $lockValue === null || $lockValue === '') { return false; }
        if (!is_numeric($lockValue)) { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } return false; }
        $lockTimestamp = (int)$lockValue;
        if ($lockTimestamp > 0 && (time() - $lockTimestamp) > self::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS) { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } return false; }
        return true;
    }

    /** @return bool */
    private function acquireHitsTableRebuildLock(): bool {
        if (!function_exists('add_option')) { return true; }
        $lockName = $this->getHitsTableRebuildLockOptionName();
        if (add_option($lockName, (string)time(), '', false)) { return true; }
        if ($this->isHitsTableRebuildLocked()) { return false; }
        return (bool)add_option($lockName, (string)time(), '', false);
    }

    /** @return void */
    private function releaseHitsTableRebuildLock(): void { if (function_exists('delete_option')) { delete_option($this->getHitsTableRebuildLockOptionName()); } }

    // =========================================================================
    // logsv2 / logs_hits id queries
    // =========================================================================

    /** @inheritDoc */
    public function getMaxLogId() {
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /** @inheritDoc */
    public function getMinLogId() {
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $minId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return is_numeric($minId) ? (int)$minId : 0;
    }

    /** @inheritDoc */
    public function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $storedRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($storedRows)) {
            if (!empty($results['last_error'])) { $statusRow = $this->getLogsHitsTableStatusRow(); $commentFromStatus = $statusRow['comment'] ?? ''; if ($commentFromStatus !== '') { $parts = explode('|', is_string($commentFromStatus) ? $commentFromStatus : ''); if (count($parts) >= 2) { return (int)$parts[1]; } } }
            return 0;
        }
        $row = is_array($storedRows[0] ?? null) ? $storedRows[0] : array();
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';
        $parts = explode('|', is_string($comment) ? $comment : '');
        if (count($parts) >= 2) { return (int)$parts[1]; }
        return 0;
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastCheckedAt() { $rawTsFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG); $ts = is_scalar($rawTsFlag) ? (int)$rawTsFlag : 0; return $ts > 0 ? $ts : null; }

    /** @inheritDoc */
    public function getLogsHitsTableLastScheduledAt() { $rawTsFlag2 = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG); $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0; return $ts > 0 ? $ts : null; }

    /** @inheritDoc */
    public function getLogsHitsTableLastDecision(): string { $v = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG); return is_string($v) ? $v : ''; }
}
