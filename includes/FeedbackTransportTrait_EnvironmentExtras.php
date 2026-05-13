<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * environment_extras passthrough probes for the server's JSON column.
 *
 * Extracted from ABJ_404_Solution_FeedbackTransport so the host class
 * stays under the modularity / line-size limits. The trait owns:
 *
 *   environmentExtras(): composer for the JSON passthrough map.
 *   probe*() / collect*(): best-effort diagnostic probes, each wrapped
 *     by recordProbe() so a single probe failure cannot blank the
 *     others or block the support send. Failures emit a marker key
 *     `<probe>_error` with a short slug so the server side can tell
 *     "no data" from "probe failed."
 *   recordProbe() / classifyProbeError(): the wrapper layer that
 *     captures throws and writes the marker key.
 *
 * Every method here is `private static` and uses `self::` to call into
 * the host class's helpers (tryInt / tryArray). The trait is composed
 * into ABJ_404_Solution_FeedbackTransport via a single `use` statement.
 *
 * The probe set is documented in detail in
 * docs/bruno-failure-modes-2026-05-13.md (server-side correlation
 * targets) and pinned by tests/FeedbackTransportEnvironmentExtrasTest.
 */
trait ABJ_404_Solution_FeedbackTransport_EnvironmentExtrasTrait {

    /**
     * Best-effort diagnostic passthrough for the server's JSON column. The
     * typed columns cover plugin version + WP/PHP/DB identity + content
     * counts, but they cannot cover the operational signals that decide
     * whether a query times out on a real shared host: MySQL memory globals
     * (innodb_buffer_pool_size, tmp_table_size), disk headroom,
     * and PHP SAPI specifics that the server doesn't pre-declare.
     *
     * Every probe is wrapped in recordProbe() so a failed lookup never
     * blocks the support send and surfaces a `<probe>_error` marker
     * with a short server-groupable slug. Filterable via the
     * `abj404_environment_extras` filter so operators can append
     * site-specific diagnostics (or strip fields for privacy) before the
     * payload is sent.
     *
     * @return array<string, mixed>
     */
    private static function environmentExtras(): array {
        $extras = array();

        // MySQL global variables: the binding constraints for slow
        // JOIN / GROUP BY on Bruno-class sites. SHOW GLOBAL VARIABLES
        // is read-only, no plugin tables involved.
        self::recordProbe($extras, 'mysql_globals', static function () { return self::collectMysqlGlobals(); }, array());

        // MySQL session-variable probe persisted by the staged view-build
        // (DataAccessTrait_ViewBuildSessionEnvProbe). Already covers
        // tmp_table_size, max_heap_table_size, innodb_lock_wait_timeout,
        // wait_timeout, innodb_flush_method, character_set_server.
        // Reading the option instead of re-querying keeps the support
        // request cheap and reflects the state of the most recent build.
        self::recordProbe($extras, 'mysql_session_probe', static function () { return self::loadViewBuildSessionEnvProbe(); }, array());

        // Disk headroom on the WP uploads directory (where the plugin's
        // debug log and any cron-scratch files land). "Table is full"
        // errors are nearly always disk-quota, not the logical
        // table-full condition.
        self::recordProbe($extras, 'disk_free_bytes', static function () { return self::diskFreeBytesOrThrow(); }, null);
        self::recordProbe($extras, 'disk_total_bytes', static function () { return self::diskTotalBytesOrThrow(); }, null);

        // PHP runtime identity beyond version. SAPI distinguishes
        // mod_php (per-request fork, fresh memory) from php-fpm
        // (long-lived worker, opcache hot). max_input_vars caps how
        // many POST fields the importer can accept. realpath_cache
        // size matters for sites with many include paths.
        $extras['php_sapi'] = function_exists('php_sapi_name') ? (string)php_sapi_name() : '';
        $extras['php_memory_peak_bytes'] = function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : 0;
        $extras['php_opcache_enabled'] = self::opcacheEnabled();
        $extras['php_max_input_vars'] = function_exists('ini_get') ? (int)ini_get('max_input_vars') : 0;
        $extras['php_realpath_cache_size_bytes'] = function_exists('realpath_cache_size') ? (int)realpath_cache_size() : 0;

        // Plugin table sizes beyond logsv2 (which has its own typed
        // column). redirects volume and logs_hits rollup size are
        // direct signals for the getRedirectsForViewTempTable.sql
        // perf class.
        self::recordProbe($extras, 'plugin_tables_bytes', static function () { return self::collectPluginTableSizes(); }, array());

        // View-build freshness signals: when did the rollup last
        // complete, what stage did the most recent build reach, is
        // the rollup stale relative to logsv2? Hand-assembled from
        // plugin options the staged build already writes; no new SQL.
        self::recordProbe($extras, 'view_build_state', static function () { return self::collectViewBuildState(); }, array());

        // SHOW PROCESSLIST row count. Indicator of shared-host MySQL
        // saturation: a queue of 200+ idle connections explains why
        // the staged build's BEGIN/COMMIT slots wait. Just the count;
        // no connection details (user/host) are emitted.
        self::recordProbe($extras, 'active_connection_count', static function () { return self::probeActiveConnectionCount(); }, null);

        // SHOW INDEX cardinality for the canonical indexes on
        // redirects + logs_hits + logs_hits_preagg. A degraded
        // cardinality (1 row, or NULL after a crash recovery) is a
        // sufficient explanation for a previously-fast JOIN suddenly
        // doing a full table scan. Shape: {table: {index: int}}.
        self::recordProbe($extras, 'index_cardinality', static function () { return self::probeIndexCardinality(); }, array());

        // Best-effort hosting-class hint parsed from server_software
        // and host-specific environment markers (cPanel, hPanel,
        // Plesk, WP Engine, Kinsta, Pantheon, Flywheel, RunCloud,
        // CloudPanel). Lets server-side group heartbeats by host
        // class retroactively without paying for a deep fingerprint.
        self::recordProbe($extras, 'hosting_class', static function () { return self::probeHostingClass(); }, array());

        // Object-cache backend NAME, not just the on/off enum already
        // shipped in `object_cache`. Detect Redis / Memcached / APCu
        // / W3TC / LiteSpeed / WP Engine native via known constants
        // + wp_using_ext_object_cache(). Stale-cache reports cluster
        // by backend class.
        self::recordProbe($extras, 'object_cache_backend', static function () { return self::probeObjectCacheBackend(); }, array());

        // SHOW GLOBAL STATUS counterpart to mysql_globals. Captures
        // runtime symptoms (lock waits, tmp-disk spills, aborted
        // connects, slow queries) that the variables can only
        // bound, never observe.
        self::recordProbe($extras, 'mysql_status', static function () { return self::probeMysqlStatus(); }, array());

        // DB charset + collation, plus per-column collation on the
        // canonical JOIN keys for redirects (url, canonical_url) and
        // logs_hits (requested_url). Collation drift silently
        // disables index seeks on JOIN: symptom is "fast on staging,
        // slow on prod with identical data."
        self::recordProbe($extras, 'db_collation', static function () { return self::probeDbCollation(); }, array());

        // WP + PHP timezone identity. Bruno-class sites in non-UTC
        // zones (pt_BR, ja_JP) sometimes show off-by-N-hours bugs
        // in cooldown arithmetic; capturing both lets us diff
        // server time vs WP time vs PHP time after the fact.
        self::recordProbe($extras, 'timezone', static function () { return self::probeTimezone(); }, array());

        // Install + upgrade history. The single most useful
        // bifurcator for "started after upgrade Tuesday" vs
        // "always broken since install." Read-only from plugin
        // options the upgrade path already writes.
        self::recordProbe($extras, 'plugin_lifecycle', static function () { return self::probePluginLifecycle(); }, array());

        // Top distinct recurring error signatures from the debug
        // log file over the last 7 days, capped at 5 entries. The
        // triggering error is captured by the report itself; this
        // captures the recurring error which is often different
        // and which the email-on-first-error path would never send.
        self::recordProbe($extras, 'recent_error_signatures', static function () { return self::probeRecentErrorSignatures(); }, array());

        // opcache detail beyond the on/off enum already shipped
        // in `php_opcache_enabled`. validate_timestamps=0 +
        // revalidate_freq high explains "fresh install still
        // buggy after upgrade" reports where the host serves
        // cached bytecode from the prior version.
        self::recordProbe($extras, 'opcache_settings', static function () { return self::probeOpcacheSettings(); }, array());

        // open_basedir restriction string (or null when not set).
        // Hardened shared hosts use this to box file access;
        // explains "permission denied" failures on paths the
        // plugin can otherwise write.
        $extras['open_basedir'] = self::probeOpenBasedir();

        // Multisite identity: is this the main site, what blog
        // and network are we on, is the plugin network-activated?
        // Behavior differs significantly across these axes
        // (network-active vs single-site-active changes hook
        // registration and upgrade scheduling).
        self::recordProbe($extras, 'multisite_role', static function () { return self::probeMultisiteRole(); }, array());

        // .htaccess writability at the WP home path. When false
        // the plugin's Apache-rule install path cannot succeed
        // and we fall back to the DB-only redirect handler.
        // Differentiates "redirects not firing" reports between
        // "Apache rule never wrote" and "DB handler bug".
        $extras['htaccess_writable'] = self::probeHtaccessWritable();

        // /tmp filesystem free bytes. Some shared hosts have
        // separate /tmp quotas from the WP install path; tmp
        // exhaustion breaks MySQL tmp tables (Created_tmp_disk_*
        // counter) and PHP file uploads. disk_free_bytes on the
        // uploads dir cannot see this.
        self::recordProbe($extras, 'tmp_free_bytes', static function () { return self::probeTmpFreeBytesOrThrow(); }, null);

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_environment_extras', $extras);
            if (is_array($filtered)) {
                $extras = $filtered;
            }
        }

        return $extras;
    }

    /**
     * Run a probe and write either its return value into $extras[$key]
     * on success, or a default value plus a marker $extras[$key.'_error']
     * on failure. The marker is a short server-groupable slug
     * ('sql_failed', 'wpdb_unavailable', 'fs_unavailable',
     * 'invalid_shape', 'exception:<class>'), not the raw exception
     * message. Exception text can carry PII (paths, user-supplied
     * fragments) and we explicitly do not ship it. The raw message
     * still goes to error_log so the host's local debugging is
     * unaffected.
     *
     * Why marker keys at all: the prior pattern (tryMixedArray returning
     * empty array) could not distinguish "probe succeeded with no data"
     * from "probe failed and we have no signal." Markers make failure
     * explicit so the server side does not have to guess.
     *
     * @param array<string,mixed> $extras
     * @param string $key
     * @param callable $fn
     * @param mixed $default Value written to $extras[$key] on failure
     *   so downstream consumers can iterate without per-probe null
     *   checks.
     * @return void
     */
    private static function recordProbe(array &$extras, string $key, callable $fn, $default): void {
        try {
            $value = $fn();
        } catch (\Throwable $e) {
            $extras[$key] = $default;
            $extras[$key . '_error'] = self::classifyProbeError($e);
            @error_log('404 Solution: FeedbackTransport probe "' . $key . '" failed: ' . $e->getMessage());
            return;
        }
        $extras[$key] = $value;
    }

    /**
     * Map a thrown probe exception to a short server-groupable slug.
     * Matched on the message rather than the exception class because
     * the probe helpers all throw \RuntimeException. The message is
     * the differentiator. Unmatched throws degrade to
     * 'exception:<ShortClass>' so the slug still carries fingerprint.
     *
     * @param \Throwable $e
     * @return string
     */
    private static function classifyProbeError(\Throwable $e): string {
        $msg = strtolower((string)$e->getMessage());
        if (strpos($msg, 'wpdb unavailable') !== false || strpos($msg, 'wpdb missing') !== false) {
            return 'wpdb_unavailable';
        }
        if (strpos($msg, 'disk_free_space') !== false
            || strpos($msg, 'disk_total_space') !== false
            || strpos($msg, 'sys_get_temp_dir') !== false) {
            return 'fs_unavailable';
        }
        if (strpos($msg, 'invalid shape') !== false
            || strpos($msg, 'non-array') !== false
            || strpos($msg, 'unexpected shape') !== false) {
            return 'invalid_shape';
        }
        if (strpos($msg, 'sql') !== false
            || strpos($msg, 'mysql') !== false
            || strpos($msg, 'mariadb') !== false
            || strpos($msg, 'query') !== false
            || strpos($msg, 'processlist') !== false
            || strpos($msg, 'simulated db') !== false
            || strpos($msg, 'show global') !== false
            || strpos($msg, 'show index') !== false
            || strpos($msg, 'show processlist') !== false
            || strpos($msg, 'information_schema') !== false
            || strpos($msg, 'all tables failed') !== false
            || strpos($msg, 'no tables probed') !== false) {
            return 'sql_failed';
        }
        $shortClass = (new \ReflectionClass($e))->getShortName();
        return 'exception:' . $shortClass;
    }

    /**
     * Pull a fixed set of MySQL global variables relevant to staged
     * view-build / temp-table JOIN performance on Bruno-class hosts. One
     * SHOW GLOBAL VARIABLES query, parameterized name list, suppressed
     * errors so a perms-denied response degrades to an empty map rather
     * than a payload error.
     *
     * @return array<string, mixed>
     */
    private static function collectMysqlGlobals(): array {
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
     * Read the persisted session-variable probe written at S1 entry by
     * the staged view-build pipeline (DataAccessTrait_ViewBuildSessionEnvProbe).
     * Reflects the most recent build's MySQL session settings without
     * paying for a fresh SHOW SESSION VARIABLES on the support-request path.
     *
     * @return array<string, mixed>
     */
    private static function loadViewBuildSessionEnvProbe(): array {
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
     * Free bytes available on the WP uploads directory's filesystem. Used
     * to triage "Table is full" reports (the logical table-full condition
     * is rare, disk quota is common). Throws when disk_free_space() is
     * disabled (open_basedir, hardened hosts) so the caller's tryInt
     * wrapper records null rather than a misleading zero.
     *
     * @return int
     */
    private static function diskFreeBytesOrThrow(): int {
        if (!function_exists('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $dir = self::supportDiagnosticsDirectory();
        $v = @disk_free_space($dir);
        if ($v === false) {
            throw new \RuntimeException('disk_free_space returned false for ' . $dir);
        }
        return (int)$v;
    }

    /**
     * Total bytes on the same filesystem. Combined with disk_free_bytes,
     * lets the server-side report show "8% free" rather than a raw byte
     * count that is hard to interpret across hosts.
     *
     * @return int
     */
    private static function diskTotalBytesOrThrow(): int {
        if (!function_exists('disk_total_space')) {
            throw new \RuntimeException('disk_total_space unavailable');
        }
        $dir = self::supportDiagnosticsDirectory();
        $v = @disk_total_space($dir);
        if ($v === false) {
            throw new \RuntimeException('disk_total_space returned false for ' . $dir);
        }
        return (int)$v;
    }

    /**
     * Best directory to probe for the plugin's filesystem headroom. The
     * uploads dir is the most useful target (the debug log and any
     * cron-scratch files land there), but it may not be writable in
     * locked-down installs. Falls back to ABSPATH and finally __DIR__.
     *
     * @return string
     */
    private static function supportDiagnosticsDirectory(): string {
        if (function_exists('wp_upload_dir')) {
            $info = wp_upload_dir(null, false);
            if (is_array($info) && isset($info['basedir']) && is_string($info['basedir']) && $info['basedir'] !== '') {
                return $info['basedir'];
            }
        }
        if (defined('ABSPATH') && is_string(ABSPATH) && ABSPATH !== '') {
            return ABSPATH;
        }
        return __DIR__;
    }

    /** @return bool */
    private static function opcacheEnabled(): bool {
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
            if (is_array($st) && isset($st['opcache_enabled'])) {
                return (bool)$st['opcache_enabled'];
            }
        }
        if (function_exists('ini_get')) {
            $v = ini_get('opcache.enable');
            if ($v === false) {
                return false;
            }
            return ((int)$v === 1 || strtolower((string)$v) === 'on');
        }
        return false;
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
    private static function collectPluginTableSizes(): array {
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
                @error_log('404 Solution: collectPluginTableSizes probe failed for ' . $table . ': ' . $e->getMessage());
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
    private static function collectViewBuildState(): array {
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
    private static function probeActiveConnectionCount(): int {
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
            @error_log('404 Solution: probeActiveConnectionCount failed: ' . $e->getMessage());
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
    private static function probeIndexCardinality(): array {
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
                @error_log('404 Solution: probeIndexCardinality failed for ' . $table . ': ' . $e->getMessage());
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
     * Best-effort hosting-class hint. Parses well-known markers from
     * server_software + per-host environment vars + per-host PHP
     * constants. Returns a small object so the server side can
     * distinguish "WP Engine" from "Kinsta" without re-parsing strings.
     *
     * No PII: only matched markers are returned. server_software is NOT
     * echoed wholesale; it may include a hostname.
     *
     * @return array<string, mixed>
     */
    private static function probeHostingClass(): array {
        $out = array(
            'host'           => 'unknown',
            'panel'          => 'unknown',
            'matched_marker' => '',
        );
        $sw = '';
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE'])) {
            $sw = strtolower((string)$_SERVER['SERVER_SOFTWARE']);
        }
        // Webserver class only (no version, no hostname).
        if (strpos($sw, 'apache') !== false)       { $out['server_class'] = 'apache'; }
        elseif (strpos($sw, 'nginx') !== false)    { $out['server_class'] = 'nginx'; }
        elseif (strpos($sw, 'litespeed') !== false){ $out['server_class'] = 'litespeed'; }
        elseif (strpos($sw, 'iis') !== false)      { $out['server_class'] = 'iis'; }
        else                                       { $out['server_class'] = ($sw === '' ? 'unknown' : 'other'); }

        // Managed-host markers: each host publishes a distinctive
        // constant or environment variable.
        $managedHostChecks = array(
            'wp_engine'   => array('const' => array('WPE_APIKEY', 'WPE_PLUGIN_DIR'), 'env' => array('IS_WPE')),
            'kinsta'      => array('const' => array('KINSTA_CACHE_ZONE'), 'env' => array('KINSTA_SERVICE_NAME')),
            'pantheon'    => array('const' => array('PANTHEON_ENVIRONMENT'), 'env' => array('PANTHEON_ENVIRONMENT')),
            'flywheel'    => array('const' => array('FLYWHEEL_CONFIG_DIR', 'FLYWHEEL_PLUGIN_DIR'), 'env' => array()),
            'pressable'   => array('const' => array('PRESSABLE_VERSION'), 'env' => array()),
            'siteground'  => array('const' => array('SG_OPTIMIZER_VERSION'), 'env' => array()),
            'wordpress_com' => array('const' => array('IS_ATOMIC', 'IS_WPCOM'), 'env' => array()),
            'cloudways'   => array('const' => array(), 'env' => array('cw_allowed_ip')),
        );
        foreach ($managedHostChecks as $hostKey => $checks) {
            foreach ((array)$checks['const'] as $c) {
                if (defined($c)) {
                    $out['host'] = $hostKey;
                    $out['matched_marker'] = 'const:' . $c;
                    break 2;
                }
            }
            foreach ((array)$checks['env'] as $e) {
                if (getenv($e) !== false) {
                    $out['host'] = $hostKey;
                    $out['matched_marker'] = 'env:' . $e;
                    break 2;
                }
            }
        }

        // Control-panel markers: cPanel / hPanel / Plesk / DirectAdmin /
        // RunCloud / CloudPanel. These are independent of the managed-host
        // class above: a cPanel site might also be on SiteGround.
        $panelChecks = array(
            'cpanel'      => array('env' => array('CPANEL'), 'path' => array('/usr/local/cpanel')),
            'hpanel'      => array('env' => array('HOSTINGER'), 'path' => array('/usr/local/hostinger')),
            'plesk'       => array('env' => array('PLESK_ADMIN_PASSWORD'), 'path' => array('/usr/local/psa', '/opt/psa')),
            'directadmin' => array('env' => array(), 'path' => array('/usr/local/directadmin')),
            'runcloud'    => array('env' => array(), 'path' => array('/etc/runcloud')),
            'cloudpanel'  => array('env' => array(), 'path' => array('/home/clp')),
        );
        foreach ($panelChecks as $panelKey => $checks) {
            foreach ((array)$checks['env'] as $e) {
                if (getenv($e) !== false) {
                    $out['panel'] = $panelKey;
                    if ($out['matched_marker'] === '') {
                        $out['matched_marker'] = 'env:' . $e;
                    }
                    break 2;
                }
            }
            foreach ((array)$checks['path'] as $p) {
                if (is_dir($p)) {
                    $out['panel'] = $panelKey;
                    if ($out['matched_marker'] === '') {
                        $out['matched_marker'] = 'path:' . $p;
                    }
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * Object-cache backend NAME. The base payload's `object_cache` enum
     * answers "external or default"; this answers "external WHAT": Redis
     * (predis vs phpredis vs Redis Object Cache plugin), Memcached,
     * APCu, W3TC, LiteSpeed, WP Engine native, Pantheon, etc.
     *
     * @return array<string, mixed>
     */
    private static function probeObjectCacheBackend(): array {
        $out = array(
            'using_ext_cache' => false,
            'backend'         => 'unknown',
            'backend_detail'  => '',
        );
        if (function_exists('wp_using_ext_object_cache')) {
            $out['using_ext_cache'] = (bool)wp_using_ext_object_cache();
        }
        // Known constants/classes/extensions from popular object-cache
        // drop-ins. Each tuple is (name, type, marker): the first match
        // wins so a Redis Object Cache Pro install is not also tagged
        // as plain Redis.
        $checks = array(
            array('redis_object_cache_pro', 'const', 'WP_REDIS_VERSION'),
            array('redis_object_cache_pro', 'class', 'RedisCachePro\\Plugin'),
            array('redis_object_cache',     'class', 'WP_Object_Cache'),
            array('memcached',              'class', 'Memcached'),
            array('apcu',                   'ext',   'apcu'),
            array('w3_total_cache',         'const', 'W3TC_VERSION'),
            array('litespeed_cache',        'const', 'LSCWP_DIR'),
            array('wp_engine_native',       'const', 'WPE_APIKEY'),
            array('pantheon',               'const', 'PANTHEON_ENVIRONMENT'),
        );
        foreach ($checks as $check) {
            list($name, $type, $marker) = $check;
            if ($type === 'const' && defined($marker)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'const:' . $marker;
                return $out;
            }
            if ($type === 'class' && class_exists($marker, false)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'class:' . $marker;
                return $out;
            }
            if ($type === 'ext' && extension_loaded($marker)) {
                $out['backend'] = $name;
                $out['backend_detail'] = 'ext:' . $marker;
                return $out;
            }
        }
        // Default WP object cache used in-memory per request.
        if (!$out['using_ext_cache']) {
            $out['backend'] = 'default';
            $out['backend_detail'] = 'wp_object_cache:in_memory';
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
    private static function probeMysqlStatus(): array {
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
    private static function probeDbCollation(): array {
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
                    @error_log('404 Solution: probeDbCollation probe failed for ' . $table . '.' . $col . ': ' . $e->getMessage());
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

    /**
     * Timezone identity for the WP install, PHP runtime, and OS. The
     * canonical "off-by-N-hours" cron-window bug class is when WP thinks
     * it is in pt_BR while PHP is in UTC; capturing all three lets us
     * detect drift retroactively.
     *
     * @return array<string, mixed>
     */
    private static function probeTimezone(): array {
        $out = array(
            'wp_timezone'              => '',
            'wp_gmt_offset'            => 0,
            'php_timezone'             => '',
            'server_utc_offset_seconds' => 0,
        );
        if (function_exists('get_option')) {
            $tz = get_option('timezone_string', '');
            if (is_scalar($tz)) { $out['wp_timezone'] = (string)$tz; }
            $off = get_option('gmt_offset', 0);
            if (is_scalar($off)) { $out['wp_gmt_offset'] = (int)round((float)$off * 3600); }
        }
        if (function_exists('date_default_timezone_get')) {
            $out['php_timezone'] = (string)date_default_timezone_get();
        }
        try {
            $tz = new \DateTimeZone($out['php_timezone'] !== '' ? $out['php_timezone'] : 'UTC');
            $dt = new \DateTime('now', $tz);
            $out['server_utc_offset_seconds'] = (int)$tz->getOffset($dt);
        } catch (\Throwable $e) {
            // allow-silent-catch: server_utc_offset is best-effort; an invalid tz string leaves the default zero in place
            @error_log('404 Solution: probeTimezone offset probe failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Install + upgrade timeline. The single most useful bifurcator for
     * "started after upgrade Tuesday" vs "always broken since install."
     * Read-only from plugin options the upgrade path already writes;
     * no new SQL, no new options.
     *
     * Fields:
     *   installed_at:     int|null  unix seconds, from abj404_installed_time
     *   current_version:  string    ABJ404_VERSION (live)
     *   db_version_option string|null abj404_settings['DB_VERSION'] (the value
     *                                 stamped at the last upgrade; equals
     *                                 current_version after the upgrade path
     *                                 ran, mismatches between upgrade tick
     *                                 and DB_VERSION write on lock contention)
     *
     * @return array<string, mixed>
     */
    private static function probePluginLifecycle(): array {
        $out = array(
            'installed_at'      => null,
            'current_version'   => defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : '',
            'db_version_option' => null,
        );
        if (function_exists('get_option')) {
            $t = get_option('abj404_installed_time', null);
            if (is_scalar($t) && is_numeric($t)) {
                $out['installed_at'] = (int)$t;
            }
            $settings = get_option('abj404_settings', null);
            if (is_array($settings) && isset($settings['DB_VERSION']) && is_scalar($settings['DB_VERSION'])) {
                $out['db_version_option'] = (string)$settings['DB_VERSION'];
            }
        }
        return $out;
    }

    /**
     * Top distinct recurring error signatures from the plugin's debug
     * log file over the last 7 days, capped at 5 entries. The triggering
     * error is captured by the report itself ('error_signature' on the
     * payload); this probe captures the recurring error which is often
     * different and would never reach the email-on-first-error path.
     *
     * Bounded cost: reads the tail 256 KB of the debug file, parses
     * lines matching the canonical "YYYY-MM-DD HH:MM:SS (LEVEL): ..."
     * shape, keeps only [ERROR]/[WARN] entries within the last 7 days,
     * groups by a coarse signature (first 200 chars after the level),
     * keeps the top 5 by count. Returns an empty array on any read
     * failure.
     *
     * Shape:
     *   [ {signature: string, count: int, last_seen_at: int}, ... ]
     *
     * @return array<int, array<string, mixed>>
     */
    private static function probeRecentErrorSignatures(): array {
        $out = array();
        try {
            $log = abj_service('logging');
        // allow-silent-catch: container miss is fatal for this probe; null check below records empty
        } catch (\Throwable $e) {
            return $out;
        }
        if (!is_object($log) || !method_exists($log, 'getDebugFilePath')) {
            return $out;
        }
        $path = (string)$log->getDebugFilePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $out;
        }
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return $out;
        }
        $readBytes = 262144; // 256 KB
        $offset = $size > $readBytes ? $size - $readBytes : 0;
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            return $out;
        }
        $tail = '';
        try {
            if ($offset > 0) {
                @fseek($fh, $offset);
                // Discard the partial first line so we only group on whole records.
                @fgets($fh);
            }
            $chunk = @fread($fh, $readBytes);
            if (is_string($chunk)) {
                $tail = $chunk;
            }
        } finally {
            @fclose($fh);
        }
        if ($tail === '') {
            return $out;
        }
        $cutoff = time() - 7 * 86400;
        $byKey = array();
        $lines = preg_split('/\r?\n/', $tail);
        if (!is_array($lines)) {
            return $out;
        }
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '') { continue; }
            // Match "YYYY-MM-DD HH:MM:SS (LEVEL): tail..." per Logging.php format.
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \((ERROR|WARN)\):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $ts = strtotime($m[1]);
            if ($ts === false || $ts < $cutoff) { continue; }
            $level = $m[2];
            $msg = trim($m[3]);
            if ($msg === '') { continue; }
            $sig = $level . ':' . substr(self::normalizeErrorSignature($msg), 0, 200);
            if (!isset($byKey[$sig])) {
                $byKey[$sig] = array('signature' => $sig, 'count' => 0, 'last_seen_at' => 0);
            }
            $byKey[$sig]['count']++;
            if ($ts > $byKey[$sig]['last_seen_at']) {
                $byKey[$sig]['last_seen_at'] = $ts;
            }
        }
        if (empty($byKey)) {
            return $out;
        }
        $list = array_values($byKey);
        usort($list, function ($a, $b) {
            $cmp = $b['count'] - $a['count'];
            if ($cmp !== 0) { return $cmp; }
            return $b['last_seen_at'] - $a['last_seen_at'];
        });
        return array_slice($list, 0, 5);
    }

    /**
     * Coarse-grain an error message so different incident timestamps,
     * memory addresses, file paths, and line numbers fold into the same
     * signature. Used by probeRecentErrorSignatures to group recurring
     * errors.
     *
     * @param string $msg
     * @return string
     */
    private static function normalizeErrorSignature(string $msg): string {
        $s = $msg;
        // Strip absolute paths to just the basename.
        $s = preg_replace('#/[A-Za-z0-9_\-\./]+/([A-Za-z0-9_\-]+\.php)#', '$1', $s) ?? $s;
        // Collapse memory addresses, hex, and digit sequences.
        $s = preg_replace('/\b0x[0-9a-fA-F]+\b/', '0xN', $s) ?? $s;
        $s = preg_replace('/\b\d{4,}\b/', 'N', $s) ?? $s;
        // Collapse runs of whitespace.
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * opcache detail fields beyond the on/off enum. Each value is
     * explicitly nullable: ini_get() returns false when the directive
     * is unknown, and "we couldn't read it" is materially different
     * from a stamped 0/false the host configured deliberately.
     *
     * Shape:
     *   { revalidate_freq: int|null,
     *     validate_timestamps: bool|null,
     *     enable_cli: bool|null }
     *
     * @return array<string, mixed>
     */
    private static function probeOpcacheSettings(): array {
        $out = array(
            'revalidate_freq'     => null,
            'validate_timestamps' => null,
            'enable_cli'          => null,
        );
        if (!function_exists('ini_get')) {
            return $out;
        }
        $rf = ini_get('opcache.revalidate_freq');
        if ($rf !== false) {
            $out['revalidate_freq'] = (int)$rf;
        }
        $vt = ini_get('opcache.validate_timestamps');
        if ($vt !== false) {
            $out['validate_timestamps'] = ((int)$vt === 1 || strtolower((string)$vt) === 'on');
        }
        $ec = ini_get('opcache.enable_cli');
        if ($ec !== false) {
            $out['enable_cli'] = ((int)$ec === 1 || strtolower((string)$ec) === 'on');
        }
        return $out;
    }

    /**
     * open_basedir restriction string, or null when not configured.
     * Returned wholesale (path list) so the server side can match it
     * against the plugin's known write targets; the value is not PII
     * and the per-host shapes vary enough that any normalization here
     * would lose signal.
     *
     * @return string|null
     */
    private static function probeOpenBasedir(): ?string {
        if (!function_exists('ini_get')) {
            return null;
        }
        $v = ini_get('open_basedir');
        if (!is_string($v) || $v === '') {
            return null;
        }
        return $v;
    }

    /**
     * Multisite identity for the request the report originates from.
     * When `is_multisite()` is false the rest of the shape is omitted
     * rather than emitted as nulls per probe (a single-site install
     * has no blog_id/network_id and the keys would be misleading).
     *
     * Shape (multisite):
     *   { is_multisite: true,
     *     is_main_site: bool|null,
     *     blog_id: int|null,
     *     network_id: int|null,
     *     network_activated: bool|null }
     *
     * Shape (single-site):
     *   { is_multisite: false }
     *
     * @return array<string, mixed>
     */
    private static function probeMultisiteRole(): array {
        $isMultisite = function_exists('is_multisite') && (bool)is_multisite();
        $out = array('is_multisite' => $isMultisite);
        if (!$isMultisite) {
            return $out;
        }
        $out['is_main_site'] = function_exists('is_main_site') ? (bool)is_main_site() : null;
        $out['blog_id'] = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : null;
        $out['network_id'] = function_exists('get_current_network_id') ? (int)get_current_network_id() : null;

        $networkActivated = null;
        if (function_exists('is_plugin_active_for_network') && function_exists('plugin_basename') && defined('ABJ404_FILE')) {
            try {
                $networkActivated = (bool) is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
            } catch (\Throwable $e) {
                // allow-silent-catch: best-effort multisite probe; is_plugin_active_for_network requires wp-admin context that may not be loaded on front-end / cron paths, leave null
                @error_log('404 Solution: probeMultisiteRole network-activated check failed: ' . $e->getMessage());
                $networkActivated = null;
            }
        }
        $out['network_activated'] = $networkActivated;
        return $out;
    }

    /**
     * Whether the .htaccess at the WP home path is writable by the
     * plugin. Differentiates "Apache rule install will succeed" from
     * "must use the DB-only redirect handler". Falls back to ABSPATH
     * when get_home_path() is unavailable (front-end / cron context
     * loads it on demand from wp-admin/includes/file.php).
     *
     * @return bool
     */
    private static function probeHtaccessWritable(): bool {
        $path = self::resolveHtaccessPath();
        if ($path === '') {
            return false;
        }
        // is_writable() returns false on a non-existent file too,
        // which matches the install-method intent: if the file does
        // not yet exist and we cannot write the directory either, the
        // Apache-rule path cannot succeed.
        return @is_writable($path);
    }

    /**
     * Best path to test for .htaccess writability. Prefers
     * get_home_path() (which honors WordPress in-subdir installs);
     * falls back to ABSPATH for early-boot / front-end contexts where
     * wp-admin/includes/file.php has not been loaded.
     *
     * @return string
     */
    private static function resolveHtaccessPath(): string {
        if (function_exists('get_home_path')) {
            $home = (string) get_home_path();
            if ($home !== '') {
                return rtrim($home, "/\\") . '/.htaccess';
            }
        }
        if (defined('ABSPATH') && ABSPATH !== '') {
            return rtrim(ABSPATH, "/\\") . '/.htaccess';
        }
        return '';
    }

    /**
     * Free bytes on the system temp directory's filesystem. Some
     * shared hosts mount /tmp as a separate quota from the WP install
     * path; the disk_free_bytes probe (which targets the uploads dir)
     * cannot see /tmp exhaustion. Throws when disk_free_space is
     * disabled so the caller's tryInt wrapper records null rather
     * than a misleading zero.
     *
     * @return int
     */
    private static function probeTmpFreeBytesOrThrow(): int {
        if (!function_exists('disk_free_space')) {
            throw new \RuntimeException('disk_free_space unavailable');
        }
        $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        if ($tmp === '') {
            throw new \RuntimeException('sys_get_temp_dir returned empty');
        }
        $v = @disk_free_space($tmp);
        if ($v === false) {
            throw new \RuntimeException('disk_free_space returned false for ' . $tmp);
        }
        return (int)$v;
    }
}
