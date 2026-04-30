<?php

if (!defined('ABSPATH')) {
    exit;
}

// Auto-generated classmap to avoid runtime glob() scans.
// Regenerate via scripts/generate-classmap.php (optional).

$base = dirname(__DIR__) . DIRECTORY_SEPARATOR;
return array(
    // Traits used by host classes via `use TraitName`.  Each host class
    // require_once's its trait files at the top of its source file, but under
    // ParaTest's 16-worker parallel runs Patchwork's stream wrapper can hit
    // fd saturation and silently fail to open a trait source — the autoloader
    // then needs to find the trait by name.  Without these entries the
    // autoloader returns null, the host class declaration hits "Trait not
    // found", and the test fails with no useful error.
    'ABJ_404_Solution_AjaxSecurityTrait' => $base . 'includes/ajax/AjaxSecurityTrait.php',
    'ABJ_404_Solution_DataAccess_ErrorClassificationTrait' => $base . 'includes/DataAccessTrait_ErrorClassification.php',
    'ABJ_404_Solution_DataAccess_LogsTrait' => $base . 'includes/DataAccessTrait_Logs.php',
    'ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait' => $base . 'includes/DataAccessTrait_LogsHitsRebuild.php',
    'ABJ_404_Solution_DataAccess_MaintenanceTrait' => $base . 'includes/DataAccessTrait_Maintenance.php',
    'ABJ_404_Solution_DataAccess_RedirectsTrait' => $base . 'includes/DataAccessTrait_Redirects.php',
    'ABJ_404_Solution_DataAccess_StatsTrait' => $base . 'includes/DataAccessTrait_Stats.php',
    'ABJ_404_Solution_DataAccess_ViewQueriesTrait' => $base . 'includes/DataAccessTrait_ViewQueries.php',
    'ABJ_404_Solution_DatabaseUpgradesEtc_MaintenanceTrait' => $base . 'includes/DatabaseUpgradesEtcTrait_Maintenance.php',
    'ABJ_404_Solution_DatabaseUpgradesEtc_NGramTrait' => $base . 'includes/DatabaseUpgradesEtcTrait_NGram.php',
    'ABJ_404_Solution_DatabaseUpgradesEtc_PluginUpdateTrait' => $base . 'includes/DatabaseUpgradesEtcTrait_PluginUpdate.php',
    'ABJ_404_Solution_DatabaseUpgradesEtc_TableRepairTrait' => $base . 'includes/DatabaseUpgradesEtcTrait_TableRepair.php',
    'ABJ_404_Solution_PluginLogicTrait_AdminActions' => $base . 'includes/PluginLogicTrait_AdminActions.php',
    'ABJ_404_Solution_PluginLogicTrait_ImportExport' => $base . 'includes/PluginLogicTrait_ImportExport.php',
    'ABJ_404_Solution_PluginLogicTrait_Lifecycle' => $base . 'includes/PluginLogicTrait_Lifecycle.php',
    'ABJ_404_Solution_PluginLogicTrait_PageOrdering' => $base . 'includes/PluginLogicTrait_PageOrdering.php',
    'ABJ_404_Solution_PluginLogicTrait_SettingsUpdate' => $base . 'includes/PluginLogicTrait_SettingsUpdate.php',
    'ABJ_404_Solution_PluginLogicTrait_UrlNormalization' => $base . 'includes/PluginLogicTrait_UrlNormalization.php',
    // SpellChecker and View traits use unprefixed names (legacy from earlier
    // refactor).  The test bootstrap autoloader is classmap-driven (no prefix
    // gate) so these resolve correctly when the host class's require_once
    // silently fails under fd pressure.
    'SpellCheckerTrait_CandidateFiltering' => $base . 'includes/SpellCheckerTrait_CandidateFiltering.php',
    'SpellCheckerTrait_LevenshteinEngine' => $base . 'includes/SpellCheckerTrait_LevenshteinEngine.php',
    'SpellCheckerTrait_PostListeners' => $base . 'includes/SpellCheckerTrait_PostListeners.php',
    'SpellCheckerTrait_URLMatching' => $base . 'includes/SpellCheckerTrait_URLMatching.php',
    'ViewTrait_Logs' => $base . 'includes/ViewTrait_Logs.php',
    'ViewTrait_Redirects' => $base . 'includes/ViewTrait_Redirects.php',
    'ViewTrait_RedirectsTable' => $base . 'includes/ViewTrait_RedirectsTable.php',
    'ViewTrait_Settings' => $base . 'includes/ViewTrait_Settings.php',
    'ViewTrait_Shared' => $base . 'includes/ViewTrait_Shared.php',
    'ViewTrait_Stats' => $base . 'includes/ViewTrait_Stats.php',
    'ViewTrait_UI' => $base . 'includes/ViewTrait_UI.php',
    'ABJ_404_Solution_Ajax_CrossPluginImporter' => $base . 'includes/ajax/Ajax_CrossPluginImporter.php',
    'ABJ_404_Solution_Ajax_Php' => $base . 'includes/ajax/Ajax_Php.php',
    'ABJ_404_Solution_Ajax_TrendData' => $base . 'includes/ajax/Ajax_TrendData.php',
    'ABJ_404_Solution_CrossPluginImporter' => $base . 'includes/CrossPluginImporter.php',
    'ABJ_404_Solution_GoogleSearchConsole' => $base . 'includes/GoogleSearchConsole.php',
    'ABJ_404_Solution_InternalLinkScanner' => $base . 'includes/InternalLinkScanner.php',
    'ABJ_404_Solution_Ajax_SettingsModeToggle' => $base . 'includes/ajax/Ajax_SettingsModeToggle.php',
    'ABJ_404_Solution_Ajax_SuggestionCompute' => $base . 'includes/ajax/Ajax_SuggestionCompute.php',
    'ABJ_404_Solution_Ajax_SuggestionPolling' => $base . 'includes/ajax/Ajax_SuggestionPolling.php',
    'ABJ_404_Solution_Ajax_TrashLink' => $base . 'includes/ajax/Ajax_TrashLink.php',
    'ABJ_404_Solution_DataAccess' => $base . 'includes/DataAccess.php',
    'ABJ_404_Solution_EmailDigest' => $base . 'includes/EmailDigest.php',
    'ABJ_404_Solution_DatabaseUpgradesEtc' => $base . 'includes/DatabaseUpgradesEtc.php',
    'ABJ_404_Solution_ErrorHandler' => $base . 'includes/ErrorHandler.php',
    'ABJ_404_Solution_FileSync' => $base . 'includes/php/FileSync.php',
    'ABJ_404_Solution_Functions' => $base . 'includes/Functions.php',
    'ABJ_404_Solution_FunctionsMBString' => $base . 'includes/php/FunctionsMBString.php',
    'ABJ_404_Solution_FunctionsPreg' => $base . 'includes/php/FunctionsPreg.php',
    'ABJ_404_Solution_FrontendRequestPipeline' => $base . 'includes/FrontendRequestPipeline.php',
    'ABJ_404_Solution_ImportExportService' => $base . 'includes/ImportExportService.php',
    'ABJ_404_Solution_Logging' => $base . 'includes/Logging.php',
    'ABJ_404_Solution_EngineProfileResolver' => $base . 'includes/EngineProfileResolver.php',
    'ABJ_404_Solution_Ajax_EngineProfiles' => $base . 'includes/ajax/Ajax_EngineProfiles.php',
    'ABJ_404_Solution_MatchingEngine' => $base . 'includes/MatchingEngine.php',
    'ABJ_404_Solution_MatchRequest' => $base . 'includes/MatchRequest.php',
    'ABJ_404_Solution_MatchResult' => $base . 'includes/MatchResult.php',
    'ABJ_404_Solution_ArchiveFallbackEngine' => $base . 'includes/engine/ArchiveFallbackEngine.php',
    'ABJ_404_Solution_CategoryTagMatchingEngine' => $base . 'includes/engine/CategoryTagMatchingEngine.php',
    'ABJ_404_Solution_ContentMatchingEngine' => $base . 'includes/engine/ContentMatchingEngine.php',
    'ABJ_404_Solution_SlugMatchingEngine' => $base . 'includes/engine/SlugMatchingEngine.php',
    'ABJ_404_Solution_UrlFixEngine' => $base . 'includes/engine/UrlFixEngine.php',
    'ABJ_404_Solution_SpellingMatchingEngine' => $base . 'includes/engine/SpellingMatchingEngine.php',
    'ABJ_404_Solution_TitleMatchingEngine' => $base . 'includes/engine/TitleMatchingEngine.php',
    'ABJ_404_Solution_NGramFilter' => $base . 'includes/NGramFilter.php',
    'ABJ_404_Solution_PermalinkCache' => $base . 'includes/PermalinkCache.php',
    'ABJ_404_Solution_PluginLogic' => $base . 'includes/PluginLogic.php',
    'ABJ_404_Solution_RedirectConditionEvaluator' => $base . 'includes/RedirectConditionEvaluator.php',
    'ABJ_404_Solution_RequestContext' => $base . 'includes/RequestContext.php',
    'ABJ_404_Solution_RestApiController' => $base . 'includes/RestApiController.php',
    'ABJ_404_Solution_WPCLICommands' => $base . 'includes/WPCLICommands.php',
    'ABJ_404_Solution_PostEditorIntegration' => $base . 'includes/PostEditorIntegration.php',
    'ABJ_404_Solution_PublishedPostsProvider' => $base . 'includes/PublishedPostsProvider.php',
    'ABJ_404_Solution_Privacy' => $base . 'includes/Privacy.php',
    'ABJ_404_Solution_ServiceContainer' => $base . 'includes/ServiceContainer.php',
    'ABJ_404_Solution_Clock' => $base . 'includes/Clock.php',
    'ABJ_404_Solution_SystemClock' => $base . 'includes/Clock.php',
    'ABJ_404_Solution_FrozenClock' => $base . 'includes/Clock.php',
    'ABJ_404_Solution_SetupWizard' => $base . 'includes/SetupWizard.php',
    'ABJ_404_Solution_ShortCode' => $base . 'includes/ShortCode.php',
    'ABJ_404_Solution_SlugChangeHandler' => $base . 'includes/SlugChangeHandler.php',
    'ABJ_404_Solution_SpellChecker' => $base . 'includes/SpellChecker.php',
    'ABJ_404_Solution_SystemPage' => $base . 'includes/SystemPage.php',
    'ABJ_404_Solution_SynchronizationUtils' => $base . 'includes/SynchronizationUtils.php',
    'ABJ_404_Solution_Timer' => $base . 'includes/Timer.php',
    'ABJ_404_Solution_UninstallModal' => $base . 'includes/UninstallModal.php',
    'ABJ_404_Solution_Uninstaller' => $base . 'includes/Uninstaller.php',
    'ABJ_404_Solution_UserRequest' => $base . 'includes/php/objs/UserRequest.php',
    'ABJ_404_Solution_View' => $base . 'includes/View.php',
    'ABJ_404_Solution_ViewUpdater' => $base . 'includes/ajax/ViewUpdater.php',
    'ABJ_404_Solution_View_Suggestions' => $base . 'includes/View_Suggestions.php',
    'ABJ_404_Solution_WPDBExtension_PHP5' => $base . 'includes/php/wordpress/WPDBExtension.php',
    'ABJ_404_Solution_WPDBExtension_PHP7' => $base . 'includes/php/wordpress/WPDBExtension.php',
    'ABJ_404_Solution_WPNotice' => $base . 'includes/php/objs/WPNotice.php',
    'ABJ_404_Solution_WPNotices' => $base . 'includes/php/wordpress/WPNotices.php',
    'ABJ_404_Solution_WPUtils' => $base . 'includes/php/wordpress/WPUtils.php',
    'ABJ_404_Solution_WordPress_Connector' => $base . 'includes/WordPress_Connector.php',
);
