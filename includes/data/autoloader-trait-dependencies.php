<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composition dependency map for the safe autoloader.
 *
 * Maps each "host" facade class to the list of collaborator classes whose
 * files must be present on disk before the host class is loaded. The autoloader
 * (includes/root-boot/Autoloader.php) consults this map so that a corrupt
 * install with a missing collaborator surfaces a degraded admin page instead of
 * an uncatchable compile-time fatal when the host class is required.
 *
 * This is DATA, not logic: it is stored here (outside the entry point) so the
 * entry point stays small and so completeness tests can read the map directly
 * rather than grepping the plugin's main file source.
 *
 * @return array<string, array<int, string>> host class name => collaborator class names
 */
// allow-no-test-found: static data file (returns a class-to-collaborator dependency map, no logic); its completeness against the real classes is asserted directly in AutoloaderTraitDependenciesCompletenessTest.
return array(
    'ABJ_404_Solution_View' => array(
        'ABJ_404_Solution_ViewComponent',
        'ABJ_404_Solution_View_Shared',
        'ABJ_404_Solution_View_UI',
        'ABJ_404_Solution_View_Stats',
        'ABJ_404_Solution_View_Settings',
        'ABJ_404_Solution_View_Redirects',
        'ABJ_404_Solution_View_RedirectsTable',
        'ABJ_404_Solution_View_CapturedURLsTable',
        'ABJ_404_Solution_View_RedirectForms',
        'ABJ_404_Solution_View_ListTableChrome',
        'ABJ_404_Solution_View_RedirectTypeUI',
        'ABJ_404_Solution_View_RedirectConditions',
        'ABJ_404_Solution_View_Logs',
    ),
    'ABJ_404_Solution_DataAccess' => array(
        'ABJ_404_Solution_DatabaseCore',
        'ABJ_404_Solution_ContentRepository',
        'ABJ_404_Solution_RedirectsRepository',
        'ABJ_404_Solution_LogsRepository',
        'ABJ_404_Solution_StatsRepository',
        'ABJ_404_Solution_ViewReadService',
    ),
    'ABJ_404_Solution_PluginLogic' => array(
        'ABJ_404_Solution_PluginLogicUrlNormalization',
        'ABJ_404_Solution_PluginLogicAdminActions',
        'ABJ_404_Solution_AdminActionsDependencies',
        'ABJ_404_Solution_PluginLogicImportExport',
        'ABJ_404_Solution_PluginLogicSettingsUpdate',
        'ABJ_404_Solution_PluginLogicPageOrdering',
        'ABJ_404_Solution_PluginLogicLifecycle',
    ),
    'ABJ_404_Solution_DatabaseUpgradesEtc' => array(
        'ABJ_404_Solution_DatabaseUpgradeCoordinator',
        'ABJ_404_Solution_DatabaseUpgradeComponent',
        'ABJ_404_Solution_DatabaseUpgradeComponentRegistry',
        'ABJ_404_Solution_DatabaseUpgradeNGram',
        'ABJ_404_Solution_DatabaseUpgradeEngineNormalization',
        'ABJ_404_Solution_DatabaseUpgradeCollationDrift',
        'ABJ_404_Solution_DatabaseUpgradeSelfHeal',
        'ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill',
        'ABJ_404_Solution_DatabaseUpgradeDailyMaintenance',
        'ABJ_404_Solution_DatabaseUpgradePluginUpdate',
        'ABJ_404_Solution_DatabaseUpgradeTableRepair',
        'ABJ_404_Solution_DatabaseUpgradeDropStagedViewTables',
        'ABJ_404_Solution_DatabaseUpgradeIndexes',
        'ABJ_404_Solution_DatabaseUpgradeOrphanAdoption',
        'ABJ_404_Solution_DatabaseUpgradeMultiSite',
        'ABJ_404_Solution_DatabaseUpgradeSchemaDiff',
    ),
    'ABJ_404_Solution_FeedbackTransport' => array(
        'ABJ_404_Solution_FeedbackEnvironmentExtras',
        'ABJ_404_Solution_FeedbackEnvironmentExtras_DbProbes',
        'ABJ_404_Solution_FeedbackEnvironmentExtras_HostProbes',
        'ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint',
        'ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures',
    ),
    'ABJ_404_Solution_DatabaseCore' => array(
        'ABJ_404_Solution_DatabaseConnectionManager',
        'ABJ_404_Solution_DatabaseQueryTimeoutManager',
        'ABJ_404_Solution_DatabaseErrorClassifier',
        'ABJ_404_Solution_DatabaseSqlErrorReporter',
    ),
);
