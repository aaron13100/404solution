<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'rebuildNgramCache': schedules a background WP-cron job to
 * rebuild the n-gram spelling cache. Per-user rate limit via transient
 * (10-second window) so an impatient admin can't queue dozens of rebuilds.
 *
 * Behavior preserved verbatim from PluginLogicAdminActions::handlePluginAction
 * branch (covered by NgramCacheRebuildActionTest).
 */
class ABJ_404_Solution_RebuildNgramCacheHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    public function nonceAction(): string {
        return 'abj404_rebuildNgramCache';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $userId = get_current_user_id();
        $transientKey = 'abj404_ngram_rebuild_request_' . $userId;
        $recentRequest = get_transient($transientKey);

        if ($recentRequest) {
            return __('N-gram cache rebuild is already scheduled or in progress. Please wait for it to complete.', '404-solution');
        }

        // allow-cache-empty: timestamp marker throttles duplicate rebuild clicks; not a cached query payload.
        set_transient($transientKey, abj_clock()->now(), 10);

        $dbUpgrades = abj_service('database_upgrades');
        $scheduled = $dbUpgrades->components()->nGramUpgrade()->scheduleNGramCacheRebuild();

        if ($scheduled) {
            return __('N-gram cache rebuild has been scheduled and will run in the background. This may take several minutes on large sites. You can continue using the plugin normally.', '404-solution');
        }

        $nextScheduled = abj_cron_scheduler()->nextScheduled(
            ABJ_404_Solution_CronScheduler::HOOK_REBUILD_NGRAM_CACHE
        );
        if ($nextScheduled) {
            return __('N-gram cache rebuild is already scheduled or in progress. Please wait for it to complete.', '404-solution');
        }
        return __('Failed to schedule N-gram cache rebuild. Please try again or check your WordPress cron configuration.', '404-solution');
    }
}
