<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent degraded-build state for host-side staged-build failures.
 *
 * Skip markers live outside the regular progress registry so normal redirect
 * edits do not re-arm denied DDL. Explicit force rebuild clears these markers
 * after the admin has fixed the host configuration.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @method ABJ_404_Solution_Clock clock()
 * @method void clearPrefixAtStageOne()
 * @method string getLowercasePrefix()
 * @method void setStagedBuildDegradedNotice(int $stageNumber, string $kind, string $errorText)
 */
class ABJ_404_Solution_ViewBuildHostFailureState extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * @param int $stageNumber
     * @return string Site-prefixed option name for the stage skip marker.
     */
    public function stageSkipOptionName(int $stageNumber): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_s' . $stageNumber . '_skipped';
    }

    /**
     * Read whether the named stage is permanently skipped on this site.
     *
     * @param int $stageNumber
     * @return bool
     */
    public function isStageMarkedSkipped(int $stageNumber): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $value = get_option($this->stageSkipOptionName($stageNumber), 0);
        return is_scalar($value) && intval($value) > 0;
    }

    /**
     * Mark an optional stage permanently skipped due to a host-side failure.
     *
     * @param int    $stageNumber
     * @param string $errorText Original $wpdb->last_error / exception message.
     * @return void
     */
    public function markStageSkippedForHostFailure(int $stageNumber, string $errorText): void {
        if (function_exists('update_option')) {
            update_option($this->stageSkipOptionName($stageNumber), $this->clock()->now(), false);
        }
        $this->setStagedBuildDegradedNotice($stageNumber, 'skipped', $errorText);
        $this->logger->warn(sprintf(
            '[staged] stage %d permanently skipped (host-side environmental '
            . 'constraint, will not retry until force rebuild). Reason: %s',
            $stageNumber,
            substr($errorText, 0, 240)
        ));
    }

    /**
     * Mark the build halted at a critical stage and open the dedup window.
     *
     * @param int    $stageNumber
     * @param string $errorText
     * @return void
     */
    public function markBuildHaltedForHostFailure(int $stageNumber, string $errorText): void {
        if (function_exists('set_transient')) {
            // allow-cache-empty: host-failure halt marker intentionally stores error context, not query data.
            set_transient(
                $this->buildHaltTransientKey(),
                array(
                    'stage' => $stageNumber,
                    'error' => $errorText,
                    'when'  => $this->clock()->now(),
                ),
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        }
        $this->setStagedBuildDegradedNotice($stageNumber, 'halted', $errorText);
        $this->logger->warn(sprintf(
            '[staged] critical stage %d halted (host-side environmental '
            . 'constraint, will not retry until force rebuild or 24h dedup '
            . 'window expires). Reason: %s',
            $stageNumber,
            substr($errorText, 0, 240)
        ));
    }

    /** @return string Transient key for the build-halted gate. */
    public function buildHaltTransientKey(): string {
        return 'abj404_view_build_halted';
    }

    /**
     * @return bool True when a prior tick halted the build and the dedup
     *              window has not expired.
     */
    public function isBuildHaltedForHostFailure(): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        $value = get_transient($this->buildHaltTransientKey());
        return is_array($value);
    }

    /**
     * Clear skip markers and the halt gate for an explicit force rebuild.
     *
     * @return void
     */
    public function clearStagedBuildDegradedState(): void {
        if (function_exists('delete_option')) {
            for ($s = 1; $s <= 11; $s++) {
                delete_option($this->stageSkipOptionName($s));
            }
        }
        if (function_exists('delete_transient')) {
            delete_transient($this->buildHaltTransientKey());
        }
        // A force rebuild explicitly restarts the pipeline; the captured
        // prefix is per-build, not per-host, so wipe it so fresh S1 recaptures.
        $this->clearPrefixAtStageOne();
    }
}
