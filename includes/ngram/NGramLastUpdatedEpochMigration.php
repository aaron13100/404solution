<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts the n-gram cache `last_updated` column from a datetime/timestamp
 * type to bigint Unix epoch seconds, before the generic schema diff runs.
 *
 * A direct ALTER from datetime to bigint lets MySQL coerce
 * "2026-06-13 03:04:05" into a YYYYMMDDHHMMSS-style number, not an epoch. This
 * migration performs the conversion explicitly via a temporary epoch column so
 * the generic schema verifier never applies that unsafe implicit conversion.
 *
 * Pure schema/data-access concern: it owns no n-gram cache business logic and
 * is constructed on demand by {@see ABJ_404_Solution_DatabaseUpgradeNGram}.
 */
class ABJ_404_Solution_NGramLastUpdatedEpochMigration {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Ensure the n-gram cache `last_updated` column is bigint epoch seconds.
     *
     * @param mixed $tableName Physical n-gram cache table name.
     * @return bool True when the table is already safe for generic schema
     *              verification, or after conversion succeeds.
     */
    public function ensureEpochColumn($tableName): bool {
        $tableName = is_scalar($tableName) ? (string)$tableName : '';
        if ($tableName === '') {
            $this->logger->warn('Skipping n-gram last_updated epoch migration because the table name was empty.');
            return false;
        }

        $lastUpdatedColumn = $this->getColumnMetadata($tableName, 'last_updated');
        if ($lastUpdatedColumn === null) {
            $tempColumn = $this->getColumnMetadata($tableName, 'last_updated_epoch');
            if ($tempColumn !== null) {
                return $this->renameEpochTempColumn($tableName);
            }
            return true;
        }

        $type = strtolower($this->columnValue($lastUpdatedColumn, 'Type'));
        if (strpos($type, 'datetime') === false && strpos($type, 'timestamp') === false) {
            return true;
        }

        $tempColumn = $this->getColumnMetadata($tableName, 'last_updated_epoch');
        if ($tempColumn === null && !$this->runMigrationQuery(
            "ALTER TABLE {$tableName}
             ADD COLUMN `last_updated_epoch` bigint(20) NOT NULL DEFAULT 0
             COMMENT 'Last time N-grams were computed as Unix epoch seconds'
             AFTER `ngram_count`",
            'add temporary n-gram epoch timestamp column'
        )) {
            return false;
        }

        if (!$this->runMigrationQuery(
            "UPDATE {$tableName}
             SET `last_updated_epoch` = COALESCE(
                 TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', `last_updated`),
                 0
             )",
            'copy legacy n-gram datetimes into epoch timestamp column'
        )) {
            return false;
        }

        if (!$this->runMigrationQuery(
            "ALTER TABLE {$tableName}
             DROP COLUMN `last_updated`",
            'drop legacy n-gram datetime timestamp column'
        )) {
            return false;
        }

        if (!$this->renameEpochTempColumn($tableName)) {
            return false;
        }

        $convertedColumn = $this->getColumnMetadata($tableName, 'last_updated');
        $convertedType = $convertedColumn !== null ? strtolower($this->columnValue($convertedColumn, 'Type')) : '';
        if (strpos($convertedType, 'bigint') === false) {
            $this->logger->warn("N-gram last_updated epoch migration did not leave a bigint column on {$tableName}.");
            return false;
        }

        $this->logger->infoMessage("Migrated {$tableName}.last_updated from datetime to bigint Unix epoch seconds.");
        return true;
    }

    /**
     * @param string $tableName
     * @return bool
     */
    private function renameEpochTempColumn(string $tableName): bool {
        return $this->runMigrationQuery(
            "ALTER TABLE {$tableName}
             CHANGE COLUMN `last_updated_epoch` `last_updated` bigint(20) NOT NULL DEFAULT 0
             COMMENT 'Last time N-grams were computed as Unix epoch seconds'",
            'rename temporary n-gram epoch timestamp column'
        );
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return array<string, mixed>|null
     */
    private function getColumnMetadata(string $tableName, string $columnName): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM {$tableName}",
            array('log_errors' => false)
        );
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $columnRow = array();
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $columnRow[$key] = $value;
                }
            }
            if (strtolower($this->columnValue($columnRow, 'Field')) === strtolower($columnName)) {
                return $columnRow;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $wantedKey
     * @return string
     */
    private function columnValue(array $row, string $wantedKey): string {
        foreach ($row as $key => $value) {
            if (strtolower((string)$key) === strtolower($wantedKey) && is_scalar($value)) {
                return (string)$value;
            }
        }
        return '';
    }

    /**
     * @param string $query
     * @param string $action
     * @return bool
     */
    private function runMigrationQuery(string $query, string $action): bool {
        global $wpdb;
        // Schema-bootstrap migration for a system-generated plugin table name.
        // Runs before generic schema diff to avoid unsafe datetime-to-bigint coercion.
        // DAO-bypass-approved: one-shot schema/data migration over a system-generated plugin table name.
        $result = $wpdb->query($query);
        $lastError = isset($wpdb->last_error) && is_string($wpdb->last_error) ? $wpdb->last_error : '';
        if ($result !== false && $lastError === '') {
            return true;
        }
        $this->logger->warn("Failed to {$action}: " . ($lastError !== '' ? $lastError : 'wpdb query returned false'));
        return false;
    }
}
