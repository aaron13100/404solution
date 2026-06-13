<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin-table storage-engine drift correction.
 *
 * Walks every plugin table and ALTERs anything that is not InnoDB back to InnoDB.
 * Plugin tables are uniformly InnoDB by design: crash-safe, no MyISAM "table is full"
 * (.MYI 4 GiB) failure mode, no table-level locking on high-traffic logsv2 writes.
 * Hosting migrations and manual SQL restores periodically revert tables to MyISAM;
 * this component is the centralised place that reverts them back.
 *
 * Reachable from {@see ABJ_404_Solution_DatabaseUpgradeSelfHeal::verifyAndRepairCurrentSite()}
 * and from {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance::runDatabaseMaintenanceTasks()}
 * via the coordinator delegate map.
 */
class ABJ_404_Solution_DatabaseUpgradeEngineNormalization extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @return void */
    function updateTableEngineToInnoDB() {
        $result = $this->viewRead->getTableEngines();
        $resultRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        if (empty($resultRows)) {
            return;
        }

        foreach ($resultRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tableName = array_key_exists('table_name', $row) ? (string)$row['table_name'] :
              (array_key_exists('TABLE_NAME', $row) ? (string)$row['TABLE_NAME'] : '');
            $engine = array_key_exists('engine', $row) ? (string)$row['engine'] :
              (array_key_exists('ENGINE', $row) ? (string)$row['ENGINE'] : '');

            // All plugin tables use InnoDB: crash-safe, no row-count ceiling, no table-level
            // locking. The former MyISAM special-case for logsv2 ("OPTIMIZE TABLE is slow
            // otherwise") no longer applies -- OPTIMIZE TABLE on InnoDB has been equivalent to
            // ALTER TABLE ... ENGINE=InnoDB since MySQL 5.6 (rebuilds tablespace in-place).
            // InnoDB also eliminates the MyISAM-specific "table is full" failure mode on sites
            // with disk pressure (MyISAM .MYI files cannot grow past 4 GiB by default).
            if (strtolower($engine) === 'innodb') {
                continue;
            }

            $this->logger->infoMessage("Updating " . $tableName . " to InnoDB.");
            $query = 'alter table `' . $tableName . '` engine = InnoDB;';

            $result = $this->dbCore->queryAndGetResults($query, array("log_errors" => false));
            $this->logger->infoMessage("I changed an engine: " . $query);
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';

            if ($lastError !== '' &&
              strpos($lastError, 'Index column size too large') !== false) {

                // delete the indexes, try again, and create the indexes later.
                $this->upgrades()->schemaDiffUpgrade()->deleteIndexes($tableName);

                $this->dbCore->queryAndGetResults($query,
                  array("ignore_errors" => array("Unknown storage engine")));
                $this->logger->infoMessage("I tried to change an engine again: " . $query);
            }
        }
    }
}
