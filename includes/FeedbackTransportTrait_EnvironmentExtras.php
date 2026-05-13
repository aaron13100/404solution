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
 *     in its own try/catch so a single probe failure cannot blank the
 *     others or block the support send.
 *   tryMixedArray(): sibling of FeedbackTransport::tryArray() that
 *     preserves mixed (string/nested/float/bool) values instead of
 *     coercing to int-only.
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
     * Every probe is wrapped in tryInt/tryArray/tryMixedArray so a failed
     * lookup never blocks the support send. Filterable via the
     * `abj404_environment_extras` filter so operators can append
     * site-specific diagnostics (or strip fields for privacy) before the
     * payload is sent.
     *
     * @return array<string, mixed>
     */
    private static function environmentExtras(): array {
        $extras = array(
            // MySQL global variables: the binding constraints for slow
            // JOIN / GROUP BY on Bruno-class sites. SHOW GLOBAL VARIABLES
            // is read-only, no plugin tables involved.
            'mysql_globals' => self::tryMixedArray(function () { return self::collectMysqlGlobals(); }),

            // MySQL session-variable probe persisted by the staged view-build
            // (DataAccessTrait_ViewBuildSessionEnvProbe). Already covers
            // tmp_table_size, max_heap_table_size, innodb_lock_wait_timeout,
            // wait_timeout, innodb_flush_method, character_set_server.
            // Reading the option instead of re-querying keeps the support
            // request cheap and reflects the state of the most recent build.
            'mysql_session_probe' => self::tryMixedArray(function () { return self::loadViewBuildSessionEnvProbe(); }),

            // Disk headroom on the WP uploads directory (where the plugin's
            // debug log and any cron-scratch files land). "Table is full"
            // errors are nearly always disk-quota, not the logical
            // table-full condition.
            'disk_free_bytes' => self::tryInt(function () { return self::diskFreeBytesOrThrow(); }),
            'disk_total_bytes' => self::tryInt(function () { return self::diskTotalBytesOrThrow(); }),

            // PHP runtime identity beyond version. SAPI distinguishes
            // mod_php (per-request fork, fresh memory) from php-fpm
            // (long-lived worker, opcache hot). max_input_vars caps how
            // many POST fields the importer can accept. realpath_cache
            // size matters for sites with many include paths.
            'php_sapi' => function_exists('php_sapi_name') ? (string)php_sapi_name() : '',
            'php_memory_peak_bytes' => function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : 0,
            'php_opcache_enabled' => self::opcacheEnabled(),
            'php_max_input_vars' => function_exists('ini_get') ? (int)ini_get('max_input_vars') : 0,
            'php_realpath_cache_size_bytes' => function_exists('realpath_cache_size') ? (int)realpath_cache_size() : 0,

            // Plugin table sizes beyond logsv2 (which has its own typed
            // column). redirects volume and logs_hits rollup size are
            // direct signals for the getRedirectsForViewTempTable.sql
            // perf class.
            'plugin_tables_bytes' => self::tryMixedArray(function () { return self::collectPluginTableSizes(); }),

            // View-build freshness signals: when did the rollup last
            // complete, what stage did the most recent build reach, is
            // the rollup stale relative to logsv2? Hand-assembled from
            // plugin options the staged build already writes; no new SQL.
            'view_build_state' => self::tryArray(function () { return self::collectViewBuildState(); }),

            // SHOW PROCESSLIST row count. Indicator of shared-host MySQL
            // saturation: a queue of 200+ idle connections explains why
            // the staged build's BEGIN/COMMIT slots wait. Just the count;
            // no connection details (user/host) are emitted.
            'active_connection_count' => self::tryInt(function () { return self::probeActiveConnectionCount(); }),

            // SHOW INDEX cardinality for the canonical indexes on
            // redirects + logs_hits + logs_hits_preagg. A degraded
            // cardinality (1 row, or NULL after a crash recovery) is a
            // sufficient explanation for a previously-fast JOIN suddenly
            // doing a full table scan. Shape: {table: {index: int}}.
            'index_cardinality' => self::tryMixedArray(function () { return self::probeIndexCardinality(); }),

            // Best-effort hosting-class hint parsed from server_software
            // and host-specific environment markers (cPanel, hPanel,
            // Plesk, WP Engine, Kinsta, Pantheon, Flywheel, RunCloud,
            // CloudPanel). Lets server-side group heartbeats by host
            // class retroactively without paying for a deep fingerprint.
            'hosting_class' => self::tryMixedArray(function () { return self::probeHostingClass(); }),

            // Object-cache backend NAME, not just the on/off enum already
            // shipped in `object_cache`. Detect Redis / Memcached / APCu
            // / W3TC / LiteSpeed / WP Engine native via known constants
            // + wp_using_ext_object_cache(). Stale-cache reports cluster
            // by backend class.
            'object_cache_backend' => self::tryMixedArray(function () { return self::probeObjectCacheBackend(); }),

            // SHOW GLOBAL STATUS counterpart to mysql_globals. Captures
            // runtime symptoms (lock waits, tmp-disk spills, aborted
            // connects, slow queries) that the variables can only
            // bound, never observe.
            'mysql_status' => self::tryArray(function () { return self::probeMysqlStatus(); }),

            // DB charset + collation, plus per-column collation on the
            // canonical JOIN keys for redirects (url, canonical_url) and
            // logs_hits (requested_url). Collation drift silently
            // disables index seeks on JOIN: symptom is "fast on staging,
            // slow on prod with identical data."
            'db_collation' => self::tryMixedArray(function () { return self::probeDbCollation(); }),

            // WP + PHP timezone identity. Bruno-class sites in non-UTC
            // zones (pt_BR, ja_JP) sometimes show off-by-N-hours bugs
            // in cooldown arithmetic; capturing both lets us diff
            // server time vs WP time vs PHP time after the fact.
            'timezone' => self::tryMixedArray(function () { return self::probeTimezone(); }),

            // Install + upgrade history. The single most useful
            // bifurcator for "started after upgrade Tuesday" vs
            // "always broken since install." Read-only from plugin
            // options the upgrade path already writes.
            'plugin_lifecycle' => self::tryMixedArray(function () { return self::probePluginLifecycle(); }),

            // Top distinct recurring error signatures from the debug
            // log file over the last 7 days, capped at 5 entries. The
            // triggering error is captured by the report itself; this
            // captures the recurring error which is often different
            // and which the email-on-first-error path would never send.
            'recent_error_signatures' => self::tryMixedArray(function () { return self::probeRecentErrorSignatures(); }),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_environment_extras', $extras);
            if (is_array($filtered)) {
                $extras = $filtered;
            }
        }

        return $extras;
    }

    /**
     * Call a structured-data helper, returning [] if it throws or
     * returns a non-array. Unlike tryArray(), preserves mixed values
     * (strings, nested arrays, bools, floats) so diagnostic probes
     * can ship their natural shape into the JSON passthrough column
     * without being silently filtered to integer-only entries.
     *
     * @param callable $fn
     * @return array<mixed, mixed>
     */
    private static function tryMixedArray(callable $fn): array {
        try {
            $v = $fn();
            return is_array($v) ? $v : array();
        } catch (\Throwable $e) {
            @error_log('404 Solution: FeedbackTransport structured probe failed: ' . $e->getMessage());
            return array();
        }
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
            return array();
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
        $rows = null;
        try {
            $prepared = "SHOW GLOBAL VARIABLES";
            if (method_exists($wpdb, 'prepare')) {
                // DAO-bypass-approved: SHOW GLOBAL VARIABLES placeholder bind; no plugin-table writes possible.
                $prepared = $wpdb->prepare("SHOW GLOBAL VARIABLES WHERE Variable_name IN ($placeholders)", $names);
            }
            // DAO-bypass-approved: read-only probe of @@GLOBAL; no plugin tables involved.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } catch (\Throwable $e) {
            // allow-silent-catch: globals probe is best-effort diagnostic, must not block support send; suppress restored below
            @error_log('404 Solution: collectMysqlGlobals probe failed: ' . $e->getMessage());
            $rows = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }

        $out = array();
        if (!is_array($rows)) {
            return $out;
        }
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
            return array();
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
        foreach ($candidates as $key => $table) {
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
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map
                @error_log('404 Solution: collectPluginTableSizes probe failed for ' . $table . ': ' . $e->getMessage());
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
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
            return array();
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
        foreach ($candidates as $key => $table) {
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
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map
                @error_log('404 Solution: probeIndexCardinality failed for ' . $table . ': ' . $e->getMessage());
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
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
            return array();
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
        $rows = null;
        try {
            $prepared = 'SHOW GLOBAL STATUS';
            if (method_exists($wpdb, 'prepare')) {
                // DAO-bypass-approved: SHOW GLOBAL STATUS placeholder bind; no plugin-table writes possible.
                $prepared = $wpdb->prepare("SHOW GLOBAL STATUS WHERE Variable_name IN ($placeholders)", $names);
            }
            // DAO-bypass-approved: read-only probe of @@GLOBAL_STATUS; no plugin tables involved.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } catch (\Throwable $e) {
            // allow-silent-catch: status probe is best-effort diagnostic, must not block support send; suppress restored below
            @error_log('404 Solution: probeMysqlStatus probe failed: ' . $e->getMessage());
            $rows = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        $out = array();
        if (!is_array($rows)) {
            return $out;
        }
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
            return $out;
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $targets = array(
            $prefix . 'abj404_redirects'  => array('url', 'canonical_url'),
            $prefix . 'abj404_logs_hits'  => array('requested_url'),
            $prefix . 'abj404_logsv2'     => array('url'),
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        foreach ($targets as $table => $cols) {
            foreach ($cols as $col) {
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
                    // allow-silent-catch: per-column probe is best-effort; missing-table / permissions errors must not abort the whole map
                    @error_log('404 Solution: probeDbCollation probe failed for ' . $table . '.' . $col . ': ' . $e->getMessage());
                }
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
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
}
