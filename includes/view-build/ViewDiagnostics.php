<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Failure message formatting and diagnostic probing for view queries.
 *
 * Captures structured diagnostic snapshots when admin view queries fail
 * or time out, giving support enough evidence in a single debug zip to
 * identify the root cause without a follow-up round trip to the user.
 */
class ABJ_404_Solution_ViewDiagnostics {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseQueryDiagnostics */
    private $queryDiagnostics;

    /** @var ABJ_404_Solution_ViewQueryExplainDiagnostics */
    private $explainDiagnostics;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_DatabaseQueryDiagnostics|null $queryDiagnostics
     * @param ABJ_404_Solution_ViewQueryExplainDiagnostics|null $explainDiagnostics
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $queryDiagnostics = null, $explainDiagnostics = null) {
        $this->dbCore = $dbCore;
        $this->queryDiagnostics = $queryDiagnostics !== null ? $queryDiagnostics : $dbCore->queryDiagnostics();
        $this->explainDiagnostics = $explainDiagnostics !== null
            ? $explainDiagnostics
            : new ABJ_404_Solution_ViewQueryExplainDiagnostics($dbCore);
    }

    /**
     * @param string $queryLabel
     * @param string $query
     * @param array<string, mixed> $result
     * @return string
     */
    public function formatViewQueryFailureMessage(string $queryLabel, string $query, array $result): string {
        $lastErrorRaw = $result['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? trim($lastErrorRaw) : '';
        $timedOut = !empty($result['timed_out']);
        $sqlSource = $this->queryDiagnostics->extractSqlFilename($query);

        if ($lastError === '' && $timedOut) {
            $lastError = $queryLabel . ' timed out';
        } else if ($lastError === '') {
            $lastError = $queryLabel . ' failed without a database error message';
        }

        return $queryLabel . ' failed'
            . '; last_error=' . $lastError
            . '; timed_out=' . ($timedOut ? 'true' : 'false')
            . '; sql_source=' . $sqlSource;
    }

    /**
     * Capture a structured diagnostics snapshot when getRedirectsForView() or
     * getRedirectsForViewCount() fails or times out.
     *
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $queryResult
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
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $logsv2Table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $postsTable = $this->resolvePostsTableName();

        $diag['explain'] = $this->explainDiagnostics->collect($sub, $failedQuery, $queryResult);
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
            ), 'createRedirectsTable.sql'),
            $logsv2Table => $this->safeProbeIndexCoverage($logsv2Table, array(
                'PRIMARY', 'timestamp', 'requested_url', 'username', 'min_log_id',
                'idx_requested_url_timestamp', 'idx_canonical_url',
            ), 'createLogTable.sql'),
        );

        $diag['canonical_url_state'] = array(
            $redirectsTable => $this->safeProbeCanonicalUrlState($redirectsTable),
            $logsv2Table => $this->safeProbeCanonicalUrlState($logsv2Table),
        );

        return $diag;
    }

    /**
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
     * @param string $countQuery
     * @return int|string
     */
    private function safeProbeCount(string $countQuery) {
        try {
            $result = $this->dbCore->queryAndGetResults($countQuery, array(
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

    /** @return string */
    private function safeProbeDbVersion(): string {
        try {
            $result = $this->dbCore->queryAndGetResults('SELECT VERSION() AS version', array(
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
            $result = $this->dbCore->queryAndGetResults($query, array(
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
     * Which indexes a table has, which of the ones this failure bundle cares
     * about are gone, and which are present under the right name but no longer
     * match the shipped DDL.
     *
     * The `drifted` bucket exists because a name-only answer is not diagnostic:
     * MySQL and MariaDB silently strip a dropped column out of every index that
     * named it and keep the rest, so a table can report every expected index as
     * `present` while the sort those indexes were built for filesorts the whole
     * partition. A bundle that says "all indexes present" for a page timing out
     * on an unindexed sort sends the reader in the wrong direction.
     *
     * `drifted` covers every index in the DDL, not just $expectedKeys: the
     * composites the derived-sort reads depend on are exactly the ones a column
     * drop narrows, and they were never in the hand-listed set.
     *
     * @param string $tableName
     * @param array<int, string> $expectedKeys
     * @param string $ddlFileName create*Table.sql to compare definitions against.
     * @return array{expected: array<int,string>, present: array<int,string>, missing: array<int,string>, drifted: array<string,string>, error?: string}
     */
    private function safeProbeIndexCoverage(string $tableName, array $expectedKeys, string $ddlFileName = ''): array {
        $out = array(
            'expected' => array_values($expectedKeys),
            'present' => array(),
            'missing' => array(),
            'drifted' => array(),
        );
        try {
            $result = $this->dbCore->queryAndGetResults('SHOW INDEX FROM `' . $tableName . '`', array(
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
            $definitions = ABJ_404_Solution_TableIndexDefinitions::fromShowIndexRows($rows);
            if ($definitions === null) {
                // The engine answered with rows this version cannot read, so
                // this is not a description of the table. Reporting the indexes
                // we managed to parse as "present" and the rest as "missing"
                // would put a fabricated index inventory into a support payload,
                // which is worse than reporting that the probe failed.
                $out['error'] = 'SHOW INDEX returned rows that could not be read';
                $out['missing'] = $out['expected'];
                return $out;
            }
            $present = array();
            foreach ($definitions as $definition) {
                $present[(string)$definition['name']] = true;
            }
            $out['present'] = array_keys($present);
            $missing = array();
            foreach ($expectedKeys as $expected) {
                if (!array_key_exists($expected, $present)) {
                    $missing[] = $expected;
                }
            }
            $out['missing'] = $missing;
            $out['drifted'] = $this->describeDriftedIndexes($definitions, $ddlFileName);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['missing'] = $out['expected'];
        }
        return $out;
    }

    /**
     * Present-but-wrong indexes, as "index name" => "has (...), schema says (...)".
     *
     * @param array<string, array{name: string, columns: array<int, array{column: string, prefix: int|null}>, unique: bool, describable?: bool}> $definitions
     * @param string $ddlFileName
     * @return array<string, string>
     */
    private function describeDriftedIndexes(array $definitions, string $ddlFileName): array {
        if ($ddlFileName === '') {
            return array();
        }
        $ddl = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/' . $ddlFileName);
        $drifted = array();
        foreach (ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql((string)$ddl) as $name => $spec) {
            $live = $definitions[strtolower((string)$name)] ?? null;
            if (!is_array($live)) {
                continue;
            }
            if (!ABJ_404_Solution_IndexDefinitionComparator::isDriftedFromDdlSpec($live, $spec)) {
                // Either the index matches its DDL, or one of the two sides was
                // never fully read (the engine describes the index in a form
                // this version cannot compare, or our own SQL template did not
                // parse). Reporting the latter as drifted would send a support
                // payload claiming a difference nobody established.
                continue;
            }
            $drifted[(string)$name] = 'has (' .
                ABJ_404_Solution_TableIndexDefinitions::describeColumns($live) .
                '), schema says ' . trim((string)$spec['columns']);
        }
        return $drifted;
    }

    /**
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
            $colResult = $this->dbCore->queryAndGetResults('SHOW COLUMNS FROM `' . $tableName . '`', array(
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
