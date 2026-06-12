<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schedules staged view-build cron work and surfaces cron health notices.
 *
 * Keeps rebuild scheduling, stuck-cron measurement, and schedule-failure
 * notice payloads separate from the build-writer lock primitive.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
 */
class ABJ_404_Solution_ViewBuildCronScheduler extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Schedule the staged-build cron rebuild hook.
     *
     * @param int $delaySeconds
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if ($this->host->dataBoundary()->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->host->dataBoundary()->rebuildHealth()->mayStartExpensiveRebuild()) {
            $this->host->dataBoundary()->logger()->debugMessage(__FUNCTION__ . ' skipped because rebuild health gate is closed.');
            return;
        }
        $hook = ABJ_404_Solution_CronScheduler::HOOK_REBUILD_VIEW_DONE;
        $stuckHours = $this->getCronStuckHours();
        if ($stuckHours >= 24) {
            $this->setViewBuildCronStuckNotice($stuckHours);
        } elseif (function_exists('delete_transient')) {
            delete_transient('abj404_view_build_stuck_wp_cron_disabled');
        }
        $scheduled = abj_cron_scheduler()->scheduleSingleIfMissing(
            $hook,
            max(1, intval($delaySeconds))
        );
        if (!$scheduled) {
            $this->setViewBuildScheduleFailedNotice(abj_cron_scheduler()->lastFailureDetail());
        }
    }

    /**
     * @param int $hoursStuck how many hours the earliest overdue event has been waiting
     * @return void
     */
    public function setViewBuildCronStuckNotice(int $hoursStuck): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_build_stuck_wp_cron_disabled';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return;
        }
        $payload = array(
            'type'         => 'view_build_stuck_cron_disabled',
            'message_key'  => 'view.build_cron_stuck',
            'message_params' => array('hours_stuck' => $hoursStuck),
            'timestamp'    => abj_cron_scheduler()->now(),
            'error_string' => '',
        );
        // allow-cache-empty: intentional notice payload; error_string is empty by definition for cron-disabled state.
        set_transient($key, $payload, 86400);
    }

    /**
     * @return int hours since the earliest overdue WordPress cron event,
     *             or 0 when cron is healthy / cannot be inspected.
     */
    public function getCronStuckHours(): int {
        $ready = abj_cron_scheduler()->readyCronJobs();
        if (empty($ready)) {
            return 0;
        }
        $earliest = 0;
        foreach (array_keys($ready) as $ts) {
            $tsInt = (int) $ts;
            if ($tsInt > 0 && ($earliest === 0 || $tsInt < $earliest)) {
                $earliest = $tsInt;
            }
        }
        if ($earliest <= 0) {
            return 0;
        }
        $delta = abj_cron_scheduler()->now() - $earliest;
        if ($delta <= 0) {
            return 0;
        }
        return (int) floor($delta / 3600);
    }

    /**
     * @param string $detail
     * @return void
     */
    public function setViewBuildScheduleFailedNotice(string $detail): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_build_cron_schedule_failed';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return;
        }
        $payload = array(
            'type'         => 'view_build_schedule_failed',
            'message_key'  => 'view.build_schedule_failed',
            'message_params' => array('detail' => $detail),
            'timestamp'    => abj_cron_scheduler()->now(),
            'error_string' => $detail,
        );
        // allow-cache-empty: schedule-failure notice remains useful even when WP returns no detail string.
        set_transient($key, $payload, 86400);
    }
}
