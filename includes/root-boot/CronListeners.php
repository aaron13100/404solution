<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Secondary cron-action listeners for the plugin.
 *
 * Each function is a WP-Cron action callback that lazily loads Loader.php and
 * dispatches to the relevant service: log canonical-URL backfill, permalink
 * cache rebuild, n-gram cache rebuild, multisite network activation/upgrade
 * batches, the email digest, and the Google Search Console fetch/refresh.
 *
 * The add_action() registrations for ALL cron actions stay in 404-solution.php
 * so the entry-point self-heal audit discovers them; this file only defines the
 * callbacks. Each lazily loads Loader.php via ABJ404_FILE so the autoloader can
 * resolve the plugin classes they dispatch to.
 */
// allow-no-test-found: boot-time WP-Cron action callbacks wired via add_action in 404-solution.php; no same-named unit file. The cron listener dispatch and wiring are exercised in FeedbackTransportCronListenerTest and CronListenerServiceWiringTest.

if (!function_exists('abj404_dailyMaintenanceCronJobListener')) {
/** @return void */
function abj404_dailyMaintenanceCronJobListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $retentionService = abj_service('redirects_retention_service');
        $retentionService->deleteOldRedirectsCron();

        $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dbUpgrades->components()->dailyMaintenanceUpgrade()->runDatabaseMaintenanceTasks();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron maintenance failed', $e);
    }
}
}

if (!function_exists('abj404_updateLogsHitsTableListener')) {
/** @return void */
function abj404_updateLogsHitsTableListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $logsRepo = abj_service('logs_repository');
        $logsRepo->createRedirectsForViewHitsTable();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron logs/hits refresh failed', $e);
    }
}
}

if (!function_exists('abj404_repairCollationsListener')) {
/** Run schema-wide plugin-table collation correction outside foreground requests. @return void */
function abj404_repairCollationsListener(): void {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        abj_service('database_upgrades')->correctCollations();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron collation repair failed', $e);
    }
}
}

if (!function_exists('abj404_refreshStatusCountsListener')) {
/**
 * Recompute one status-count cache outside the foreground admin request.
 *
 * @param mixed $scope
 */
function abj404_refreshStatusCountsListener($scope): void {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        if (!is_string($scope)) {
            throw new \InvalidArgumentException('Status-count refresh scope must be a string.');
        }
        $viewReadService = abj_service('view_read_service');
        $viewReadService->refreshStatusCounts($scope);
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron status-count refresh failed', $e);
    }
}
}

if (!function_exists('abj404_sendQueuedReportListener')) {
/**
 * Cron handler for FeedbackTransport queued sends. Loads Loader.php so the
 * autoloader resolves ABJ_404_Solution_FeedbackTransport, then dispatches.
 *
 * @param string $uuid
 * @return void
 */
function abj404_sendQueuedReportListener($uuid) {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        ABJ_404_Solution_FeedbackTransport::handleQueuedSend(is_string($uuid) ? $uuid : '');
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron feedback transport failed', $e);
    }
}
}

if (!function_exists('abj404_logsv2CanonicalUrlBackfillListener')) {
/** @return void */
function abj404_logsv2CanonicalUrlBackfillListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dbUpgrades->components()->canonicalUrlBackfillUpgrade()->backfillLogsv2CanonicalUrl();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron log canonical URL backfill failed', $e);
    }
}
}
if (!function_exists('abj404_redirectsDenormBackfillListener')) {
/**
 * On-demand drain of the main redirects denorm backlog (dest_for_view,
 * published_status, logshits, last_used), armed by an admin redirect-table
 * render when legacy NULL dest_for_view rows exist. The sort-key drains run
 * immediately after so Destination/URL sort gates can open in the same deferred
 * pass once the main source columns are populated.
 *
 * @return void
 */
function abj404_redirectsDenormBackfillListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $denorm = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance()
            ->components()->redirectsDenormBackfillUpgrade();
        // One shared time budget across the three drains (see
        // runDeferredDenormBackfillPass): this cron listener and the
        // browser-triggered AJAX drain must not consume 3x the budget back to
        // back.
        $denorm->runDeferredDenormBackfillPass();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron redirects denorm backfill failed', $e);
    }
}
}
if (!function_exists('abj404_redirectsSortKeyBackfillListener')) {
/**
     * Drain of the redirects narrow sort-key columns (dest_sort_key /
     * url_sort_key). Light-path: the drains route through queryAndGetResults and
     * no-op on a missing/empty table, so a fresh-install or
     * deactivated-but-installed state degrades gracefully.
 *
 * @return void
 */
function abj404_redirectsSortKeyBackfillListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $sortKey = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance()
            ->components()->redirectsSortKeyBackfillUpgrade();
        // Shared time budget across both sort-key drains (see
        // runDeferredSortKeyBackfillPass) so a browser-triggered or cron pass
        // is capped at one budget, not two back to back.
        $sortKey->runDeferredSortKeyBackfillPass();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron redirects sort-key backfill failed', $e);
    }
}
}
if (!function_exists('abj404_updatePermalinkCacheListener')) {
/**
 * @param int $maxExecutionTime
 * @param int $executionCount
 * @return void
 */
function abj404_updatePermalinkCacheListener($maxExecutionTime, $executionCount = 1) {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
        $permalinkCache->updatePermalinkCache($maxExecutionTime, $executionCount);
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron permalink cache update failed', $e);
    }
}
}
if (!function_exists('abj404_rebuildNGramCacheListener')) {
/**
 * @param int $offset
 * @return void
 */
function abj404_rebuildNGramCacheListener($offset = 0) {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dbUpgrades->components()->nGramUpgrade()->rebuildNGramCacheAsync($offset);
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron ngram cache rebuild failed', $e);
    }
}
}
if (!function_exists('abj404_networkActivationListener')) {
/** @return void */
function abj404_networkActivationListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        ABJ_404_Solution_PluginLogicLifecycle::networkActivationCronHandler();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron network activation failed', $e);
    }
}
}
if (!function_exists('abj404_networkActivationBackgroundListener')) {
/** @return void */
function abj404_networkActivationBackgroundListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->components()->multiSiteUpgrade()->processMultisiteActivationBatch();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron multisite activation batch failed', $e);
    }
}
}
if (!function_exists('abj404_networkUpgradeBackgroundListener')) {
/** @return void */
function abj404_networkUpgradeBackgroundListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->components()->multiSiteUpgrade()->processMultisiteUpgradeBatch();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron multisite upgrade batch failed', $e);
    }
}
}
if (!function_exists('abj404_sendDigestCronListener')) {
/** @return void */
function abj404_sendDigestCronListener() {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();
        $emailDigest = new ABJ_404_Solution_EmailDigest($dao, $logger);
        $emailDigest->onCronSendDigest();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron email digest failed', $e);
    }
}
}

if (!function_exists('abj404_gscFetchCronListener')) {
/** Nightly cron: fetch GSC data and cache it. @return void */
function abj404_gscFetchCronListener(): void {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $gscLogger = ABJ_404_Solution_Logging::getInstance();
        $gsc = new ABJ_404_Solution_GoogleSearchConsole($gscLogger);
        $gsc->searchAnalytics()->fetchAndCacheGscData();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron GSC fetch failed', $e);
    }
}
}

if (!function_exists('abj404_gscBackgroundRefreshListener')) {
/** On-demand background refresh triggered when an admin views the Options tab with stale data. @return void */
function abj404_gscBackgroundRefreshListener(): void {
    try {
        require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
        $gscLogger = ABJ_404_Solution_Logging::getInstance();
        $gsc = new ABJ_404_Solution_GoogleSearchConsole($gscLogger);
        $gsc->searchAnalytics()->fetchAndCacheGscData();
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('Cron GSC background refresh failed', $e);
    }
}
}
