<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewBuildOrchestrationInterface.php';
require_once __DIR__ . '/ViewBuildEnvironmentProbeInterface.php';
require_once __DIR__ . '/ViewBuildStageStateInterface.php';
require_once __DIR__ . '/ViewBuildReadBridgeInterface.php';

/**
 * Aggregate type that bundles the four view-build sub-interfaces. New code
 * should depend on the narrowest sub-interface it actually uses; this
 * composite is preserved so existing typed callers (DataAccess delegate,
 * ViewBuildOrchestrator, public-surface and extraction tests) continue to
 * compile.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 7.
 */
interface ABJ_404_Solution_ViewBuildOrchestratorInterface extends
    ABJ_404_Solution_ViewBuildOrchestrationInterface,
    ABJ_404_Solution_ViewBuildEnvironmentProbeInterface,
    ABJ_404_Solution_ViewBuildStageStateInterface,
    ABJ_404_Solution_ViewBuildReadBridgeInterface {
}
