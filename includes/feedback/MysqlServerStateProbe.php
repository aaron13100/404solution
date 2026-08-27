<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Read-only probes of the MySQL/MariaDB server's own state for the feedback
 * payload's `environment_extras` field.
 *
 * One subject: the database SERVER. What it is configured to allow (global and
 * session variables), what it is actually doing (status counters), and how
 * saturated it is (processlist row count). No plugin table name appears in
 * this class -- reads about this plugin's own schema live in
 * ABJ_404_Solution_PluginSchemaMetadataProbe, and reads about the plugin's
 * rollup freshness live in ABJ_404_Solution_RollupFreshnessProbe.
 *
 * Every probe throws on a genuine failure (no $wpdb, non-array response)
 * rather than returning an empty map, so the caller's recordProbe() wrapper
 * writes a `<probe>_error` marker key. "Probe failed" and "the server
 * reported nothing" must stay distinguishable in the payload: an empty map
 * that silently means "broken" is the exact defect that left
 * `view_build_state` looking healthy-but-empty for seven weeks
 * (t_260801_071502_922).
 *
 * The DAO-bypass markers here are specifically scoped to read-only @@GLOBAL /
 * @@SESSION / status probes; no plugin-owned table is reachable from any
 * statement in this class, so DatabaseCore's repair-and-retry recovery does
 * not apply (see docs/adr/dataaccess-refactor.md).
 */
class ABJ_404_Solution_MysqlServerStateProbe {

    /**
     * Fixed set of MySQL global variables relevant to view-build / temp-table
     * JOIN performance on Bruno-class hosts. One SHOW GLOBAL VARIABLES query,
     * parameterized name list, suppressed errors so a perms-denied response
     * degrades to an empty map rather than a payload error.
     *
     * @return array<string, mixed>
     */
    public function collectMysqlGlobals(): array {
        return $this->coerceNumericScalars($this->fetchVariableRows('SHOW GLOBAL VARIABLES', array(
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
        )));
    }

    /**
     * Live SHOW SESSION VARIABLES read for the operational and DDL-safety
     * variables that can degrade or break the redirects-hits rollup rebuild
     * (LogsHitsRollupService::createRedirectsForViewHitsTable()) on this
     * connection. Session scope, not global scope: some hosts override these
     * per-connection (a pooler, a session_variables config, a wp-config.php
     * SET SESSION shim), so the global values collectMysqlGlobals() reports
     * can differ from what the rollup rebuild actually ran under.
     *
     * Formerly a get_option() read of a value the deleted staged view-build
     * subsystem used to persist (abj404_view_build_session_env_probe); commit
     * 73a55a70 removed that writer without updating this read, so the option
     * stayed forever empty and `mysql_session_probe` shipped `[]` on every
     * payload. Replaced with a live probe so there is no persisted name left
     * to drift out of sync with its writer again.
     *
     * @return array<string, mixed>
     */
    public function collectMysqlSessionVariables(): array {
        return $this->coerceNumericScalars($this->fetchVariableRows('SHOW SESSION VARIABLES', array(
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
        )));
    }

    /**
     * SHOW GLOBAL STATUS counterpart to collectMysqlGlobals(). The variables
     * tell us what the server is CONFIGURED to allow; the status counters tell
     * us what is actually HAPPENING. Counters that have ticked up since boot
     * are the strongest proximate-cause signal: lock-wait pile-ups, tmp-disk
     * spills, aborted connects, slow queries.
     *
     * Non-numeric status values are dropped rather than emitted as strings:
     * every allowlisted name below is a counter, so a non-numeric value means
     * the server returned something unexpected for that row.
     *
     * @return array<string, int>
     */
    public function probeMysqlStatus(): array {
        $raw = $this->fetchVariableRows('SHOW GLOBAL STATUS', array(
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
        ));
        $out = array();
        foreach ($raw as $name => $value) {
            if (is_numeric($value)) {
                $out[$name] = (int)$value;
            }
        }
        return $out;
    }

    /**
     * Row count from SHOW PROCESSLIST. Cheap on a shared host (returns the
     * current request's view of connection saturation) and a strong leading
     * indicator for "the rollup rebuild is waiting because there are 200 other
     * queries in flight". Only the row count is emitted; user/host/info
     * columns are dropped to avoid PII leakage from other tenants on the same
     * MySQL instance.
     *
     * Throws when the probe genuinely cannot complete (no $wpdb, query failed)
     * so the caller records a marker rather than a misleading zero.
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
     * The connection's own server version string, vendor suffix included
     * ("8.0.45-azure", "10.6.18-MariaDB-log"), or '' when the connection
     * cannot report one.
     *
     * Read off the live connection rather than through `SELECT VERSION()`:
     * mysqli already holds this string, so the probe costs no round trip and
     * cannot fail on a read-only replica or a saturated server. The SUFFIX is
     * what makes it worth collecting here -- `db_version` in the typed columns
     * keeps the number, and the vendor tag on the end is a hosting-platform
     * marker (see FeedbackEnvironmentExtras_PlatformFingerprint's
     * INFRASTRUCTURE_HOST_MARKERS).
     *
     * Returns '' rather than throwing: unlike the probes above this has no
     * `<probe>_error` marker of its own, and a host whose driver hides the
     * version is not a failure, it is one fewer marker.
     */
    public function serverVersionString(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }
        if (isset($wpdb->db_server_info) && is_scalar($wpdb->db_server_info)) {
            return (string)$wpdb->db_server_info;
        }
        if (!method_exists($wpdb, 'db_server_info')) {
            return '';
        }
        try {
            $info = $wpdb->db_server_info();
            return is_scalar($info) ? (string)$info : '';
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn', 'serverVersionString probe failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Run one `SHOW <scope> VARIABLES|STATUS WHERE Variable_name IN (...)`
     * statement and fold the returned rows into a lowercased name => raw
     * string-value map.
     *
     * Shared by all three name/value probes above because MySQL's SHOW
     * VARIABLES and SHOW STATUS wire shape is identical (a Variable_name
     * column and a Value column, whose letter case varies by driver) and the
     * suppress-errors / non-array-response handling has to be identical too.
     * Value COERCION is deliberately not done here: the variables probes keep
     * strings and floats, while the status probe wants ints only, so each
     * caller applies its own policy to the raw map.
     *
     * @param string $statement Bare SHOW statement; also used verbatim in the
     *                          failure messages so each probe's thrown text
     *                          still names the statement that failed.
     * @param array<int, string> $names Allowlisted variable names to bind.
     * @return array<string, string>
     */
    private function fetchVariableRows(string $statement, array $names): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for ' . $statement . ' probe');
        }
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            $prepared = $statement;
            if (method_exists($wpdb, 'prepare')) {
                // DAO-bypass-approved: server variable/status name list placeholder bind; no plugin-table writes possible.
                $prepared = $wpdb->prepare($statement . " WHERE Variable_name IN ($placeholders)", $names);
            }
            // DAO-bypass-approved: read-only probe of server variables/status; no plugin tables involved.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } finally {
            if (method_exists($wpdb, 'suppress_errors')) {
                $wpdb->suppress_errors($prevSuppress);
            }
        }

        if (!is_array($rows)) {
            throw new \RuntimeException($statement . ' returned non-array');
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
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Coerce numeric-looking variable values to int/float so the server-side
     * JSON sort is meaningful (otherwise 9 sorts after 100 lexically) while
     * leaving genuinely textual values (sql_mode, optimizer_switch,
     * collation_server) as strings.
     *
     * @param array<string, string> $raw
     * @return array<string, mixed>
     */
    private function coerceNumericScalars(array $raw): array {
        $out = array();
        foreach ($raw as $name => $value) {
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
}
