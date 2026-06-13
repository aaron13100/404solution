<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Foreground admin/AJAX lease for staged view builds.
 *
 * Cron checks this short-lived lease before taking the build lock so stage
 * diagnostics stay attached to the browser request that initiated the work.
 *
 */
class ABJ_404_Solution_ViewBuildForegroundLease extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        if (!function_exists('update_option')) { return; }
        update_option($this->host->dataBoundary()->getLowercasePrefix() . 'abj404_view_build_foreground_until',
            abj_clock()->now() + ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS, false);
    }

    /** @return bool */
    public function foregroundViewBuildLeaseActive(): bool {
        if (!function_exists('get_option')) { return false; }
        $until = get_option($this->host->dataBoundary()->getLowercasePrefix() . 'abj404_view_build_foreground_until', 0);
        return is_scalar($until) && intval($until) > abj_clock()->now();
    }
}
