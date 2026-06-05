<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prefix drift guard for staged view-build writes.
 *
 * Captures `$wpdb->prefix` at S1 entry and verifies later stage entries still
 * target the same blog prefix before writing staged tables. This prevents a
 * mid-build `switch_to_blog()` from corrupting another blog's view tables.
 */
class ABJ_404_Solution_ViewBuildPrefixDriftGuard extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Captured `$wpdb->prefix` snapshot taken at S1 entry. Compared at every
     * subsequent stage entry to detect mid-build `switch_to_blog()` that
     * would otherwise let S2-S11 run against a different blog's tables and
     * silently corrupt the precomputed view (Codex finding #8).
     *
     * Authoritative for within-request detection: if a `switch_to_blog()`
     * happens mid-request, `$wpdb->prefix` changes but this property does
     * not (it lives on the singleton orchestrator). The companion option
     * `abj404_view_build_prefix_at_s1` provides cross-request persistence
     * (multisite options tables are per-blog, so the option naturally
     * isolates per-blog: a resume on the same blog finds its capture; a
     * resume after a between-request switch lands on a different options
     * table where current_stage is also 0 and re-runs S1 cleanly).
     *
     * Empty when no build is active. Cleared on S11 completion.
     *
     * @var string
     */
    private $prefixAtStageOne = '';

    /**
     * Option name used to persist the `$wpdb->prefix` captured at S1. Kept
     * deliberately NOT site-prefixed so that within a single request we can
     * still tell when `switch_to_blog()` has flipped `$wpdb->prefix` out
     * from under us: the option-key the get_option call computes does not
     * itself depend on the current prefix. WP's options table itself is
     * per-blog in multisite, which gives the cross-blog isolation we want
     * for the cross-request resume case for free.
     *
     * @return string
     */
    public function prefixAtStageOneOptionName(): string {
        return 'abj404_view_build_prefix_at_s1';
    }

    /**
     * Snapshot the current `$wpdb->prefix` so subsequent stage entries can
     * detect a mid-build `switch_to_blog()`. Called from runStagedBuildOnce
     * at S1 entry. Idempotent on repeated S1 runs (fresh start clears via
     * clearPrefixAtStageOne first, then captures the live prefix here).
     *
     * @return void
     */
    public function capturePrefixAtBuildStart(): void {
        global $wpdb;
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';
        $this->prefixAtStageOne = $prefix;
        if (function_exists('update_option')) {
            update_option($this->prefixAtStageOneOptionName(), $prefix, false);
        }
    }

    /**
     * Compare the live `$wpdb->prefix` against the snapshot taken at S1.
     * Returns true when they match (or no snapshot exists: fresh blog or
     * pre-S1). Returns false when a mismatch is detected, which is the
     * orchestrator's signal to halt the rebuild before S2-S11 writes
     * against a different blog's tables.
     *
     * Logic:
     *   - In-memory `$this->prefixAtStageOne` is authoritative when set.
     *     `switch_to_blog()` cannot flip an instance property, so any
     *     change in `$wpdb->prefix` after capture is a real mismatch.
     *   - Falls back to the persisted option for cross-request resumes
     *     (the in-memory capture starts empty on each request).
     *   - Empty captured value means S1 has never run on this blog (in
     *     multisite, options are per-blog: a fresh blog has no record
     *     of any past build) so treat as "nothing to verify".
     *
     * @return bool  False on mismatch (caller should halt the build).
     */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        global $wpdb;
        $current = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';

        if ($this->prefixAtStageOne !== '') {
            return $this->prefixAtStageOne === $current;
        }

        if (!function_exists('get_option')) {
            return true;
        }
        $captured = get_option($this->prefixAtStageOneOptionName(), '');
        if (!is_string($captured) || $captured === '') {
            return true;
        }
        return $captured === $current;
    }

    /**
     * Clear the captured S1 prefix so the next rebuild starts fresh.
     * Called after a successful S11 swap and from the explicit force
     * rebuild path in clearStagedBuildDegradedState().
     *
     * @return void
     */
    public function clearPrefixAtStageOne(): void {
        $this->prefixAtStageOne = '';
        if (function_exists('delete_option')) {
            delete_option($this->prefixAtStageOneOptionName());
        }
    }

    /**
     * Read-only accessor for diagnostic logging. Returns the in-memory
     * capture if present, otherwise the persisted option, otherwise ''.
     *
     * @return string
     */
    public function capturedPrefixForLog(): string {
        if ($this->prefixAtStageOne !== '') {
            return $this->prefixAtStageOne;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $captured = get_option($this->prefixAtStageOneOptionName(), '');
        return is_string($captured) ? $captured : '';
    }
}
