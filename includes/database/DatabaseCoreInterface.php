<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseQueryInterface.php';
require_once __DIR__ . '/DatabaseTableMetadataInterface.php';
require_once __DIR__ . '/DatabaseQueryBuilderInterface.php';
require_once __DIR__ . '/DatabaseErrorRecoveryInterface.php';
require_once __DIR__ . '/DatabaseRuntimeStateInterface.php';

/**
 * Aggregate type that bundles the five database-infrastructure sub-interfaces.
 * New code should depend on the narrowest sub-interface it actually uses;
 * this composite stays so every DAO and existing typed caller keeps
 * compiling.
 *
 * Every DAO module (RedirectsRepository, LogsRepository, etc.) receives a
 * DatabaseCore instance through this interface. It encapsulates:
 *   - The centralized error-handling query pipeline (queryAndGetResults)
 *   - Table-name resolution and DDL introspection
 *   - Query timeouts (engine-aware: MariaDB SET STATEMENT, MySQL hints)
 *   - Error classification and infrastructure-error recovery
 *   - Connection management and reconnection
 *   - Runtime flags, admin notices, and write-block detection
 */
interface ABJ_404_Solution_DatabaseCoreInterface extends
    ABJ_404_Solution_DatabaseQueryInterface,
    ABJ_404_Solution_DatabaseTableMetadataInterface,
    ABJ_404_Solution_DatabaseQueryBuilderInterface,
    ABJ_404_Solution_DatabaseErrorRecoveryInterface,
    ABJ_404_Solution_DatabaseRuntimeStateInterface {
}
