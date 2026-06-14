<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes one full rebuild of the admin view snapshot into `view_done`.
 *
 * This is the "how" of a rebuild: it drops any stale transient build tables,
 * creates a fresh build table, inserts redirects in bounded batches, applies
 * the staged SQL templates (posts, terms, home, external, special, hits),
 * indexes, then renames the freshly built table into place. It assumes the
 * caller already holds the writer lock and is responsible for recording
 * freshness afterward; this class owns no locking, scheduling, or freshness
 * state of its own.
 */
class ABJ_404_Solution_ViewDoneRebuildExecutor {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_ViewBuildTableNames */
    private $tableNames;
    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;
    /** @var int */
    private $stagedQueryTimeoutSeconds = 0;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ViewBuildTableNames $tableNames
     */
    public function __construct($dbCore, $f, $logger, ABJ_404_Solution_ViewBuildTableNames $tableNames) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
        $this->tableNames = $tableNames;
    }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo @return void */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->logsRepo = $logsRepo;
    }

    /** @param int $seconds @return void */
    public function setStagedQueryTimeout(int $seconds): void {
        $this->stagedQueryTimeoutSeconds = max(0, $seconds);
    }

    /**
     * Run a full rebuild into the build table and rename it into place. The
     * caller must hold the writer lock and record freshness afterward.
     *
     * @return void
     */
    public function run(): void {
        $this->dropTransientBuildTables();
        $this->createBuildTable();
        $this->runInsertRedirectsBatches();
        $this->runSqlTemplate('03_index_fd.sql', array(), true);
        $this->runIdRangeTemplate('04_update_posts.sql');
        $this->runIdRangeTemplate('05_update_terms.sql');
        $this->runSqlTemplate('06_update_home.sql', array(), false);
        $this->runSqlTemplate('07_update_external.sql', array(), false);
        $this->runSqlTemplate('08_update_special.sql', array(), false);
        if ($this->logsHitsTableExists()) {
            $collation = $this->dbCore->collationHelper()->getColumnCollationString($this->tableNames->logsHits(), 'requested_url');
            $collation = $collation !== '' ? $collation : 'utf8mb4_unicode_ci';
            $extra = array('{S9_COLLATION}' => $collation);
            $this->runSqlTemplate('09a_drop_hits_temp.sql', array(), false);
            $this->runSqlTemplate('09b_create_hits_temp.sql', $extra, false);
            $this->runSqlTemplate('09c_insert_hits_temp.sql', array(), false);
            $this->runSqlTemplate('09_update_hits.sql', $extra, false);
            $this->runSqlTemplate('09a_drop_hits_temp.sql', array(), false);
        }
        $this->runSqlTemplate('10_index_sort.sql', array(), true);
        if (function_exists('do_action')) {
            do_action('abj404_view_build_before_rename_swap');
        }
        $this->renameBuildToDone();
        $this->clearBuildProgressOptions();
    }

    /** @return void */
    public function renameBuildToDone(): void {
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->tableNames->viewDeleteme() . '`',
            array('log_errors' => false), 'drop stale view_deleteme');
        if ($this->tableNames->tableExists($this->tableNames->viewDone())) {
            $sql = 'RENAME TABLE `' . $this->tableNames->viewDone() . '` TO `' . $this->tableNames->viewDeleteme()
                . '`, `' . $this->tableNames->viewBuild() . '` TO `' . $this->tableNames->viewDone() . '`';
        } else {
            $sql = 'RENAME TABLE `' . $this->tableNames->viewBuild() . '` TO `' . $this->tableNames->viewDone() . '`';
        }
        $this->queryAndRequireSuccess($sql, array('log_errors' => true), 'rename view build into place');
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->tableNames->viewDeleteme() . '`',
            array('log_errors' => false), 'drop replaced view_done');
    }

    /** @return void */
    public function dropStaleViewDeleteme(): void {
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->tableNames->viewDeleteme() . '`',
            array('log_errors' => false), 'drop stale view_deleteme');
    }

    /** @return void */
    public function dropTransientBuildTables(): void {
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->tableNames->viewBuild() . '`',
            array('log_errors' => false), 'drop view_build');
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->tableNames->viewDeleteme() . '`',
            array('log_errors' => false), 'drop view_deleteme');
    }

    /** @return void */
    public function clearBuildProgressOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        foreach (array('started_at', 'current_stage', 'last_started_stage', 'last_completed_stage') as $name) {
            delete_option($this->tableNames->prefixedOption('abj404_view_build_' . $name));
        }
        delete_option($this->tableNames->prefixedOption('abj404_view_build_prefix_at_s1'));
    }

    /** @return void */
    private function runInsertRedirectsBatches(): void {
        $batchSize = $this->viewBuildBatchSize();
        $lo = 0;
        do {
            $result = $this->runSqlTemplate('02_insert.sql', array(
                '{LO_BOUND}' => (string)$lo,
                '{BATCH_SIZE}' => (string)$batchSize,
            ), false);
            $affected = isset($result['rows_affected']) && is_scalar($result['rows_affected'])
                ? intval($result['rows_affected']) : 0;
            if ($affected <= 0 || $affected < $batchSize) {
                break;
            }
            $nextLo = $this->queryScalar('SELECT COALESCE(MAX(id), 0) AS max_id FROM `' . $this->tableNames->viewBuild() . '`');
            if ($nextLo <= $lo) {
                break;
            }
            $lo = $nextLo;
        } while (true);
    }

    /** @param string $relativePath @return void */
    private function runIdRangeTemplate(string $relativePath): void {
        $batchSize = $this->viewBuildBatchSize();
        $maxId = $this->queryScalar('SELECT COALESCE(MAX(id), 0) AS max_id FROM `' . $this->tableNames->viewBuild() . '`');
        if ($maxId <= 0) {
            $this->runSqlTemplate($relativePath, array('{LO_BOUND}' => '0', '{HI_BOUND}' => (string)PHP_INT_MAX), false);
            return;
        }
        for ($lo = 0; $lo < $maxId; $lo += $batchSize) {
            $this->runSqlTemplate($relativePath, array(
                '{LO_BOUND}' => (string)$lo,
                '{HI_BOUND}' => (string)min($maxId, $lo + $batchSize),
            ), false);
        }
    }

    /**
     * @param string $relativePath
     * @param array<string, string> $extra
     * @param bool $tolerateDuplicateKey
     * @return array<string, mixed>
     */
    private function runSqlTemplate(string $relativePath, array $extra, bool $tolerateDuplicateKey): array {
        $path = __DIR__ . '/../sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_FileSystemService::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \RuntimeException('View SQL template missing or empty: ' . $relativePath); // allow-raw-error: unrecoverable plugin asset corruption
        }
        $sql = $this->dbCore->doTableNameReplacements($template);
        if (!empty($extra)) {
            $sql = str_replace(array_keys($extra), array_values($extra), $sql);
        }
        if (method_exists($this->f, 'doNormalReplacements')) {
            $sql = $this->f->doNormalReplacements($sql);
        }
        $queryOptions = $this->getStagedQueryOptionsForRead();
        if ($tolerateDuplicateKey) {
            // An interrupted prior rebuild can leave the transient build table
            // already carrying these indexes, so re-running ADD INDEX fails with
            // errno 1061 / "Duplicate key name". That is EXPECTED and recoverable
            // for control flow (the index we wanted exists), so suppress the
            // centralized error log / telemetry report for exactly that benign
            // string. $result['last_error'] is still populated, so the
            // tolerate-return check below keeps working, and genuine non-1061
            // failures still log and throw. Scoped to this tolerated call site
            // only: a duplicate key elsewhere may be a real bug (prod report #93,
            // tv503.com, 4.2.0, MySQL 5.7.23).
            $queryOptions['ignore_errors'] = array('Duplicate key name', 'errno: 1061');
        }
        $result = $this->dbCore->queryAndGetResults($sql, $queryOptions);
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            if ($tolerateDuplicateKey
                    && (stripos($err, 'Duplicate key name') !== false || stripos($err, 'errno: 1061') !== false)) {
                $this->logger->debugMessage($relativePath . ': index already exists, tolerated.');
                return $result;
            }
            throw new \RuntimeException('View SQL ' . $relativePath . ' failed: ' . $err); // allow-raw-error: includes database error for admin diagnostics
        }
        return $result;
    }

    /** @return void */
    private function createBuildTable(): void {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/createViewBuildTable.sql');
        $base = $this->dbCore->doTableNameReplacements(is_string($template) ? $template : '');
        if (trim($base) === '') {
            throw new \RuntimeException('createViewBuildTable.sql is empty or unreadable.'); // allow-raw-error: unrecoverable plugin asset corruption
        }
        $attempts = array($base, $base . ' ENGINE=MyISAM', $base . ' ENGINE=InnoDB');
        $lastError = '';
        foreach ($attempts as $sql) {
            $result = $this->dbCore->queryAndGetResults($sql, array('log_errors' => false));
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
            if ($lastError === '') {
                return;
            }
        }
        throw new \RuntimeException('Could not create view build table: ' . $lastError); // allow-raw-error: includes database error for admin diagnostics
    }

    /**
     * @param string $sql
     * @param array<string, mixed> $options
     * @param string $context
     * @return array<string, mixed>
     */
    private function queryAndRequireSuccess(string $sql, array $options, string $context): array {
        $result = $this->dbCore->queryAndGetResults($sql, $options);
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \RuntimeException($context . ' failed: ' . $err); // allow-raw-error: includes database error for admin diagnostics
        }
        return $result;
    }

    /** @param string $sql @return int */
    private function queryScalar(string $sql): int {
        $result = $this->dbCore->queryAndGetResults($sql, $this->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $row = $rows[0];
        $value = reset($row);
        return is_scalar($value) ? intval($value) : 0;
    }

    /** @return array<string, mixed> */
    private function getStagedQueryOptionsForRead(): array {
        return $this->stagedQueryTimeoutSeconds > 0 ? array('timeout' => $this->stagedQueryTimeoutSeconds) : array();
    }

    /** @return bool */
    private function logsHitsTableExists(): bool {
        if ($this->logsRepo instanceof ABJ_404_Solution_LogsRepository) {
            return (bool)$this->logsRepo->logsHitsTableExists();
        }
        return $this->tableNames->tableExists($this->tableNames->logsHits());
    }

    /** @return int */
    private function viewBuildBatchSize(): int {
        $size = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEFAULT_BATCH_SIZE;
        if (defined('ABJ404_VIEW_BUILD_BATCH_SIZE')) {
            $size = intval(ABJ404_VIEW_BUILD_BATCH_SIZE);
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_batch_size', $size);
            if (is_scalar($filtered)) {
                $size = intval($filtered);
            }
        }
        return max(1, $size);
    }
}
