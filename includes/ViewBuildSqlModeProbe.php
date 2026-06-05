<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MySQL session sql_mode and max_allowed_packet probe for the staged build.
 *
 * Sibling of ViewBuildProgressOptions / ViewBuildStagedSqlExecutor /
 * ViewBuildStateProbe / ViewBuildHostEnvironmentProbe.
 * Originally extracted from the now-deleted ViewBuildHelpers grouping when
 * that file crossed the 1500-line ceiling; the i803 split further decomposed
 * that grouping into its three real responsibilities. Behavior is unchanged;
 * callers continue to see the same return shape and side effects (option
 * persistence, dedup'd warn log, best-effort `SET SESSION sql_mode = ''`).
 *
 * Public entry point: {@see probeSqlModeForBuild()} (alias:
 * {@see detectAndAdjustSqlMode()}). The orchestrator delegates to these from
 * its own `probeSqlModeForBuild()` / `detectAndAdjustSqlMode()` bridges.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @method void clearSessionVariablesProbeCache(...$arguments)
 */
class ABJ_404_Solution_ViewBuildSqlModeProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Cached probe result for the current build run: SESSION sql_mode and
     * max_allowed_packet. Populated on first call to probeSqlModeForBuild()
     * within a request. Returned shape:
     *   array{
     *     sql_mode: string,                    // raw flags, e.g. "STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY"
     *     strict_mode_active: bool,            // true if STRICT_TRANS_TABLES or STRICT_ALL_TABLES present
     *     only_full_group_by_active: bool,     // true if ONLY_FULL_GROUP_BY present
     *     no_zero_date_active: bool,           // true if NO_ZERO_DATE / NO_ZERO_IN_DATE present
     *     max_allowed_packet: int,             // bytes (0 if unknown)
     *     adjusted: bool,                      // true if we successfully relaxed sql_mode for the build connection
     *     adjustment_denied: bool,             // true if the relax attempt was rejected (privilege)
     *     truncate_url_to: int                 // 2048 default, smaller when packet is constrained
     *   }
     *
     * Read by sanitizeUrlBeforeInsert() on the sibling helpers collaborator
     * through the orchestrator's reflection-routed __get; cross-collaborator
     * read keeps state authoritative on this single owner.
     *
     * @var array<string,mixed>|null
     */
    private $sqlModeProbeCache = null;

    /**
     * Option name used to persist the most recent probe result so a
     * post-mortem on a stuck build can see the exact session config the
     * runner saw at S1 entry. Lives outside the per-request cache so the
     * dashboard can read it across requests.
     *
     * @return string
     */
    public function sqlModeProbeOptionName(): string {
        return 'abj404_view_build_session_probe';
    }

    /**
     * Probe the live MySQL session for sql_mode and max_allowed_packet at
     * staged-build entry, before S1 runs. Persists the result in
     * `view_build_state` for diagnostic purposes and tries to relax
     * STRICT_TRANS_TABLES / ONLY_FULL_GROUP_BY for THIS connection only via
     * `SET SESSION sql_mode = ''`. The S2 INSERT already uses a strict-safe
     * REGEXP-guarded CAST so it survives strict mode regardless; the relax
     * is belt-and-suspenders for any future query the build may issue.
     *
     * Idempotent within a request -- repeat calls return the cached result
     * without re-querying. Cleared by clearSqlModeProbeCache() on a fresh
     * build (alongside clearAllProgressOptions).
     *
     * Public so the orchestrator and tests can call it. The contract test
     * `StagedBuildHostQuirksTest::testStrictSqlModeIsDetectedAndAdjustedOrSurfaced`
     * asserts the method exists; without this, a strict host fails S2 with
     * an unhelpful CAST error.
     *
     * @return array<string,mixed>  See sqlModeProbeCache docblock.
     */
    public function probeSqlModeForBuild(): array {
        if (is_array($this->sqlModeProbeCache)) {
            return $this->sqlModeProbeCache;
        }

        $result = array(
            'sql_mode'                  => '',
            'strict_mode_active'        => false,
            'only_full_group_by_active' => false,
            'no_zero_date_active'       => false,
            'max_allowed_packet'        => 0,
            'adjusted'                  => false,
            'adjustment_denied'         => false,
            'truncate_url_to'           => 2048,
        );

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            $this->sqlModeProbeCache = $result;
            return $result;
        }
        /** @var \wpdb $wpdb */

        // Round-trip both probes in one query: avoids two protocol hops on
        // slow shared hosts. Suppress wpdb's own error display because some
        // hosts revoke @@SESSION reads (rare but real on certain ProxySQL
        // routings) and we want to fail soft.
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            // DAO-bypass-approved: probe live @@SESSION on this connection.
            $row = $wpdb->get_row(
                "SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.max_allowed_packet AS max_allowed_packet",
                ARRAY_A
            );
        } catch (\Throwable $e) { // allow-silent-catch: best-effort session-variable probe; null falls through to default struct below
            $row = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }

        if (is_array($row)) {
            // Case-insensitive key lookup (some MySQL drivers normalize column case).
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'sql_mode' && is_scalar($v)) {
                    $result['sql_mode'] = (string)$v;
                } elseif ($klow === 'max_allowed_packet' && is_scalar($v)) {
                    $result['max_allowed_packet'] = (int)$v;
                }
            }
        }

        $modeUpper = strtoupper($result['sql_mode']);
        $result['strict_mode_active'] = (
            strpos($modeUpper, 'STRICT_TRANS_TABLES') !== false ||
            strpos($modeUpper, 'STRICT_ALL_TABLES') !== false
        );
        $result['only_full_group_by_active'] = (strpos($modeUpper, 'ONLY_FULL_GROUP_BY') !== false);
        $result['no_zero_date_active'] = (
            strpos($modeUpper, 'NO_ZERO_DATE') !== false ||
            strpos($modeUpper, 'NO_ZERO_IN_DATE') !== false
        );

        // If max_allowed_packet < 1MB, leave headroom for SQL framing
        // (column names, escapes, repeated values) by truncating URL inputs
        // to floor(packet * 0.4). Leaves >50% packet room for the rest of
        // the row payload. Above 1MB we keep the schema's 2048-char ceiling.
        $packet = (int)$result['max_allowed_packet'];
        if ($packet > 0 && $packet < 1048576) {
            $result['truncate_url_to'] = max(255, (int)floor($packet * 0.4));
            $this->logger->warn(sprintf(
                '[staged] max_allowed_packet=%d (<1MB); URL inputs will be truncated to %d chars to leave room for SQL framing.',
                $packet, $result['truncate_url_to']
            ));
        }

        // If strict mode or ONLY_FULL_GROUP_BY is active, attempt to relax
        // it for THIS connection only (no global change, no other clients
        // affected). This is best-effort: managed hosts may deny the SET.
        // The S2 INSERT already uses a strict-safe CAST so the build
        // survives a denied relax; the warn below makes it diagnosable.
        if ($result['strict_mode_active'] || $result['only_full_group_by_active']) {
            $relaxed = $this->attemptRelaxSqlModeForBuildConnection($result['sql_mode']);
            $result['adjusted'] = $relaxed === true;
            $result['adjustment_denied'] = $relaxed === false;
            if ($result['adjustment_denied']) {
                $this->logger->warn(sprintf(
                    '[staged] sql_mode contains STRICT_TRANS_TABLES / ONLY_FULL_GROUP_BY (%s) and the relax attempt was denied. The S2 INSERT is strict-safe via REGEXP-guarded CAST; build will proceed.',
                    $result['sql_mode']
                ));
            } elseif ($result['adjusted']) {
                $this->logger->infoMessage(sprintf(
                    '[staged] Relaxed sql_mode for build connection (was: %s).',
                    $result['sql_mode']
                ));
            }
        }

        // @cache-write-audit: opt-out - $result is a captured snapshot of
        // session-variable state used for diagnostics, not a cached query
        // result. A failed SHOW VARIABLES populates defaults that are still
        // safe to persist (the dashboard reader treats sql_mode=='' as a
        // probe failure and skips its row).
        if (function_exists('update_option')) {
            update_option($this->sqlModeProbeOptionName(), $result, false);
        }

        $this->sqlModeProbeCache = $result;
        return $result;
    }

    /**
     * Alias kept for the alternative contract phrasing in
     * StagedBuildHostQuirksTest. Returns the same probe result.
     *
     * @return array<string,mixed>
     */
    public function detectAndAdjustSqlMode(): array {
        return $this->probeSqlModeForBuild();
    }

    /**
     * Strip STRICT_TRANS_TABLES / STRICT_ALL_TABLES / ONLY_FULL_GROUP_BY /
     * NO_ZERO_DATE / NO_ZERO_IN_DATE from the supplied sql_mode string and
     * issue `SET SESSION sql_mode = '<remaining>'`. Returns true on success,
     * false on denial, null when wpdb is unavailable.
     *
     * @param string $currentSqlMode
     * @return bool|null
     */
    public function attemptRelaxSqlModeForBuildConnection(string $currentSqlMode): ?bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return null;
        }
        /** @var \wpdb $wpdb */
        $flags = array_filter(array_map('trim', explode(',', $currentSqlMode)));
        $strip = array(
            'STRICT_TRANS_TABLES',
            'STRICT_ALL_TABLES',
            'ONLY_FULL_GROUP_BY',
            'NO_ZERO_DATE',
            'NO_ZERO_IN_DATE',
            'TRADITIONAL', // umbrella that re-enables strict
        );
        $relaxed = array();
        foreach ($flags as $flag) {
            $upper = strtoupper($flag);
            if (in_array($upper, $strip, true)) {
                continue;
            }
            $relaxed[] = $flag;
        }
        $newMode = implode(',', $relaxed);
        // @utf8-audit: opt-out - sql_mode flags are server-controlled
        // uppercase ASCII identifiers (STRICT_TRANS_TABLES, ONLY_FULL_GROUP_BY,
        // etc.); $newMode is built from filtered $flags whose source is
        // SHOW SESSION VARIABLES output, never user input.
        $escaped = function_exists('esc_sql') ? esc_sql($newMode) : str_replace("'", "''", $newMode);
        $escapedStr = is_array($escaped) ? '' : (string)$escaped;
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            // DAO-bypass-approved: SET SESSION must run on the live wpdb connection.
            $ok = $wpdb->query("SET SESSION sql_mode = '" . $escapedStr . "'");
        } catch (\Throwable $e) { // allow-silent-catch: SET SESSION sql_mode is best-effort; $ok=false falls through to the last_error check and returns false to caller
            $ok = false;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        $err = $wpdb->last_error;
        if ($ok === false || $err !== '') {
            return false;
        }
        return true;
    }

    /** @return void */
    public function clearSqlModeProbeCache(): void {
        $this->sqlModeProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->sqlModeProbeOptionName());
        }
        // The session-variables probe (operational + DDL-safety MySQL vars)
        // shares the same lifecycle as the sql_mode probe: a fresh build must
        // re-evaluate session config in case the host was tuned between runs.
        // Lives on the host-environment probe collaborator.
        $this->clearSessionVariablesProbeCache();
    }
}
