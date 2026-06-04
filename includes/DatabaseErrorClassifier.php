<?php
/**
 * Error classification facade, infrastructure issue handling, and DB notice
 * side effects for DataAccess.
 *
 * The string taxonomy, staged-build policy, table metadata inspection, and
 * prefix diagnostics live in focused collaborators. This class preserves the
 * public surface used by DatabaseCore, repair policy, and staged-build code.
 *
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseErrorClassifier {

    /** @var int Cooldown when DB query quota is exceeded. */
    const DB_QUOTA_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_QUOTA_COOLDOWN_SECONDS;
    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_WRITE_BLOCK_COOLDOWN_SECONDS;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy */
    private $taxonomy;

    /** @var ABJ_404_Solution_DatabaseStagedFailureClassifier */
    private $stagedFailureClassifier;

    /** @var ABJ_404_Solution_DatabaseErrorTableInspector */
    private $tableInspector;

    /** @var ABJ_404_Solution_DatabasePrefixDiagnostics */
    private $prefixDiagnostics;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $functions, $logger) {
        $this->core = $core;
        $this->logger = $logger;
        $this->taxonomy = new ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy($functions);
        $this->stagedFailureClassifier = new ABJ_404_Solution_DatabaseStagedFailureClassifier($this->taxonomy);
        $this->tableInspector = new ABJ_404_Solution_DatabaseErrorTableInspector($logger);
        $this->prefixDiagnostics = new ABJ_404_Solution_DatabasePrefixDiagnostics($core, $logger);
    }

    /**
     * Forward DatabaseCore infrastructure calls that remain owned by the core.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->core->$name(...$arguments);
    }

    /**
     * Classify and handle a host-side database issue from direct wpdb call
     * sites that bypass queryAndGetResults().
     *
     * @param string $errorText
     * @return bool
     */
    public function classifyAndHandleInfrastructureError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }

        if ($this->taxonomy->isInfrastructureSqlError($errorText)) {
            $this->logger->warn("Server-side DB issue (handled): " . $errorText);
            $this->noteDatabaseIssueFromError($errorText);
            return true;
        }

        return false;
    }

    /** @param mixed $errorText @return bool */
    public function isInvalidDataError($errorText): bool {
        return $this->taxonomy->isInvalidDataError($errorText);
    }

    /** @param string $errorText @return bool */
    public function classifySetStatementFailure(string $errorText): bool {
        return $this->taxonomy->classifySetStatementFailure($errorText);
    }

    /** @param string|null $errorText @return bool */
    public function isTransientConnectionError(?string $errorText): bool {
        return $this->taxonomy->isTransientConnectionError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isQuotaLimitError(string $errorText): bool {
        return $this->taxonomy->isQuotaLimitError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isDiskFullError(string $errorText): bool {
        return $this->taxonomy->isDiskFullError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isReadOnlyError(string $errorText): bool {
        return $this->taxonomy->isReadOnlyError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isAccessDeniedError(string $errorText): bool {
        return $this->taxonomy->isAccessDeniedError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isCollationError(string $errorText): bool {
        return $this->taxonomy->isCollationError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isCrashedTableError(string $errorText): bool {
        return $this->taxonomy->isCrashedTableError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isIncorrectKeyFileError(string $errorText): bool {
        return $this->taxonomy->isIncorrectKeyFileError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isQueryTimeoutError(string $errorText): bool {
        return $this->taxonomy->isQueryTimeoutError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isPacketTooLarge(string $errorText): bool {
        return $this->taxonomy->isPacketTooLarge($errorText);
    }

    /** @param string $errorText @return bool */
    public function isDeadlockOrLockTimeoutError(string $errorText): bool {
        return $this->taxonomy->isDeadlockOrLockTimeoutError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isGaleraConflictError(string $errorText): bool {
        return $this->taxonomy->isGaleraConflictError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isOutOfMemoryError(string $errorText): bool {
        return $this->taxonomy->isOutOfMemoryError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isMissingPluginTableError(string $errorText): bool {
        return $this->taxonomy->isMissingPluginTableError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isTransientViewBuildTableError(string $errorText): bool {
        return $this->taxonomy->isTransientViewBuildTableError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isInfrastructureSqlError(string $errorText): bool {
        return $this->taxonomy->isInfrastructureSqlError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isPermanentHostSideStagedFailure(string $errorText): bool {
        return $this->stagedFailureClassifier->isPermanentHostSideStagedFailure($errorText);
    }

    /**
     * Classify an error raised inside the staged view build.
     *
     * @param int $stageNumber
     * @param string $errorText
     * @return string
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->stagedFailureClassifier->classifyStageFailure($stageNumber, $errorText);
    }

    /** @param string $errorText @return bool */
    public function isResumableStagedKill(string $errorText): bool {
        return $this->stagedFailureClassifier->isResumableStagedKill($errorText);
    }

    /**
     * Extract a table name from a MySQL "table is full" error message.
     *
     * @param string $errorText
     * @return string|null
     */
    public function extractTableNameFromFullError(string $errorText): ?string {
        return $this->tableInspector->extractTableNameFromFullError($errorText);
    }

    /**
     * Check if a given table uses the InnoDB storage engine.
     *
     * @param string $tableName
     * @return bool
     */
    public function isInnoDBTable(string $tableName): bool {
        return $this->tableInspector->isInnoDBTable($tableName);
    }

    /**
     * Extract the table name from a MySQL "doesn't exist" error message.
     *
     * @param string $errorText
     * @return string
     */
    public function extractMissingTableNameFromError(string $errorText): string {
        return $this->tableInspector->extractMissingTableNameFromError($errorText);
    }

    /** @return string */
    public function diagnosePrefixMismatch(): string {
        return $this->prefixDiagnostics->diagnosePrefixMismatch();
    }

    /**
     * @param string $errorText
     * @return bool
     */
    public function isMultisiteCrossPrefixError(string $errorText): bool {
        return $this->prefixDiagnostics->isMultisiteCrossPrefixError($errorText);
    }

    /** @param string $errorText @return void */
    public function noteDatabaseIssueFromError(string $errorText): void {
        if (trim($errorText) === '') {
            return;
        }
        if ($this->isDiskFullError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_disk_full_until', $this->core->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);

            $tableFull = stripos($errorText, 'table') !== false && stripos($errorText, 'is full') !== false;
            if ($tableFull) {
                $tableName = $this->extractTableNameFromFullError($errorText);
                if ($tableName !== null && $this->isInnoDBTable($tableName)) {
                    $this->core->setPluginDbNotice('disk_full', $this->core->noticeState()->localizeOrDefault('The InnoDB tablespace appears to be exhausted. Deleting plugin data will NOT free this space. Contact your hosting provider to expand the InnoDB tablespace (ibdata1).'), $errorText);
                    return;
                }
            }

            $this->core->setPluginDbNotice('disk_full', $this->core->noticeState()->localizeOrDefault('Database storage appears full (disk/engine space). Plugin write-heavy tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isQuotaLimitError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_quota_cooldown_until', $this->core->clock()->now() + self::DB_QUOTA_COOLDOWN_SECONDS, self::DB_QUOTA_COOLDOWN_SECONDS);
            $this->core->setPluginDbNotice('query_quota', $this->core->noticeState()->localizeOrDefault('Database query quota was exceeded (for example max_questions). Non-essential plugin background tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isReadOnlyError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_read_only_until', $this->core->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
            $this->core->setPluginDbNotice('read_only', $this->core->noticeState()->localizeOrDefault('Database appears to be in read-only mode. Plugin write operations are temporarily paused.'), $errorText);
            return;
        }
        if ($this->isCollationError($errorText)) {
            $this->logger->debugMessage("Collation mismatch detected (auto-recovery will run): " . $errorText);
        }
    }

    /** @return bool */
    public function isQuotaCooldownActive(): bool {
        $rawQuotaFlag = $this->core->getRuntimeFlag('abj404_db_quota_cooldown_until');
        $until = is_scalar($rawQuotaFlag) ? (int)$rawQuotaFlag : 0;
        return ($until > $this->core->clock()->now());
    }
}
