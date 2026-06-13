<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database-side environment probes for the feedback payload's
 * `environment_extras` field.
 *
 * Every method in this class reads from MySQL/MariaDB via `$wpdb` (SHOW
 * GLOBAL VARIABLES, SHOW GLOBAL STATUS, SHOW PROCESSLIST, SHOW INDEX,
 * information_schema) or from plugin options the staged view-build
 * already writes. No host/runtime introspection lives here, and no
 * filesystem access. The DAO-bypass markers on every read are
 * specifically scoped to read-only @@GLOBAL / metadata probes.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition;
 * see that class's collect() method for the keyed probe registry that
 * wraps each call below in recordProbe() for failure isolation.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_DbProbes {

    /**
     * Pull a fixed set of MySQL global variables relevant to staged
     * view-build / temp-table JOIN performance on Bruno-class hosts. One
     * SHOW GLOBAL VARIABLES query, parameterized name list, suppressed
     * errors so a perms-denied response degrades to an empty map rather
     * than a payload error.
     *
     * @return array<string, mixed>
     */
    public function collectMysqlGlobals(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for SHOW GLOBAL VARIABLES probe');
        }
        $names = array(
            'innodb_buffer_pool_size',
            'innodb_log_file_size',
            'innodb_flush_method',
            'innodb_file_per_table',
            'innodb_lock_wait_timeout',
            'tmp_table_size',
            'max_heap_table_size',
            'key_buffer_size',
            'max_allowed_packet',
            'sort_buffer_size',
            'join_buffer_size',
            'max_connections',
            'thread_cache_size',
            'table_open_cache',
            'wait_timeout',
            'interactive_timeout',
            'character_set_server',
            'collation_server',
            'optimizer_switch',
            'sql_mode',
            'long_query_time',
            'slow_query_log',
            'open_files_limit',
        );
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            $prepared = "SHOW GLOBAL VARIABLES";
            if (method_exists($wpdb, 'prepare')) {
                // DAO-bypass-approved: SHOW GLOBAL VARIABLES placeholder bind; no plugin-table writes possible.
                $prepared = $wpdb->prepare("SHOW GLOBAL VARIABLES WHERE Variable_name IN ($placeholders)", $names);
            }
            // DAO-bypass-approved: read-only probe of @@GLOBAL; no plugin tables involved.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } finally {
            if (method_exists($wpdb, 'suppress_errors')) {
                $wpdb->suppress_errors($prevSuppress);
            }
        }

        if (!is_array($rows)) {
            throw new \RuntimeException('SHOW GLOBAL VARIABLES returned non-array');
        }
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $name = '';
            $value = '';
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'variable_name' && is_scalar($v)) { $name = strtolower((string)$v); }
                if ($klow === 'value' && is_scalar($v))         { $value = (string)$v; }
            }
            if ($name === '') { continue; }
            // Coerce numeric-looking values so the server-side JSON sort
            // is meaningful (otherwise 9 sorts after 100 lexically).
            if (is_numeric($value) && strpos($value, '.') === false) {
                $out[$name] = (int)$value;
            } elseif (is_numeric($value)) {
                $out[$name] = (float)$value;
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Read persisted session-variable probe data written by older builds.
     * This preserves historical support-request context without paying for
     * a fresh SHOW SESSION VARIABLES query.
     *
     * @return array<string, mixed>
     */
    public function loadViewBuildSessionEnvProbe(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $opt = get_option('abj404_view_build_session_env_probe', array());
        if (!is_array($opt)) {
            return array();
        }
        $out = array();
        foreach ($opt as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Size of plugin-owned tables beyond logsv2 (which has its own typed
     * column). The redirects-tab perf bug is bounded by the
     * redirects/logs_hits volume, not logsv2, so shipping both lets the
     * report rank reports by the right axis.
     *
     * Per-table shape: {data_length: int, index_length: int, data_free: int,
     * bytes: int}. `data_free` is the fragmentation indicator (bytes
     * allocated to the file but not in use); a fragmentation ratio of
     * data_free / (data_length + index_length) over ~0.3 explains the
     * "tables are 200 MB but only 50 MB of data" long-tail slowness.
     * `bytes` is the legacy combined data+index size kept for backward
     * compatibility with consumers that pre-dated the data_free split.
     *
     * @return array<string, array<string, int>>
     */
    public function collectPluginTableSizes(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_row')) {
            throw new \RuntimeException('wpdb unavailable for plugin_tables_bytes probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $candidates = array(
            'redirects'        => $prefix . 'abj404_redirects',
            'logs_hits'        => $prefix . 'abj404_logs_hits',
            'logs_hits_preagg' => $prefix . 'abj404_logs_hits_preagg',
            'permalink_cache'  => $prefix . 'abj404_permalink_cache',
            'spelling_cache'   => $prefix . 'abj404_spelling_cache',
            'lookup'           => $prefix . 'abj404_lookup',
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $out = array();
        $errors = 0;
        $attempted = 0;
        foreach ($candidates as $key => $table) {
            $attempted++;
            try {
                if (!method_exists($wpdb, 'prepare')) { continue; }
                // DAO-bypass-approved: information_schema metadata probe placeholder bind; no plugin-table writes.
                $prepared = $wpdb->prepare(
                    'SELECT data_length, index_length, data_free '
                  . 'FROM information_schema.TABLES '
                  . 'WHERE table_schema = DATABASE() AND table_name = %s',
                    $table
                );
                if ($prepared === null) { continue; }
                // DAO-bypass-approved: information_schema probe; no plugin tables touched, no error class repair would apply.
                $row = $wpdb->get_row($prepared, ARRAY_A);
                if (!is_array($row)) { continue; }
                $dl = 0; $il = 0; $df = 0;
                foreach ($row as $col => $val) {
                    if (!is_scalar($val) || !is_numeric($val)) { continue; }
                    $clow = strtolower((string)$col);
                    if ($clow === 'data_length')  { $dl = (int)$val; }
                    if ($clow === 'index_length') { $il = (int)$val; }
                    if ($clow === 'data_free')    { $df = (int)$val; }
                }
                $out[$key] = array(
                    'data_length'  => $dl,
                    'index_length' => $il,
                    'data_free'    => $df,
                    'bytes'        => $dl + $il,
                );
            } catch (\Throwable $e) {
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'collectPluginTableSizes probe failed for ' . $table . ': ' . $e->getMessage());
                $errors++;
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out)) {
            throw new \RuntimeException('plugin_tables_bytes: all tables failed SQL probe');
        }
        return $out;
    }

    /**
     * View-build freshness signals: when did the rollup last complete,
     * what stage did the most recent build reach, and is the rollup
     * stale relative to logsv2? Hand-assembled from plugin options the
     * staged build already writes; no new SQL.
     *
     * @return array<string, int>
     */
    public function collectViewBuildState(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $out = array();
        $optMap = array(
            'last_build_completed_at' => 'abj404_view_build_last_completed_at',
            'last_build_started_at'   => 'abj404_view_build_last_started_at',
            'last_build_stage'        => 'abj404_view_build_last_stage',
            'last_build_failure_at'   => 'abj404_view_build_last_failure_at',
            'logs_hits_max_log_id'    => 'abj404_logs_hits_max_log_id',
        );
        foreach ($optMap as $outKey => $optName) {
            $v = get_option($optName, null);
            if (is_scalar($v)) {
                $out[$outKey] = is_numeric($v) ? (int)$v : 0;
            }
        }
        return $out;
    }

    /**
     * Row count from SHOW PROCESSLIST. Cheap on a shared host (returns
     * the current request's view of connection saturation) and a strong
     * leading indicator for "the BEGIN/COMMIT in the staged build is
     * waiting because there are 200 other queries in flight". Only the
     * row count is emitted; user/host/info columns are dropped to avoid
     * PII leakage from other tenants on the same MySQL instance.
     *
     * Throws when the probe genuinely cannot complete (no $wpdb, query
     * failed) so the caller's tryInt wrapper records null rather than
     * a misleading zero.
     *
     * @return int
     */
    public function probeActiveConnectionCount(): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable');
        }
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $rows = null;
        try {
            // DAO-bypass-approved: read-only probe of @@PROCESSLIST; no plugin tables involved.
            $rows = $wpdb->get_results('SHOW PROCESSLIST', ARRAY_A);
        } catch (\Throwable $e) {
            // allow-silent-catch: probe is best-effort; rethrow after restoring suppress so the outer tryInt records null
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeActiveConnectionCount failed: ' . $e->getMessage());
            $rows = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if (!is_array($rows)) {
            throw new \RuntimeException('processlist probe failed');
        }
        return count($rows);
    }

    /**
     * Per-index cardinality for the canonical indexes on the JOIN-hot
     * plugin tables. Output shape:
     *   { redirects: {idx_url_disabled_status: int, idx_canonical_url: int, ...},
     *     logs_hits: {requested_url: int, ...},
     *     logs_hits_preagg: {...} }
     *
     * Each table is probed in its own SHOW INDEX statement, isolated in
     * try/catch so a missing table (rebuild race, repair pending) does
     * not blank the whole map. Only the Cardinality value is captured;
     * the rest of the SHOW INDEX columns are not emitted.
     *
     * @return array<string, array<string, int>>
     */
    public function probeIndexCardinality(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for index_cardinality probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $candidates = array(
            'redirects'        => $prefix . 'abj404_redirects',
            'logs_hits'        => $prefix . 'abj404_logs_hits',
            'logs_hits_preagg' => $prefix . 'abj404_logs_hits_preagg',
            'logsv2'           => $prefix . 'abj404_logsv2',
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $out = array();
        $errors = 0;
        $attempted = 0;
        foreach ($candidates as $key => $table) {
            $attempted++;
            try {
                $prepared = 'SHOW INDEX FROM `' . $table . '`';
                if (method_exists($wpdb, 'prepare')) {
                    // DAO-bypass-approved: SHOW INDEX metadata probe placeholder bind; no plugin-table writes possible.
                    $prepared = $wpdb->prepare($prepared);
                }
                if ($prepared === null || $prepared === '') {
                    continue;
                }
                // DAO-bypass-approved: SHOW INDEX is read-only metadata; no plugin-table writes possible.
                $rows = $wpdb->get_results($prepared, ARRAY_A);
                if (!is_array($rows)) {
                    continue;
                }
                $byIndex = array();
                foreach ($rows as $row) {
                    if (!is_array($row)) { continue; }
                    $idxName = '';
                    $card = null;
                    foreach ($row as $col => $val) {
                        $clow = strtolower((string)$col);
                        if ($clow === 'key_name' && is_scalar($val)) { $idxName = (string)$val; }
                        if ($clow === 'cardinality' && is_scalar($val) && is_numeric($val)) { $card = (int)$val; }
                    }
                    if ($idxName === '' || $card === null) { continue; }
                    if (!isset($byIndex[$idxName]) || $card > $byIndex[$idxName]) {
                        $byIndex[$idxName] = $card;
                    }
                }
                if (!empty($byIndex)) {
                    $out[$key] = $byIndex;
                }
            } catch (\Throwable $e) {
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeIndexCardinality failed for ' . $table . ': ' . $e->getMessage());
                $errors++;
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out)) {
            throw new \RuntimeException('index_cardinality: all tables failed SHOW INDEX probe');
        }
        return $out;
    }

    /**
     * SHOW GLOBAL STATUS counterpart to mysql_globals. The variables tell
     * us what the server is CONFIGURED to allow; the status counters tell
     * us what is actually HAPPENING. Counters that have ticked up since
     * boot are the strongest proximate-cause signal: lock-wait pile-ups,
     * tmp-disk spills, aborted connects, slow queries.
     *
     * One SHOW GLOBAL STATUS query, parameterized name list, suppressed
     * errors so a perms-denied response degrades to an empty map rather
     * than a payload error.
     *
     * @return array<string, int>
     */
    public function probeMysqlStatus(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for SHOW GLOBAL STATUS probe');
        }
        $names = array(
            'Innodb_buffer_pool_pages_dirty',
            'Innodb_buffer_pool_pages_total',
            'Innodb_row_lock_waits',
            'Innodb_row_lock_time_avg',
            'Innodb_deadlocks',
            'Threads_running',
            'Threads_connected',
            'Aborted_connects',
            'Aborted_clients',
            'Created_tmp_disk_tables',
            'Created_tmp_tables',
            'Slow_queries',
            'Table_locks_waited',
            'Open_tables',
            'Opened_tables',
            'Uptime',
        );
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            $prepared = 'SHOW GLOBAL STATUS';
            if (method_exists($wpdb, 'prepare')) {
                // DAO-bypass-approved: SHOW GLOBAL STATUS placeholder bind; no plugin-table writes possible.
                $prepared = $wpdb->prepare("SHOW GLOBAL STATUS WHERE Variable_name IN ($placeholders)", $names);
            }
            // DAO-bypass-approved: read-only probe of @@GLOBAL_STATUS; no plugin tables involved.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } finally {
            if (method_exists($wpdb, 'suppress_errors')) {
                $wpdb->suppress_errors($prevSuppress);
            }
        }
        if (!is_array($rows)) {
            throw new \RuntimeException('SHOW GLOBAL STATUS returned non-array');
        }
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $name = '';
            $value = '';
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'variable_name' && is_scalar($v)) { $name = strtolower((string)$v); }
                if ($klow === 'value' && is_scalar($v))         { $value = (string)$v; }
            }
            if ($name === '') { continue; }
            if (is_numeric($value)) {
                $out[$name] = (int)$value;
            }
        }
        return $out;
    }

    /**
     * DB-level + per-column collation for the JOIN-hot URL columns on
     * `abj404_redirects` and `abj404_logs_hits`. Collation drift between
     * the two columns disables the index seek silently: MySQL falls back
     * to a full-scan ON the un-joined column. Capturing both lets the
     * server side classify "fast on staging, slow on prod" reports by
     * the cause that is invisible from the SHOW CREATE TABLE output.
     *
     * Shape:
     *   { db_charset: string, db_collate: string,
     *     columns: { '{prefix}abj404_redirects.url': string,
     *                '{prefix}abj404_redirects.canonical_url': string,
     *                '{prefix}abj404_logs_hits.requested_url': string } }
     *
     * @return array<string, mixed>
     */
    public function probeDbCollation(): array {
        $out = array(
            'db_charset' => defined('DB_CHARSET') && is_string(DB_CHARSET) ? DB_CHARSET : '',
            'db_collate' => defined('DB_COLLATE') && is_string(DB_COLLATE) ? DB_COLLATE : '',
            'columns'    => array(),
        );
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_var')) {
            throw new \RuntimeException('wpdb unavailable for db_collation probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $targets = array(
            $prefix . 'abj404_redirects'  => array('url', 'canonical_url'),
            $prefix . 'abj404_logs_hits'  => array('requested_url'),
            $prefix . 'abj404_logsv2'     => array('url'),
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $errors = 0;
        $attempted = 0;
        foreach ($targets as $table => $cols) {
            foreach ($cols as $col) {
                $attempted++;
                try {
                    if (!method_exists($wpdb, 'prepare')) { continue; }
                    // DAO-bypass-approved: information_schema collation probe placeholder bind; no plugin-table writes.
                    $prepared = $wpdb->prepare(
                        'SELECT COLLATION_NAME '
                      . 'FROM information_schema.COLUMNS '
                      . 'WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
                        $table,
                        $col
                    );
                    if ($prepared === null) { continue; }
                    // DAO-bypass-approved: information_schema metadata probe; no plugin-table writes.
                    $v = $wpdb->get_var($prepared);
                    if (is_scalar($v) && (string)$v !== '') {
                        $out['columns'][$table . '.' . $col] = (string)$v;
                    }
                } catch (\Throwable $e) {
                    // allow-silent-catch: per-column probe is best-effort; missing-table / permissions errors must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                    ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeDbCollation probe failed for ' . $table . '.' . $col . ': ' . $e->getMessage());
                    $errors++;
                }
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out['columns'])) {
            throw new \RuntimeException('db_collation: all per-column SQL probes failed');
        }
        return $out;
    }
}
