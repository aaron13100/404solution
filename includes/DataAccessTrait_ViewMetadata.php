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
}
