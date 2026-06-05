<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads SQL templates from includes/sql/getRedirectsForViewStaged/, performs
 * table-name and placeholder substitution, routes through
 * queryAndGetResults, and raises descriptive failure exceptions.
 *
 * Owns the per-stage timeout knob that backs stagedQueryOptions() and the
 * URL boundary sanitizer that consumes the sql-mode probe's max_allowed_packet
 * ceiling. The S11 swap orchestration also lives here because it is itself a
 * staged SQL statement (RENAME TABLE) gated on a hook.
 *
 * Sibling responsibilities:
 *   - Progress checkpoint persistence: ABJ_404_Solution_ViewBuildProgressOptions
 *   - Build-side existence / freshness probes:
 *     ABJ_404_Solution_ViewBuildStateProbe
 *
 * All three classes are registered as ViewBuildOrchestrator collaborators and
 * use the orchestrator's explicit operation map for cross-class calls.
 *
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @method array<mixed> queryAndGetResults(...$arguments)
 * @method string doTableNameReplacements(...$arguments)
 * @method string getColumnCollationString(...$arguments)
 * @method mixed runTimedViewBuildStage(...$arguments)
 * @method void stageRenameSwap(...$arguments)
 */
class ABJ_404_Solution_ViewBuildStagedSqlExecutor extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

    /**
     * Sanitize a URL string at the build/log boundary so a NULL byte or a
     * pathological length cannot reach the SQL layer. Behavior:
     *   - Strip ASCII NULL (\x00) bytes and other low control bytes (\x01-\x08,
     *     \x0B, \x0C, \x0E-\x1F, \x7F) that wpdb->prepare() would otherwise
     *     reject with "could not execute query, contains invalid data".
     *   - Truncate to the cap returned by the most recent
     *     probeSqlModeForBuild() (default 2048 == varchar(2048) ceiling on
     *     the redirects table; smaller when max_allowed_packet < 1MB).
     *
     * Public so the 404-listener boundary and the staged-build entry both
     * route through one sanitizer (same input rules everywhere). The
     * contract tests `testNullByteInUrlRejectedAtBoundaryNotInSqlLayer` and
     * `testUrlLongerThan2048CharsTruncatedOrRejectedAtBoundary` assert the
     * method exists; the implementation is what makes the gastroinovace.cz
     * 2,800x error-mailbox flood (mid-2024) stay fixed.
     *
     * @param string $url Raw URL captured from $_SERVER['REQUEST_URI'] or wpdb input.
     * @param int    $maxLength Optional override; 0 means "use the probe-derived cap".
     * @return string
     */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string {
        if ($url === '') {
            return '';
        }
        // Strip NULL bytes and control bytes BEFORE truncation so a
        // multi-byte sequence at the cap doesn't get split mid-byte and
        // become a partial NULL.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $url);
        if (!is_string($clean)) {
            $clean = $url;
        }
        if ($maxLength <= 0) {
            $probe = $this->sqlModeProbeCache();
            $truncTo = 0;
            if ($probe !== null && isset($probe['truncate_url_to'])
                    && is_scalar($probe['truncate_url_to'])) {
                $truncTo = (int)$probe['truncate_url_to'];
            }
            $maxLength = $truncTo > 0 ? max(255, $truncTo) : 2048;
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean) > $maxLength) {
                $clean = mb_substr($clean, 0, $maxLength);
            }
        } elseif (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength);
        }
        return $clean;
    }

    /**
     * Resolve the column-level collation for the S9 staged build step.
     *
     * S9 creates a temporary hits aggregate table and JOINs it against
     * the view_build buffer via requested_url. The collation is resolved
     * from the logs_hits.requested_url column (the actual join partner)
     * to prevent "Illegal mix of collations" errors when correctCollations()
     * has changed the main tables to a non-default collation.
     *
     * Bridge method: delegates to DatabaseCore::getColumnCollationString()
     * for the actual resolution.
     *
     * @return string Sanitized collation identifier (e.g. 'utf8mb4_unicode_520_ci').
     */
    public function resolveColumnCollationForStagedBuild(): string {
        $logsHitsTable = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        $collation = $this->getColumnCollationString($logsHitsTable, 'requested_url');
        return $collation;
    }

    /**
     * Execute a staged SQL file with placeholder substitution and the
     * standard error-handling pipeline.
     *
     * On failure, the error message is prefixed with the file name and any
     * batch bounds present in $extraTranslations so the GUI's "stage N
     * failed" notice carries actionable context.  The current sub-stage
     * label set by markBuildStage() remains in place so the AJAX shutdown
     * handler renders the correct stageNumber/queryLabel.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFile(string $relativePath, array $extraTranslations): void {
        $path = __DIR__ . '/sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_FileSystemService::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \Exception("Staged SQL template missing or empty: $relativePath");
        }
        $sql = $this->doTableNameReplacements($template);
        // extraTranslations (status_for_view / type_for_view labels, batch
        // bounds) must run BEFORE doNormalReplacements: doNormalReplacements
        // falls back to __() for any {key} it does not know, which strips
        // the braces and prevents the str_replace below from matching.
        if (!empty($extraTranslations)) {
            $sql = $this->f->str_replace(array_keys($extraTranslations), array_values($extraTranslations), $sql);
        }
        $sql = $this->f->doNormalReplacements($sql);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            $context = $this->describeStagedSqlFailure($relativePath, $extraTranslations);
            throw new \Exception('Staged SQL ' . $context . ' failed: ' . $err);
        }
    }

    /**
     * Same as runStagedSqlFile but silently tolerates "Duplicate key name"
     * errors so an interrupted ALTER TABLE ADD INDEX can be safely re-run
     * on a request that resumes a prior partially-completed build.  All
     * other errors are raised as usual.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFileTolerantOfDuplicateKey(string $relativePath, array $extraTranslations): void {
        try {
            $this->runStagedSqlFile($relativePath, $extraTranslations);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') !== false
                || stripos($msg, 'errno: 1061') !== false) {
                // The index already exists from a prior partial run; the
                // expected resume-time state, not a failure. Log at debug so
                // a "why did this stage take 0ms" question has an answer.
                $this->logger->debugMessage(sprintf(
                    '[staged] %s: index already exists, tolerated as resume.',
                    $relativePath
                ));
                return;
            }
            throw $e;
        }
    }

    /**
     * Render a short human-readable description of which file + which batch
     * bounds were running when an error fired.  Used to enrich error
     * messages so the GUI notice lists the exact failing slice.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return string
     */
    public function describeStagedSqlFailure(string $relativePath, array $extraTranslations): string {
        $parts = array($relativePath);
        if (isset($extraTranslations['{LO_BOUND}'])) {
            $parts[] = 'lo=' . $extraTranslations['{LO_BOUND}'];
        }
        if (isset($extraTranslations['{HI_BOUND}'])) {
            $parts[] = 'hi=' . $extraTranslations['{HI_BOUND}'];
        }
        if (isset($extraTranslations['{BATCH_SIZE}'])) {
            $parts[] = 'limit=' . $extraTranslations['{BATCH_SIZE}'];
        }
        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed> Options for queryAndGetResults that
     * inherit the warmup pipeline's per-stage timeout when set.
     */
    public function stagedQueryOptions(): array {
        if ($this->stagedQueryTimeoutSeconds > 0) {
            return array('timeout' => $this->stagedQueryTimeoutSeconds);
        }
        return array();
    }

    /** @return int */
    public function getStagedQueryTimeoutSeconds(): int {
        return $this->stagedQueryTimeoutSeconds;
    }

    /** @param int $seconds @return void */
    public function setStagedQueryTimeoutSeconds(int $seconds): void {
        $this->stagedQueryTimeoutSeconds = max(0, $seconds);
    }

    /**
     * S11 swap that fires the CLAUDE.md R6 pre-RENAME action hook
     * (`abj404_view_build_before_rename_swap`) and runs the RENAME
     * TABLE statement.
     *
     * @return bool  True when the swap completed cleanly; false when the
     *               stage halted / yielded (orchestrator should return
     *               false from runStagedBuildOnce).
     */
    public function runS11Swap(): bool {
        $result = $this->runTimedViewBuildStage(11, 'staged_build_s11_swap', function () {
            if (function_exists('do_action')) {
                do_action('abj404_view_build_before_rename_swap');
            }
            $this->stageRenameSwap();
        });
        return $result !== false && $result !== 'halted';
    }
}
