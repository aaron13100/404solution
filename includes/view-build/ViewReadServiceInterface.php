<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewStatusCountsInterface.php';
require_once __DIR__ . '/ViewListReadInterface.php';
require_once __DIR__ . '/ViewSnapshotReadInterface.php';
require_once __DIR__ . '/ViewMetadataInterface.php';
require_once __DIR__ . '/ViewHitsLifecycleInterface.php';

/**
 * Aggregate type that bundles the five view-read sub-interfaces. New code
 * should depend on the narrowest sub-interface it actually uses; this
 * composite stays so DataAccess and existing typed callers keep compiling.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
interface ABJ_404_Solution_ViewReadServiceInterface extends
    ABJ_404_Solution_ViewStatusCountsInterface,
    ABJ_404_Solution_ViewListReadInterface,
    ABJ_404_Solution_ViewSnapshotReadInterface,
    ABJ_404_Solution_ViewMetadataInterface,
    ABJ_404_Solution_ViewHitsLifecycleInterface {
}
