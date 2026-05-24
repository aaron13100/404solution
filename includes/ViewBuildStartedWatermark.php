<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-build data-signature stamp machinery for the staged view-build
 * pipeline.
 *
 * Three persistent stamps, three lifetimes (carried forward from the
 * pre-removal watermark-counter implementation; only the underlying
 * source of "the value" changed from the counter primitive to
 * {@see ABJ_404_Solution_MutationDataSignature::current()}):
 *
 *   - active_build_started_watermark: written at S1 entry on a fresh
 *     build, cleared on S11 success and on every abort (signature-
 *     advanced gate, prefix-changed gate, force-restart primitive).
 *     Read at every stage boundary by
 *     {@see mutationWatermarkAdvancedSinceBuildStart}: a strict
 *     `current() !== stamp` comparison drives the abort decision. While
 *     this stamp exists, a build is in flight against the data signature
 *     it holds.
 *   - last_build_started_watermark: diagnostic sibling. Written at S1
 *     entry alongside the active stamp, RETAINED across every settle
 *     path. Answers "what was the most recent stamp we wrote, regardless
 *     of whether that build completed?"
 *   - built_watermark: the cross-build pre-image. Written ONLY at S11
 *     success and ONLY from the active stamp's value. Survives every
 *     fresh-start and abort cleanup. Currently retained for diagnostic /
 *     operator-visibility purposes; the admin gate no longer consults
 *     it (see {@see ABJ_404_Solution_AdminMutationGate}).
 *
 * All three stamps live OUTSIDE `$viewBuildProgressOptionNames` because
 * reads/writes route through {@see readWatermarkOption} /
 * {@see writeWatermarkOption} to preserve the absent-vs-0 distinction:
 * a fresh install whose data signature is 0 produces a legitimate stamp
 * of 0, which `readProgressOption` (clamping to >= 0) cannot distinguish
 * from "never stamped".
 *
 * Why a signature rather than the counter the previous implementation
 * used. The counter required a side table (`wp_abj404_mutation_
 * watermark`) and a hot-path upsert on every mutation. The signature
 * derives the same information from data already in
 * `wp_abj404_redirects` (one indexed `SELECT MAX(timestamp), COUNT(*)`).
 * See docs/design-lesson-watermark-overengineering.md.
 *
 * Composition. Mixed into `ABJ_404_Solution_DataAccess` alongside
 * {@see ABJ_404_Solution_ViewBuildHelpers} (the orchestrator's abort /
 * fresh-start / gate methods call into this trait via `$this->`).
 *
 * @see ABJ_404_Solution_MutationDataSignature
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @method string getLowercasePrefix(...$arguments)
 */
class ABJ_404_Solution_ViewBuildStartedWatermark extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Unprefixed option-name suffix for the S11-completion data-signature
     * stamp. Lives on its own (NOT in `$viewBuildProgressOptionNames`) so
     * it survives across builds: this is the published "what signature
     * did the LAST SUCCESSFUL view_done snapshot cover?" pre-image.
     * clearAllProgressOptions (called on abort and on fresh-start) must
     * NOT touch it.
     */
    public function builtWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_built_watermark';
    }

    /**
     * Option name for the in-flight build's S1-entry stamp. Cleared on
     * S11 success, on at-stage abort, and by `forceRestartViewBuild()`.
     */
    public function activeBuildStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_active_started_watermark';
    }

    /**
     * Option name for the diagnostic-only "most recent stamp" sibling.
     * Written at S1 entry alongside the active stamp, RETAINED across S11
     * success, abort, and force-restart so operators can see the value
     * regardless of completion.
     */
    public function lastBuildStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_last_started_watermark';
    }

    /**
     * Pre-rename option name (from before the active/last split). Read
     * via the migration fallback in {@see readActiveBuildStartedWatermark}
     * and deleted by every stamp / clear call so post-upgrade installs
     * converge to the new naming after one build cycle.
     */
    public function legacyStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_started_watermark';
    }

    /**
     * Raw watermark-option reader. Returns -1 when the option is absent,
     * the stored integer otherwise. The absent-vs-0 distinction matters
     * for the resume-preserves-stamp contract: a fresh install with no
     * mutations has signature=0, so a stamp of 0 is a legitimate stamped
     * value, NOT the "no stamp yet" sentinel.
     *
     * @param string $fullyPrefixedName Option name as built by
     *   activeBuildStartedWatermarkOptionName() / builtWatermarkOptionName().
     * @return int  -1 when absent; the stored integer otherwise.
     */
    public function readWatermarkOption(string $fullyPrefixedName): int {
        if (!function_exists('get_option')) {
            return -1;
        }
        $value = get_option($fullyPrefixedName, null);
        if ($value === null || $value === false) {
            return -1;
        }
        if (!is_scalar($value)) {
            return -1;
        }
        return intval($value);
    }

    /**
     * Raw watermark-option writer. Autoload=false so the per-build stamps
     * do not bloat the alloptions cache loaded on every WP page.
     */
    public function writeWatermarkOption(string $fullyPrefixedName, int $value): void {
        if (!function_exists('update_option')) {
            return;
        }
        update_option($fullyPrefixedName, $value, false);
    }

    /**
     * True when the live data signature is different from the pre-image
     * stamped at S1 entry of the in-flight build. Returns false when no
     * stamp exists (no in-flight build), when the signature primitive is
     * unavailable (UNAVAILABLE sentinel), or when current === stamp.
     *
     * Called at every stage boundary by the orchestrator. A true result
     * means an external caller mutated redirects while this build was
     * running, so the buffer it has assembled does not cover that
     * mutation. The orchestrator must abort and let the next tick
     * rebuild.
     */
    public function mutationWatermarkAdvancedSinceBuildStart(): bool {
        $started = $this->readActiveBuildStartedWatermark();
        if ($started < 0) {
            return false;
        }
        if (!class_exists('ABJ_404_Solution_MutationDataSignature')) {
            return false;
        }
        $current = ABJ_404_Solution_MutationDataSignature::current();
        if ($current === ABJ_404_Solution_MutationDataSignature::UNAVAILABLE) {
            return false;
        }
        return $current !== $started;
    }

    /**
     * Read the active stamp, falling back to the pre-rename legacy option
     * name when the new key is absent. The fallback covers the upgrade
     * window: an install whose previous PHP request stamped
     * `started_watermark` mid-flight and whose current request is the
     * first to run with the renamed code must still see the stamp at the
     * gate-check site. On a hit, we promote the legacy value into the
     * active key (so the next read is direct) and delete the legacy key
     * so convergence happens after one tick.
     *
     * @return int  -1 when neither key is set; the stamp value otherwise.
     */
    public function readActiveBuildStartedWatermark(): int {
        $value = $this->readWatermarkOption($this->activeBuildStartedWatermarkOptionName());
        if ($value >= 0) {
            return $value;
        }
        $legacy = $this->readWatermarkOption($this->legacyStartedWatermarkOptionName());
        if ($legacy < 0) {
            return -1;
        }
        $this->writeWatermarkOption($this->activeBuildStartedWatermarkOptionName(), $legacy);
        if (function_exists('delete_option')) {
            delete_option($this->legacyStartedWatermarkOptionName());
        }
        return $legacy;
    }

    /**
     * Stamp BOTH started-watermark fields at S1 entry of a fresh build.
     * Callers route through this from the orchestrator's `if ($stage < 1)`
     * block, which only fires on fresh starts -- a resuming tick has
     * `current_stage >= 1` and never re-enters this branch, so the stamps
     * written here remain the pre-image for every subsequent stage
     * boundary in the build.
     *
     * Always overwrites both. The S11-completion path and the abort
     * cleanup path clear `active_*` only; the diagnostic `last_*` is
     * retained for operator visibility and overwritten on the next S1
     * entry. Also deletes the legacy option name so a post-upgrade
     * install converges to the new naming.
     */
    public function stampStartedWatermarksAtS1Entry(): void {
        if (!class_exists('ABJ_404_Solution_MutationDataSignature')) {
            return;
        }
        $signature = ABJ_404_Solution_MutationDataSignature::current();
        if ($signature === ABJ_404_Solution_MutationDataSignature::UNAVAILABLE) {
            // The signature read failed (degraded wpdb, dropped table).
            // Skip the stamp; the next tick will retry. Stamping a fake
            // value would cause the abort gate to fire spuriously once
            // the real signature is readable again.
            return;
        }
        $this->writeWatermarkOption($this->activeBuildStartedWatermarkOptionName(), $signature);
        $this->writeWatermarkOption($this->lastBuildStartedWatermarkOptionName(), $signature);
        if (function_exists('delete_option')) {
            delete_option($this->legacyStartedWatermarkOptionName());
        }
    }

    /**
     * Delete the active stamp. Called from the abort path so the next
     * tick's fresh-start branch sees no leftover stamp from the aborted
     * run, and from the S11 success path so the just-completed build's
     * pre-image does not become the next build's pre-image. (S11 also
     * publishes built_watermark, which is the cross-build pre-image that
     * survives the clear.)
     *
     * Does NOT clear `last_build_started_watermark`: that stamp's whole
     * purpose is to survive both abort and success so operators can
     * observe "what signature did the most recent build attempt stamp?"
     * regardless of completion.
     *
     * Also deletes the legacy option name so post-upgrade installs
     * converge to the new naming after the first build settles.
     */
    public function clearActiveBuildStartedWatermark(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        delete_option($this->activeBuildStartedWatermarkOptionName());
        delete_option($this->legacyStartedWatermarkOptionName());
    }

    /**
     * Stamp built_watermark = active stamp at S11 success. Retained for
     * operator-visibility / diagnostics; the admin-mutation gate now
     * uses wall-clock comparison rather than signature ordering (see
     * {@see ABJ_404_Solution_AdminMutationGate}).
     *
     * Must be called BEFORE clearActiveBuildStartedWatermark() since the
     * read source disappears once the active stamp is cleared.
     * built_watermark itself lives outside the progress registry, so it
     * survives the post-S11 clears.
     */
    public function publishBuiltWatermarkFromActiveBuildStartedWatermark(): void {
        $started = $this->readActiveBuildStartedWatermark();
        if ($started < 0) {
            return;
        }
        $this->writeWatermarkOption($this->builtWatermarkOptionName(), $started);
    }
}
