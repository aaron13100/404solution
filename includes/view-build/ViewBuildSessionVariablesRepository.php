<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository boundary for read-only MySQL session-variable probes.
 */
class ABJ_404_Solution_ViewBuildSessionVariablesRepository {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Single-query SHOW VARIABLES read for every name the staged build cares
     * about. Returns an empty array when the read is unavailable.
     *
     * @return array<string,string>
     */
    public function fetchSessionVariablesRowOrEmpty(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }
        /** @var \wpdb $wpdb */
        $names = array(
            'innodb_lock_wait_timeout',
            'tmp_table_size',
            'max_heap_table_size',
            'slow_query_log',
            'long_query_time',
            'innodb_buffer_pool_size',
            'wait_timeout',
            'interactive_timeout',
            'innodb_flush_method',
            'character_set_server',
            'collation_server',
            'sql_require_primary_key',
            'innodb_file_per_table',
            'thread_stack',
            'open_files_limit',
            'innodb_online_alter_log_max_size',
        );
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $sql = "SHOW SESSION VARIABLES WHERE Variable_name IN ($placeholders)";
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            $prepared = method_exists($wpdb, 'prepare') ? $wpdb->prepare($sql, $names) : $sql;
            // DAO-bypass-approved: read-only probe of @@SESSION on this connection.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } catch (\Throwable $e) {
            $rows = null;
            $this->logger->warn('[staged] session-variable probe failed: '
                . get_class($e) . ': ' . $e->getMessage());
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }

        $out = array();
        if (!is_array($rows)) { return $out; }
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $name = '';
            $value = '';
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'variable_name' && is_scalar($v)) { $name = strtolower((string)$v); }
                if ($klow === 'value' && is_scalar($v)) { $value = (string)$v; }
            }
            if ($name !== '') { $out[$name] = $value; }
        }
        return $out;
    }
}
