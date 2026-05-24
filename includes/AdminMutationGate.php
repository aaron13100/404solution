<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-mutation visibility gate.
 *
 * The single observable contract: after an admin clicks Save on the
 * redirects UI, the next AJAX fetch must NOT return a snapshot built
 * before the mutation, OR it must fall back to stale-serving once the
 * sanity timeout elapses (so a stuck cron does not block the admin
 * redirects screen forever).
 *
 * Wall-clock implementation (t_260523_224315_207). Two options drive the
 * gate:
 *
 *   - `wp_abj404_view_done_mutation_invalidated_at`: Unix timestamp the
 *     most recent admin mutation fired at. Written by
 *     {@see markViewDoneInvalidatedByAdminMutation()}; cleaned up by
 *     {@see clearAdminMutationGateOptions()} on build completion.
 *   - `wp_abj404_view_done_data_built_at`: Unix timestamp the data
 *     currently in `view_done` was produced. Written by
 *     {@see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait::markViewDoneBuildCompleted()}.
 *
 * Gate behaviour (pinned by `ViewDoneServeabilityCharacterizationTest`):
 *
 *   - `data_built_at >= invalidated_at` releases the gate (the snapshot
 *     was built after the admin mutation, so it covers the change).
 *   - Sanity bound: `time() - invalidated_at > VIEW_DONE_MUTATION_
 *     INVALIDATED_SANITY_SECONDS` releases the gate even when the build
 *     has not caught up, so a stuck cron / broken build cannot block the
 *     admin redirects screen indefinitely (fbc270d8 stale-serving
 *     fallback + the hard-stale admin notice take over).
 *
 * Why wall-clock and not the data signature. The signature primitive
 * (see {@see ABJ_404_Solution_MutationDataSignature}) is unordered:
 * it changes on every mutation but cannot answer "is the build's
 * signature equal-or-newer than the admin's observed signature?" once
 * other writers (captured-404 inserts, sibling admin actions) advance
 * the data between admin save and build start. Wall-clock comparison
 * sidesteps that entirely: the question becomes "did the build start
 * after the admin clicked save?", which is monotone and free of the
 * ordering problem.
 *
 * The previous Phase 4 implementation used a separate watermark counter
 * for ordering; see docs/design-lesson-watermark-overengineering.md for
 * why that mechanism was removed.
 *
 * @see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @method string getLowercasePrefix(...$arguments)
 * @method int viewDoneDataBuiltAt(...$arguments)
 * @method void invalidateViewDoneServeableCache(...$arguments)
 */
class ABJ_404_Solution_AdminMutationGate extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Unprefixed suffix of the wall-clock option recording when the most
     * recent admin redirect mutation fired. Written by
     * {@see markViewDoneInvalidatedByAdminMutation()} and cleared by
     * {@see clearAdminMutationGateOptions()} on every successful build
     * completion so the gate naturally relaxes after coverage.
     */
    public function viewDoneMutationInvalidatedAtOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_mutation_invalidated_at';
    }

    /**
     * Read the timestamp of the most recent admin redirect mutation, or
     * 0 when no mutation has been recorded since the last build
     * completion (option absent or cleared by
     * {@see clearAdminMutationGateOptions()}).
     */
    public function viewDoneMutationInvalidatedAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $val = get_option($this->viewDoneMutationInvalidatedAtOptionName(), 0);
        return is_scalar($val) ? max(0, intval($val)) : 0;
    }

    /**
     * True when the admin-mutation gate is currently blocking reads. The
     * gate fires when an admin-mutation timestamp is recorded, the
     * recorded time is within the sanity window, AND the data on disk
     * was built BEFORE the admin's mutation. Otherwise false (no gate
     * state, gate expired, or build already covers the mutation).
     *
     * Called from {@see viewDoneIsServeable()} as the sole admin-gate
     * check; the staged-queries trait does not consult any of the
     * underlying options directly.
     */
    public function adminMutationGateBlocks(): bool {
        $invalidatedAt = $this->viewDoneMutationInvalidatedAt();
        if ($invalidatedAt <= 0) {
            return false;
        }
        $sanity = ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS;
        if ($invalidatedAt <= time() - $sanity) {
            return false;
        }
        $builtAt = $this->viewDoneDataBuiltAt();
        return $builtAt < $invalidatedAt;
    }

    /**
     * Mark view_done as needing a fresh build because the admin just
     * mutated a redirect through the UI (add/edit/trash/delete). The
     * staged build runner observes that `data_built_at` is now behind
     * `invalidated_at` and either aborts an in-flight build (its
     * `MutationDataSignature` will reflect the row change) or completes
     * a build whose `data_built_at` covers the admin's mutation, which
     * relaxes the gate via {@see adminMutationGateBlocks()}.
     *
     * Differs from a plain mutation event (captured 404, bulk import):
     * admin actions need IMMEDIATE feedback, so the recorded timestamp
     * drives the stricter gate that pends the AJAX fetch until a
     * covering build completes. Non-admin mutations only need the
     * runner to detect a signature change at the next stage boundary;
     * they don't need to block reads in the meantime (fbc270d8 stale-
     * serving).
     */
    public function markViewDoneInvalidatedByAdminMutation(): void {
        if (!function_exists('update_option')) {
            return;
        }
        update_option($this->viewDoneMutationInvalidatedAtOptionName(), time(), false);
        $this->invalidateViewDoneServeableCache();
    }

    /**
     * Clear the admin-mutation gate options after a build covers the
     * recorded admin mutation. Called from
     * {@see markViewDoneBuildCompleted()} on the S11-success and
     * reconcile-promote paths.
     */
    public function clearAdminMutationGateOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        delete_option($this->viewDoneMutationInvalidatedAtOptionName());
    }
}
