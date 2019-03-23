<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], array($GLOBALS['abj404_whitelist']))) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DatabaseUpgradesEtc {

    /** Create the tables when the plugin is first activated. 
     * @global type $wpdb
     */
    function createDatabaseTables() {
        global $wpdb;
        global $abj404logging;
        
        $redirectsTable = $wpdb->prefix . "abj404_redirects";
        $logsTable = $wpdb->prefix . 'abj404_logsv2';
        $lookupTable = $wpdb->prefix . 'abj404_lookup';
        $permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
        $query = str_replace('{wp_abj404_permalink_cache}', $permalinkCacheTable, $query);
        ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
        $query = str_replace('{redirectsTable}', $redirectsTable, $query);
        ABJ_404_Solution_DataAccess::queryAndGetResults($query);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
        $query = str_replace('{wp_abj404_logsv2}', $logsTable, $query);
        ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
        $query = str_replace('{wp_abj404_lookup}', $lookupTable, $query);
        ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        
        // since 2.3.1. changed from fulltext to btree for Christos. https://github.com/aaron13100/404solution/issues/21
        $result = ABJ_404_Solution_DataAccess::queryAndGetResults("show create table " . $redirectsTable);
        // this encode/decode turns the results into an array from a "stdClass"
        $rows = $result['rows'];
        $row1 = array_values($rows[0]);
        $tableSQL = $row1[1];
        // if the column does not have btree then drop and recreate the index.
        if (!preg_match("/url.+ USING BTREE/i", $tableSQL)) {
            if (preg_match("/KEY.+url/i", $tableSQL)) {
                $query = "ALTER TABLE " . $redirectsTable . " DROP INDEX url";
                ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX url (`url`) USING BTREE";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table URL column to use a btree index.");
        }
        if (!preg_match("/final_dest.+ USING BTREE/i", $tableSQL)) {
            if (preg_match("/KEY.+final_dest/i", $tableSQL)) {
                $query = "ALTER TABLE " . $redirectsTable . " DROP INDEX final_dest";
                ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX final_dest (`final_dest`) USING BTREE";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table FINAL_DEST column to use a btree index.");
        }
        if (!preg_match("/status.+TINYINT\(1\)/i", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . "   CHANGE `status` `status` TINYINT(1) NOT NULL, \n" .
                    "  CHANGE `type` `type` TINYINT(1) NOT NULL, \n" .
                    "  CHANGE `code` `code` SMALLINT(3) NOT NULL, \n" .
                    "  CHANGE `disabled` `disabled` TINYINT(1) NOT NULL DEFAULT '0' \n"; 
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table STATUS column type to TINYINT.");
        }
        if (!preg_match("/url.+2048/i", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " CHANGE `url` `url` VARCHAR(2048)";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/final_dest.+2048/i", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " CHANGE `final_dest` `final_dest` VARCHAR(2048)";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        
        $result = ABJ_404_Solution_DataAccess::queryAndGetResults("show create table " . $logsTable);
        // this encode/decode turns the results into an array from a "stdClass"
        $rows = $result['rows'];
        $row1 = array_values($rows[0]);
        $tableSQL = $row1[1];
        // if the column does not have btree then drop and recreate the index. ""
        if (!preg_match("/requested_url.+ USING BTREE/i", $tableSQL)) {
            if (preg_match("/KEY.+requested_url/i", $tableSQL)) {
                $query = "ALTER TABLE " . $logsTable . " DROP INDEX requested_url";
                ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX requested_url (`requested_url`) USING BTREE";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/referrer.+DEFAULT NULL/i", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' CHANGE `referrer` `referrer` VARCHAR(512) NULL DEFAULT NULL';
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Changed referrer to allow null on " . $logsTable);
        }
        if (!preg_match("/requested_url_detail/i", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' ADD `requested_url_detail` varchar(512) DEFAULT NULL '
                    . 'after `requested_url` ';
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/username.+bigint/i", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' ADD `username` bigint(20) DEFAULT NULL '
                    . 'after `requested_url_detail` ';
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/country.+bigint/i", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' ADD `country` bigint(20) DEFAULT NULL '
                    . 'after `username` ';
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/username.+ USING BTREE/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX username (`username`) USING BTREE";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Added index for username on " . $logsTable);
        }
        if (!preg_match("/min_log_id.+ DEFAULT NULL/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD min_log_id BOOLEAN NULL DEFAULT NULL";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);

            // set the min_log_id for all logs entries that were created before the column was created.
            $abj404logging->infoMessage("Setting the min_log_id for the logs table: Begin.");
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
            $query = str_replace('{wp_abj404_logsv2}', $logsTable, $query);
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Setting the min_log_id for the logs table: Done.");
        }
        if (!preg_match("/KEY .+min_log_id/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX min_log_id (min_log_id)";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $abj404logging->infoMessage("Added index for min_log_id on " . $logsTable);
        }
        if (!preg_match("/requested_url.+2048/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `requested_url` `requested_url` VARCHAR(2048) ";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/requested_url_detail.+2048/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `requested_url_detail` `requested_url_detail` VARCHAR(2048) ";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        if (!preg_match("/dest_url.+2048/i", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `dest_url` `dest_url` VARCHAR(2048) ";
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
        
        $me = new ABJ_404_Solution_DatabaseUpgradesEtc();
        $me->correctCollations();
    }

    function correctCollations() {
        global $wpdb;
        global $abj404logging;
        
        $collationNeedsUpdating = false;
        
        $redirectsTable = $wpdb->prefix . "abj404_redirects";
        $logsTable = $wpdb->prefix . "abj404_logsv2";
        $lookupTable = $wpdb->prefix . "abj404_lookup";
        $permalinkCacheTable = $wpdb->prefix . "abj404_permalink_cache";
        $postsTable = $wpdb->prefix . 'posts';
        
        $abjTableNames = array($redirectsTable, $logsTable, $lookupTable, $permalinkCacheTable);

        // get the target collation
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
        $query = str_replace('{table_names}', "'" . $postsTable . "'", $query);
        $query = str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
        $results = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        $rows = $results['rows'];
        $row = $rows[0];
        $postsTableCollation = $row['table_collation'];
        $postsTableCharset = $row['character_set_name'];
        
        // check our own tables to see if they match.
        foreach ($abjTableNames as $tableName) {
            // get collations of our tables and a target table.
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
            $query = str_replace('{table_names}', "'" . $tableName . "'", $query);
            $query = str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
            $results = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $rows = $results['rows'];
            $row = $rows[0];
            $abjTableCollation = $row['table_collation'];
            
            if ($abjTableCollation != $postsTableCollation) {
                $collationNeedsUpdating = true;
                break;
            }
        }
        
        // if they match then we're done.
        if (!$collationNeedsUpdating) {
            return;
        }
        
        // if they don't match then update our tables to match teh target tables.
        $abj404logging->infoMessage("Updating collation from " . $abjTableCollation . " to " .
                $postsTableCollation);

        foreach ($abjTableNames as $tableName) {
            $query = "alter table {table_name} convert to charset " . $postsTableCharset . 
                    " collate " . $postsTableCollation;
            $query = str_replace('{table_name}', $tableName, $query);
            ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        }
    }
    
    function updatePlugin() {
        // I copied this from wordfence.
        
        global $abj404logging;
        global $abj404dao;
        
        $latestVersion = $abj404dao->getLatestPluginVersion();
        if (ABJ404_VERSION == $latestVersion) {
            $abj404logging->debugMessage("The latest plugin version is already installed (" . 
                    ABJ404_VERSION . ").");
            return;
        }
        
        // 1.12.0 becomes array("1", "12", "0")
        $myVersionArray = explode(".", ABJ404_VERSION);
        $latestVersionArray = explode(".", $latestVersion);
        
        // if there's a new major version then don't automatically upgrade.
        if ($myVersionArray[0] != $latestVersionArray[0] || $myVersionArray[1] != $latestVersionArray[1]) {
            $abj404logging->infoMessage("A new major version is available (" . 
                    $latestVersionArray . "), currently version " + ABJ404_VERSION . " is installed. "
                    . "Automatic updates are only for minor versions.");
            return;
        }
        
        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
            $abj404logging->infoMessage("There was an issue including the Plugin_Upgrader class.");
            return;
        }
        
        ob_start();
        $upgrader = new Plugin_Upgrader();
        $upret = $upgrader->upgrade(ABJ404_SOLUTION_BASENAME);
        if ($upret) {
            $abj404logging->infoMessage("Plugin successfully upgraded to: " . $latestVersion);
            
        } else if ($upret instanceof WP_Error) {
            $abj404logging->infoMessage("Plugin upgrade error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
        }
        $output = @ob_get_contents();
        @ob_end_clean();
        if (mb_strlen(trim($output)) > 0) {
            $abj404logging->infoMessage("Upgrade output: " . $output);
        }
        
        $activateResult = activate_plugin(ABJ404_NAME);
        if ($activateResult instanceof WP_Error) {
            $abj404logging->errorMessage("Plugin activation error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
            
        } else if ($activateResult == null) {
            $abj404logging->infoMessage("Successfully reactivated plugin after upgrade to version " . 
                $latestVersion);
        }
    }
}
