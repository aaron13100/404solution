<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes queries against a foreign redirect plugin's storage through the
 * centralized {@see ABJ_404_Solution_DatabaseQueryInterface} pipeline and
 * translates driver-level outcomes (missing table, query error, timeout)
 * into simple pass/fail results.
 *
 * This class holds zero per-source schema knowledge (no table names, no
 * column names, no WHERE-clause filters): it only knows how to ask "does
 * this table exist" and "run this SQL and hand back rows or nothing."
 * {@see ABJ_404_Solution_ForeignRedirectSourceReader} owns the per-source
 * knowledge and is the primary caller today; a future 6th foreign-plugin
 * source would reuse this gateway unchanged.
 */
class ABJ_404_Solution_ForeignSourceQueryGateway {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseQueryInterface|null */
    private $dbQuery;

    /**
     * @param mixed $redirectsRepository Used only to resolve a database query
     *                                   service when one is not supplied directly.
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseQueryInterface|null $dbQuery
     */
    public function __construct($redirectsRepository, $logger, $dbQuery = null) {
        $this->logger = $logger;
        $this->dbQuery = $this->resolveDatabaseQuery($redirectsRepository, $dbQuery);
    }

    /**
     * Check whether a table exists using SHOW TABLES LIKE.
     *
     * @param string $tableName Fully-prefixed table name
     * @return bool
     */
    public function tableExists(string $tableName): bool {
        if (!$this->dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            $this->logger->warn(
                'CrossPluginImporter: cannot check source table "' . $tableName . '" because no database query service is available.'
            );
            return false;
        }

        $result = $this->dbQuery->queryAndGetResults(
            'SHOW TABLES LIKE %s',
            array(
                'query_params' => array($tableName),
                'result_type' => defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A',
                'log_errors' => false,
                'skip_repair' => true,
            )
        );

        if ($this->queryFailed($result)) {
            $this->logger->warn(
                'CrossPluginImporter: source table probe failed for "' . $tableName . '". Error: ' .
                $this->queryErrorMessage($result)
            );
            return false;
        }

        return !empty($result['rows']) && is_array($result['rows']);
    }

    /**
     * Run a SQL statement against foreign source-plugin storage through the
     * centralized query pipeline and return its rows. Used for both full row
     * reads (import/preview) and COUNT(*) queries (preview count) -- the
     * shape of the row(s) returned is up to the caller's SQL, this method
     * only handles execution and error interpretation.
     *
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    public function queryRows(string $sql): array {
        if (!$this->dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            $this->logger->warn('CrossPluginImporter: cannot query source storage because no database query service is available.');
            return array();
        }

        $result = $this->dbQuery->queryAndGetResults(
            $sql,
            array('result_type' => defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A')
        );

        if ($this->queryFailed($result)) {
            $this->logger->warn(
                'CrossPluginImporter: source query failed. Error: ' . $this->queryErrorMessage($result)
            );
            return array();
        }

        $rows = $result['rows'] ?? array();
        if (!is_array($rows)) {
            return array();
        }

        $normalizedRows = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalizedRows[] = $row;
            }
        }
        return $normalizedRows;
    }

    /**
     * @param array<string, mixed> $result
     * @return bool
     */
    private function queryFailed(array $result): bool {
        if (($result['timed_out'] ?? false) === true) {
            return true;
        }
        return $this->queryErrorMessage($result) !== '';
    }

    /**
     * @param array<string, mixed> $result
     * @return string
     */
    private function queryErrorMessage(array $result): string {
        if (($result['timed_out'] ?? false) === true) {
            return 'query timed out';
        }

        $error = $result['last_error'] ?? '';
        if ($error === '') {
            return '';
        }
        if (is_scalar($error)) {
            return (string)$error;
        }
        if (is_object($error) && method_exists($error, '__toString')) {
            return (string)$error;
        }
        return 'non-scalar database error of type ' . gettype($error);
    }

    /**
     * Resolve the database query service without requiring existing callers
     * to pass the optional constructor argument.
     *
     * @param mixed $redirectsRepository
     * @param mixed $dbQuery
     * @return ABJ_404_Solution_DatabaseQueryInterface|null
     */
    private function resolveDatabaseQuery($redirectsRepository, $dbQuery) {
        if ($dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            return $dbQuery;
        }

        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'getDbCore')) {
            $candidate = $redirectsRepository->getDbCore();
            if ($candidate instanceof ABJ_404_Solution_DatabaseQueryInterface) {
                return $candidate;
            }
        }

        return null;
    }
}
