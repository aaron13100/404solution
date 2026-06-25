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

    /**
     * Rows handled per cleanup SELECT batch. Each cleanup SELECT is bounded
     * with a LIMIT of this size so a large match set (up to ~630k rows on big
     * installs) never loads into PHP memory all at once during cron.
     * @var int
     */
    const CLEANUP_BATCH_SIZE = 1000;

    /**
     * Maximum number of cleanup batches handled per run (safety backstop:
     * caps a run at CLEANUP_BATCH_SIZE * CLEANUP_MAX_BATCHES rows). Prevents an
     * infinite loop if a row's deletion silently fails and it keeps
     * re-matching the SELECT.
     * @var int
     */
    const CLEANUP_MAX_BATCHES = 1000;

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

    /**
     * Drive a cleanup SELECT in bounded batches so a large match set never loads
     * into PHP memory all at once. $runBatch($batchSize) returns the rows for one
     * LIMIT-bounded batch; $handleRow($row) processes each row and MUST remove it
     * from the SELECT's match set (delete/trash it) so the next batch advances.
     * Loops until a batch returns fewer than $batchSize rows (or the iteration cap
     * is hit). Returns the total number of rows handled.
     *
     * @param callable(int):array<int,mixed> $runBatch
     * @param callable(array<mixed,mixed>):void $handleRow Each $row is a DB
     *        result row (string-keyed at runtime; typed array<mixed,mixed>
     *        because the result-set key type is not statically provable).
     * @return int
     */
    private function runBatchedCleanup(callable $runBatch, callable $handleRow,
            int $batchSize = self::CLEANUP_BATCH_SIZE, int $maxBatches = self::CLEANUP_MAX_BATCHES): int {
        $handled = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $rows = $runBatch($batchSize);
            $rows = is_array($rows) ? $rows : array();
            $rowCount = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                $handleRow($row);
                $handled++;
            }
            if ($rowCount < $batchSize) {
                break;
            }
        }
        return $handled;
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

        // Bound the SELECT with a LIMIT and loop until exhausted so the orphaned
        // match set never loads into PHP memory all at once. Each handled row is
        // deleted, so it drops out of the next batch's match set.
        $runBatch = function (int $batchSize) use ($query): array {
            $results = $this->dbCore->queryAndGetResults($query . " LIMIT " . (int)$batchSize);
            return is_array($results['rows'] ?? null) ? $results['rows'] : array();
        };
        $handleRow = function (array $row): void {
            $id = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0';
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $this->logger->debugMessage('Orphaned auto redirect deleted: "' . $url . '" (dest post ' .
                (isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '?') . ' missing/unpublished).');
            $this->redirectsRepo->deleteRedirect($id);
        };

        return $this->runBatchedCleanup($runBatch, $handleRow);
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

        // Bound the SELECT with a LIMIT and loop until exhausted so the unused
        // redirect match set never loads into PHP memory all at once. Each
        // handled row is deleted, so it drops out of the next batch's match set.
        $runBatch = function (int $batchSize) use ($query): array {
            $results = $this->dbCore->queryAndGetResults($query . " LIMIT " . (int)$batchSize);
            return is_array($results['rows'] ?? null) ? $results['rows'] : array();
        };
        $handleRow = function (array $row) use ($debugMessageType): void {
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
        };

        return $this->runBatchedCleanup($runBatch, $handleRow);
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
        // allow-unbounded-select: LIMIT appended at runtime by runBatchedCleanup (batched); dedup collapses each url so the match set drains
        $query = "SELECT COUNT(id) as repetitions, url FROM {wp_abj404_redirects} GROUP BY url HAVING repetitions > 1 ";

        // Bound the duplicate-group SELECT with a LIMIT and loop until exhausted
        // so the full set of duplicated URLs never loads into PHP memory at
        // once. De-duplicating a URL collapses its repetitions to 1, so it drops
        // out of the next batch's HAVING repetitions > 1 match set.
        $runBatch = function (int $batchSize) use ($query): array {
            $result = $this->dbCore->queryAndGetResults($query . " LIMIT " . (int)$batchSize);
            return is_array($result['rows'] ?? null) ? $result['rows'] : array();
        };
        $handleRow = function (array $outerRow) use (&$rowsDeleted): void {
            $url = $outerRow['url'];

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
        };

        $this->runBatchedCleanup($runBatch, $handleRow);

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

        // allow-unbounded-select: LIMIT %d appended at runtime by runBatchedCleanup (batched cron cleanup)
        $sql = "SELECT id FROM `{$redirectsTable}`
             WHERE status = %d
               AND disabled = 0
               AND `timestamp` > 0
               AND `timestamp` < %d";

        // Each batch SELECTs the next LIMIT-bounded slice of still-enabled
        // expired auto-redirects, then trashes them. moveRedirectsToTrash sets
        // disabled = 1, so trashed rows drop out of the next batch's
        // `disabled = 0` filter and the loop advances without an offset. On a
        // batch that times out or errors, the closure returns array() so the
        // loop stops cleanly (preserving the original bail-on-error semantics
        // for the first batch).
        $runBatch = function (int $batchSize) use ($sql, $cutoff): array {
            $result = $this->dbCore->queryAndGetResults($sql . " LIMIT %d", array(
                'query_params' => array(ABJ404_STATUS_AUTO, $cutoff, (int)$batchSize),
            ));
            if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
                return array();
            }
            return is_array($result['rows'] ?? null) ? $result['rows'] : array();
        };
        $handleRow = function (array $row): void {
            $value = $row['id'] ?? reset($row);
            if ($value !== null && $value !== '') {
                $this->redirectsRepo->moveRedirectsToTrash(absint($value), 1);
            }
        };

        $moved = $this->runBatchedCleanup($runBatch, $handleRow);

        if ($moved > 0) {
            $this->logger->infoMessage("expireOldAutoRedirects: moved {$moved} expired auto-redirect(s) to trash (threshold: {$days} days).");
        }
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
