<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../DatabaseCollationHelper.php';

/**
 * Plugin-table collation/charset drift correction.
 *
 * Discovers the current charset+collation of every plugin table (and every character
 * column within), resolves the canonical utf8mb4 collation target for this site, and
 * ALTERs any table or column that has drifted off it. Drift sources include legacy
 * latin1 tables created before utf8mb4 support, hosting migrations that swap
 * collations under us, and partial dbDelta runs that leave per-column collations
 * inconsistent with the table default.
 *
 * Reachable from {@see ABJ_404_Solution_DatabaseUpgradeSelfHeal::verifyAndRepairCurrentSite()},
 * the initial table-create boot path via the coordinator, and the daily cron via
 * {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance::runDatabaseMaintenanceTasks()}.
 */
class ABJ_404_Solution_DatabaseUpgradeCollationDrift extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** Retrieve the collation for a given table name.
     * @param string $tableName
     * @return array{0: string, 1: string}|null Array of [collation, charset] or null if retrieval failed.
     */
    function getTableCollation($tableName) {
        // Try SHOW CREATE TABLE first
        $result = $this->getTableCollationFromShowCreate($tableName);

        if ($result !== null) {
            return $result;
        }

        // Fallback to information_schema query
        $result = $this->getTableCollationFromInformationSchema($tableName);

        if ($result !== null) {
            return $result;
        }

        $this->logger->warn("Could not retrieve collation for $tableName from SHOW CREATE TABLE or information_schema.");
        return null;
    }

    /** Parse collation/charset from SHOW CREATE TABLE output.
     * @param string $tableName
     * @return array{0: string, 1: string}|null Array of [collation, charset] or null if parsing failed.
     */
    function getTableCollationFromShowCreate($tableName) {
        $query = "SHOW CREATE TABLE `$tableName`";
        $results = $this->dbCore->queryAndGetResults($query);

        // Check for query errors or empty results
        $lastError = isset($results['last_error']) && is_scalar($results['last_error'])
            ? (string)$results['last_error']
            : '';
        if ($lastError !== '') {
            $this->logger->debugMessage("SHOW CREATE TABLE failed for $tableName: " . $lastError);
            return null;
        }

        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];
        $firstRow = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if ($firstRow === null) {
            $this->logger->debugMessage("SHOW CREATE TABLE returned no data for $tableName.");
            return null;
        }

        // Use array_values to handle varying column name cases ('Create Table', 'CREATE TABLE', etc.)
        // SHOW CREATE TABLE returns: [table_name, create_statement]
        $row = array_values($firstRow);
        if (count($row) < 2 || empty($row[1])) {
            $this->logger->debugMessage("SHOW CREATE TABLE returned unexpected format for $tableName.");
            return null;
        }

        if (!is_string($row[1])) {
            $this->logger->debugMessage("SHOW CREATE TABLE returned non-string DDL for $tableName.");
            return null;
        }

        $createTableSQL = $row[1];

        // The table default lives in the table-options section, after the
        // closing paren of the body -- never inside it. Column definitions come
        // FIRST in real engine output, and a column may carry its own
        // `CHARACTER SET x COLLATE y`, so a pattern run over the whole statement
        // returns the first COLUMN's charset and calls it the table's. That is
        // not a near-miss this caller can second-guess: correctCollations()
        // reads "utf8mb3" off a utf8mb4 table as drift and issues ALTER TABLE
        // ... CONVERT against a table that never drifted. The parser owns the
        // body/options boundary so no reader has to find it again.
        $tableDefault = ABJ_404_Solution_CreateTableOptionsParser::tableCharsetAndCollation($createTableSQL);
        if ($tableDefault === null) {
            $this->logger->debugMessage("SHOW CREATE TABLE output for $tableName has no readable "
                . "table-options section; falling back to information_schema.");
            return null;
        }

        $charset = $tableDefault['charset'];
        $collation = $tableDefault['collation'];

        // If we got charset but no explicit collation, derive default collation from charset
        if ($charset && !$collation) {
            $collation = $this->getDefaultCollationForCharset($charset);

        } else if ($collation && !$charset) {
            // The mirror case: a table-options section that states COLLATE and
            // leaves the charset implicit. A collation names its own charset, so
            // the pair is derivable -- through the single owner of that rule, not
            // a private explode() that could pair the two halves differently
            // from everywhere else (errno 1253 is what a mismatched pair costs).
            $pair = ABJ_404_Solution_DatabaseCollationHelper::charsetCollationPair($collation);
            $charset = $pair['charset'];
        }

        return ($collation && $charset) ? [$collation, $charset] : null;
    }

    /** Query information_schema for table collation (fallback method).
     * @param string $tableName
     * @return array{0: string, 1: string}|null Array of [collation, charset] or null if query failed.
     */
    function getTableCollationFromInformationSchema($tableName) {
        $queryResult = $this->dbCore->queryAndGetResults(
            "SELECT TABLE_COLLATION, " .
            "SUBSTRING_INDEX(TABLE_COLLATION, '_', 1) as TABLE_CHARSET " .
            "FROM information_schema.tables " .
            "WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            ['query_params' => [$tableName]]
        );

        $lastError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->debugMessage("information_schema query failed for $tableName: " . $lastError);
            return null;
        }

        $results = isset($queryResult['rows']) && is_array($queryResult['rows']) ? $queryResult['rows'] : [];
        if (empty($results) || !is_array($results[0])) {
            $this->logger->debugMessage("Table $tableName not found in information_schema (may not exist).");
            return null;
        }

        // Handle case-insensitive column names (some MySQL configs return uppercase)
        $row = array_change_key_case($results[0], CASE_UPPER);
        $collation = isset($row['TABLE_COLLATION']) && is_scalar($row['TABLE_COLLATION'])
            ? (string)$row['TABLE_COLLATION']
            : null;
        $charset = isset($row['TABLE_CHARSET']) && is_scalar($row['TABLE_CHARSET'])
            ? (string)$row['TABLE_CHARSET']
            : null;

        if (empty($collation)) {
            return null;
        }

        // Handle edge case where charset extraction might fail
        if (empty($charset)) {
            $charset = explode('_', $collation)[0];
        }

        return [$collation, $charset];
    }

    /** Get the default collation for a given charset.
     * @param string $charset
     * @return string|null Default collation or null if unknown.
     */
    function getDefaultCollationForCharset($charset) {
        // Common charset to default collation mappings
        $defaults = [
            'utf8mb4' => 'utf8mb4_general_ci',
            'utf8' => 'utf8_general_ci',
            'utf8mb3' => 'utf8mb3_general_ci',
            'latin1' => 'latin1_swedish_ci',
            'ascii' => 'ascii_general_ci',
        ];

        $charsetLower = strtolower($charset);
        return $defaults[$charsetLower] ?? null;
    }

    /**
     * Keep collation identifiers SQL-safe.
     *
     * @param string $collation
     * @return string
     */
    private function sanitizeCollationIdentifier($collation) {
        if (!is_string($collation) || $collation === '') {
            return '';
        }
        return preg_replace('/[^A-Za-z0-9_]/', '', $collation) ?? '';
    }

    /**
     * Resolve the utf8mb4 collation target for plugin-table normalization.
     *
     * Priority:
     * 1) Active wpdb connection collation if utf8mb4
     * 2) Most common existing utf8mb4 plugin-table collation
     * 3) Database default collation variable if utf8mb4
     * 4) Safe fallback (utf8mb4_unicode_ci)
     *
     * @param array<int, string> $tableNames
     * @param array<string, array{0: string, 1: string}|null> $tableCollations Optional map: table => [collation, charset]
     * @return string
     */
    private function resolveTargetUtf8mb4Collation($tableNames, $tableCollations = []) {
        global $wpdb;

        if (!empty($wpdb->collate)) {
            $wpdbCollation = $this->sanitizeCollationIdentifier((string)$wpdb->collate);
            if (ABJ_404_Solution_DatabaseCollationHelper::isUtf8mb4Collation($wpdbCollation)) {
                return $wpdbCollation;
            }
        }

        $counts = [];
        foreach ($tableNames as $tableName) {
            $row = $tableCollations[$tableName] ?? $this->getTableCollation($tableName);
            if (!is_array($row)) {
                continue;
            }
            $collation = $this->sanitizeCollationIdentifier((string)$row[0]);
            $charset = strtolower((string)$row[1]);
            if ($charset === 'utf8mb4' && ABJ_404_Solution_DatabaseCollationHelper::isUtf8mb4Collation($collation)) {
                $counts[$collation] = ($counts[$collation] ?? 0) + 1;
            }
        }
        if (!empty($counts)) {
            arsort($counts);
            return array_key_first($counts);
        }

        $vars = $this->dbCore->queryAndGetResults("SHOW VARIABLES LIKE 'collation_database'");
        $varRows = isset($vars['rows']) && is_array($vars['rows']) ? $vars['rows'] : [];
        if (!empty($varRows)) {
            $row = is_array($varRows[0]) ? $varRows[0] : [];
            $valueRaw = isset($row['Value']) ? $row['Value'] : (isset($row['value']) ? $row['value'] : '');
            $value = $this->sanitizeCollationIdentifier(is_scalar($valueRaw) ? (string)$valueRaw : '');
            if (ABJ_404_Solution_DatabaseCollationHelper::isUtf8mb4Collation($value)) {
                return $value;
            }
        }

        return 'utf8mb4_unicode_ci';
    }

    /**
     * Ensure our tables use utf8mb4 (do not alter WordPress core tables).
     * @return void
     */
    function correctCollations() {
        // Discover all plugin tables dynamically so new tables are automatically included.
        // Use queryAndGetResults() so the SHOW TABLES call goes through the same DAO
        // layer as all other queries (enables testability via mock injection).
        // {wp_prefix} is resolved by doTableNameReplacements inside queryAndGetResults.
        $rawResult = $this->dbCore->queryAndGetResults("SHOW TABLES LIKE '{wp_prefix}abj404_%'");
        /** @var array<int, string> $abjTableNames */
        $abjTableNames = [];
        if (isset($rawResult['rows']) && is_array($rawResult['rows'])) {
            foreach ($rawResult['rows'] as $row) {
                $tableName = is_array($row) ? reset($row) : $row;
                if (is_scalar($tableName)) {
                    $abjTableNames[] = (string)$tableName;
                }
            }
        }

        // Exclude the vestigial staged-build tables (view_build / view_done /
        // view_deleteme). They are no longer read or maintained (the denorm
        // columns on wp_abj404_redirects are the live source) and are slated
        // for removal in the final denorm step. ALTERing tables that nothing
        // reads and that are about to be dropped has no correctness value.
        // This matches the existing "transient tables are out of scope"
        // treatment in the permanent-DDL schema-diff sweep
        // (DatabaseUpgradeTableRepair).
        $abjTableNames = array_values(array_filter(
            $abjTableNames,
            static function ($t) {
                return preg_match('/abj404_view_(build|done|deleteme)$/i', (string)$t) !== 1;
            }
        ));

        /** @var array<string, array{0: string, 1: string}|null> $tableCollations */
        $tableCollations = [];
        foreach ($abjTableNames as $tableName) {
            $collationResult = $this->getTableCollation($tableName);
            $tableCollations[$tableName] = (
                is_array($collationResult)
                && isset($collationResult[0], $collationResult[1])
                && is_scalar($collationResult[0])
                && is_scalar($collationResult[1])
            ) ? [(string)$collationResult[0], (string)$collationResult[1]] : null;
        }

        $targetCharset = 'utf8mb4';
        $targetCollation = $this->resolveTargetUtf8mb4Collation($abjTableNames, $tableCollations);

        foreach ($abjTableNames as $tableName) {
            $abjTableData = $tableCollations[$tableName] ?? null;

            if ($abjTableData === null) {
                $this->logger->warn("Failed to retrieve collation for $tableName.");
                continue;  // Skip this table if collation can't be determined
            }

            [$abjTableCollation, $abjTableCharset] = $abjTableData;

            $needsUpdate = !($abjTableCharset === $targetCharset && $abjTableCollation === $targetCollation);
            if (!$needsUpdate) {
                // Table default matches, but individual columns can still drift (e.g., some columns left as *_bin).
                $columnMismatch = $this->tableHasMismatchedCharacterColumnCollation($tableName, $targetCharset, $targetCollation);
                if ($columnMismatch === true) {
                    $needsUpdate = true;
                    $this->logger->infoMessage("Detected column-level collation mismatch on {$tableName}; normalizing to {$targetCharset}/{$targetCollation}");
                } else if ($columnMismatch === null) {
                    $this->logger->warn("Could not verify column collations for {$tableName}; skipping collation normalization.");
                    continue;
                }
            }
            if (!$needsUpdate) {
                continue;
            }

            $this->logger->infoMessage("Updating charset/collation on {$tableName} from {$abjTableCharset}/{$abjTableCollation} to {$targetCharset}/{$targetCollation}");

            $query = "ALTER TABLE {table_name} CONVERT TO CHARSET " . $targetCharset .
                     " COLLATE " . $targetCollation;
            $query = str_replace('{table_name}', $tableName, $query);
            $results = $this->dbCore->queryAndGetResults($query,
                array('ignore_errors' => array("Index column size too large")));

            $lastErr = isset($results['last_error']) && is_string($results['last_error']) ? $results['last_error'] : '';
            if ($lastErr !== '' &&
                    strpos($lastErr, "Index column size too large") !== false) {

                $this->logger->warn("Charset/collation change for $tableName failed: Index column size too large. Deleting indexes and retrying...");

                // delete indexes and try again.
                $this->upgrades()->schemaDiffUpgrade()->deleteIndexes($tableName);

                $retryResults = $this->dbCore->queryAndGetResults($query);
                $retryLastError = isset($retryResults['last_error']) && is_scalar($retryResults['last_error'])
                    ? (string)$retryResults['last_error']
                    : '';
                if ($retryLastError !== '') {
                    $this->logger->warn("Charset/collation retry for $tableName failed: " . $retryLastError);
                } else {
                    $this->logger->infoMessage("Successfully changed charset/collation of $tableName after retry.");
                }

            } else if (empty($results['last_error'])) {
                $this->logger->infoMessage("Successfully changed charset/collation of $tableName to {$targetCharset}/{$targetCollation}");
            } else {
                $resultLastError = isset($results['last_error']) && is_scalar($results['last_error'])
                    ? (string)$results['last_error']
                    : '';
                $this->logger->warn("Charset/collation change for $tableName failed: " . $resultLastError);
            }
        }
    }

    /**
     * Detect character column collation drift on a table.
     *
     * Some environments can end up with per-column collations that differ from the table default
     * (e.g., `utf8mb4_bin` on one VARCHAR column while the table default is `utf8mb4_unicode_520_ci`).
     * This causes MySQL errors in string operations (REPLACE/LOWER) that mix collations.
     *
     * @param string $tableName Fully qualified table name (with prefix)
     * @param string $targetCharset Expected charset (e.g., utf8mb4)
     * @param string $targetCollation Expected collation (e.g., utf8mb4_unicode_ci)
     * @return bool|null True if mismatch found, false if all match, null if query failed
     */
    private function tableHasMismatchedCharacterColumnCollation($tableName, $targetCharset, $targetCollation) {
        $results = $this->dbCore->queryAndGetResults("SHOW FULL COLUMNS FROM " . $tableName);
        $lastError = isset($results['last_error']) && is_scalar($results['last_error'])
            ? (string)$results['last_error']
            : '';
        if ($lastError !== '') {
            $this->logger->warn("Failed to read columns for {$tableName}: " . $lastError);
            return null;
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];
        if (empty($rows)) {
            return false;
        }

        $collationKey = null;
        $firstRow = $rows[0];
        foreach (array_keys($firstRow) as $key) {
            if ($this->f->strtolower((string)$key) === 'collation') {
                $collationKey = $key;
                break;
            }
        }
        if ($collationKey === null) {
            $this->logger->warn("SHOW FULL COLUMNS returned no Collation column for {$tableName}");
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawColCollation = $row[$collationKey] ?? null;
            if ($rawColCollation === null || !is_string($rawColCollation) || trim($rawColCollation) === '') {
                continue; // Non-character columns
            }
            $colCollation = trim($rawColCollation);
            $colCharset = explode('_', $colCollation)[0] ?? '';

            if ($colCharset !== $targetCharset || $colCollation !== $targetCollation) {
                return true;
            }
        }

        return false;
    }
}
