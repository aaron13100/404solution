<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackEnvironmentExtras_DbProbes.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_HostProbes.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_PlatformFingerprint.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_DebugLogSignatures.php';

/**
 * Environment-extras passthrough probes for the feedback payload's JSON column.
 *
 * Orchestrates a set of best-effort diagnostic probes about the server
 * environment (MySQL globals, disk headroom, PHP SAPI, hosting class,
 * etc.) and packages them into a keyed array for the `environment_extras`
 * field of the feedback payload.
 *
 * This class owns ONLY the probe registry and the failure-isolation
 * wrapper. The probe implementations live in four collaborator classes,
 * partitioned by data source and lifecycle:
 *   - FeedbackEnvironmentExtras_DbProbes: MySQL/MariaDB probes via $wpdb
 *     (SHOW GLOBAL VARIABLES/STATUS, SHOW PROCESSLIST, SHOW INDEX,
 *     information_schema, view-build option signals).
 *   - FeedbackEnvironmentExtras_HostProbes: dynamic PHP/OS/WP runtime
 *     state (opcache, filesystem headroom, open_basedir, timezone,
 *     multisite role, htaccess writability, lifecycle).
 *   - FeedbackEnvironmentExtras_PlatformFingerprint: static platform
 *     identity (hosting class, control panel, object-cache backend) --
 *     marker-table scans that rarely change for the life of the install.
 *   - FeedbackEnvironmentExtras_DebugLogSignatures: tail-read of the
 *     plugin debug log + PII-stripping signature normalization for
 *     `recent_error_signatures`.
 *
 * Each probe is wrapped by recordProbe() so a single probe failure
 * cannot blank the others or block the support send. Failures emit a
 * marker key `<probe>_error` with a short slug so the server side can
 * tell "no data" from "probe failed."
 *
 * Used by ABJ_404_Solution_FeedbackTransport via composition:
 *   $extras = (new ABJ_404_Solution_FeedbackEnvironmentExtras())->collect();
 *
 * The probe set is documented in detail in
 * docs/bruno-failure-modes-2026-05-13.md (server-side correlation
 * targets) and pinned by tests/FeedbackTransportEnvironmentExtrasTest.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras {

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_DbProbes */
    private $db;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_HostProbes */
    private $host;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint */
    private $platform;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures */
    private $debugLog;

    public function __construct() {
        $this->db = new ABJ_404_Solution_FeedbackEnvironmentExtras_DbProbes();
        $this->host = new ABJ_404_Solution_FeedbackEnvironmentExtras_HostProbes();
        $this->platform = new ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint();
        $this->debugLog = new ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures();
    }

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
    public function collect(): array {
        $extras = array();
        $db = $this->db;
        $host = $this->host;
        $platform = $this->platform;
        $debugLog = $this->debugLog;

        // MySQL global variables: the binding constraints for slow
        // JOIN / GROUP BY on Bruno-class sites. SHOW GLOBAL VARIABLES
        // is read-only, no plugin tables involved.
        $this->recordProbe($extras, 'mysql_globals', function () use ($db) { return $db->collectMysqlGlobals(); }, array());

        // MySQL session-variable probe persisted by older builds. Reading the
        // option instead of re-querying keeps the support request cheap when
        // historical probe data is present.
        $this->recordProbe($extras, 'mysql_session_probe', function () use ($db) { return $db->loadViewBuildSessionEnvProbe(); }, array());

        // Disk headroom on the WP uploads directory (where the plugin's
        // debug log and any cron-scratch files land). "Table is full"
        // errors are nearly always disk-quota, not the logical
        // table-full condition.
        $this->recordProbe($extras, 'disk_free_bytes', function () use ($host) { return $host->diskFreeBytesOrThrow(); }, null);
        $this->recordProbe($extras, 'disk_total_bytes', function () use ($host) { return $host->diskTotalBytesOrThrow(); }, null);

        // PHP runtime identity beyond version. SAPI distinguishes
        // mod_php (per-request fork, fresh memory) from php-fpm
        // (long-lived worker, opcache hot). max_input_vars caps how
        // many POST fields the importer can accept. realpath_cache
        // size matters for sites with many include paths.
        $extras['php_sapi'] = function_exists('php_sapi_name') ? (string)php_sapi_name() : '';
        $extras['php_memory_peak_bytes'] = function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : 0;
        $extras['php_opcache_enabled'] = $host->opcacheEnabled();
        $extras['php_max_input_vars'] = function_exists('ini_get') ? (int)ini_get('max_input_vars') : 0;
        $extras['php_realpath_cache_size_bytes'] = function_exists('realpath_cache_size') ? (int)realpath_cache_size() : 0;

        // Plugin table sizes beyond logsv2 (which has its own typed
        // column). redirects volume and logs_hits rollup size are
        // direct signals for the getRedirectsForViewTempTable.sql
        // perf class.
        $this->recordProbe($extras, 'plugin_tables_bytes', function () use ($db) { return $db->collectPluginTableSizes(); }, array());

        // View-build freshness signals: when did the rollup last
        // complete, what stage did the most recent build reach, is
        // the rollup stale relative to logsv2? Hand-assembled from
        // plugin options the staged build already writes; no new SQL.
        $this->recordProbe($extras, 'view_build_state', function () use ($db) { return $db->collectViewBuildState(); }, array());

        // SHOW PROCESSLIST row count. Indicator of shared-host MySQL
        // saturation: a queue of 200+ idle connections explains why
        // the staged build's BEGIN/COMMIT slots wait. Just the count;
        // no connection details (user/host) are emitted.
        $this->recordProbe($extras, 'active_connection_count', function () use ($db) { return $db->probeActiveConnectionCount(); }, null);

        // SHOW INDEX cardinality for the canonical indexes on
        // redirects + logs_hits + logs_hits_preagg. A degraded
        // cardinality (1 row, or NULL after a crash recovery) is a
        // sufficient explanation for a previously-fast JOIN suddenly
        // doing a full table scan. Shape: {table: {index: int}}.
        $this->recordProbe($extras, 'index_cardinality', function () use ($db) { return $db->probeIndexCardinality(); }, array());

        // Best-effort hosting-class hint parsed from server_software
        // and host-specific environment markers (cPanel, hPanel,
        // Plesk, WP Engine, Kinsta, Pantheon, Flywheel, RunCloud,
        // CloudPanel). Lets server-side group heartbeats by host
        // class retroactively without paying for a deep fingerprint.
        $this->recordProbe($extras, 'hosting_class', function () use ($platform) { return $platform->probeHostingClass(); }, array());

        // Object-cache backend NAME, not just the on/off enum already
        // shipped in `object_cache`. Detect Redis / Memcached / APCu
        // / W3TC / LiteSpeed / WP Engine native via known constants
        // + wp_using_ext_object_cache(). Stale-cache reports cluster
        // by backend class.
        $this->recordProbe($extras, 'object_cache_backend', function () use ($platform) { return $platform->probeObjectCacheBackend(); }, array());

        // SHOW GLOBAL STATUS counterpart to mysql_globals. Captures
        // runtime symptoms (lock waits, tmp-disk spills, aborted
        // connects, slow queries) that the variables can only
        // bound, never observe.
        $this->recordProbe($extras, 'mysql_status', function () use ($db) { return $db->probeMysqlStatus(); }, array());

        // DB charset + collation, plus per-column collation on the
        // canonical JOIN keys for redirects (url, canonical_url) and
        // logs_hits (requested_url). Collation drift silently
        // disables index seeks on JOIN: symptom is "fast on staging,
        // slow on prod with identical data."
        $this->recordProbe($extras, 'db_collation', function () use ($db) { return $db->probeDbCollation(); }, array());

        // WP + PHP timezone identity. Bruno-class sites in non-UTC
        // zones (pt_BR, ja_JP) sometimes show off-by-N-hours bugs
        // in cooldown arithmetic; capturing both lets us diff
        // server time vs WP time vs PHP time after the fact.
        $this->recordProbe($extras, 'timezone', function () use ($host) { return $host->probeTimezone(); }, array());

        // Install + upgrade history. The single most useful
        // bifurcator for "started after upgrade Tuesday" vs
        // "always broken since install." Read-only from plugin
        // options the upgrade path already writes.
        $this->recordProbe($extras, 'plugin_lifecycle', function () use ($host) { return $host->probePluginLifecycle(); }, array());

        // Top distinct recurring error signatures from the debug
        // log file over the last 7 days, capped at 5 entries. The
        // triggering error is captured by the report itself; this
        // captures the recurring error which is often different
        // and which the email-on-first-error path would never send.
        $this->recordProbe($extras, 'recent_error_signatures', function () use ($debugLog) { return $debugLog->probeRecentErrorSignatures(); }, array());

        // opcache detail beyond the on/off enum already shipped
        // in `php_opcache_enabled`. validate_timestamps=0 +
        // revalidate_freq high explains "fresh install still
        // buggy after upgrade" reports where the host serves
        // cached bytecode from the prior version.
        $this->recordProbe($extras, 'opcache_settings', function () use ($host) { return $host->probeOpcacheSettings(); }, array());

        // open_basedir restriction string (or null when not set).
        // Hardened shared hosts use this to box file access;
        // explains "permission denied" failures on paths the
        // plugin can otherwise write.
        $extras['open_basedir'] = $host->probeOpenBasedir();

        // Multisite identity: is this the main site, what blog
        // and network are we on, is the plugin network-activated?
        // Behavior differs significantly across these axes
        // (network-active vs single-site-active changes hook
        // registration and upgrade scheduling).
        $this->recordProbe($extras, 'multisite_role', function () use ($host) { return $host->probeMultisiteRole(); }, array());

        // .htaccess writability at the WP home path. When false
        // the plugin's Apache-rule install path cannot succeed
        // and we fall back to the DB-only redirect handler.
        // Differentiates "redirects not firing" reports between
        // "Apache rule never wrote" and "DB handler bug".
        $extras['htaccess_writable'] = $host->probeHtaccessWritable();

        // /tmp filesystem free bytes. Some shared hosts have
        // separate /tmp quotas from the WP install path; tmp
        // exhaustion breaks MySQL tmp tables (Created_tmp_disk_*
        // counter) and PHP file uploads. disk_free_bytes on the
        // uploads dir cannot see this.
        $this->recordProbe($extras, 'tmp_free_bytes', function () use ($host) { return $host->probeTmpFreeBytesOrThrow(); }, null);

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
     * still goes to the plugin logger so local debugging is unaffected.
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
    private function recordProbe(array &$extras, string $key, callable $fn, $default): void {
        try {
            $value = $fn();
        } catch (\Throwable $e) {
            $extras[$key] = $default;
            $extras[$key . '_error'] = $this->classifyProbeError($e);
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackEnvironmentExtras probe "' . $key . '" failed: ' . $e->getMessage());
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
    private function classifyProbeError(\Throwable $e): string {
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
}
