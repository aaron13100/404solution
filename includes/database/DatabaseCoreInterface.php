<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseQueryInterface.php';
require_once __DIR__ . '/DatabaseTableMetadataInterface.php';

/**
 * Aggregate type that bundles the database-infrastructure sub-interfaces.
 * New code should depend on the narrowest sub-interface it actually uses;
 * this composite stays so every DAO and existing typed caller keeps
 * compiling.
 *
 * Every DAO module (RedirectsRepository, LogsRepository, etc.) receives a
 * DatabaseCore instance through this interface. It encapsulates:
 *   - The centralized error-handling query pipeline (queryAndGetResults)
 *   - Table-name resolution and DDL introspection
 *   - Query timeouts (engine-aware: MariaDB SET STATEMENT, MySQL hints)
 *   - Connection management and reconnection
 *
 * Runtime flags, plugin admin notices, and write-block detection live on
 * ABJ_404_Solution_DatabaseRuntimeStateInterface, implemented by
 * ABJ_404_Solution_DatabaseNoticeStateHolder. Reach them via
 * DatabaseCore::noticeState()->setRuntimeFlag(...) etc.
 *
 * The post-type/category SQL-list builders and SQL session preamble live on
 * ABJ_404_Solution_DatabaseQueryBuilderInterface, implemented by
 * ABJ_404_Solution_DatabaseTableNameResolver. Reach them via
 * DatabaseCore::tableNameResolver()->buildPostTypeSqlList(...) etc.
 *
 * Error classification, self-healing recovery, and clock injection live on
 * ABJ_404_Solution_DatabaseErrorRecoveryInterface, split across
 * DatabaseErrorClassifier (classifyAndHandleInfrastructureError,
 * isOutOfMemoryError via taxonomy()->hostState()), DatabaseTableRepairer
 * (repairTable, repairDuplicateIDs), and DatabaseCollationHelper
 * (recoverFromCollationMismatchAndRetry). Reach them via
 * DatabaseCore::errorClassifier()->...(), tableRepairer()->...(), and
 * collationHelper()->...(). Test clock injection goes through the
 * ServiceContainer 'clock' binding (see clock_injection_pattern.md).
 */
interface ABJ_404_Solution_DatabaseCoreInterface extends
    ABJ_404_Solution_DatabaseQueryInterface,
    ABJ_404_Solution_DatabaseTableMetadataInterface {
}
