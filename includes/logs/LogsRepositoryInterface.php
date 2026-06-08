<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogReadQueryInterface.php';
require_once __DIR__ . '/LogPrivacyInterface.php';
require_once __DIR__ . '/LogWriteInterface.php';
require_once __DIR__ . '/LogHitsRebuildInterface.php';
require_once __DIR__ . '/LogHitsLifecycleInterface.php';

/**
 * Aggregate type that bundles the five log subsystem sub-interfaces. New
 * code should depend on the narrowest sub-interface it actually uses; this
 * composite stays so DataAccess and existing typed callers keep compiling.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 3.
 */
interface ABJ_404_Solution_LogsRepositoryInterface extends
    ABJ_404_Solution_LogReadQueryInterface,
    ABJ_404_Solution_LogPrivacyInterface,
    ABJ_404_Solution_LogWriteInterface,
    ABJ_404_Solution_LogHitsRebuildInterface,
    ABJ_404_Solution_LogHitsLifecycleInterface {
}
