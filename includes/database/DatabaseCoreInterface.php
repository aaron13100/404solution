<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseTableNameResolver.php';
require_once __DIR__ . '/DatabaseCollationHelper.php';

/**
 * Database component-access contract for callers that need core-owned
 * collaborators. Query-only callers should depend on
 * ABJ_404_Solution_DatabaseQueryInterface instead.
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
 * Table-name resolution, DDL introspection, existence checks, and column
 * listing also live on DatabaseTableNameResolver. Reach them through
 * DatabaseCore::tableNameResolver()->getPrefixedTableName(...) etc.
 *
 * Table and column collation lookup lives on DatabaseCollationHelper. Reach
 * it through DatabaseCore::collationHelper()->getColumnCollationString(...)
 * etc.
 *
 * Error classification, self-healing recovery, and clock injection live on
 * ABJ_404_Solution_DatabaseErrorRecoveryInterface, split across
 * DatabaseErrorClassifier (classifyAndHandleInfrastructureError,
 * isOutOfMemoryError via taxonomy()->hostState()), DatabaseTableRepairer
 * (repairTable, repairDuplicateIDs), and DatabaseCollationHelper
 * (scheduleCollationRecovery). Reach them via
 * DatabaseCore::errorClassifier()->...(), tableRepairer()->...(), and
 * collationHelper()->...(). Test clock injection goes through the
 * ServiceContainer 'clock' binding (see clock_injection_pattern.md).
 */
interface ABJ_404_Solution_DatabaseCoreInterface {
    /** @return ABJ_404_Solution_DatabaseTableNameResolver */
    public function tableNameResolver(): ABJ_404_Solution_DatabaseTableNameResolver;

    /** @return ABJ_404_Solution_DatabaseCollationHelper */
    public function collationHelper(): ABJ_404_Solution_DatabaseCollationHelper;
}
