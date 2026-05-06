<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ViewMetadataTrait {

    /** @return array<string, mixed> */
    function getTableEngines() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
    	$results = $this->queryAndGetResults($query);
    	return $results;
    }

    /** @return bool */
    function isMyISAMSupported(): bool {
        $abj404dao = abj_service('data_access');
        $supportResults = $abj404dao->queryAndGetResults("SELECT ENGINE, SUPPORT " .
            "FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false));

        if (!empty($supportResults) && !empty($supportResults['rows']) && is_array($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $supportValue = array_key_exists('support', $row) ? (string)($row['support'] ?? '') :
            (array_key_exists('SUPPORT', $row) ? (string)($row['SUPPORT'] ?? '') : "nope");

            return strtolower($supportValue) == 'yes';
        }
        return false;
    }

    /** Insert data into the database.
     * Create my own insert statement because wordpress messes it up when the field
     * length is too long. this also returns the correct value for the last_query.
     * @global type $wpdb
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->doTableNameReplacements($tableName);

        $columns = array();
        $placeholders = array();
        $values = array();

        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';

            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = is_scalar($value) ? (string)$value : '';
                }
            }
        }

        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return $this->queryAndGetResults($sql, ['query_params' => $values]);
    }

   /**
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);

       $result = $this->queryAndGetResults($query);
       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           return 0;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }
       $first = $rows[0];
       $value = is_array($first) ? reset($first) : $first;
       return intval($value);
   }

   /** Get all of the post types from the wp_posts table.
    * @return array<int, string> An array of post type names. */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $results = $this->queryAndGetResults($query);
       $rows = $results['rows'];

       $postType = array();

       if (is_array($rows)) {
           foreach ($rows as $row) {
               array_push($postType, $row['post_type']);
           }
       }

       return $postType;
   }

   /** Get the approximate number of bytes used by the logs table.
    *
    * @return int Bytes used by the logs table, 0 on missing/empty stats,
    *             or -1 if the lookup itself failed/timed out.
    */
   function getLogDiskUsage() {
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables '
               . 'WHERE table_name=\'{wp_abj404_logsv2}\'';

       $result = $this->queryAndGetResults($query);

       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           $err = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
           if ($err !== '') {
               $this->logger->errorMessage("Error: " . esc_html($err));
           }
           return -1;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }

       $row = is_array($rows[0] ?? null) ? $rows[0] : array();
       $size = $row['tablesize'] ?? null;
       if ($size === null || !is_scalar($size)) {
           return 0;
       }
       return intval($size);
   }

    /**
     * @global type $wpdb
     * @param array<int, int> $types specified types such as ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        $recordCount = 0;

        if (count($types) >= 1) {
            $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in (";

            $filteredTypes = array_map('absint', $types);
            $typesForSQL = implode(", ", $filteredTypes);
            $query .= $typesForSQL . "))";
            $query .= " and disabled = " . absint($trashed);

            $result = $this->queryAndGetResults($query);
            $rows = is_array($result['rows']) ? $result['rows'] : array();
            if (!empty($rows)) {
	            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
	            $recordCount = isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
            }
        }

        return intval($recordCount);
    }

    /**
     * Capture a structured diagnostics snapshot when getRedirectsForView() or
     * getRedirectsForViewCount() fails or times out. The snapshot is intended
     * to give support enough evidence in a single debug zip / AJAX response to
     * identify the root cause of slow view queries (missing index, MyISAM
     * corruption, multi-million-row logsv2, canonical_url not backfilled,
     * collation mismatch, etc.) without a follow-up round trip to the user.
     *
     * Every sub-probe is wrapped in try/catch with a tight per-query timeout:
     * one failed probe never blocks the others, and the original failure is
     * never masked by a diagnostic capture exception.
     *
     * @param string $sub Subpage that triggered the query (abj404_redirects / abj404_captured / abj404_logs).
     * @param string $failedQuery The SQL that failed (used for EXPLAIN + redacted shape).
     * @param array<string, mixed> $tableOptions The original tableOptions (kept for context echo).
     * @param array<string, mixed> $queryResult The wpdb-shaped result of the failed call (last_error / timed_out / elapsed_time).
     * @return array<string, mixed>
     */
    public function captureViewQueryFailureDiagnostics(string $sub, string $failedQuery, array $tableOptions, array $queryResult): array {
        $diag = array(
            'failed_query_label' => '',
            'failed_query_redacted' => '',
            'last_error' => '',
            'timed_out' => false,
            'elapsed_time_seconds' => null,
            'sub' => $sub,
            'redirects_count' => array('active' => null, 'trashed' => null),
            'logsv2_count' => null,
            'wp_posts_count' => null,
            'tables' => array(),
            'expected_indexes' => array(),
            'canonical_url_state' => array(),
            'db_version' => '',
            'explain' => null,
        );

        $diag['failed_query_label'] = $this->resolveViewQueryDiagnosticLabel($failedQuery, $sub);
        $diag['failed_query_redacted'] = $this->redactQueryShapeForDiagnostics($failedQuery);

        $lastError = is_string($queryResult['last_error'] ?? null) ? $queryResult['last_error'] : '';
        $diag['last_error'] = $lastError;
        $diag['timed_out'] = !empty($queryResult['timed_out']);
        if (isset($queryResult['elapsed_time']) && is_numeric($queryResult['elapsed_time'])) {
            $diag['elapsed_time_seconds'] = (float)$queryResult['elapsed_time'];
        }

        global $wpdb;
        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        $logsv2Table = $this->doTableNameReplacements('{wp_abj404_logsv2}');
        $postsTable = $this->resolvePostsTableName();

        $diag['explain'] = $this->safeProbeExplain($failedQuery);
        $diag['db_version'] = $this->safeProbeDbVersion();

        $diag['redirects_count']['active'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $redirectsTable . "` WHERE disabled = 0"
        );
        $diag['redirects_count']['trashed'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $redirectsTable . "` WHERE disabled = 1"
        );
        $diag['logsv2_count'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $logsv2Table . "`"
        );
        if ($postsTable !== '') {
            $diag['wp_posts_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $postsTable . "`"
            );
        }

        $diag['tables'] = $this->safeProbeTableEnginesAndCollations(array($redirectsTable, $logsv2Table));

        $diag['expected_indexes'] = array(
            $redirectsTable => $this->safeProbeIndexCoverage($redirectsTable, array(
                'PRIMARY', 'status', 'type', 'code', 'timestamp', 'disabled', 'url', 'final_dest',
                'idx_url_disabled_status', 'idx_status_disabled', 'idx_canonical_url',
            )),
            $logsv2Table => $this->safeProbeIndexCoverage($logsv2Table, array(
                'PRIMARY', 'timestamp', 'requested_url', 'username', 'min_log_id',
                'idx_requested_url_timestamp', 'idx_canonical_url',
            )),
        );

        $diag['canonical_url_state'] = array(
            $redirectsTable => $this->safeProbeCanonicalUrlState($redirectsTable),
            $logsv2Table => $this->safeProbeCanonicalUrlState($logsv2Table),
        );

        return $diag;
    }

    /**
     * Resolve a human-readable label for the failing query. Mirrors the
     * sql_source extraction from formatViewQueryFailureMessage() so the
     * diagnostic snapshot stays self-explanatory in the debug log.
     *
     * @param string $failedQuery
     * @param string $sub
     * @return string
     */
    private function resolveViewQueryDiagnosticLabel(string $failedQuery, string $sub): string {
        if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $failedQuery, $m)) {
            return basename($m[1]);
        }
        if (stripos($failedQuery, 'COUNT(*)') !== false) {
            return 'getRedirectsForViewCount';
        }
        return 'getRedirectsForView';
    }

    /**
     * Redact literals from a SQL string for safe inclusion in error responses
     * and the debug log. Keeps table / column / keyword shape so support can
     * pattern-match against the failing query, but strips quoted strings,
     * numbers, and IN(...) value lists.
     *
     * @param string $sql
     * @return string
     */
    private function redactQueryShapeForDiagnostics(string $sql): string {
        if ($sql === '') {
            return '';
        }
        $out = $sql;
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out) ?? $out;
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out) ?? $out;
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out) ?? $out;
        $out = preg_replace('~\\s+~', ' ', trim($out)) ?? $out;
        if (strlen($out) > 4000) {
            $out = substr($out, 0, 4000);
        }
        return $out;
    }

    /** @return string */
    private function resolvePostsTableName(): string {
        global $wpdb;
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }
        if (isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix . 'posts';
        }
        return '';
    }

    /**
     * Run a `SELECT COUNT(*)` style query with a tight diagnostic timeout
     * and silent error handling. Returns the integer count, or a string error
     * marker if the probe itself failed.
     *
     * @param string $countQuery
     * @return int|string
     */
    private function safeProbeCount(string $countQuery) {
        try {
            $result = $this->queryAndGetResults($countQuery, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '' || !empty($result['timed_out'])) {
                return 'error: ' . ($err !== '' ? $err : 'timed out');
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (empty($rows)) {
                return 0;
            }
            $first = is_array($rows[0]) ? $rows[0] : array();
            $value = $first['count'] ?? $first['COUNT(*)'] ?? reset($first);
            return is_scalar($value) ? (int)$value : 0;
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Run EXPLAIN against the failing query and return the plan rows. Falls
     * back to a string error marker if EXPLAIN itself errors (e.g., the query
     * was a SET STATEMENT wrapper or a stored procedure call).
     *
     * @param string $failedQuery
     * @return array<int, array<string,mixed>>|string
     */
    private function safeProbeExplain(string $failedQuery) {
        if ($failedQuery === '') {
            return 'error: no query supplied';
        }
        $stripped = $this->stripWrappersForExplain($failedQuery);
        try {
            $result = $this->queryAndGetResults('EXPLAIN ' . $stripped, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '' || !empty($result['timed_out'])) {
                return 'error: ' . ($err !== '' ? $err : 'timed out');
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            $clean = array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $clean[] = $row;
                } else if (is_object($row)) {
                    $clean[] = (array)$row;
                }
            }
            return $clean;
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Strip the `SET STATEMENT max_statement_time=N FOR ` / `/*+ MAX_EXECUTION_TIME(...) *\/`
     * wrappers that applyQueryTimeout() prepends so EXPLAIN sees the original
     * SELECT shape. Best effort: if the input does not match, return as-is.
     *
     * @param string $query
     * @return string
     */
    private function stripWrappersForExplain(string $query): string {
        $q = trim($query);
        $q = preg_replace('/^\\s*\\/\\*\\+[^*]*\\*\\/\\s*/', '', $q) ?? $q;
        $q = preg_replace('/^\\s*SET\\s+STATEMENT\\s+max_statement_time\\s*=\\s*\\d+\\s+FOR\\s+/i', '', $q) ?? $q;
        return $q;
    }

    /** @return string */
    private function safeProbeDbVersion(): string {
        try {
            $result = $this->queryAndGetResults('SELECT VERSION() AS version', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (empty($rows)) {
                return '';
            }
            $first = is_array($rows[0]) ? $rows[0] : array();
            $value = $first['version'] ?? $first['VERSION()'] ?? reset($first);
            return is_scalar($value) ? (string)$value : '';
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Probe engine + collation for the requested plugin tables via
     * information_schema.TABLES. Driver-case insensitive (MySQL drivers vary
     * between TABLE_NAME / table_name).
     *
     * @param array<int, string> $tableNames
     * @return array<string, array{engine:string, collation:string}>
     */
    private function safeProbeTableEnginesAndCollations(array $tableNames): array {
        $out = array();
        foreach ($tableNames as $name) {
            $out[$name] = array('engine' => '', 'collation' => '');
        }
        if (empty($tableNames)) {
            return $out;
        }
        try {
            $list = array();
            foreach ($tableNames as $name) {
                $list[] = "'" . str_replace("'", "''", $name) . "'";
            }
            $query = "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', $list) . ")";
            $result = $this->queryAndGetResults($query, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = '';
                $engine = '';
                $collation = '';
                foreach ($row as $key => $value) {
                    $k = strtolower((string)$key);
                    if ($k === 'table_name' && is_scalar($value)) {
                        $name = (string)$value;
                    } else if ($k === 'engine' && is_scalar($value)) {
                        $engine = (string)$value;
                    } else if ($k === 'table_collation' && is_scalar($value)) {
                        $collation = (string)$value;
                    }
                }
                if ($name !== '' && array_key_exists($name, $out)) {
                    $out[$name] = array('engine' => $engine, 'collation' => $collation);
                }
            }
        } catch (Throwable $e) {
            foreach (array_keys($out) as $name) {
                if ($out[$name]['engine'] === '') {
                    $out[$name] = array('engine' => 'error: ' . $e->getMessage(), 'collation' => '');
                }
            }
        }
        return $out;
    }

    /**
     * Compare an expected index list against what SHOW INDEX reports for the
     * named table. Returns three lists (expected, present, missing) so the
     * support workflow can spot dropped indexes at a glance.
     *
     * @param string $tableName
     * @param array<int, string> $expectedKeys
     * @return array{expected: array<int,string>, present: array<int,string>, missing: array<int,string>, error?: string}
     */
    private function safeProbeIndexCoverage(string $tableName, array $expectedKeys): array {
        $out = array(
            'expected' => array_values($expectedKeys),
            'present' => array(),
            'missing' => array(),
        );
        try {
            $result = $this->queryAndGetResults('SHOW INDEX FROM `' . $tableName . '`', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '') {
                $out['error'] = $err;
                $out['missing'] = $out['expected'];
                return $out;
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            $present = array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'key_name' && is_scalar($value)) {
                        $present[(string)$value] = true;
                        break;
                    }
                }
            }
            $out['present'] = array_keys($present);
            $missing = array();
            foreach ($expectedKeys as $expected) {
                if (!array_key_exists($expected, $present)) {
                    $missing[] = $expected;
                }
            }
            $out['missing'] = $missing;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['missing'] = $out['expected'];
        }
        return $out;
    }

    /**
     * Probe canonical_url backfill state for one of the plugin's tables.
     * Returns column existence, NULL count, total row count, and an error
     * marker when the probe itself failed.
     *
     * @param string $tableName
     * @return array{column_exists: bool, null_count: int|string|null, total_count: int|string|null, error?: string}
     */
    private function safeProbeCanonicalUrlState(string $tableName): array {
        $out = array(
            'column_exists' => false,
            'null_count' => null,
            'total_count' => null,
        );
        try {
            $colResult = $this->queryAndGetResults('SHOW COLUMNS FROM `' . $tableName . '`', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $colRows = is_array($colResult['rows'] ?? null) ? $colResult['rows'] : array();
            foreach ($colRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'field' && is_scalar($value)
                            && strtolower((string)$value) === 'canonical_url') {
                        $out['column_exists'] = true;
                        break 2;
                    }
                }
            }
            if (!$out['column_exists']) {
                return $out;
            }
            $out['null_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $tableName . "` WHERE canonical_url IS NULL"
            );
            $out['total_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $tableName . "`"
            );
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}
