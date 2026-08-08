<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composite database infrastructure error taxonomy.
 *
 * Holds three focused subsystem taxonomies and exposes them via accessor
 * methods. Owns no classification logic of its own; the union aggregator
 * `isInfrastructureSqlError()` is the only behavior at this layer.
 *
 *   connectivity()  - transport, timeout, packet, deadlock failures
 *                     (DatabaseConnectivityErrorTaxonomy)
 *   hostState()     - disk, quota, read-only, oom, access, galera,
 *                     set-statement privilege failures
 *                     (DatabaseHostStateErrorTaxonomy)
 *   schema()        - crashed/missing tables, view-build churn, invalid
 *                     data, collation failures
 *                     (DatabaseSchemaErrorTaxonomy)
 *
 * No database, transient, notice, or logging side effects.
 */

require_once __DIR__ . '/DatabaseConnectivityErrorTaxonomy.php';
require_once __DIR__ . '/DatabaseHostStateErrorTaxonomy.php';
require_once __DIR__ . '/DatabaseSchemaErrorTaxonomy.php';

// allow-no-test-found: covered through DatabaseErrorClassifier facade by tests/SuppressWpdbErrorsTest.php and tests/DataAccessRetrySemanticsTest.php / tests/DataAccessInfraErrorNoticeLifecycleTest.php
class ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy {

    public const QUERY_RETRY_NONE = 'none';
    public const QUERY_RETRY_IMMEDIATE = 'immediate';
    public const QUERY_RETRY_BACKOFF = 'backoff';

    /** @var ABJ_404_Solution_DatabaseConnectivityErrorTaxonomy */
    private $connectivity;

    /** @var ABJ_404_Solution_DatabaseHostStateErrorTaxonomy */
    private $hostState;

    /** @var ABJ_404_Solution_DatabaseSchemaErrorTaxonomy */
    private $schema;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->connectivity = new ABJ_404_Solution_DatabaseConnectivityErrorTaxonomy($functions);
        $this->hostState = new ABJ_404_Solution_DatabaseHostStateErrorTaxonomy($functions);
        $this->schema = new ABJ_404_Solution_DatabaseSchemaErrorTaxonomy($functions);
    }

    /** @return ABJ_404_Solution_DatabaseConnectivityErrorTaxonomy */
    public function connectivity(): ABJ_404_Solution_DatabaseConnectivityErrorTaxonomy {
        return $this->connectivity;
    }

    /** @return ABJ_404_Solution_DatabaseHostStateErrorTaxonomy */
    public function hostState(): ABJ_404_Solution_DatabaseHostStateErrorTaxonomy {
        return $this->hostState;
    }

    /** @return ABJ_404_Solution_DatabaseSchemaErrorTaxonomy */
    public function schema(): ABJ_404_Solution_DatabaseSchemaErrorTaxonomy {
        return $this->schema;
    }

    /**
     * Union aggregator: true when the error text matches any infrastructure
     * failure mode the plugin should react to (notice-state, retry, repair,
     * or write-block).
     *
     * @param string $errorText
     * @return bool
     */
    public function isInfrastructureSqlError(string $errorText): bool {
        return $this->hostState->isDiskFullError($errorText)
            || $this->hostState->isReadOnlyError($errorText)
            || $this->hostState->isQuotaLimitError($errorText)
            || $this->hostState->isOutOfMemoryError($errorText)
            || $this->schema->isInvalidDataError($errorText)
            || $this->schema->isCollationError($errorText)
            || $this->schema->isMissingPluginTableError($errorText)
            || $this->schema->isIncorrectKeyFileError($errorText)
            || $this->schema->isCrashedTableError($errorText)
            || $this->connectivity->isDeadlockOrLockTimeoutError($errorText)
            || $this->hostState->isGaleraConflictError($errorText)
            || $this->connectivity->isTransientConnectionError($errorText)
            || $this->connectivity->isQueryTimeoutError($errorText)
            || $this->connectivity->isCommandsOutOfSyncError($errorText)
            || $this->hostState->isAccessDeniedError($errorText);
    }

    /**
     * Classify retryable DAO errors once so observed-error suppression and the
     * recovery pipeline cannot drift into different string-family policies.
     *
     * @param string $errorText
     * @return array{strategy: string, branch: string, reason: string}
     */
    public function classifyQueryRetry(string $errorText): array {
        if ($this->connectivity->isTransientConnectionError($errorText)) {
            return array(
                'strategy' => self::QUERY_RETRY_IMMEDIATE,
                'branch' => 'transient_connection',
                'reason' => 'connection_lost',
            );
        }
        if ($this->hostState->isGaleraConflictError($errorText)) {
            return array(
                'strategy' => self::QUERY_RETRY_IMMEDIATE,
                'branch' => 'galera_cluster_state',
                'reason' => 'galera_retryable',
            );
        }
        if ($this->connectivity->isDeadlockOrLockTimeoutError($errorText)) {
            return array(
                'strategy' => self::QUERY_RETRY_BACKOFF,
                'branch' => 'deadlock',
                'reason' => 'deadlock_or_lock_timeout',
            );
        }
        return array(
            'strategy' => self::QUERY_RETRY_NONE,
            'branch' => '',
            'reason' => '',
        );
    }
}
