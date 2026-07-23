<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRetentionPolicy.php';
require_once __DIR__ . '/RedirectsCleanupRepository.php';

/**
 * Scheduled-maintenance workflow for the redirects table.
 *
 * Owns the redirect/log retention pruning, auto-redirect expiry, junk
 * auto-trash, orphan cleanup, and the coordinated cron run. Previously fronted
 * by RedirectsRetentionServiceInterface, but that
 * interface had a single implementer and no narrowing consumer, so it was
 * inlined to remove the unrealized abstraction.
 */
class ABJ_404_Solution_RedirectsRetentionService {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRetentionPolicy */
    private $retentionPolicy;

    /** @var ABJ_404_Solution_RedirectsCleanupRepository */
    private $cleanupRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_DatabaseConnectionManager|null $connectionManager
     * @param ABJ_404_Solution_RedirectsRetentionPolicy|null $retentionPolicy
     * @param ABJ_404_Solution_RedirectsCleanupRepository|null $cleanupRepository
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo,
        $functions = null,
        $logging = null,
        $connectionManager = null,
        ?ABJ_404_Solution_RedirectsRetentionPolicy $retentionPolicy = null,
        ?ABJ_404_Solution_RedirectsCleanupRepository $cleanupRepository = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->connectionManager = $connectionManager !== null ? $connectionManager : $dbCore->connectionManager();
        $this->retentionPolicy = $retentionPolicy !== null
            ? $retentionPolicy
            : new ABJ_404_Solution_RedirectsRetentionPolicy();
        $this->cleanupRepository = $cleanupRepository !== null
            ? $cleanupRepository
            : new ABJ_404_Solution_RedirectsCleanupRepository(
                $dbCore,
                $redirectsRepo,
                $this->f,
                $this->logger,
                $this->retentionPolicy
            );
    }

    /** @return int Number of orphaned auto redirects removed. */
    public function cleanupOrphanedAutoRedirects(): int {
        return $this->cleanupRepository->cleanupOrphanedAutoRedirects();
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @return int
     */
    public function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        return $this->cleanupRepository->deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType);
    }

    /**
     * @param int $daysToKeep
     * @param int $now
     * @return int
     */
    public function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        return $this->cleanupRepository->deleteOldLogsByAge($daysToKeep, $now);
    }

    /** @return string Human-readable summary of the cron run. */
    function deleteOldRedirectsCron() {
        $viewRead = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');

        $options = abj_service('options_repository')->getOptions(true);
        $now = abj_clock()->now();
        $manually_fired = $this->isManualMaintenanceRun();

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->components()->bootstrapUpgrade()->createDatabaseTables(false);

        $this->connectionManager->ensureConnection();

        $this->deleteTempExportFile($abj404logic);

        $duplicateRowsDeleted = $this->removeDuplicatesCron();
        $deletions = $this->deleteConfiguredOldRedirects($options, $now);
        $orphanedCount = $this->cleanupOrphanedAutoRedirects();
        $junkTrashedCount = $this->autoTrashJunkCapturedUrls($options);
        $oldLogRowsDeletedBySize = $this->deleteOldLogsBySize($viewRead, $options);

        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $logSizeMB = round($logsSizeBytes / (1024 * 1000), 2);

        $renamed = $this->limitDebugFileSize();
        $renamed = $renamed ? "true" : "false";

        $oldLogRowsDeleted = $deletions['logRowsDeletedByAge'] + $oldLogRowsDeletedBySize;

        $message = "deleteOldRedirectsCron. Old captured URLs removed: " .
                $deletions['capturedURLs'] . ", Old automatic redirects removed: " . $deletions['autoRedirects'] .
                ", Old manual redirects removed: " . $deletions['manualRedirects'] .
                ", Orphaned auto redirects removed: " . $orphanedCount .
                ", Junk URLs auto-trashed: " . $junkTrashedCount .
                ", Old log lines removed: " . $oldLogRowsDeleted .
                " (age: " . $deletions['logRowsDeletedByAge'] . ", size: " . $oldLogRowsDeletedBySize . ")" .
                ", New log size: " . $logSizeMB . "MB" .
                ", Duplicate rows deleted: " . $duplicateRowsDeleted . ", Debug file size limited: " .
                $renamed;

        $message = $this->appendAdminNotificationMessage($message, $options, $manually_fired, $abj404logic);
        $message = $this->appendDeveloperLogMessage($message, $options);

        $abj404permalinkCache = abj_service('permalink_cache');
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;

        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;

        $this->logger->infoMessage($message);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->components()->bootstrapUpgrade()->createDatabaseTables();

        $this->dbCore->queryAndGetResults("optimize table {wp_abj404_redirects}");

        $upgradesEtc->components()->pluginUpdateUpgrade()->updatePluginCheck();

        return $message;
    }

    private function isManualMaintenanceRun(): bool {
        $manuallyFired = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('manually_fired', 'false');
        return $this->f->strtolower($manuallyFired) == 'true';
    }

    /**
     * @param mixed $abj404logic
     * @return void
     */
    private function deleteTempExportFile($abj404logic): void {
        $tempFile = null;
        if (is_object($abj404logic) && method_exists($abj404logic, 'importExport')) {
            $importExport = $abj404logic->importExport();
            if (is_object($importExport) && method_exists($importExport, 'getExportFilename')) {
                $tempFile = $importExport->getExportFilename();
            }
        }
        if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($tempFile);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @return array{capturedURLs: int, autoRedirects: int, manualRedirects: int, logRowsDeletedByAge: int}
     */
    private function deleteConfiguredOldRedirects(array $options, int $now): array {
        $deleted = array(
            'capturedURLs' => 0,
            'autoRedirects' => 0,
            'manualRedirects' => 0,
            'logRowsDeletedByAge' => 0,
        );

        if ($this->retentionPolicy->daysFromOptions($options, 'capture_deletion') > 0) {
            $statusList = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;
            $deleted['capturedURLs'] = $this->deleteOldRedirectsByType($options, $now, 'capture_deletion', $statusList, 'Captured 404');
            $deleted['logRowsDeletedByAge'] = $this->deleteOldLogsByAge(
                $this->retentionPolicy->daysFromOptions($options, 'capture_deletion'),
                $now
            );
        }

        if ($this->retentionPolicy->daysFromOptions($options, 'auto_deletion') > 0) {
            $deleted['autoRedirects'] = $this->deleteOldRedirectsByType(
                $options,
                $now,
                'auto_deletion',
                (string)ABJ404_STATUS_AUTO,
                'Automatic redirect'
            );
        }

        if ($this->retentionPolicy->daysFromOptions($options, 'manual_deletion') > 0) {
            $statusList = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;
            $deleted['manualRedirects'] = $this->deleteOldRedirectsByType(
                $options,
                $now,
                'manual_deletion',
                $statusList,
                'Manual redirect'
            );
        }

        return $deleted;
    }

    /**
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewRead
     * @param array<string, mixed> $options
     * @return int
     */
    private function deleteOldLogsBySize($viewRead, array $options): int {
        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $maxLogSizeRaw = array_key_exists('maximum_log_disk_usage', $options) ? $options['maximum_log_disk_usage'] : 100;
        $maxLogSizeBytes = (is_int($maxLogSizeRaw) || is_float($maxLogSizeRaw) || is_string($maxLogSizeRaw))
            ? (int)$maxLogSizeRaw * 1024 * 1000
            : 100 * 1024 * 1000;

        if ($logsSizeBytes <= $maxLogSizeBytes) {
            return 0;
        }

        $totalLogLines = $viewRead->getLogsCount(0);
        $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
        $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
        $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
        if ($logLinesToDelete <= 0) {
            return 0;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/deleteOldLogs.sql");
        $query = $this->f->str_replace('{lines_to_delete}', (string)$logLinesToDelete, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        $oldLogRowsDeletedBySizeRaw = $results['rows_affected'] ?? 0;
        return (is_int($oldLogRowsDeletedBySizeRaw) || is_float($oldLogRowsDeletedBySizeRaw) || is_string($oldLogRowsDeletedBySizeRaw))
            ? (int)$oldLogRowsDeletedBySizeRaw
            : 0;
    }

    /**
     * @param string $message
     * @param array<string, mixed> $options
     * @param bool $manuallyFired
     * @param mixed $abj404logic
     * @return string
     */
    private function appendAdminNotificationMessage(string $message, array $options, bool $manuallyFired, $abj404logic): string {
        $adminEmailVal = array_key_exists('admin_notification_email', $options) ? $options['admin_notification_email'] : '';
        $adminEmail = is_string($adminEmailVal) ? $adminEmailVal : '';
        if ($this->f->strlen(trim($adminEmail)) <= 5) {
            return $message . ', Admin email notification option turned off.';
        }

        if ($manuallyFired) {
            return $message . ', The admin email notification option is skipped for user initiated maintenance runs.';
        }

        if (!is_object($abj404logic) || !method_exists($abj404logic, 'pageOrdering')) {
            $this->logger->warn('Admin email notification skipped: plugin logic pageOrdering unavailable.');
            return $message . ', Admin email notification option unavailable.';
        }

        $pageOrdering = $abj404logic->pageOrdering();
        if (!is_object($pageOrdering) || !method_exists($pageOrdering, 'emailCaptured404Notification')) {
            $this->logger->warn('Admin email notification skipped: page ordering email sender unavailable.');
            return $message . ', Admin email notification option unavailable.';
        }

        return $message . ', ' . $pageOrdering->emailCaptured404Notification();
    }

    /**
     * @param string $message
     * @param array<string, mixed> $options
     * @return string
     */
    private function appendDeveloperLogMessage(string $message, array $options): string {
        if (!isset($options['send_error_logs']) || $options['send_error_logs'] != '1') {
            return $message;
        }

        // Drain any pending crash beacon first: a fatal/OOM that could not phone
        // home at the time is the highest-value signal, and it is independent of
        // whether there is also a fresh error line to email this run.
        if ($this->logger->drainCrashBeaconIfNecessary()) {
            $message .= ", Crash beacon reported to developer.";
        }

        if ($this->logger->emailErrorLogIfNecessary()) {
            return $message . ", Log file emailed to developer.";
        }

        if ($this->logger->sendHeartbeatIfDueWeekly()) {
            return $message . ", Heartbeat log emailed to developer.";
        }

        return $message;
    }

    /** @return bool Whether the debug log file was rotated. */
    function limitDebugFileSize(): bool {
        $renamed = false;

        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }

        return $renamed;
    }

    /** @return int Number of duplicate redirect rows removed. */
    function removeDuplicatesCron(): int {
        return $this->cleanupRepository->removeDuplicatesCron();
    }

    /**
     * @param array<string, mixed> $options Plugin options array.
     * @return int Number of captured URLs auto-trashed.
     */
    function autoTrashJunkCapturedUrls(array $options): int {
        return $this->cleanupRepository->autoTrashJunkCapturedUrls($options);
    }

    /**
     * Move auto-created redirects to trash if they are older than the configured expiration.
     *
     * @return int Number of redirects moved to trash.
     */
    public function expireOldAutoRedirects(): int {
        $options = abj_service('options_repository')->getOptions();
        $days = $this->retentionPolicy->daysFromOptions(is_array($options) ? $options : array(), 'auto_302_expiration_days');
        return $this->cleanupRepository->expireOldAutoRedirects($days, abj_clock()->now());
    }
}
