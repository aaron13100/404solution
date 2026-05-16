<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Source-mutation seam wrapping ABJ_404_Solution_MutationWatermark::bump().
 *
 * Every admin / REST / CLI / AJAX mutation that changes the redirects (or
 * logs) source data calls $this->dao->bumpMutationWatermark() so the
 * staged-build runner sees a higher counter than active_build_started_
 * watermark at the next stage boundary and aborts cleanly. This is the
 * single seam external callers reach into; the bump primitive itself
 * lives on the static ABJ_404_Solution_MutationWatermark class
 * (includes/MutationWatermark.php) so its atomic-INSERT contract stays
 * decoupled from the DAO's connection-aware retry harness.
 *
 * Phase 3 Cluster A (queue t_260516_131217_769) wires the 14 admin call
 * sites in PluginLogicTrait_AdminActions.php to this seam, paired with
 * the existing markViewDoneInvalidatedByAdminMutation() admin-visibility
 * seam. Phase 4 (queue c570) rewrites markViewDoneInvalidatedByAdmin
 * Mutation() to record mutation_watermark_observed_by_admin_action
 * against the watermark value rather than the unix-timestamp gate; until
 * then both signals fire and the admin-visibility contract stays pinned
 * by PostMutationViewDoneVisibilityTest.
 *
 * Why route through the DAO instead of calling the static method
 * directly. Unit-test DAO stubs (the anonymous classes in
 * AdminRedirectFormValidationTest, RegexAutoPromoteAdminSaveTest, and
 * siblings) don't initialize a real $wpdb; the static bump() would
 * fatal on its INSERT. The DAO seam lets every such stub provide a
 * one-line no-op override, mirroring the existing markViewDone
 * InvalidatedByAdminMutation stub pattern. The integration suite
 * (LocalMariaDBServer-backed AdminMutationEndToEndCharacterizationTest)
 * keeps the real bump in scope via the inherited DataAccess class.
 *
 * Lives in its own tiny trait, not on DataAccessTrait_ViewQueriesStaged,
 * so the staged-queries trait stays under the 1500-line ModularityTest
 * cap; the seam is conceptually the source-mutation counterpart to
 * DataAccessTrait_ViewBuildForceRestart.php (runner-owned) so a parallel
 * dedicated file is the right shape.
 *
 * @see docs/refactor-staged-view-build-watermark.md Phase 3.
 * @see includes/MutationWatermark.php for the atomic-INSERT body.
 */
trait ABJ_404_Solution_DataAccess_MutationWatermarkSeamTrait {

    /**
     * Bump the per-blog mutation watermark to signal "source redirect /
     * logs data changed". Returns the post-increment counter value (the
     * caller's own contribution, surfaced via $wpdb->insert_id per the
     * LAST_INSERT_ID(expr) trick in MutationWatermark::bump()), or 0
     * when the watermark class is not yet loaded (defensive guard for
     * cold-bootstrap paths that could hit this seam before the
     * autoloader has resolved MutationWatermark.php).
     *
     * @return int Post-increment counter value, or 0 if the primitive
     *             is unavailable.
     */
    public function bumpMutationWatermark(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        return ABJ_404_Solution_MutationWatermark::bump();
    }
}
