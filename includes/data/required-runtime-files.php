<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static lists of files required for a healthy runtime/plugin package.
 *
 * This is DATA, not logic: it is stored here (outside the entry point) so the
 * entry point stays small and so the SQL-integrity completeness test can read
 * the list directly rather than grepping the plugin's main file source.
 *
 * Paths are stored relative to the plugin's includes/ directory (and, for the
 * report schema, relative to the plugin root). abj404_get_required_runtime_files()
 * resolves them to absolute paths and merges in the dynamic classmap file list.
 *
 * Keys:
 *   - 'boot'   : boot-critical PHP and asset files, relative to includes/.
 *   - 'sql'    : SQL template files, relative to includes/ (i.e. "sql/foo.sql").
 *               A test (SqlFileIntegrityListCompletenessTest) verifies this list
 *               stays in sync with the actual files in includes/sql/.
 *   - 'root'   : files relative to the plugin root (not includes/).
 *
 * @return array{boot: array<int, string>, sql: array<int, string>, root: array<int, string>}
 */
// allow-no-test-found: static data file (returns boot/sql/root required-file lists, no logic); the lists are verified against the real on-disk files in SqlFileIntegrityListCompletenessTest and BootResilienceTest.
return array(
	'boot' => array(
		'Loader.php',
		'bootstrap.php',
		'bootstrap/CoreServiceRegistration.php',
		'bootstrap/DataServiceRegistration.php',
		'bootstrap/DomainServiceRegistration.php',
		'bootstrap/MatchingEngineServiceRegistration.php',
		'bootstrap/RuntimeServiceRegistration.php',
		'bootstrap/LegacyInstanceResolver.php',
		'bootstrap/service-locator.php',
		'classmap.php',
		'ajax/SupportRequest.js',
		'js/support-request-button.js',
		'js/support-request-transport.js',
		'js/support-request-modal-view.js',
		'js/diagnosticDataCard.js',
	),
	'sql' => array(
		'sql/correctLookupTableIssue.sql',
		'sql/createEngineProfilesTable.sql',
		'sql/createLogTable.sql',
		'sql/createLogsHitsPreAggTempTable.sql',
		'sql/createLogsHitsTempTable.sql',
		'sql/createLookupTable.sql',
		'sql/createNGramCacheTable.sql',
		'sql/createPermalinkCacheTable.sql',
		'sql/createRedirectConditionsTable.sql',
		'sql/createRedirectsTable.sql',
		'sql/createSpellingCacheTable.sql',
		'sql/createViewCacheTable.sql',
		'sql/deleteOldLogs.sql',
		'sql/getAdditionalPostData.sql',
		'sql/getLogRecords.sql',
		'sql/getLogsCount.sql',
		'sql/getDistinctLoggedUrls.sql',
		'sql/getLogsIDandURL.sql',
		'sql/getLogsIDandURLForAjax.sql',
		'sql/getMostUnusedRedirects.sql',
		'sql/getOrphanedAutoRedirects.sql',
		'sql/getPermalinkFromURL.sql',
		'sql/getPostsNeedingContentKeywords.sql',
		'sql/getPublishedCategories.sql',
		'sql/getPublishedCategoryCount.sql',
		'sql/getPublishedPagesAndPostsIDs.sql',
		'sql/getPublishedTagCount.sql',
		'sql/getPublishedTags.sql',
		'sql/getPublishedTermsByIds.sql',
		'sql/getRedirectsExport.sql',
		'sql/getRedirectsForViewTempTable.sql',
		'sql/importDataFromPluginRedirectioner.sql',
		'sql/insertPermalinkCache.sql',
		'sql/insertSpellingCache.sql',
		'sql/logsSetMinLogID.sql',
		'sql/migrateToNewLogsTable.sql',
		'sql/selectTableEngines.sql',
		'sql/updatePermalinkCache.sql',
		'sql/updatePermalinkCacheParentPages.sql',
	),
	'root' => array(
		'contracts/schemas/report.schema.json',
	),
);
