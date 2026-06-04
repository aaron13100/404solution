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
 * @method string localizeOrDefaultViewBuildNotice(...$arguments)
 */
class ABJ_404_Solution_ViewBuildCronScheduler extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Schedule the staged-build cron rebuild hook.
     *
     * @param int $delaySeconds
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->rebuildHealth->mayStartExpensiveRebuild()) {
            $this->logger->debugMessage(__FUNCTION__ . ' skipped because rebuild health gate is closed.');
            return;
        }
        $hook = 'abj404_rebuildViewDone';
        $stuckHours = $this->getCronStuckHours();
        if ($stuckHours >= 24) {
            $this->setViewBuildCronStuckNotice($stuckHours);
        } elseif (function_exists('delete_transient')) {
            delete_transient('abj404_view_build_stuck_wp_cron_disabled');
        }
        $next = wp_next_scheduled($hook);
        if ($next !== false) {
            return;
        }
        $scheduled = wp_schedule_single_event(
            time() + max(1, intval($delaySeconds)),
            $hook,
            array(),
            true
        );
        $isError = (function_exists('is_wp_error') && is_wp_error($scheduled));
        if ($scheduled === false) {
            $this->setViewBuildScheduleFailedNotice('');
        } elseif ($isError) {
            $errMsg = '';
            if (is_object($scheduled) && method_exists($scheduled, 'get_error_message')) {
                $msg = $scheduled->get_error_message();
                $errMsg = is_string($msg) ? $msg : '';
            }
            $this->setViewBuildScheduleFailedNotice($errMsg);
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
        $template = $this->localizeOrDefaultViewBuildNotice(
            'WordPress cron does not appear to be running. The earliest overdue '
            . 'cron event has been waiting at least %d hours, so cron-dependent '
            . 'plugin features (staged view-build, daily cleanup, log updates, '
            . 'digest emails) are not advancing. To resolve: if DISABLE_WP_CRON '
            . 'is set in wp-config.php either remove it, or configure a system '
            . 'cron job that requests wp-cron.php periodically. To force the '
            . 'redirect view to rebuild right now in your browser (workaround '
            . 'while cron is broken), open the 404 Solution Redirects page '
            . 'with ?abj404_force_view_rebuild=1 appended to the URL.'
        );
        $payload = array(
            'type'         => 'view_build_stuck_cron_disabled',
            'message'      => sprintf($template, $hoursStuck),
            'timestamp'    => time(),
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
        if (!function_exists('wp_get_ready_cron_jobs')) {
            return 0;
        }
        $ready = wp_get_ready_cron_jobs();
        if (!is_array($ready) || empty($ready)) {
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
        $delta = time() - $earliest;
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
        $message = 'Scheduling the 404 Solution staged view-build cron event failed. '
            . 'The build will not advance in the background until this clears. '
            . 'This usually indicates the WordPress cron lock is held, the cron '
            . 'option is unwritable, or a custom cron implementation rejected '
            . 'the event. Check your hosting provider and any cron-replacement '
            . 'plugins. To force the redirect view to rebuild right now in your '
            . 'browser (workaround while cron scheduling is failing), open the '
            . '404 Solution Redirects page with ?abj404_force_view_rebuild=1 '
            . 'appended to the URL.';
        if ($detail !== '') {
            $message .= ' (' . $detail . ')';
        }
        $payload = array(
            'type'         => 'view_build_schedule_failed',
            'message'      => $this->localizeOrDefaultViewBuildNotice($message),
            'timestamp'    => time(),
            'error_string' => $detail,
        );
        // allow-cache-empty: schedule-failure notice remains useful even when WP returns no detail string.
        set_transient($key, $payload, 86400);
    }
}
