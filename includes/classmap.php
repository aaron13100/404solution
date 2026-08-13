<?php

if (!defined('ABSPATH')) {
    exit;
}

// Deterministic classmap, so the autoloader never has to glob() at runtime.
//
// Stored as "which classes live in which includes/ subdirectory" rather than
// as one path per class, because that is what the data actually is: 481 of the
// 488 entries are the same sentence repeated -- class ABJ_404_Solution_Foo
// lives in includes/<dir>/Foo.php. Writing that convention once, and listing
// only the handful of classes that break it, is what keeps this file
// navigable (and under the project's file-size limit) as the plugin grows.
//
// Adding a class: put its short name (no ABJ_404_Solution_ prefix) in the list
// for the directory that holds it. A class whose FILE is not named after it --
// several classes sharing one file, or a file renamed without its class --
// goes in $abj404ClassFileExceptions instead.
// A standalone class omitted from this map must be require_once'd by every production file that loads or statically references it.
//
// Grepping: search the short name (`grep -rn "SpellChecker" includes/classmap.php`).
// The fully prefixed class name appears here only for the exceptions.

$base = dirname(__DIR__) . DIRECTORY_SEPARATOR;

/**
 * Class short names by the includes/ subdirectory that holds them.
 * Each resolves to includes/<directory>/<ShortName>.php.
 *
 * @var array<string, array<int, string>> $abj404ClassesByDirectory
 */
$abj404ClassesByDirectory = array(
    // Includes the admin action handlers (strategy/registry pattern for
    // handlePluginAction dispatch).
    'admin' => array(
        'AdminAssetEnqueuer', 'SetupWizardPresenter', 'RedirectsTableColumns',
        'RedirectDestinationWarningPolicy', 'RedirectDestinationWarningContext',
        'RedirectDestinationLinkResolver', 'RedirectRowActionsPresenter', 'RedirectRowPresenter',
        'RedirectAddModalPresenter', 'RedirectsTablePagePresenter', 'AdminActionsDependencies',
        'AdminThemeManager', 'AdminRuntimeErrorNotice',
    ),
    'admin/actions' => array(
        'AdminActionHandlerInterface', 'AdminActionRegistry', 'UpdateOptionsHandler',
        'AddRedirectHandler', 'DeleteRedirectActionHandler', 'StatusLinkActionHandler',
        'TrashEmptier', 'LegacyImportActionHandler', 'EmptyRedirectTrashHandler',
        'EmptyCapturedTrashHandler', 'PurgeRedirectsHandler', 'RunMaintenanceHandler',
        'RebuildNgramCacheHandler', 'ClearSpellingCacheHandler', 'SaveGscSettingsHandler',
        'ImportFromPluginHandler', 'UndoRegexAutoPromoteHandler', 'BulkActionHandler',
        'TrashLinkActionHandler', 'EditRedirectHandler', 'RedirectFormResolver',
    ),
    'ajax' => array(
        'AjaxSecurityGate', 'AjaxRequestContractEnforcementPolicy',
        'AjaxRequestContractSchemaRepository', 'AjaxRequestContractSchemaValidator',
        'AjaxRequestContractValidator', 'AjaxFailureLogger', 'AjaxFailureDetailsBuilder',
        'Ajax_CrossPluginImporter', 'Ajax_Php', 'Ajax_UpdateOptions', 'Ajax_RunLazyBackfill',
        'Ajax_LoadGscSection', 'Ajax_ViewLogs', 'Ajax_RedirectDestinationAutocomplete',
        'Ajax_TrendData', 'Ajax_SettingsModeToggle', 'Ajax_RestoreDefaults', 'Ajax_PrivacyExport',
        'Ajax_PrivacyDelete', 'Ajax_SupportRequest', 'Ajax_SupportRequestPreview',
        'Ajax_SuggestionCompute', 'Ajax_SuggestionPolling', 'Ajax_TrashLink',
        'Ajax_EngineProfiles', 'Ajax_UninstallPrefs', 'AjaxAdminEndpointRegistrar',
        'AjaxClientReportBeaconResponder',
        'AjaxAdminEndpointSupport', 'AjaxResponseEmitter', 'Ajax_GetPaginationLinks',
        'AdminTableResponseParts',
        'Ajax_RefreshStatsDashboard', 'Ajax_RefreshHealthBar', 'Ajax_RefreshAdminNonces',
        'Ajax_CanaryLadder',
    ),
    'bootstrap' => array(
        'CoreServiceRegistration', 'DataServiceRegistration', 'DomainServiceRegistration',
        'MatchingEngineServiceRegistration', 'Abj404ServiceRegistrationContract',
        'RuntimeServiceRegistration', 'LegacyInstanceResolver',
    ),
    'core' => array(
        'PluginLogicAdminActions', 'PluginLogicImportExport', 'PluginLogicLifecycle',
        'PluginLogicPageOrdering', 'PluginLogicSettingsUpdate', 'PluginLogicUrlNormalization',
        'PluginLogicDefaults', 'InternalLinkScanner', 'LockOwnerStore',
        'PluginLogicVersionUpgrader', 'PluginLogicOptionsResolver', 'ErrorHandler',
        'FileSystemService', 'Functions', 'RegexHelper', 'RegexHelperMb', 'RegexHelperPreg',
        'PermalinkResolver', 'UrlEncoder', 'Sanitizer', 'QueryStringHelper', 'PostRef', 'UserRef',
        'SiteRef', 'PluginLogic', 'PluginLogicInterface', 'Privacy', 'ServiceContainer', 'Clock',
        'SiteTimezone', 'PluginReleaseChannel', 'SetupWizard', 'SynchronizationUtils', 'Timer',
        'WordPressHookRegistrar', 'WordPress_Connector', 'WordPressConnectorDependencies',
        'ReviewFeedback', 'RebuildHealthState',
    ),
    'database' => array(
        'DatabaseMetadataReader', 'DatabaseRuntimeState', 'DatabaseCoreInterface', 'DatabaseCore',
        'DatabaseConnectionManager', 'DatabaseErrorClassifier', 'DatabaseRepairPolicy',
        'DatabaseQueryRecoveryPolicy', 'DatabaseQueryTimeoutManager', 'DatabaseSqlErrorReporter',
        'DatabaseQueryInterface', 'DatabaseTableMetadataInterface',
        'DatabaseQueryBuilderInterface', 'DatabaseErrorRecoveryInterface',
        'DatabaseRuntimeStateInterface', 'DataAccessDependencies', 'DataAccess',
        'TrackingDatabaseCore', 'TableIndexDefinitions', 'TableReadinessGate', 'ShowIndexRowReader',
        'CreateTableIndexParser', 'IndexDefinitionComparator',
    ),
    'database/upgrades' => array(
        'DatabaseUpgradeCoordinator', 'DatabaseUpgradeComponent',
        'DatabaseUpgradeComponentRegistry', 'DatabaseUpgradeRuntimeState',
        'DatabaseUpgradeIndexes', 'DatabaseUpgradeEngineNormalization',
        'DatabaseUpgradeCollationDrift', 'DatabaseUpgradeSelfHeal',
        'DatabaseUpgradeCanonicalUrlBackfill', 'DatabaseUpgradeRedirectsDenormBackfill',
        'DatabaseUpgradeRedirectsSortKeyBackfill', 'DatabaseUpgradeRedirectsDenormReconcile',
        'DatabaseUpgradeDailyMaintenance', 'DatabaseUpgradeNGram',
        'DatabaseUpgradeNGramCacheInitializer', 'DatabaseUpgradePluginUpdate',
        'DatabaseUpgradeTableRepair', 'DatabaseUpgradeDropStagedViewTables',
        'DatabaseUpgradeOrphanAdoption', 'DatabaseUpgradeMultiSite', 'DatabaseUpgradeSchemaDiff',
        'DatabaseUpgradeBootstrap', 'DatabaseTableLowercaseRenamer', 'DatabaseTableDdlExecutor',
        'DatabaseUpgradesDependencies', 'DatabaseUpgradesEtc',
    ),
    'diagnostics' => array(
        'CrashBeacon', 'CrashBeaconStore', 'CrashBeaconReporter', 'AjaxRequestTrace',
        'AjaxTeardownRecorder',
        'AjaxCheckpointLogger', 'AjaxCheckpointBoundaryWriter', 'AjaxFrequentCheckpointWriter',
        'CheckpointRecordFactory', 'CheckpointIntentStore', 'CheckpointIntentCorrelation',
        'DurableOperationRecorder',
        'ActiveOperationBreadcrumbs', 'ActiveOperationBoundaryManifest',
        'CheckpointJournalWriter',
        'CheckpointJournalReader', 'RequiredCheckpointEvidence', 'RequiredCheckpointIdentityEvidence',
        'MalformedCheckpointEvidence',
        'DecisiveRecordManifest',
        'DecisiveRecordAuthorizationFamilies',
        'DecisiveRecordRenderFamilies',
        'DecisiveRecordRenderOptionIoFamilies',
        'DecisiveRecordStatusCountFamilies',
        'DecisiveRecordQueryFilterFamilies',
        'DecisiveRecordShutdownFamilies',
        'DecisiveRecordFailureFamilies',
        'AjaxRequestLedger', 'AjaxDiagnosticRequestPolicy',
        'AuthorizationLogTracer', 'PostAuthorizationFailureTracer',
        'TableRenderTranslationTracer', 'DiagnosticInternalHookObserver',
        'HookInstrumentationRegistration', 'HookInstrumentationRegistry',
        'DiagnosticAppendStream',
        'HookRegistryInspectionLedger', 'DiagnosticDirectoryResolver',
        'RenderOptionIoTracer',
        'RenderOptionIoOperationJournal',
        'DatabaseQueryPreflightTracer',
        'DatabaseQueryRecoveryTracer',
        'DatabaseQueryFilterTracer',
        'DetachAbResolutionTracer',
        'AjaxQueryTimeline', 'AjaxRowLoopProgress', 'CacheMetricsProbeTracer',
        'RateLimitOperationTracer', 'CacheOperationTraceSink',
        'HookCallbackIdentity', 'HookInstrumentationLifecycleTracer', 'HookCallbackInstrumenter',
        'OptionPersistenceTracer', 'RowRenderOperationTracer',
        'TableRendererPreludeTracer', 'TemplateFileReadTracer', 'RoutineLogTracer',
        'SortReadinessTracer', 'StatusCountOperationJournal', 'StatusCountsForegroundTracer',
        'ResponseControlFilterTracer',
        'ShutdownCallbackTracer',
        'InstrumentedObjectCache',
        'AjaxTraceJournal', 'AjaxStageDiagnostics',
        'ShutdownEnvironmentInventory', 'ShutdownTeardownBracket', 'DiagnosticJournalExcerpt',
        'DiagnosticJournalFileSelector',
        'DiagnosticClientVerdict', 'DiagnosticCollectionManifest', 'DiagnosticDirectoryProbe',
        'DiagnosticEvidencePriority', 'DiagnosticEvidenceBudget', 'DetachAbEvidence', 'CanaryReceiptEvidence',
        'FailingSessionEvidence',
        'DiagnosticRequestGroup',
        'HostFilesystemPressureProbe', 'HostPressureSampler', 'HostServerCounterProbe',
        'SameSiteRequestCensus', 'SameSiteCensusReading', 'SameSiteRequestRegistry',
        'StrandedRequestLedger',
        'RequestEnvironmentFingerprint', 'ClientBuildFingerprint', 'DiagnosticModuleManifest',
        'OpcacheGenerationProbe', 'BootWaypointRecorder',
        'ClientTransportReport', 'AjaxCanaryLadder', 'AjaxCanaryPayloadFactory',
        'AjaxCanaryReceiptParser', 'DebugLogFileStore', 'DebugLogReader',
        'DebugLogArchiveBuilder', 'DeveloperLogMailer', 'ToolsDiagnostics',
    ),
    'engine' => array(
        'ArchiveFallbackEngine', 'CategoryTagMatchingEngine', 'ContentMatchingEngine',
        'SlugMatchingEngine', 'OldPermalinkStructureEngine', 'UrlFixEngine',
        'SpellingMatchingEngine', 'TitleMatchingEngine',
    ),
    'feedback' => array(
        'FeedbackDatabaseIdentity', 'FeedbackDiagnosticsCollector', 'FeedbackEnvironmentExtras',
        'FeedbackPluginSettingsSnapshot',
        'MysqlServerStateProbe', 'PluginSchemaMetadataProbe', 'RollupFreshnessProbe',
        'FeedbackEnvironmentExtras_HostProbes',
        'FeedbackEnvironmentExtras_PlatformFingerprint',
        'FeedbackEnvironmentExtras_DebugLogSignatures', 'FeedbackSiteTokenStore',
        'SupportLogExcerpt', 'SupportEvidenceExcerpt', 'CanaryReceiptSupportSection',
        'FailingSessionSupportSection', 'StrandedRequestSupportSection',
        'DetachAbVerdictSupportSection',
        'FeedbackDsrClient', 'FeedbackReportUuid',
        'FeedbackRuntimeEnvironment', 'FeedbackWordPressInventory', 'SupportRequestButton',
        'FeedbackTransport', 'PayloadSchema', 'ReportPayloadJsonSchemaValidator',
        'ErrorEmailDedupeState', 'ErrorEmailBodyFormatter', 'PiiRedactor', 'OpaqueTokenClassifier',
        'RequestCredentialRedactor', 'SensitiveValueMask',
    ),
    'frontend' => array(
        'FrontendRequestPipeline', 'FrontendPipelineDependencies', 'FrontendLegacyAdapters',
        'FrontendRuntimeOptions', 'FrontendHitRecorder', 'FrontendAsyncSuggestionTrigger',
        'FrontendPipelineTrace', 'FrontendDbVersionRecovery', 'FrontendPipelineTelemetry',
        'RequestContext', 'PostEditorIntegration', 'PublishedPostsProvider', 'ShortCode',
        'SlugChangeHandler', 'SystemPage',
    ),
    'gsc' => array(
        'GscConfig', 'GscOAuthTokenStore', 'GscSearchAnalyticsClient', 'GscAdminSectionRenderer',
        'GoogleSearchConsole', 'GscOAuthHandler',
    ),
    'import' => array(
        'CrossPluginImporter', 'ForeignRedirectSourceReader', 'ForeignSourceQueryGateway',
        'ExportService', 'ImportService',
    ),
    'logs' => array(
        'LoggingCapabilityDiagnostics', 'LoggingFeedbackDispatcher', 'LoggingStateStore',
        'LoggingMessageWriter', 'RoutineLoggingBridge', 'LogTimestampFormatter', 'LogDebugModeResolver',
        'LogsMetricsReader', 'LogReadQueryInterface', 'LogPrivacyInterface', 'LogWriteInterface',
        'LogHitsRebuildInterface', 'LogHitsLifecycleInterface', 'LogsHitsRollupServiceInterface',
        'LogsHitsRollupService', 'LogsHitsTableRebuilder', 'LogsLookupRepository',
        'LogsPrivacyService', 'LogsReadQueries', 'LogsHitsDataPopulator',
        'LogsWriteRecoveryPolicy', 'LogsWriter', 'RedirectHitLogEntry', 'LogsRepositoryInterface',
        'LogsRepository', 'EmailDigest', 'Logging', 'TrackingLogsRepository',
    ),
    'matching' => array(
        'EngineProfileSaveRequest', 'MatchingEngineOrchestrator', 'RedirectCandidateEvaluator',
        'WordPressGuessFallback', 'EngineProfileResolver', 'MatchingEngine', 'MatchRequest',
        'MatchResult', 'OldPermalinkStructureResolver', 'PermalinkStructureCompiler',
        'OldPermalinkCandidateStructureProvider', 'OldPermalinkPostResolver',
    ),
    'ngram' => array(
        'NGramNetworkOptionStore', 'NGramCacheRebuildScheduler', 'NGramCacheRebuildBatchRunner',
        'NGramRebuildProgressState', 'NGramRescheduleFailureReport', 'NGramRebuildDrain',
        'NGramRebuildRetryPolicy', 'NGramRebuildRuntime',
        'NGramCacheSyncRebuilder',
        'NGramCacheReconciler', 'NGramTermCacheReconciler', 'NGramLastUpdatedEpochMigration',
        'NGramFilter',
        'NGramFilterCollaboratorResolver', 'TermNGramCoveragePolicy', 'TermCandidateSource',
        'NGramExtractor', 'NGramSimilarity', 'NGramCacheRepository', 'NGramCoveragePolicy',
        'NGramRebuilder', 'NGramRebuilderDependencies', 'NGramUsageTelemetry',
    ),
    'php' => array(
        'FileSync', 'MbStringAdapter', 'MbStringAdapterMb', 'MbStringAdapterPreg',
        'FunctionsMBString', 'FunctionsPreg',
    ),
    'php/objs' => array(
        'UserRequest', 'UserRequestParts', 'WPNotice',
    ),
    'php/wordpress' => array(
        'WPNotices', 'WPUtils',
    ),
    'policies' => array(
        'SettingsAdminExcludedPagesPolicy', 'SettingsBooleanModePolicy',
        'AdminStatusFallbackResolver',
        'SettingsNotificationPolicy', 'SetupWizardAnswerPolicy', 'SettingsRedirectPolicy',
        'SettingsRegexPatternPolicy', 'SettingsRetentionPolicy', 'SettingsSuggestionPolicy',
        'SettingsWordPressPolicy', 'PluginAdminAccessPolicy', 'ErrorTypeClassifier',
    ),
    'redirects' => array(
        'RedirectsBulkReader', 'RedirectLookupService', 'RedirectSpec',
        'RedirectsDenormChunkResolver', 'RedirectsSortKeyChunkStore', 'RedirectsDenormColumnSql',
        'RedirectsDenormMaintenanceService', 'RedirectsDenormContentHooks',
        'RedirectsRetentionPolicy', 'RedirectsCleanupRepository', 'RedirectDeadDestinationChecker',
        'RedirectsRetentionService', 'RedirectRow', 'RedirectUpdate', 'RedirectCanonicalUrl',
        'RedirectScheduleTimezone', 'ExistingRedirectLookup', 'AutoRedirectHandler',
        'RedirectExclusionPolicy', 'RedirectDispatcher', 'RedirectConditionEvaluator',
        'RegexAutoPromote', 'RegexDestinationTemplateValidator', 'RegexSourcePatternValidator',
        'RedirectWriteAdmissionPolicy',
    ),
    'repositories' => array(
        'ContentRepositoryInterface', 'PublishedContentLookupInterface', 'OldSlugLookupInterface',
        'TermUrlEnricher', 'InternalSourceEvidenceRepository', 'PublishedContentRepository',
        'PublishedTermsProvider', 'PermalinkCacheRepository', 'OldPermalinkStructureStore',
        'SpellingCacheRepository', 'OldSlugRepository', 'ContentRepository',
        'RedirectsRepositoryInterface', 'RedirectConditionsRepository', 'RedirectExportReader',
        'RedirectLookupRepository', 'RedirectRegexCacheStore', 'RedirectWriteService',
        'RedirectsRepository', 'ContentKeywordsRepository', 'PluginUpdateMetadataRepository',
        'PermalinkCache', 'ReviewStateRepository', 'NetworkSitesRepository', 'NextNetworkSite',
    ),
    'rest' => array(
        'RestApiResponsePresenter', 'RestApiRedirectMutationService', 'RestApiReadService',
        'RestApiController',
    ),
    'services' => array(
        'PerPageOptionUpdater', 'CronScheduler', 'RequestInputNormalizer',
        'RedirectDestinationSuggestionService', 'RedirectEngineLabeler', 'StatsRepositoryResolver',
        'RestApiRequestParser', 'FrontendSuggestionLocaleScope', 'ShortcodeRequestedUrlResolver',
        'ShortcodeUrlBarUpdater', 'SettingsModePreference', 'NotFoundResponseService',
        'NotFoundResponseDependencies', 'PreviousRequestCookieTracker', 'RequestIgnoreNormalizer',
        'RequestIgnoreNormalizerDependencies', 'ErrorDiagnosticsReporter',
        'AdminFatalErrorResponder', 'AjaxFatalErrorResponder', 'FatalErrorProcessor',
        'ImportCsvParser', 'ImportProgressStore', 'ImportRedirectRowProcessor',
        'ImportUploadValidator', 'OutputBufferDrain',
    ),
    'settings' => array(
        'SettingsFieldValidator', 'StorageOptionContracts', 'SuggestionTransient',
        'SuggestionDisplayOptions', 'SetupWizardOptionStore',
    ),
    // Includes the standalone classes SpellChecker delegates to (converted
    // from traits).
    'spelling' => array(
        'SpellURLMatcher', 'SpellPostListeners', 'SpellNGramPrefilter',
        'SpellCandidatePermalinkLookup', 'SpellSuggestionExclusionPolicy', 'SpellSuggestionScorer',
        'SpellLevenshteinEngine', 'SpellLevenshteinEngineDependencies',
        'SpellCandidateDistanceRanker', 'SpellCandidateFilter', 'SpellSuggestionShortcodeDetector',
        'StopWords', 'SpellChecker', 'SpellCheckerDependencies',
    ),
    'stats' => array(
        'StatusCountBuckets', 'StatusCountsMutationSync', 'StatusCountsRepository', 'RedirectHitCountHistogramRepository',
        'RedirectRowCountRepository', 'UnavailableStatsRepository', 'StatsRepositoryInterface',
        'StatsRefreshLock', 'StatsReadRepository', 'StatsDashboardSnapshotCache',
        'StatsDigestDataProvider', 'StatsRepository',
    ),
    'uninstall' => array(
        'UninstallModal', 'UninstallCollationSnapshotPresenter', 'UninstallDatabaseDefaultsReader',
        'UninstallDatabaseMetadataReader', 'UninstallDiagnostics', 'UninstallFeedbackEmail',
        'UninstallStatisticsReader', 'UninstallWordPressInventory', 'Uninstaller',
    ),
    // Includes the view components converted from the former ViewTrait_* family.
    'view' => array(
        'TableViewOptionsResolver', 'ViewComponent', 'RedirectDestinationOptionsPresenter',
        'RedirectEditFormPresenter', 'RedirectDestinationResolver', 'View_Logs', 'View_Redirects',
        'View_RedirectsTable', 'View_CapturedURLsTable', 'CapturedTableHeaderRenderer',
        'CapturedPageChromeRenderer', 'CapturedRowActionButtonsRenderer',
        'CapturedSourceEvidenceRenderer', 'View_RedirectForms', 'EditRedirectFormContext',
        'View_ListTableChrome', 'View_RedirectTypeUI', 'View_RedirectConditions',
        'View_AdminChrome', 'OptionsSectionView', 'View_OptionsPresenter', 'View_Settings',
        'View_SettingsSections', 'View_Shared', 'View_SimpleSettings', 'View_Stats', 'View_Tools',
        'View_UI', 'ShortcodeSuggestionAdminDebugPresenter', 'ShortcodeSuggestionsPresenter',
        'View', 'View_Suggestions',
    ),
    'view-build' => array(
        'ViewReadServiceInterface', 'ViewReadService', 'StatusCountsRefreshCoordinator',
        'ViewReadRuntimeState', 'ViewQueryPolicy', 'ViewQueryBuilder', 'RedirectsViewLiveResolver',
        'RedirectsDenormSchemaReadiness', 'RedirectsHitsRollupReader', 'ScoreThresholds',
        'ViewDiagnostics', 'ViewQueryExplainDiagnostics', 'ViewCacheInvalidator',
        'AdminViewReadCoordinator', 'ViewReadOutcome', 'HitsTableRebuildPolicy',
        'ViewQueryFailureException',
    ),
    'wpcli' => array(
        'WPCLICommandPresenter', 'WPCLIImportExportCommandService', 'WPCLIRedirectCommandService',
        'WPCLICommands',
    ),
);

/**
 * The classes whose file is not named after them: several classes declared in
 * one file, or a file whose name outlived a class rename. Paths are relative
 * to the plugin root.
 *
 * @var array<string, string> $abj404ClassFileExceptions
 */
$abj404ClassFileExceptions = array(
    'ABJ_404_Solution_Ajax_ServiceResolver' => 'includes/ajax/AjaxServiceResolver.php',
    'ABJ_404_Solution_Ajax_SearchFeedback' => 'includes/ajax/AjaxSearchFeedback.php',
    'ABJ_404_Solution_SystemClock' => 'includes/core/Clock.php',
    'ABJ_404_Solution_FrozenClock' => 'includes/core/Clock.php',
    'ABJ_404_Solution_RedirectHitCountHistogramQueryException' =>
        'includes/stats/RedirectHitCountHistogramRepository.php',
    'ABJ_404_Solution_TrackingLogsHitsRollupService' => 'includes/logs/TrackingLogsRepository.php',
    'ABJ_404_Solution_WPDBExtension_PHP5' => 'includes/php/wordpress/WPDBExtension.php',
    'ABJ_404_Solution_WPDBExtension_PHP7' => 'includes/php/wordpress/WPDBExtension.php',
);

$abj404Classmap = array();
foreach ($abj404ClassesByDirectory as $abj404Directory => $abj404ShortNames) {
    $abj404Prefix = $base . 'includes/' . $abj404Directory . '/';
    foreach ($abj404ShortNames as $abj404ShortName) {
        $abj404Classmap['ABJ_404_Solution_' . $abj404ShortName] =
            $abj404Prefix . $abj404ShortName . '.php';
    }
}
foreach ($abj404ClassFileExceptions as $abj404Class => $abj404RelativePath) {
    $abj404Classmap[$abj404Class] = $base . $abj404RelativePath;
}

return $abj404Classmap;
