<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRetentionServiceInterface.php';
require_once __DIR__ . '/ViewReadRuntimeState.php';

/**
 * Scheduled-maintenance workflow for the redirects table.
 *
 * Owns the cron-driven retention surface that used to live on
 * RedirectsRepository (extracted under M201):
 *   - pruning old captured / auto / manual redirects by age,
 *   - aging out old logsv2 rows by the matching window,
 *   - removing orphaned auto redirects whose destination disappeared,
 *   - flagging redirects whose destinations are themselves 404ing,
 *   - expiring old auto-redirects (302) into the trash,
 *   - auto-trashing junk / stale captured URLs,
 *   - log-file rotation, duplicate cleanup, and the coordinated cron tick.
 *
 * Persistence (CRUD, lookup, conditions, regex cache) stays on
 * RedirectsRepository. This service depends on the repository for
 * per-row delete and trash operations.
 */
class ABJ_404_Solution_RedirectsRetentionService implements ABJ_404_Solution_RedirectsRetentionServiceInterface {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_DatabaseConnectionManager|null $connectionManager
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo,
        $functions = null,
        $logging = null,
        $connectionManager = null
    ) {
        $this->dbCore = $dbCore;
        $this->redirectsRepo = $redirectsRepo;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->connectionManager = $connectionManager !== null ? $connectionManager : $dbCore->connectionManager();
    }

    /** @inheritDoc */
    public function cleanupOrphanedAutoRedirects(): int {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableExists($redirectsTable)) {
            $this->logger->warn("Skipping orphaned redirect cleanup: table missing.");
            return 0;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getOrphanedAutoRedirects.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : [];
        $deletedCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0';
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $this->logger->debugMessage('Orphaned auto redirect deleted: "' . $url . '" (dest post ' .
                (isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '?') . ' missing/unpublished).');
            $this->redirectsRepo->deleteRedirect($id);
            $deletedCount++;
        }

        return $deletedCount;
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
        $logsRepo = abj_service('logs_repository');
        $deletedCount = 0;

        $rawDays = $options[$optionKey] ?? 0;
        $deletionDays = intval(is_scalar($rawDays) ? $rawDays : 0);
        if ($deletionDays <= 0) {
            return 0;
        }
        $deletionTime = $deletionDays * 86400;
        $then = $now - $deletionTime;

        $this->dbCore->setSqlBigSelects();

        if (!$logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipping: logs_hits table missing; scheduling rebuild.");
            $logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', (string)$then, $query);

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        foreach ($rows as $rowRaw) {
            if (!is_array($rowRaw)) {
                continue;
            }
            $row = $rowRaw;
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') .
                    '" deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            } else {
                $this->logger->debugMessage($debugMessageType . " from: " . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') . ' to: ' .
                    (is_string($row['best_guess_dest'] ?? '') ? $row['best_guess_dest'] : '') . ' deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            }

            $this->redirectsRepo->deleteRedirect(isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0');
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * @param int $daysToKeep
     * @param int $now
     * @return int
     */
    public function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        if ($daysToKeep <= 0) {
            return 0;
        }

        $cutoffTimestamp = max(0, $now - ($daysToKeep * 86400));
        $deletedTotal = 0;
        $batchSize = 2000;
        $maxBatches = 200;

        for ($i = 0; $i < $maxBatches; $i++) {
            $result = $this->dbCore->queryAndGetResults(
                "DELETE FROM {wp_abj404_logsv2} WHERE timestamp <= %d LIMIT %d",
                array(
                    'query_params' => array($cutoffTimestamp, $batchSize),
                    'log_errors' => true,
                )
            );
            $rowsDeletedRaw = $result['rows_affected'] ?? 0;
            $rowsDeleted = (is_int($rowsDeletedRaw) || is_float($rowsDeletedRaw) || is_string($rowsDeletedRaw))
                ? (int)$rowsDeletedRaw
                : 0;
            if ($rowsDeleted <= 0) {
                break;
            }
            $deletedTotal += $rowsDeleted;
            if ($rowsDeleted < $batchSize) {
                break;
            }
        }

        return $deletedTotal;
    }

    /** @inheritDoc */
    function deleteOldRedirectsCron() {
        $viewRead = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');

        $options = abj_service('plugin_logic')->optionsResolver()->getOptions(true);
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogRowsDeletedBySize = 0;
        $oldLogRowsDeletedByAge = 0;

        $manually_fired = abj_service('functions')->getPostOrGetSanitize('manually_fired', 'false');
        if ($this->f->strtolower($manually_fired) == 'true') {
            $manually_fired = true;
        } else {
            $manually_fired = false;
        }

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables(false);

        $this->connectionManager->ensureConnection();

        $tempFile = null;
        if (is_object($abj404logic) && method_exists($abj404logic, 'importExport')) {
            $importExport = $abj404logic->importExport();
            if (is_object($importExport) && method_exists($importExport, 'getExportFilename')) {
                $tempFile = $importExport->getExportFilename();
            }
        }
        if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
            ABJ_404_Solution_Functions::safeUnlink($tempFile);
        }

        $duplicateRowsDeleted = $this->removeDuplicatesCron();

        if (array_key_exists('capture_deletion', $options) && $options['capture_deletion'] != '0') {
            $status_list = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;
            $capturedURLsCount = $this->deleteOldRedirectsByType($options, $now, 'capture_deletion', $status_list, 'Captured 404');
            $captureDeletionDays = intval(is_scalar($options['capture_deletion']) ? $options['capture_deletion'] : 0);
            $oldLogRowsDeletedByAge = $this->deleteOldLogsByAge($captureDeletionDays, $now);
        }

        if (isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $status_list = (string)ABJ404_STATUS_AUTO;
            $autoRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'auto_deletion', $status_list, 'Automatic redirect');
        }

        if (isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $status_list = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;
            $manualRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'manual_deletion', $status_list, 'Manual redirect');
        }

        $orphanedCount = $this->cleanupOrphanedAutoRedirects();

        $junkTrashedCount = $this->autoTrashJunkCapturedUrls($options);

        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $maxLogSizeBytes = (array_key_exists('maximum_log_disk_usage', $options) ? $options['maximum_log_disk_usage'] : 100) * 1024 * 1000;

        if ($logsSizeBytes > $maxLogSizeBytes) {
            $totalLogLines = $viewRead->getLogsCount(0);
            $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
            $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
            $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
            if ($logLinesToDelete > 0) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/deleteOldLogs.sql");
                $query = $this->f->str_replace('{lines_to_delete}', (string)$logLinesToDelete, $query);
                $results = $this->dbCore->queryAndGetResults($query);
                $oldLogRowsDeletedBySizeRaw = $results['rows_affected'] ?? 0;
                $oldLogRowsDeletedBySize = (is_int($oldLogRowsDeletedBySizeRaw) || is_float($oldLogRowsDeletedBySizeRaw) || is_string($oldLogRowsDeletedBySizeRaw))
                    ? (int)$oldLogRowsDeletedBySizeRaw
                    : 0;
            }
        }

        $logsSizeBytes = $viewRead->getLogDiskUsage();
        $logSizeMB = round($logsSizeBytes / (1024 * 1000), 2);

        $renamed = $this->limitDebugFileSize();
        $renamed = $renamed ? "true" : "false";

        $oldLogRowsDeleted = $oldLogRowsDeletedByAge + $oldLogRowsDeletedBySize;

        $message = "deleteOldRedirectsCron. Old captured URLs removed: " .
                $capturedURLsCount . ", Old automatic redirects removed: " . $autoRedirectsCount .
                ", Old manual redirects removed: " . $manualRedirectsCount .
                ", Orphaned auto redirects removed: " . $orphanedCount .
                ", Junk URLs auto-trashed: " . $junkTrashedCount .
                ", Old log lines removed: " . $oldLogRowsDeleted .
                " (age: " . $oldLogRowsDeletedByAge . ", size: " . $oldLogRowsDeletedBySize . ")" .
                ", New log size: " . $logSizeMB . "MB" .
                ", Duplicate rows deleted: " . $duplicateRowsDeleted . ", Debug file size limited: " .
                $renamed;

        $adminEmailVal = array_key_exists('admin_notification_email', $options) ? $options['admin_notification_email'] : '';
        if ($adminEmailVal !== null &&
                $this->f->strlen(trim(is_string($adminEmailVal) ? $adminEmailVal : '')) > 5) {

            if ($manually_fired) {
                $message .= ', The admin email notification option is skipped for user '
                        . 'initiated maintenance runs.';
            } else {
                $message .= ', ' . $abj404logic->pageOrdering()->emailCaptured404Notification();
            }
        } else {
            $message .= ', Admin email notification option turned off.';
        }

        if (isset($options['send_error_logs']) &&
                $options['send_error_logs'] == '1') {
            if ($this->logger->emailErrorLogIfNecessary()) {
                $message .= ", Log file emailed to developer.";
            } else {
                if ($this->logger->sendHeartbeatIfDueRandom(200)) {
                    $message .= ", Heartbeat log emailed to developer.";
                }
            }
        }

        $this->flagDeadDestinationRedirects();

        $abj404permalinkCache = abj_service('permalink_cache');
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;

        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;

        $this->logger->infoMessage($message);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables();

        $this->dbCore->queryAndGetResults("optimize table {wp_abj404_redirects}");

        $upgradesEtc->updatePluginCheck();

        return $message;
    }

    /** @inheritDoc */
    function limitDebugFileSize(): bool {
        $renamed = false;

        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }

        return $renamed;
    }

    /** @inheritDoc */
    function removeDuplicatesCron(): int {
        $rowsDeleted = 0;
        $query = "SELECT COUNT(id) as repetitions, url FROM {wp_abj404_redirects} GROUP BY url HAVING repetitions > 1 ";
        $result = $this->dbCore->queryAndGetResults($query);
        $outerRows = is_array($result['rows']) ? $result['rows'] : array();
        foreach ($outerRows as $outerRow) {
            if (!is_array($outerRow)) {
                continue;
            }
            $row = $outerRow;
            $url = $row['url'];

            $queryr1 = $this->prepareQueryWp(
                "select id from {wp_abj404_redirects} where url = {url} order by timestamp desc limit 0,1",
                array("url" => $url)
            );
            $result = $this->dbCore->queryAndGetResults($queryr1);
            $innerRows = is_array($result['rows']) ? $result['rows'] : array();
            if (count($innerRows) >= 1) {
                $row = is_array($innerRows[0]) ? $innerRows[0] : array();
                $original = isset($row['id']) ? $row['id'] : 0;

                $queryl = $this->prepareQueryWp(
                    "delete from {wp_abj404_redirects} where url = {url} and id != {original}", // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
                    array("url" => $url, "original" => $original)
                );
                $deleteResult = $this->dbCore->queryAndGetResults($queryl);
                $affected = isset($deleteResult['rows_affected']) && is_numeric($deleteResult['rows_affected'])
                    ? (int)$deleteResult['rows_affected'] : 1;
                $rowsDeleted += max($affected, 1);
            }
        }

        if ($rowsDeleted > 0) {
            abj_service('view_read_service')->invalidateStatusCountsCache();
        }

        return $rowsDeleted;
    }

    /** @inheritDoc */
    function autoTrashJunkCapturedUrls(array $options): int {
        $enabled = $options['auto_trash_junk_urls'] ?? '0';
        if ($enabled !== '1') {
            return 0;
        }

        $transientKey = 'abj404_last_auto_trash';
        if (get_transient($transientKey) !== false) {
            return 0;
        }
        set_transient($transientKey, time(), HOUR_IN_SECONDS);

        $patternsRaw = $options['auto_trash_junk_patterns'] ?? '';
        $patternsStr = is_string($patternsRaw) ? $patternsRaw : '';
        $lines = array_filter(array_map('trim', explode("\n", $patternsStr)));

        if (empty($lines)) {
            return 0;
        }

        global $wpdb;
        $totalTrashed = 0;

        $likeClauses = array();
        foreach ($lines as $pattern) {
            $escaped = $wpdb->esc_like($pattern);
            // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; result goes through queryAndGetResults
            $likeClauses[] = $wpdb->prepare("url LIKE %s", '%' . $escaped . '%');
        }

        $wherePatterns = implode(' OR ', $likeClauses);
        // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
        $query = "UPDATE {wp_abj404_redirects}
            SET disabled = 1
            WHERE status = " . ABJ404_STATUS_CAPTURED . "
            AND disabled = 0
            AND (" . $wherePatterns . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        $cutoff = time() - (14 * DAY_IN_SECONDS);
        // allow-no-watermark-bump: DAO layer; admin callers bump via invalidateViewDoneAndScheduleRebuild()
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; result goes through queryAndGetResults
        $query = $wpdb->prepare("UPDATE {wp_abj404_redirects} r
            SET r.disabled = 1
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . "
            AND r.disabled = 0
            AND r.timestamp < %d
            AND NOT EXISTS (
                SELECT 1 FROM {wp_abj404_logsv2} l
                WHERE l.requested_url = r.url
                LIMIT 1
            )",
            $cutoff
        );
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        if ($totalTrashed > 0) {
            $this->logger->infoMessage("Auto-trashed " . $totalTrashed . " junk/stale captured URLs during maintenance.");
            delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS);
        }

        return $totalTrashed;
    }

    /** @inheritDoc */
    public function flagDeadDestinationRedirects(): void {
        $cutoff = time() - 7 * 86400;
        $flaggedIds = array();

        $hitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $hitsTableExists = $this->dbCore->tableExists($hitsTable);

        if (!$hitsTableExists || !$this->logsHitsHasFailedHitsColumn()) {
            /** @var ABJ_404_Solution_LogsRepository|null $logsRepo */
            $logsRepo = abj_service('logs_repository');
            if ($logsRepo !== null) {
                $logsRepo->scheduleHitsTableRebuild();
            }
            $this->storeDeadDestIdsTransient($flaggedIds);
            return;
        }

        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
        $sql = "SELECT DISTINCT r.id
             FROM {wp_abj404_redirects} r
             INNER JOIN {wp_abj404_logs_hits} h
                 ON h.requested_url = CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
                AND BINARY h.requested_url = BINARY CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
             WHERE h.last_used > %d
               AND h.failed_hits > 0
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";
        $sql = $this->dbCore->doTableNameReplacements($sql);

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($cutoff),
            'timeout' => 30,
        ));

        if (empty($result['timed_out']) && (!isset($result['last_error']) || $result['last_error'] == '')) {
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $value = $row['id'] ?? reset($row);
                } elseif (is_object($row)) {
                    $value = $row->id ?? null;
                } else {
                    $value = $row;
                }
                if ($value !== null && $value !== '') {
                    $flaggedIds[] = (string)$value;
                }
            }
        }

        $this->storeDeadDestIdsTransient($flaggedIds);

        if (!empty($flaggedIds)) {
            $this->logger->infoMessage(
                __CLASS__ . '/' . __FUNCTION__ . ': Flagged ' . count($flaggedIds) .
                ' redirect(s) with dead destinations: ' . implode(', ', $flaggedIds)
            );
        }
    }

    /**
     * @param array<int, string> $flaggedIds
     * @return void
     */
    private function storeDeadDestIdsTransient(array $flaggedIds): void {
        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? 25 * (int) HOUR_IN_SECONDS : 90000;
            // allow-cache-empty: flaggedIds is a diagnostic list (dead-destination redirect IDs); an empty array is a valid "no dead destinations" result.
            set_transient('abj404_dead_dest_ids', $flaggedIds, $ttl);
        }
    }

    /**
     * @return bool
     */
    private function logsHitsHasFailedHitsColumn(): bool {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $sql = "SELECT 1 FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = %s "
            . "AND column_name = 'failed_hits' LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($tableName),
            'log_errors' => false,
        ));
        if (!empty($result['last_error'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /** @inheritDoc */
    public function expireOldAutoRedirects(): int {
        $options = abj_service('plugin_logic')->optionsResolver()->getOptions();
        $daysRaw = isset($options['auto_302_expiration_days']) ? $options['auto_302_expiration_days'] : 0;
        $days = is_numeric($daysRaw) ? (int)$daysRaw : 0;
        if ($days <= 0) {
            return 0;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableExists($redirectsTable)) {
            $this->logger->warn("expireOldAutoRedirects: redirects table missing, skipping.");
            return 0;
        }

        $cutoff = time() - ($days * 86400);

        $sql = "SELECT id FROM `{$redirectsTable}`
             WHERE status = %d
               AND disabled = 0
               AND `timestamp` > 0
               AND `timestamp` < %d";

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array(ABJ404_STATUS_AUTO, $cutoff),
        ));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $value = $row['id'] ?? reset($row);
            } elseif (is_object($row)) {
                $value = $row->id ?? null;
            } else {
                $value = $row;
            }
            if ($value !== null && $value !== '') {
                $ids[] = absint($value);
            }
        }

        if (empty($ids)) {
            return 0;
        }

        $moved = 0;
        foreach ($ids as $id) {
            $this->redirectsRepo->moveRedirectsToTrash($id, 1);
            $moved++;
        }

        $this->logger->infoMessage("expireOldAutoRedirects: moved {$moved} expired auto-redirect(s) to trash (threshold: {$days} days).");
        return $moved;
    }

    /**
     * Token-style wpdb prepare helper used by removeDuplicatesCron.
     *
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
     */
    private function prepareQueryWp($query, $data) {
        global $wpdb;
        $orderedValues = [];
        $preparedQuery = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$orderedValues) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                return $matches[0];
            }
            $value = $data[$key];
            $orderedValues[] = $value;
            return is_int($value) ? '%d' : '%s';
        }, $query);
        $preparedQuery = $preparedQuery !== null ? $preparedQuery : $query;
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; callers execute the result through queryAndGetResults
        return $wpdb->prepare($preparedQuery, $orderedValues);
    }
}
