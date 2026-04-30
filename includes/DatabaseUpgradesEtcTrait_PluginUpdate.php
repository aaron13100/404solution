<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DatabaseUpgradesEtc_PluginUpdateTrait {


    /**
     * Migrate existing redirects from absolute paths to relative paths.
     * This is a one-time migration for upgrading from versions prior to 2.37.0.
     * Fixes Issue #24: Redirects now survive WordPress subdirectory changes.
     *
     * Uses a single atomic SQL UPDATE statement - no locks or transactions needed.
     *
     * @return array<string, mixed> Migration results with counts
     */
    function migrateURLsToRelativePaths() {
        global $wpdb;

        $abj404logging = abj_service('logging');

        // Get current WordPress subdirectory
        $homeURL = get_home_url();
        $urlPath = parse_url($homeURL, PHP_URL_PATH);

        if ($urlPath === false || $urlPath === null) {
            $urlPath = '';
        }

        $decodedPath = rawurldecode(rtrim($urlPath, '/'));
        $subdirectory = preg_replace('/[\x00-\x1F\x7F]/', '', $decodedPath);

        $results = array(
            'redirects_updated' => 0,
            'subdirectory' => $subdirectory,
            'errors' => array()
        );

        // Skip if WordPress is at domain root (no subdirectory)
        if (empty($subdirectory) || $subdirectory === '/') {
            $abj404logging->debugMessage("No subdirectory detected. Migration skipped.");
            return $results;
        }

        $startTime = microtime(true);
        $redirectsTable = $this->dao->getPrefixedTableName('abj404_redirects');

        $abj404logging->infoMessage("Migrating redirects table to relative paths...");

        // Single SQL UPDATE - atomic at database level, no transaction needed
        // Uses CHAR_LENGTH() for UTF-8 multibyte character safety
        $subdirectoryWithSlash = $subdirectory . '/';

        // canonical_url stays in lockstep with url — same CASE expression
        // applied to both columns so the captured-page JOIN keeps matching
        // logs_hits.requested_url after the path rewrite. Wrapped in
        // CONCAT('/', TRIM(BOTH '/' FROM ...)) to preserve the canonical form
        // (no leading-slash duplication, no trailing slash). The CASE WHEN
        // canonical_url IS NOT NULL guard avoids overwriting NULL rows
        // (post-upgrade, pre-backfill) which the daily backfill will fill.
        $canonicalCase = "CASE
                 WHEN url = %s OR url = %s THEN '/'
                 WHEN url LIKE %s THEN CONCAT('/', SUBSTRING(url, CHAR_LENGTH(%s) + 1))
                 ELSE url
             END";

        $updateQuery = $wpdb->prepare(
            "UPDATE {$redirectsTable}
             SET url = " . $canonicalCase . ",
                 canonical_url = CASE
                     WHEN canonical_url IS NULL THEN NULL
                     ELSE CONCAT('/', TRIM(BOTH '/' FROM (" . $canonicalCase . ")))
                 END
             WHERE url = %s OR url = %s OR url LIKE %s",
            $subdirectory,                                  // url CASE: exact match /blog
            $subdirectoryWithSlash,                         // url CASE: with slash /blog/
            $wpdb->esc_like($subdirectoryWithSlash) . '%', // url CASE: with path /blog/*
            $subdirectoryWithSlash,                         // url SUBSTRING length
            $subdirectory,                                  // canonical CASE: exact match /blog
            $subdirectoryWithSlash,                         // canonical CASE: with slash /blog/
            $wpdb->esc_like($subdirectoryWithSlash) . '%', // canonical CASE: with path /blog/*
            $subdirectoryWithSlash,                         // canonical SUBSTRING length
            $subdirectory,                                  // WHERE: exact match
            $subdirectoryWithSlash,                         // WHERE: with slash
            $wpdb->esc_like($subdirectoryWithSlash) . '%'  // WHERE: with path
        );

        // DAO-bypass-approved: One-shot path-relativization migration; already prepared (multi-line CASE with prefix args), wpdb->query for rows-affected return; tightly mocked in DatabaseMigrationTest
        $updateResult = $wpdb->query($updateQuery);

        // Check for errors
        if ($updateResult === false) {
            $results['errors'][] = "Failed to update redirects: " . $wpdb->last_error;
            if (!$this->dao->classifyAndHandleInfrastructureError($wpdb->last_error ?? '')) {
                $abj404logging->errorMessage("Migration failed: " . $wpdb->last_error);
            }
        } else {
            $results['redirects_updated'] = $updateResult;

            $duration = microtime(true) - $startTime;
            $abj404logging->infoMessage(sprintf(
                "Migrated %d redirects in %.4f seconds.",
                $results['redirects_updated'],
                $duration
            ));

            // Note: Log entries are intentionally NOT migrated for performance.
            // Historical logs with absolute paths are display-only and don't affect functionality.

            // Mark migration as complete
            update_option('abj404_migrated_to_relative_paths', '1');
            update_option('abj404_migration_results', $results);
            $abj404logging->infoMessage("Migration to relative paths completed successfully.");
        }

        return $results;
    }


    /** @return void */
    function updatePluginCheck() {

        $pluginInfo = $this->dao->getLatestPluginVersion();
        
        $shouldUpdate = $this->shouldUpdate($pluginInfo);
        
        if ($shouldUpdate) {
            $this->doUpdatePlugin($pluginInfo);
        }
    }
    
    /**
     * @param array<string, mixed> $pluginInfo
     * @return void
     */
    function doUpdatePlugin($pluginInfo) {

        $this->logger->infoMessage("Attempting update to " . $pluginInfo['version']);
        
        // do the update.
        if (!class_exists('WP_Upgrader')) {
        	$this->logger->infoMessage("Including WP_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->infoMessage("Including Plugin_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        if (!function_exists('show_message')) {
        	$this->logger->infoMessage("Including misc.php for update.");
        	require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->warn("There was an issue including the Plugin_Upgrader class.");
        	return;
        }
        if (!function_exists('show_message')) {
        	$this->logger->warn("There was an issue including the misc.php class.");
        	return;
        }
        
        $this->logger->infoMessage("Includes for update complete. Updating... ");
        
        ob_start();
        $upgrader = new Plugin_Upgrader();
        $upret = $upgrader->upgrade(ABJ404_SOLUTION_BASENAME);
        if ($upret) {
            $this->logger->infoMessage("Plugin successfully upgraded to " . $pluginInfo['version']);
        }
        $output = "";
        if (@ob_get_contents()) {
        	$output = @ob_get_contents();
        	@ob_end_clean();
        }
        if ($this->f->strlen(trim($output)) > 0) {
            $this->logger->infoMessage("Upgrade output: " . $output);
        }
        
        $activateResult = activate_plugin(ABJ404_NAME);
        if ($activateResult instanceof WP_Error) {
            $this->logger->errorMessage("Plugin activation error " .
                json_encode($activateResult->get_error_codes()) . ": " . json_encode($activateResult->get_error_messages()));
            
        } else {
            $this->logger->infoMessage("Successfully reactivated plugin after upgrade to version " .
                $pluginInfo['version']);
        }        
    }
    
    /**
     * @param array<string, mixed> $pluginInfo
     * @return bool
     */
    function shouldUpdate($pluginInfo) {


        $options = $this->logic->getOptions(true);
        $latestVersion = isset($pluginInfo['version']) && is_string($pluginInfo['version']) ? $pluginInfo['version'] : '';

        if (ABJ404_VERSION == $latestVersion) {
            $this->logger->debugMessage("The latest plugin version is already installed (" .
                    ABJ404_VERSION . ").");
            return false;
        }

        // don't overwrite development versions.
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return false;
        }
        
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, array('127.0.0.1', '::1', 'localhost'))) {
            $this->logger->infoMessage("Update narrowly avoided on localhost.");
            return false;
        }        
        
        // 1.12.0 becomes array("1", "12", "0")
        $myVersionArray = explode(".", ABJ404_VERSION);
        $latestVersionArray = explode(".", $latestVersion);

        // check the latest date to see if it's been long enough to update.
        $lastUpdated = isset($pluginInfo['last_updated']) && is_string($pluginInfo['last_updated']) ? $pluginInfo['last_updated'] : '';
        $lastReleaseDate = new DateTime($lastUpdated);
        $todayDate = new DateTime();
        $dateInterval = $lastReleaseDate->diff($todayDate);
        $daysDifference = $dateInterval->days;
        
        // if there's a new minor version then update.
        // only update if it was released at least 3 days ago.
        if ($myVersionArray[0] == $latestVersionArray[0] && 
        	$myVersionArray[1] == $latestVersionArray[1] && 
        	intval($myVersionArray[2]) < intval($latestVersionArray[2]) &&
        	$daysDifference >= 3) {
        		
            $this->logger->infoMessage("A new minor version is available (" . 
                    $latestVersion . "), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        $minDaysDifference = $options['days_wait_before_major_update'];
        if ($daysDifference >= $minDaysDifference) {
            $this->logger->infoMessage("The latest major version is old enough for updating automatically (" . 
                    $minDaysDifference . "days minimum, version " . $latestVersion . " is " . $daysDifference . 
                    " days old), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        return false;
    }
}
