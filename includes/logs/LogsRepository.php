<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsRepositoryInterface.php';
require_once __DIR__ . '/LogsHitsRollupServiceInterface.php';
require_once __DIR__ . '/LogsHitsRollupService.php';
require_once __DIR__ . '/LogsLookupRepository.php';
require_once __DIR__ . '/LogsPrivacyService.php';
require_once __DIR__ . '/LogsReadQueries.php';
require_once __DIR__ . '/LogsHitsDataPopulator.php';
require_once __DIR__ . '/LogsEntrySanitizer.php';
require_once __DIR__ . '/LogsRequestedUrlColumnMetadata.php';
require_once __DIR__ . '/LogsWriteRecoveryPolicy.php';
require_once __DIR__ . '/LogsQueueFlusher.php';
require_once __DIR__ . '/LogsWriter.php';

/**
 * Public-surface facade for the logs subsystem. Implements the
 * LogsRepositoryInterface contract that callers (PluginLogic, ViewReadService,
 * RedirectsRetentionService, Privacy, etc.) depend on, and composes the
 * five single-responsibility collaborators that hold the actual logic:
 *
 *   - LogsWriter             - log-write pipeline (logRedirectHit, queue, flush, recovery)
 *   - LogsHitsDataPopulator  - join logshits/logsid/last_used onto result rows
 *   - LogsReadQueries        - admin-list-table reads + daily-activity trend
 *   - LogsLookupRepository   - lkup table CRUD
 *   - LogsPrivacyService     - GDPR export and erasure
 *   - LogsHitsRollupService  - hits-rollup lifecycle (rebuild / scheduling / locking)
 *
 * The facade also owns the static pipeline-trace decode utility used by
 * View_Logs + DataAccess, and re-exports the hits-rollup constants for
 * backwards-compatible callers reaching at LogsRepository::CONST_NAME.
 */
class ABJ_404_Solution_LogsRepository implements ABJ_404_Solution_LogsRepositoryInterface {

    // Backwards-compatible constants for callers reaching at
    // ABJ_404_Solution_LogsRepository::CONST_NAME. Authoritative copies
    // live on ABJ_404_Solution_LogsHitsRollupService.
    const UPDATE_LOGS_HITS_TABLE_HOOK = ABJ_404_Solution_LogsHitsRollupService::UPDATE_LOGS_HITS_TABLE_HOOK;
    const HITS_TABLE_MAX_AGE_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_MAX_AGE_SECONDS;
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS;
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS;
    const HITS_TABLE_PREAGG_CHUNK_SIZE = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_PREAGG_CHUNK_SIZE;
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_DIRECT_PATH_THRESHOLD;
    const HITS_TABLE_LAST_SCHEDULED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_SCHEDULED_FLAG;
    const HITS_TABLE_LAST_REFRESHED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_LAST_REFRESHED_FLAG;
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_FIRST_STALE_DETECTED_FLAG;
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_STALE_NOTICE_TRANSIENT;
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = ABJ_404_Solution_LogsHitsRollupService::HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS;

    /** @var int Max age for cached daily-activity trend data. */
    const TREND_DATA_CACHE_TTL_SECONDS = ABJ_404_Solution_LogsReadQueries::TREND_DATA_CACHE_TTL_SECONDS;

    /** @var ABJ_404_Solution_LogsHitsRollupServiceInterface */
    private $rollup;

    /** @var ABJ_404_Solution_LogsLookupRepository */
    private $lookups;

    /** @var ABJ_404_Solution_LogsPrivacyService */
    private $privacy;

    /** @var ABJ_404_Solution_LogsReadQueries */
    private $reads;

    /** @var ABJ_404_Solution_LogsHitsDataPopulator */
    private $hitsPopulator;

    /** @var ABJ_404_Solution_LogsWriter */
    private $writer;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth Forwarded to the rollup service when one is constructed internally.
     * @param ABJ_404_Solution_LogsHitsRollupServiceInterface|null $rollup Pre-built rollup service (preferred wiring). When null, an internal one is constructed.
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier
     * @param ABJ_404_Solution_DatabaseCollationHelper|null $collationHelper
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder|null $noticeState
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        $rebuildHealth = null,
        $rollup = null,
        $errorClassifier = null,
        $collationHelper = null,
        $noticeState = null
    ) {
        $f = $functions !== null ? $functions : abj_service('functions');
        $logger = $logging !== null ? $logging : abj_service('logging');
        $ec = $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier();
        $ch = $collationHelper !== null ? $collationHelper : $dbCore->collationHelper();
        $ns = $noticeState !== null ? $noticeState : $dbCore->noticeState();
        if ($rollup instanceof ABJ_404_Solution_LogsHitsRollupServiceInterface) {
            $this->rollup = $rollup;
        } else {
            $this->rollup = new ABJ_404_Solution_LogsHitsRollupService($dbCore, $logger, $rebuildHealth);
        }
        $this->lookups = new ABJ_404_Solution_LogsLookupRepository($dbCore);
        $this->privacy = new ABJ_404_Solution_LogsPrivacyService($dbCore);
        $this->reads = new ABJ_404_Solution_LogsReadQueries($dbCore, $f, $logger);
        $this->hitsPopulator = new ABJ_404_Solution_LogsHitsDataPopulator($dbCore);
        $this->writer = new ABJ_404_Solution_LogsWriter($dbCore, $f, $logger, $ec, $ch, $ns, $this->lookups);
    }

    /**
     * Accessor for callers that need to drive the rollup directly (e.g. tests
     * inspecting scheduling state). Not part of the LogsRepository interface.
     *
     * @return ABJ_404_Solution_LogsHitsRollupServiceInterface
     */
    public function getRollupService(): ABJ_404_Solution_LogsHitsRollupServiceInterface {
        return $this->rollup;
    }

    // =========================================================================
    // Log read queries (delegated to LogsReadQueries + LogsHitsDataPopulator)
    // =========================================================================

    /** @inheritDoc */
    function populateLogsData($rows) {
        return $this->hitsPopulator->populate($rows, $this);
    }

    /** @inheritDoc */
    function getDistinctLoggedUrls(
        int $recentLogWindow = self::DEFAULT_DISTINCT_RECENT_LOG_WINDOW,
        int $distinctUrlCap = self::DEFAULT_DISTINCT_URL_CAP
    ): array {
        return $this->reads->getDistinctLoggedUrls($recentLogWindow, $distinctUrlCap);
    }

    /** @inheritDoc */
    function getLogsIDandURL($specificURL = '') {
        return $this->reads->getLogsIDandURL($specificURL);
    }

    /** @inheritDoc */
    function getLogsIDandURLLike($specificURL, $limitResults) {
        return $this->reads->getLogsIDandURLLike($specificURL, $limitResults);
    }

    /** @inheritDoc */
    function getLogRecords($tableOptions) {
        return $this->reads->getLogRecords($tableOptions);
    }

    /** @inheritDoc */
    public function getDailyActivityTrend(int $days = 30): array {
        return $this->reads->getDailyActivityTrend($days, $this);
    }

    // =========================================================================
    // GDPR (delegated to LogsPrivacyService)
    // =========================================================================

    /** @inheritDoc */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100) {
        return $this->privacy->getLogsv2IdsForLookupValue($lkupValue, $page, $perPage);
    }

    /** @inheritDoc */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50) {
        return $this->privacy->getLogsv2RowsForLookupValue($lkupValue, $page, $perPage);
    }

    /** @inheritDoc */
    public function anonymizeLogsv2RowsByIds($ids) {
        return $this->privacy->anonymizeLogsv2RowsByIds($ids);
    }

    // =========================================================================
    // Log writes (delegated to LogsWriter)
    // =========================================================================

    /** @inheritDoc */
    function logRedirectHit(ABJ_404_Solution_RedirectHitLogEntry $entry): void {
        $this->writer->logRedirectHit($entry);
    }

    /** @inheritDoc */
    function queueLogEntry(array $entry): void {
        $this->writer->queueLogEntry($entry);
    }

    /** @inheritDoc */
    function flushLogQueue(): void {
        $this->writer->flushLogQueue();
    }

    /**
     * Backwards-compatible accessor (not on the interface). Forwarded to the
     * writer; DataAccess re-exports it as a pass-through for legacy callers.
     */
    public function isTableFullError(string $error): bool {
        return $this->writer->isTableFullError($error);
    }

    /**
     * Backwards-compatible accessor (not on the interface). Forwarded to the
     * writer.
     */
    public function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        return $this->writer->autoTrimLogsv2IfNeeded($tableName, $errorMessage);
    }

    /**
     * Backwards-compatible accessor (not on the interface). Forwarded to the
     * writer.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public function sanitizeLogEntry(array $entry): ?array {
        return $this->writer->sanitizeLogEntry($entry);
    }

    // =========================================================================
    // Lookup table (delegated to LogsLookupRepository)
    // =========================================================================

    /** @inheritDoc */
    function insertLookupValueAndGetID($valueToInsert) {
        return $this->lookups->insertLookupValueAndGetID($valueToInsert);
    }

    /** @inheritDoc */
    function getLookupIDForUser($userName) {
        return $this->lookups->getLookupIDForUser($userName);
    }

    /** @inheritDoc */
    public function correctDuplicateLookupValues(): void {
        $this->lookups->correctDuplicateLookupValues();
    }

    // =========================================================================
    // Pipeline trace decode (static; the codec stays on the facade so existing
    // ABJ_404_Solution_LogsRepository::decompressPipelineTrace() call sites
    // in View_Logs and DataAccess keep working without rewiring).
    // =========================================================================

    /** @inheritDoc */
    public static function decompressPipelineTrace(?string $raw): ?array {
        if ($raw === null || $raw === '') { return null; }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) { return null; }
        $json = @gzuncompress($decoded);
        if ($json === false) { return null; }
        $result = json_decode($json, true);
        return is_array($result) ? $result : null;
    }

    // =========================================================================
    // Hits-table rollup: thin forwarders to ABJ_404_Solution_LogsHitsRollupService
    // =========================================================================

    /** @inheritDoc */
    function recordLogsHitsRollupStalenessSignal(): void { $this->rollup->recordLogsHitsRollupStalenessSignal(); }

    /** @inheritDoc */
    function hitsTableNeedsRebuild() { return $this->rollup->hitsTableNeedsRebuild(); }

    /** @inheritDoc */
    function getLogsHitsTableLastUpdated() { return $this->rollup->getLogsHitsTableLastUpdated(); }

    /** @inheritDoc */
    function createRedirectsForViewHitsTable(): bool { return $this->rollup->createRedirectsForViewHitsTable(); }

    /** @inheritDoc */
    function logsHitsTableExists() { return $this->rollup->logsHitsTableExists(); }

    /** @inheritDoc */
    function scheduleHitsTableRebuild(): void { $this->rollup->scheduleHitsTableRebuild(); }

    /** @inheritDoc */
    function getMaxLogId() { return $this->rollup->getMaxLogId(); }

    /** @inheritDoc */
    function getMinLogId() { return $this->rollup->getMinLogId(); }

    /** @inheritDoc */
    function getStoredMaxLogId() { return $this->rollup->getStoredMaxLogId(); }

    /** @inheritDoc */
    function getLogsHitsTableLastScheduledAt() { return $this->rollup->getLogsHitsTableLastScheduledAt(); }

    /**
     * Backwards-compatible accessor used by some integration tests that need
     * the rollup-internal collation resolution. Delegates to the rollup
     * service. Not part of LogsRepositoryInterface.
     *
     * @return string
     */
    public function resolveHitsJoinCollation(): string {
        if (method_exists($this->rollup, 'resolveHitsJoinCollation')) {
            return $this->rollup->resolveHitsJoinCollation();
        }
        return '';
    }
}
