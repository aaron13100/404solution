<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility shell for the former staged view-build god collaborator.
 *
 * The real responsibilities now live in:
 * - ABJ_404_Solution_ViewBuildReadGateway
 * - ABJ_404_Solution_ViewBuildAdvanceCoordinator
 * - ABJ_404_Solution_ViewBuildStagePipeline
 * - ABJ_404_Solution_ViewBuildForegroundLease
 */
class ABJ_404_Solution_ViewQueriesStaged extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard();
    }
}
