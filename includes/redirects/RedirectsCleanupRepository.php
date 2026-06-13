<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRetentionPolicy.php';
require_once __DIR__ . '/../view-build/ViewReadRuntimeState.php';

/**
 * Repository operations for scheduled redirect and log cleanup.
 */
class ABJ_404_Solution_RedirectsCleanupRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRetentionPolicy */
    private $retentionPolicy;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectsRetentionPolicy|null $retentionPolicy
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo,
        ABJ_404_Solution_Functions $functions,
        ABJ_404_Solution_Logging $logger,
        ?ABJ_404_Solution_RedirectsRetentionPolicy $retentionPolicy = null
    ) {
        $this->dbCore = $dbCore;
        $this->redirectsRepo = $redirectsRepo;
        $this->f = $functions;
        $this->logger = $logger;
        $this->retentionPolicy = $retentionPolicy !== null ? $retentionPolicy : new ABJ_404_Solution_RedirectsRetentionPolicy();
    }

    public function cleanupOrphanedAutoRedirects(): int {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableNameResolver()->tableExists($redirectsTable)) {
            $this->logger->warn("Skipping orphaned redirect cleanup: table missing.");
            return 0;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getOrphanedAutoRedirects.sql");
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

        $deletionDays = $this->retentionPolicy->daysFromOptions(is_array($options) ? $options : array(), $optionKey);
        $then = $this->retentionPolicy->cutoffForDays($deletionDays, $now);
        if ($then === null) {
            return 0;
        }

        $this->dbCore->tableNameResolver()->setSqlBigSelects();

        if (!$logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipping: logs_hits table missing; scheduling rebuild.");
            $logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', (string)$then, $query);

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        foreach ($rows as $rowRaw) {
            if (!is_array($rowRaw)) {
                continue;
            }
            $row = $rowRaw;
            $fromUrl = isset($row['from_url']) && is_scalar($row['from_url']) ? (string)$row['from_url'] : '';
            $lastUsed = isset($row['last_used_formatted']) && is_scalar($row['last_used_formatted'])
                ? (string)$row['last_used_formatted']
                : '';
            $bestGuessDest = isset($row['best_guess_dest']) && is_scalar($row['best_guess_dest'])
                ? (string)$row['best_guess_dest']
                : '';
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . $fromUrl .
                    '" deleted (last used: ' . $lastUsed . ').');
            } else {
                $this->logger->debugMessage($debugMessageType . " from: " . $fromUrl . ' to: ' .
                    $bestGuessDest . ' deleted (last used: ' . $lastUsed . ').');
            }

            $this->redirectsRepo->deleteRedirect(isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0');
            $deletedCount++;
        }

        return $deletedCount;
    }

    public function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        $cutoffTimestamp = $this->retentionPolicy->cutoffForDays($daysToKeep, $now);
        if ($cutoffTimestamp === null) {
            return 0;
        }

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

    public function removeDuplicatesCron(): int {
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
                    "delete from {wp_abj404_redirects} where url = {url} and id != {original}",
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

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    public function autoTrashJunkCapturedUrls(array $options): int {
        $enabled = $options['auto_trash_junk_urls'] ?? '0';
        if ($enabled !== '1') {
            return 0;
        }

        $transientKey = 'abj404_last_auto_trash';
        if (get_transient($transientKey) !== false) {
            return 0;
        }
        // allow-cache-empty: timestamp marker rate-limits automatic trash cleanup; not a cached query payload.
        set_transient($transientKey, abj_clock()->now(), HOUR_IN_SECONDS);

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
        $query = "UPDATE {wp_abj404_redirects}
            SET disabled = 1
            WHERE status = " . ABJ404_STATUS_CAPTURED . "
            AND disabled = 0
            AND (" . $wherePatterns . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        $cutoff = abj_clock()->now() - (14 * DAY_IN_SECONDS);
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

    public function expireOldAutoRedirects(int $days, int $now): int {
        $cutoff = $this->retentionPolicy->cutoffForDays($days, $now);
        if ($cutoff === null) {
            return 0;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableNameResolver()->tableExists($redirectsTable)) {
            $this->logger->warn("expireOldAutoRedirects: redirects table missing, skipping.");
            return 0;
        }

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
