<?php
/**
 * Coordinates database error response: applies notice and runtime-flag side
 * effects on top of focused error-classification collaborators.
 *
 * The pure error vocabulary lives in the four collaborators exposed via
 * accessor methods:
 *   - taxonomy(): string-pattern matchers (is*Error()).
 *   - stagedFailures(): staged-build failure policy (classifyStageFailure() etc).
 *   - tableInspector(): table name extraction + InnoDB engine probe.
 *   - prefixDiagnostics(): prefix-mismatch + multisite cross-prefix detection.
 *
 * This class itself owns only the side-effecting coordination: noting an
 * issue against notice state and runtime flags, gating writes via the quota
 * cooldown, and dispatching the infrastructure-error entry point that direct
 * wpdb sites use to bypass queryAndGetResults().
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
    private $stagedFailures;

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
        $this->stagedFailures = new ABJ_404_Solution_DatabaseStagedFailureClassifier($this->taxonomy);
        $this->tableInspector = new ABJ_404_Solution_DatabaseErrorTableInspector($logger);
        $this->prefixDiagnostics = new ABJ_404_Solution_DatabasePrefixDiagnostics($core, $logger);
    }

    /** @return ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy */
    public function taxonomy(): ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy {
        return $this->taxonomy;
    }

    /** @return ABJ_404_Solution_DatabaseStagedFailureClassifier */
    public function stagedFailures(): ABJ_404_Solution_DatabaseStagedFailureClassifier {
        return $this->stagedFailures;
    }

    /** @return ABJ_404_Solution_DatabaseErrorTableInspector */
    public function tableInspector(): ABJ_404_Solution_DatabaseErrorTableInspector {
        return $this->tableInspector;
    }

    /** @return ABJ_404_Solution_DatabasePrefixDiagnostics */
    public function prefixDiagnostics(): ABJ_404_Solution_DatabasePrefixDiagnostics {
        return $this->prefixDiagnostics;
    }

    /**
     * Classify and handle a host-side database issue from direct wpdb call
     * sites that bypass queryAndGetResults().
     *
     * @param string $errorText
     * @return bool True when the text matched an infrastructure error and
     *              notice-state side effects were applied.
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

    /**
     * Apply notice and runtime-flag side effects for a recognized
     * infrastructure-error string. Sets write-block / quota-cooldown runtime
     * flags so subsequent write attempts short-circuit, and registers a
     * plugin-admin notice describing the situation.
     *
     * @param string $errorText
     * @return void
     */
    public function noteDatabaseIssueFromError(string $errorText): void {
        if (trim($errorText) === '') {
            return;
        }
        if ($this->taxonomy->isDiskFullError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_disk_full_until', $this->core->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);

            $tableFull = stripos($errorText, 'table') !== false && stripos($errorText, 'is full') !== false;
            if ($tableFull) {
                $tableName = $this->tableInspector->extractTableNameFromFullError($errorText);
                if ($tableName !== null && $this->tableInspector->isInnoDBTable($tableName)) {
                    $this->core->setPluginDbNotice('disk_full', $this->core->noticeState()->localizeOrDefault('The InnoDB tablespace appears to be exhausted. Deleting plugin data will NOT free this space. Contact your hosting provider to expand the InnoDB tablespace (ibdata1).'), $errorText);
                    return;
                }
            }

            $this->core->setPluginDbNotice('disk_full', $this->core->noticeState()->localizeOrDefault('Database storage appears full (disk/engine space). Plugin write-heavy tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->taxonomy->isQuotaLimitError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_quota_cooldown_until', $this->core->clock()->now() + self::DB_QUOTA_COOLDOWN_SECONDS, self::DB_QUOTA_COOLDOWN_SECONDS);
            $this->core->setPluginDbNotice('query_quota', $this->core->noticeState()->localizeOrDefault('Database query quota was exceeded (for example max_questions). Non-essential plugin background tasks are temporarily paused.'), $errorText);
            return;
        }
        if ($this->taxonomy->isReadOnlyError($errorText)) {
            $this->core->noticeState()->markServerSideIssueNoted();
            $this->core->setRuntimeFlag('abj404_db_read_only_until', $this->core->clock()->now() + self::DB_WRITE_BLOCK_COOLDOWN_SECONDS, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
            $this->core->setPluginDbNotice('read_only', $this->core->noticeState()->localizeOrDefault('Database appears to be in read-only mode. Plugin write operations are temporarily paused.'), $errorText);
            return;
        }
        if ($this->taxonomy->isCollationError($errorText)) {
            $this->logger->debugMessage("Collation mismatch detected (auto-recovery will run): " . $errorText);
        }
    }

    /**
     * True when a prior quota-exceeded error is still inside its cooldown
     * window, so callers should skip non-essential queries.
     *
     * @return bool
     */
    public function isQuotaCooldownActive(): bool {
        $rawQuotaFlag = $this->core->getRuntimeFlag('abj404_db_quota_cooldown_until');
        $until = is_scalar($rawQuotaFlag) ? (int)$rawQuotaFlag : 0;
        return ($until > $this->core->clock()->now());
    }
}
