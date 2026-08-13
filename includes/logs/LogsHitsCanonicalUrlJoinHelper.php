<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JOIN-shape decisions for the logs-hits rebuild pipeline, scoped to
 * canonical_url backfill state and column-level collations.
 *
 * Extracted from LogsHitsRollupService under i359 so the rollup service
 * stays focused on rebuild lifecycle (existence, scheduling, locking,
 * phase orchestration). The decisions encoded here are independent of
 * rebuild lifecycle and need to be unit-testable on their own:
 *
 *   1. Has the logsv2-side canonical_url backfill completed? -> drop
 *      COALESCE wrap on logsv2 references in the rebuild SQL.
 *   2. Has the redirects-side canonical_url backfill completed (with a
 *      defensive NULL re-probe in case the wp_options flag lags reality
 *      after a manual UPDATE or partial backup restore)? -> drop
 *      COALESCE wrap on redirects references in the rebuild SQL.
 *   3. Do the column-level collations on both canonical_url columns
 *      already match the resolved join collation? -> drop the explicit
 *      COLLATE clause on the phase2 JOIN so the planner can probe
 *      idx_canonical_url directly.
 *
 * Bruno fingerprint (i359): a host's 60s max_statement_time was killing
 * phase2Aggregate because the JOIN right-hand side wrapped an indexed
 * column in COALESCE/CONCAT/TRIM with an explicit COLLATE, defeating
 * idx_canonical_url. This helper owns the dynamic decision to drop both
 * once their preconditions hold.
 */
class ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * Request-scoped cache for isRedirectsCanonicalUrlBackfillComplete().
     * Null = not yet evaluated this request; bool = cached decision.
     * Both checks carry a NULL re-probe, so the results are cached to keep
     * the rebuild pipeline at one extra LIMIT 1 query per column at most.
     *
     * @var bool|null
     */
    private $redirectsCanonicalBackfillCompleteCache = null;

    /**
     * Request-scoped cache for isLogsv2CanonicalUrlBackfillComplete().
     *
     * @var bool|null
     */
    private $logsv2CanonicalBackfillCompleteCache = null;

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Resolve the collation from the abj404_redirects.canonical_url column,
     * the actual join partner for the hits rebuild phase2 JOIN.
     *
     * @return string Sanitized collation identifier.
     */
    public function resolveHitsJoinCollation(): string {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        return $this->dbCore->collationHelper()->getColumnCollationString($redirectsTable, 'canonical_url');
    }

    /**
     * Logsv2-side backfill completion check, with the same defensive NULL
     * re-probe the redirects side carries.
     *
     * The consequence of getting this wrong is worse than on the redirects
     * side. A logsv2 row whose canonical_url is NULL groups under NULL in
     * phase 1, so it never joins a redirect in phase 2 and vanishes from the
     * hits rollup entirely: the URL loses its hit counts and its "View Logs"
     * row action, silently. The option flag alone cannot rule that out --
     * it records that a backfill ran, not that every row currently in the
     * table went through it.
     *
     * @return bool
     */
    public function isLogsv2CanonicalUrlBackfillComplete(): bool {
        return $this->backfillCompleteWithNullReprobe(
            'abj404_logsv2_canonical_url_backfill_complete',
            '{wp_abj404_logsv2}',
            $this->logsv2CanonicalBackfillCompleteCache
        );
    }

    /**
     * Redirects-side backfill completion check with defensive NULL re-probe.
     *
     * @return bool
     */
    public function isRedirectsCanonicalUrlBackfillComplete(): bool {
        return $this->backfillCompleteWithNullReprobe(
            'abj404_redirects_canonical_url_backfill_complete',
            '{wp_abj404_redirects}',
            $this->redirectsCanonicalBackfillCompleteCache
        );
    }

    /**
     * "Is this column's backfill complete?" answered from the table rather
     * than only from the flag.
     *
     * The wp_options flag can lag reality if a site admin inserts rows via
     * SQL (skipping the plugin's own write path) or restores from a partial
     * backup. Where the flag is used to drop a COALESCE wrap, believing a
     * stale flag makes the bare-column form silently miss exactly the rows
     * that were never backfilled. So the flag is treated as a necessary
     * condition and the table is asked to confirm it.
     *
     * Both sides share this because they are the same decision about two
     * columns, and the logsv2 side went a release without the re-probe
     * precisely because it was a second hand-written copy.
     *
     * @param string $optionName wp_options flag recording that a backfill ran.
     * @param string $tableToken Table placeholder to probe for NULL canonical_url.
     * @param bool|null $cache Request-scoped memo slot, by reference. Bounds the
     *        rebuild pipeline to one extra LIMIT 1 query per column per request.
     * @return bool
     */
    private function backfillCompleteWithNullReprobe(
        string $optionName,
        string $tableToken,
        ?bool &$cache
    ): bool {
        if ($cache !== null) {
            return $cache;
        }
        if (!function_exists('get_option') || !(bool) get_option($optionName)) {
            return $cache = false;
        }
        $table = $this->dbCore->doTableNameReplacements($tableToken);
        $probe = $this->dbCore->queryAndGetResults(
            "SELECT 1 FROM " . $table . " WHERE canonical_url IS NULL LIMIT 1",
            array('log_too_slow' => false, 'log_errors' => false)
        );
        $probeRows = is_array($probe['rows'] ?? null) ? $probe['rows'] : array();
        $probeError = isset($probe['last_error']) && is_string($probe['last_error']) ? $probe['last_error'] : '';
        // Treat any probe error as "do not drop the wrap" so the defensive
        // form runs and reads stay correct.
        if ($probeError !== '' || !empty($probeRows)) {
            return $cache = false;
        }
        return $cache = true;
    }

    /**
     * Test seam: reset the request-scoped caches so the probes run again
     * after a fixture toggles a flag or the NULL-row state.
     *
     * @return void
     */
    public function resetRedirectsCanonicalBackfillCompleteCacheForTests(): void {
        $this->redirectsCanonicalBackfillCompleteCache = null;
        $this->logsv2CanonicalBackfillCompleteCache = null;
    }

    /** @param string $sql @return string */
    public function dropLogsv2CanonicalCoalesceWrap(string $sql): string {
        $pattern = '/COALESCE\(\{wp_abj404_logsv2\}\.canonical_url,\s*CONCAT\(\'\/\',\s*TRIM\(BOTH\s+\'\/\'\s+FROM\s+\{wp_abj404_logsv2\}\.requested_url\)\)\)/';
        $result = preg_replace($pattern, '{wp_abj404_logsv2}.canonical_url', $sql);
        return is_string($result) ? $result : $sql;
    }

    /**
     * Assemble the phase2 JOIN's right-hand side, applying both
     * simplifications when their preconditions hold:
     *
     *   1. Redirects backfill complete -> bare r.canonical_url instead of
     *      COALESCE/CONCAT/TRIM. Computed expressions defeat the index.
     *   2. Resolved collation matches both columns -> drop COLLATE clause.
     *      Explicit COLLATE on one side of `=` disables index probes unless
     *      the index was built with the exact collation.
     *
     * Both branches must remain reachable: defensive form covers legacy
     * and in-progress installs; optimized form lets idx_canonical_url
     * serve the JOIN probe.
     *
     * @param string $resolvedCollation
     * @return string The SQL fragment for the JOIN's RHS, e.g.
     *   "r.canonical_url" or
     *   "(COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url))) COLLATE utf8mb4_unicode_ci)".
     */
    public function buildPhase2JoinRhs(string $resolvedCollation): string {
        $redirectsCanonicalExpr = $this->isRedirectsCanonicalUrlBackfillComplete()
            ? "r.canonical_url"
            : "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $collateClause = $this->joinCollateClauseCanBeOmitted($resolvedCollation)
            ? ""
            : " COLLATE " . $resolvedCollation;
        return $collateClause === ''
            ? $redirectsCanonicalExpr
            : "(" . $redirectsCanonicalExpr . $collateClause . ")";
    }

    /**
     * Build the redirects operand for the direct logsv2-to-redirects rebuild.
     * The logsv2 canonical_url column remains bare and indexable; the redirects
     * expression is converted to that column's actual runtime collation.
     *
     * @return string
     */
    public function buildDirectJoinRhs(): string {
        $redirectsCanonicalExpr = $this->isRedirectsCanonicalUrlBackfillComplete()
            ? '{wp_abj404_redirects}.canonical_url'
            : "COALESCE({wp_abj404_redirects}.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM {wp_abj404_redirects}.url)))";
        $logsv2Table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        return $this->dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $redirectsCanonicalExpr,
            array('table' => $logsv2Table, 'column' => 'canonical_url')
        );
    }

    /**
     * Whether the phase2 JOIN can omit its explicit COLLATE clause because
     * both column-level collations already match the resolved join
     * collation. An explicit COLLATE on one side of `=` disables index
     * probes unless the index was built with that exact collation; when
     * both sides already match, the clause is redundant and the planner
     * can use idx_canonical_url.
     *
     * @param string $resolvedCollation
     * @return bool
     */
    public function joinCollateClauseCanBeOmitted(string $resolvedCollation): bool {
        if ($resolvedCollation === '') {
            return false;
        }
        $logsv2Table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $logsv2Collation = $this->dbCore->collationHelper()->getColumnCollationString($logsv2Table, 'canonical_url');
        $redirectsCollation = $this->dbCore->collationHelper()->getColumnCollationString($redirectsTable, 'canonical_url');
        return $logsv2Collation === $resolvedCollation && $redirectsCollation === $resolvedCollation;
    }
}
