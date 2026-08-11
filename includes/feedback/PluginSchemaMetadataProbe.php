<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Read-only probes of THIS PLUGIN's own storage shape for the feedback
 * payload's `environment_extras` field: how large each plugin table is, how
 * healthy its indexes are, and what collation its JOIN-hot URL columns carry.
 *
 * One subject: the plugin's schema as the server currently reports it, read
 * through `information_schema` and `SHOW INDEX`. Reads about the SERVER's own
 * configuration live in ABJ_404_Solution_MysqlServerStateProbe; reads about
 * the rollup's freshness live in ABJ_404_Solution_RollupFreshnessProbe.
 *
 * Every probe here iterates a candidate table list and isolates each table in
 * its own try/catch, so a missing table (rebuild race, repair pending, an
 * install that never got that table) degrades to a missing map entry rather
 * than blanking the whole probe. A probe throws only when EVERY attempt
 * failed, which is the "we learned nothing" case the caller's recordProbe()
 * wrapper should mark as `<probe>_error`.
 *
 * The DAO-bypass markers here are specifically scoped to read-only metadata
 * probes: these statements read table/column/index METADATA, never plugin row
 * data, so DatabaseCore's repair-and-retry recovery does not apply (see
 * docs/adr/dataaccess-refactor.md).
 */
class ABJ_404_Solution_PluginSchemaMetadataProbe {

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
