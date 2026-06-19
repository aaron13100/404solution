<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormHitsRollupSourceTest

/**
 * Rolls up wp_abj404_logs_hits for the visible page of the admin
 * redirects/captured table (Denorm Step 3b, S9-equivalent).
 *
 * For the ~50 rows on the current page, sums logshits and takes MAX(logsid) /
 * MAX(last_used) by the canonical URL key in one grouped query, keyed back by
 * the exact stored URL string (binary-collation semantics, matching the staged
 * S9 JOIN). Returns an empty map (degraded path) when the logs_hits table is
 * absent, so a read on a stripped-down install still renders. Capture-derived
 * URLs can carry invalid UTF-8 bytes, so they are stripped before esc_sql()
 * (Pattern 10) for the IN() prefilter.
 *
 * Composed by RedirectsViewLiveResolver, which feeds the rolled-up map into the
 * per-row resolution.
 */
class ABJ_404_Solution_RedirectsHitsRollupReader {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions Used to strip invalid UTF-8 from
     *  capture-derived URLs before they reach esc_sql() (Pattern 10). */
    private $f;

    /**
     * Error logging is intentionally delegated to queryAndGetResults (the
     * centralized DAO error handler), so no logger dependency is held here.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f UTF-8 sanitizer source; falls
     *   back to the container's functions service when not injected.
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $f = null) {
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : abj_service('functions');
    }

    /**
     * Roll up wp_abj404_logs_hits for every visible row's canonical URL in one
     * grouped query. Mirrors S9: SUM(logshits), MAX(logsid), MAX(last_used) by
     * requested_url. Returns an empty map (degraded path) when the logs_hits
     * table is absent, so a read on a stripped-down install still renders.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{logshits:int, logsid:int|null, last_used:int|null}>
     */
    public function resolveHitsMap(array $rows): array {
        if (!$this->logsHitsTableExists()) {
            return array();
        }
        $canonicals = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonicals[$this->canonicalUrl($this->strField($row, 'url'))] = true;
        }
        if (empty($canonicals)) {
            return array();
        }
        $quoted = array();
        foreach (array_keys($canonicals) as $canonical) {
            // Capture-derived URLs can carry invalid UTF-8 bytes; strip them
            // before esc_sql() so the IN() prefilter cannot break the query
            // (Pattern 10). The exact match below still uses the stored value.
            $quoted[] = "'" . esc_sql($this->f->sanitizeInvalidUTF8($canonical)) . "'";
        }
        $query = "SELECT requested_url, SUM(logshits) AS logshits, MAX(logsid) AS logsid,"
            . " MAX(last_used) AS last_used FROM {wp_abj404_logs_hits}"
            . " WHERE requested_url IN (" . implode(',', $quoted) . ") GROUP BY requested_url";
        $result = $this->dbCore->queryAndGetResults($query);
        $rowsOut = is_array($result['rows'] ?? null) ? $result['rows'] : array();

        $map = array();
        foreach ($rowsOut as $hitRow) {
            if (!is_array($hitRow) || !isset($hitRow['requested_url'])) {
                continue;
            }
            // Exact-string match in PHP keeps the binary-collation semantics the
            // staged S9 JOIN used; the IN() above is only a coarse prefilter.
            $map[$this->strField($hitRow, 'requested_url')] = array(
                'logshits' => $this->intFieldOrNull($hitRow, 'logshits') ?? 0,
                'logsid' => $this->intFieldOrNull($hitRow, 'logsid'),
                'last_used' => $this->intFieldOrNull($hitRow, 'last_used'),
            );
        }
        return $map;
    }

    /** @return bool */
    private function logsHitsTableExists(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        // Schema existence probe; routing a SHOW TABLES through
        // queryAndGetResults would log a benign "table missing" error on a
        // stripped install.
        // @utf8-audit: opt-out - $logsTable is an internally resolved plugin table name (doTableNameReplacements); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: SHOW TABLES schema existence probe.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsTable) . "'");
        return $found === $logsTable;
    }

    /**
     * Safe string read of a query-result field; absent/non-scalar becomes ''.
     * @param array<array-key, mixed> $row @param string $key @return string
     */
    private function strField(array $row, string $key): string {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string)$row[$key] : '';
    }

    /**
     * Safe int read; NULL/absent/non-numeric stays null, numeric becomes int.
     * @param array<array-key, mixed> $row @param string $key @return int|null
     */
    private function intFieldOrNull(array $row, string $key): ?int {
        return isset($row[$key]) && is_numeric($row[$key]) ? (int)$row[$key] : null;
    }

    /** @param string $url @return string */
    private function canonicalUrl(string $url): string {
        return '/' . trim($url, '/');
    }
}
