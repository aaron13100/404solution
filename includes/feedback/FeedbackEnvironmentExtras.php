<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/MysqlServerStateProbe.php';
require_once __DIR__ . '/PluginSchemaMetadataProbe.php';
require_once __DIR__ . '/RollupFreshnessProbe.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_HostProbes.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_PlatformFingerprint.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras_DebugLogSignatures.php';
require_once __DIR__ . '/FeedbackTransportLog.php';
require_once dirname(__DIR__) . '/services/PostResponseWorkerBudget.php';

/**
 * Environment-extras passthrough probes for the feedback payload's JSON column.
 *
 * Orchestrates a set of best-effort diagnostic probes about the server
 * environment (MySQL globals, disk headroom, PHP SAPI, hosting class,
 * etc.) and packages them into a keyed array for the `environment_extras`
 * field of the feedback payload.
 *
 * This class owns ONLY the probe registry and the failure-isolation
 * wrapper. The probe implementations live in six collaborator classes,
 * partitioned by the subject each one observes:
 *   - MysqlServerStateProbe: the database server's own state via $wpdb
 *     (SHOW GLOBAL/SESSION VARIABLES, SHOW GLOBAL STATUS, SHOW PROCESSLIST).
 *     No plugin table is named in it.
 *   - PluginSchemaMetadataProbe: this plugin's own storage shape via
 *     information_schema and SHOW INDEX (table sizes, index cardinality,
 *     JOIN-hot column collations).
 *   - RollupFreshnessProbe: redirects-hits rollup staleness, read live
 *     through the logs_repository service (no SQL of its own).
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

    /** @var ABJ_404_Solution_MysqlServerStateProbe */
    private $server;

    /** @var ABJ_404_Solution_PluginSchemaMetadataProbe */
    private $schema;

    /** @var ABJ_404_Solution_RollupFreshnessProbe */
    private $rollup;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_HostProbes */
    private $host;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_PlatformFingerprint */
    private $platform;

    /** @var ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures */
    private $debugLog;

    public function __construct() {
        $this->server = new ABJ_404_Solution_MysqlServerStateProbe();
        $this->schema = new ABJ_404_Solution_PluginSchemaMetadataProbe();
        $this->rollup = new ABJ_404_Solution_RollupFreshnessProbe();
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
        $server = $this->server;
        $schema = $this->schema;
        $rollup = $this->rollup;
        $host = $this->host;
        $platform = $this->platform;
        $debugLog = $this->debugLog;

        // MySQL global variables: the binding constraints for slow
        // JOIN / GROUP BY on Bruno-class sites. SHOW GLOBAL VARIABLES
        // is read-only, no plugin tables involved.
        $this->recordProbe($extras, 'mysql_globals', function () use ($server) { return $server->collectMysqlGlobals(); }, array());

        // Live SHOW SESSION VARIABLES probe for this connection. Some hosts
        // override operational variables per-session (poolers, connection
        // init hooks), so this can diverge from mysql_globals.
        $this->recordProbe($extras, 'mysql_session_probe', function () use ($server) { return $server->collectMysqlSessionVariables(); }, array());

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
        // PHP_SAPI, not php_sapi_name(): the constant is defined by the engine
        // on every SAPI and cannot be removed, while the function is on the
        // disable_functions hardening lists some shared/CloudLinux hosts ship,
        // where the guarded call silently degrades to ''. This is the field
        // that identified Bruno's litespeed SAPI (and with it the FPM-only
        // fastcgi_finish_request() no-op), so losing it loses the diagnosis.
        // Same accessor the flight recorder uses (RequestEnvironmentFingerprint).
        $extras['php_sapi'] = PHP_SAPI;
        $extras['php_disable_functions'] = function_exists('ini_get')
            ? (string)ini_get('disable_functions') : '';
        $extras['php_post_response_budget_armable'] =
            ABJ_404_Solution_PostResponseWorkerBudget::isSupported();
        $extras['php_memory_peak_bytes'] = function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : 0;
        $extras['php_opcache_enabled'] = $host->opcacheEnabled();
        $extras['php_max_input_vars'] = function_exists('ini_get') ? (int)ini_get('max_input_vars') : 0;
        $extras['php_output_buffering'] = function_exists('ini_get')
            ? (string)ini_get('output_buffering') : '';
        $extras['php_zlib_output_compression'] = function_exists('ini_get')
            ? (string)ini_get('zlib.output_compression') : '';
        $obStatuses = function_exists('ob_get_status') ? ob_get_status(true) : array();
        $obHandlerNames = array();
        foreach ($obStatuses as $obStatus) {
            if (is_array($obStatus) && isset($obStatus['name']) && is_string($obStatus['name'])) {
                $obHandlerNames[] = $obStatus['name'];
            }
        }
        $extras['php_ob_level_at_collect'] = array(
            'level' => function_exists('ob_get_level') ? (int)ob_get_level() : 0,
            // Handler names identify stack ownership. Other status fields,
            // especially byte counts, are unnecessary diagnostic surface.
            'handlers' => $obHandlerNames,
        );
        $extras['php_realpath_cache_size_bytes'] = function_exists('realpath_cache_size') ? (int)realpath_cache_size() : 0;

        // Plugin table sizes beyond logsv2 (which has its own typed
        // column). redirects volume and logs_hits rollup size are
        // direct signals for the getRedirectsForViewTempTable.sql
        // perf class.
        $this->recordProbe($extras, 'plugin_tables_bytes', function () use ($schema) { return $schema->collectPluginTableSizes(); }, array());

        // Redirects-hits rollup freshness signals: does the rollup table
        // exist, does it need a rebuild right now, when did it last
        // refresh/get scheduled, and how far behind is its watermark
        // relative to logsv2? Read live from the rollup service, the
        // subsystem most implicated in "the admin redirects page never
        // loads" reports.
        $this->recordProbe($extras, 'view_build_state', function () use ($rollup) { return $rollup->collectRollupFreshness(); }, array());

        // SHOW PROCESSLIST row count. Indicator of shared-host MySQL
        // saturation: a queue of 200+ idle connections explains why
        // the staged build's BEGIN/COMMIT slots wait. Just the count;
        // no connection details (user/host) are emitted.
        $this->recordProbe($extras, 'active_connection_count', function () use ($server) { return $server->probeActiveConnectionCount(); }, null);

        // SHOW INDEX cardinality for the canonical indexes on
        // redirects + logs_hits + logs_hits_preagg. A degraded
        // cardinality (1 row, or NULL after a crash recovery) is a
        // sufficient explanation for a previously-fast JOIN suddenly
        // doing a full table scan. Shape: {table: {index: int}}.
        $this->recordProbe($extras, 'index_cardinality', function () use ($schema) { return $schema->probeIndexCardinality(); }, array());

        // Best-effort hosting-class hint parsed from server_software
        // and host-specific environment markers (cPanel, hPanel,
        // Plesk, WP Engine, Kinsta, Pantheon, Flywheel, RunCloud,
        // CloudPanel). Lets server-side group heartbeats by host
        // class retroactively without paying for a deep fingerprint.
        $this->recordProbe($extras, 'hosting_class', function () use ($platform) {
            return $platform->probeHostingClass(array('php_sapi' => PHP_SAPI));
        }, array());

        // Full-request page-cache drop-ins can own an outer output buffer.
        // Report only presence and the declared Plugin Name so support can
        // identify that foreign owner without receiving file content or paths.
        $this->recordProbe($extras, 'advanced_cache_dropin', function () use ($platform) {
            return $platform->probeCacheDropin('advanced_cache');
        }, array('present' => false, 'owner' => ''));

        // Object-cache backend NAME, not just the on/off enum already
        // shipped in `object_cache`. Detect Redis / Memcached / APCu
        // / W3TC / LiteSpeed / WP Engine native via known constants
        // + wp_using_ext_object_cache(). Stale-cache reports cluster
        // by backend class.
        $this->recordProbe($extras, 'object_cache_backend', function () use ($platform) { return $platform->probeObjectCacheBackend(); }, array());

        // The backend marker identifies the running cache implementation;
        // the drop-in header identifies which plugin installed object-cache.php.
        $this->recordProbe($extras, 'object_cache_dropin', function () use ($platform) {
            return $platform->probeCacheDropin('object_cache');
        }, array('present' => false, 'owner' => ''));

        // SHOW GLOBAL STATUS counterpart to mysql_globals. Captures
        // runtime symptoms (lock waits, tmp-disk spills, aborted
        // connects, slow queries) that the variables can only
        // bound, never observe.
        $this->recordProbe($extras, 'mysql_status', function () use ($server) { return $server->probeMysqlStatus(); }, array());

        // DB charset + collation, plus per-column collation on the
        // canonical JOIN keys for redirects (url, canonical_url) and
        // logs_hits (requested_url). Collation drift silently
        // disables index seeks on JOIN: symptom is "fast on staging,
        // slow on prod with identical data."
        $this->recordProbe($extras, 'db_collation', function () use ($schema) { return $schema->probeDbCollation(); }, array());

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
     * Probe-failure slug => the lowercased message substrings that select it.
     * FIRST MATCH WINS, so declaration order is the precedence order: the
     * narrow, unambiguous causes are listed before `sql_failed`, whose needles
     * ('sql', 'query') are broad enough to swallow a more specific message.
     *
     * A table rather than an if/elseif chain because this is a classifier with
     * five branches and grows by one every time a probe learns a new way to
     * fail; adding a cause should be adding a row, not adding a branch.
     * `service_unavailable` is one such row (t_260801_071502_922): a probe
     * whose own collaborator service could not be resolved is a plugin-wiring
     * failure, not an environment failure, and the two must be groupable apart
     * on the server, because the wiring class is exactly what let
     * `view_build_state` ship empty for seven weeks without anyone noticing.
     *
     * @var array<string, array<int, string>>
     */
    private const PROBE_ERROR_SIGNATURES = array(
        'wpdb_unavailable'    => array('wpdb unavailable', 'wpdb missing'),
        'fs_unavailable'      => array('disk_free_space', 'disk_total_space', 'sys_get_temp_dir'),
        'service_unavailable' => array('service unavailable'),
        'invalid_shape'       => array('invalid shape', 'non-array', 'unexpected shape'),
        'sql_failed'          => array(
            'sql', 'mysql', 'mariadb', 'query', 'processlist', 'simulated db',
            'show global', 'show index', 'show processlist', 'information_schema',
            'all tables failed', 'no tables probed',
        ),
    );

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
        foreach (self::PROBE_ERROR_SIGNATURES as $slug => $needles) {
            foreach ($needles as $needle) {
                if (strpos($msg, $needle) !== false) {
                    return $slug;
                }
            }
        }
        $shortClass = (new \ReflectionClass($e))->getShortName();
        return 'exception:' . $shortClass;
    }
}
