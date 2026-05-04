<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staged getRedirectsForView pipeline.
 *
 * Replaces the legacy single-shot SQL that JOINed wp_posts/wp_terms/wp_options
 * onto every active redirect and forced an ORDER BY published_status filesort
 * across the full result before LIMIT applied. That shape times out at 45s+
 * on cold-cache shared hosts (Bruno/Showmetech, multiple 4.1.13 reports).
 *
 * The pipeline writes a precomputed view of every redirect into a shared
 * persistent table (`{wp_abj404_view_done}`). Reads serve directly from
 * that table with WHERE/ORDER/LIMIT applied. Per-tab status filtering and
 * filterText LIKE both apply at read time, so one shared `_done` serves
 * every admin's tab.
 *
 * Concurrency model: one builder at a time per site, gated by a session
 * lock (`GET_LOCK`). Atomic `RENAME TABLE` swap publishes a freshly built
 * buffer to readers. Stale-while-revalidate on every request: if the
 * served snapshot is older than VIEW_DONE_FRESHNESS_TTL_SECONDS, kick off
 * a rebuild for the next request and serve the stale data now.
 */
trait ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait {

    const VIEW_DONE_FRESHNESS_TTL_SECONDS = 120;
    const VIEW_DONE_BUILD_LOCK_NAME = 'abj404_view_build';
    const VIEW_DONE_FIRST_BUILD_POLL_INTERVAL_MS = 250;
    const VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS = 25000;

    /** @var bool Process-local guard so a single request never rebuilds twice. */
    private static $viewBuildAlreadyRanThisRequest = false;

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        self::$viewBuildAlreadyRanThisRequest = false;
    }

    /** @return string */
    private function viewBuildTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_build}');
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    private function viewDeletemeTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_deleteme}');
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    /**
     * Public entry: returns the page of rows the admin Redirects/Captured
     * tab should render. Always reads from the served view_done table.
     * The build is triggered (inline or background, depending on freshness
     * and presence of view_done) before the read.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        // Honor _abj404_query_timeout from the warmup pipeline so staged
        // queries inherit the same per-stage budget legacy code did. Reset
        // on entry so a previous request's value cannot leak across calls.
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $haveDone = $this->viewDoneTableExists();
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
        $isInvalidated = $haveDone && $builtAt === 0;

        if ($isFresh) {
            return $this->readFromViewDone($sub, $tableOptions);
        }

        if ($haveDone && !$isInvalidated) {
            // Stale but not invalidated: serve stale (TTL expired but data
            // is presumed correct), kick off background rebuild for next
            // request. This is the steady-state warm path.
            $this->scheduleViewDoneRebuild();
            return $this->readFromViewDone($sub, $tableOptions);
        }

        // Either view_done is missing entirely, or invalidateViewDone()
        // cleared the freshness option (redirect was just created/edited/
        // deleted). Either way, force an inline rebuild before serving.
        // Stale data after an invalidation is wildly wrong, not just 60s
        // out of date.
        if ($this->acquireViewBuildLock()) {
            try {
                $this->runStagedBuildOnce();
            } finally {
                $this->releaseViewBuildLock();
            }
            return $this->readFromViewDone($sub, $tableOptions);
        }

        if ($this->pollForViewDone(self::VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS)) {
            return $this->readFromViewDone($sub, $tableOptions);
        }

        $this->surfaceViewBuildAdminNotice(
            'The redirects view table has not finished its first build. Reload the page in a few seconds.'
        );
        throw new \Exception('Staged view build still pending after poll budget.');
    }

    /** @return int Unix timestamp of last successful build, or 0 if missing. */
    private function viewDoneBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->viewDoneFreshnessOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /**
     * COUNT(*) sibling to runRedirectsForViewStaged. Used by
     * getRedirectsForViewCount when filterText is non-empty (the
     * filterText-empty path already uses the optimized COUNT against
     * the live redirects table, which stays fast). Same build/serve flow
     * as the row path; the build is shared via the per-request guard.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $haveDone = $this->viewDoneTableExists();
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
        $isInvalidated = $haveDone && $builtAt === 0;

        if (!$isFresh) {
            if (!$haveDone || $isInvalidated) {
                // Force inline rebuild: either no data at all, or the
                // freshness option was cleared by invalidateViewDone().
                if ($this->acquireViewBuildLock()) {
                    try {
                        $this->runStagedBuildOnce();
                    } finally {
                        $this->releaseViewBuildLock();
                    }
                } else if (!$this->pollForViewDone(self::VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS)) {
                    throw new \Exception('Staged view build still pending after poll budget.');
                }
            } else {
                $this->scheduleViewDoneRebuild();
            }
        }

        $sql = $this->buildViewDoneCountQuery($sub, $tableOptions);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $row = is_array($rows[0]) ? $rows[0] : array();
        $raw = $row['cnt'] ?? reset($row);
        return is_scalar($raw) ? intval($raw) : 0;
    }

    /**
     * Hook target for `wp_schedule_single_event('abj404_rebuildViewDone')`.
     * Rebuilds inline (under the build lock) so the next admin request
     * sees fresh data. Called from PluginLogic during cron registration.
     *
     * @return void
     */
    public function rebuildViewDoneInBackground(): void {
        if (!$this->acquireViewBuildLock()) {
            return;
        }
        try {
            $this->runStagedBuildOnce();
        } catch (Throwable $e) {
            $this->logger->errorMessage('[staged] background rebuild failed: ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null);
        } finally {
            $this->releaseViewBuildLock();
        }
    }

    /**
     * Mark the served view_done table stale so the next request triggers a
     * rebuild. Hooked from invalidateViewSnapshotCache() so redirect
     * create/update/delete invalidates the precomputed view too.
     *
     * @return void
     */
    public function invalidateViewDone(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
        }
    }

    /**
     * Run all staged build statements in order against the shared build
     * buffer, then atomically swap into view_done. Idempotent guarded:
     * only runs once per request even if called multiple times.
     *
     * @return void
     */
    private function runStagedBuildOnce(): void {
        if (self::$viewBuildAlreadyRanThisRequest) {
            return;
        }
        self::$viewBuildAlreadyRanThisRequest = true;

        $this->dropTransientStagedTables();
        $this->stageCreateBuildTable();
        $this->stageInsertRedirects();
        $this->stageAddPreJoinIndexes();
        $this->stageUpdatePosts();
        $this->stageUpdateTerms();
        $this->stageUpdateHome();
        $this->stageUpdateExternal();
        $this->stageUpdateSpecial();
        if ($this->logsHitsTableExists()) {
            $this->stageUpdateHits();
        }
        $this->stageAddSortIndexes();
        $this->stageRenameSwap();
        if (function_exists('update_option')) {
            update_option($this->viewDoneFreshnessOptionName(), time(), false);
        }
    }

    /** @return void */
    private function dropTransientStagedTables(): void {
        $build = $this->viewBuildTableName();
        $deleteme = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $build . '`',
            array('log_errors' => false));
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));
    }

    /**
     * S1: create the build buffer. Tries the system default storage
     * engine, then falls back to MyISAM, then to InnoDB so it works on
     * hosts that disable one or the other.
     *
     * @return void
     */
    private function stageCreateBuildTable(): void {
        $template = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/sql/createViewBuildTable.sql');
        $base = $this->doTableNameReplacements(is_string($template) ? $template : '');
        if (trim($base) === '') {
            throw new \Exception('createViewBuildTable.sql is empty or unreadable.');
        }

        $attempts = array(
            $base,
            $base . ' ENGINE=MyISAM',
            $base . ' ENGINE=InnoDB',
        );
        $lastError = '';
        $opts = $this->stagedQueryOptions();
        $opts['log_errors'] = false;
        foreach ($attempts as $sql) {
            $result = $this->queryAndGetResults($sql, $opts);
            $err = isset($result['last_error']) && is_string($result['last_error'])
                ? trim($result['last_error']) : '';
            if ($err === '' && empty($result['timed_out'])) {
                return;
            }
            $lastError = $err !== '' ? $err : 'unknown';
        }
        throw new \Exception('Could not create view build table on any storage engine: ' . $lastError);
    }

    /** @return void */
    private function stageInsertRedirects(): void {
        $this->runStagedSqlFile('02_insert.sql', $this->viewBuildOnlyTranslations());
    }

    /** @return void */
    private function stageAddPreJoinIndexes(): void {
        $this->runStagedSqlFile('03_index_fd.sql', array());
    }

    /** @return void */
    private function stageUpdatePosts(): void {
        $this->runStagedSqlFile('04_update_posts.sql', array());
    }

    /** @return void */
    private function stageUpdateTerms(): void {
        $this->runStagedSqlFile('05_update_terms.sql', array());
    }

    /** @return void */
    private function stageUpdateHome(): void {
        $this->runStagedSqlFile('06_update_home.sql', array());
    }

    /** @return void */
    private function stageUpdateExternal(): void {
        $this->runStagedSqlFile('07_update_external.sql', array());
    }

    /** @return void */
    private function stageUpdateSpecial(): void {
        $this->runStagedSqlFile('08_update_special.sql', $this->viewBuildOnlyTranslations());
    }

    /** @return void */
    private function stageUpdateHits(): void {
        $this->runStagedSqlFile('09_update_hits.sql', array());
    }

    /** @return void */
    private function stageAddSortIndexes(): void {
        $this->runStagedSqlFile('10_index_sort.sql', array());
    }

    /**
     * S11: atomic RENAME TABLE swap. Build buffer becomes the new served
     * table; the previous served table (if any) becomes deleteme and is
     * dropped.
     *
     * @return void
     */
    private function stageRenameSwap(): void {
        $build = $this->viewBuildTableName();
        $done = $this->viewDoneTableName();
        $deleteme = $this->viewDeletemeTableName();

        // Defensive: ensure deleteme is gone before the swap (S0 already did
        // this, but a poorly-timed parallel rebuild could have created it).
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));

        if ($this->viewDoneTableExists()) {
            $sql = 'RENAME TABLE `' . $done . '` TO `' . $deleteme . '`,'
                 . ' `' . $build . '` TO `' . $done . '`';
        } else {
            $sql = 'RENAME TABLE `' . $build . '` TO `' . $done . '`';
        }

        $result = $this->queryAndGetResults($sql, array('log_errors' => true));
        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('RENAME TABLE swap failed: ' . $err);
        }

        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));
    }

    /**
     * Read page from the served view_done table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    private function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->queryAndGetResults($query, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the WHERE/ORDER/LIMIT SELECT against view_done. Mirrors the
     * legacy filter-text composite LIKE so search semantics are preserved,
     * but reads against precomputed columns (no JOINs, no CASE
     * recomputation).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types, $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: every tab including HANDLED filters by
        // disabled = 0 (active rows) except the dedicated TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $scoreRange = is_string($tableOptions['score_range'] ?? '')
            ? (string)($tableOptions['score_range'] ?? 'all') : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null)
            ? (string)$tableOptions['filterText'] : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $orderBy = $this->resolveOrderByColumn($tableOptions);
        $rawOrderVal = $tableOptions['order'] ?? '';
        $rawOrderValStr = is_string($rawOrderVal) ? $rawOrderVal : '';
        $order = strtoupper((string)preg_replace('/[^a-zA-Z]/', '', trim($rawOrderValStr)));
        if ($order !== 'DESC') { $order = 'ASC'; }

        $paged = max(1, intval(is_scalar($tableOptions['paged'] ?? 1) ? (int)$tableOptions['paged'] : 1));
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, intval(is_scalar($rawPerpage) ? (int)$rawPerpage : ABJ404_OPTION_DEFAULT_PERPAGE));
        $limitStart = ($paged - 1) * $perpage;

        $done = $this->viewDoneTableName();
        $query = "SELECT id, url, status, status_for_view, type, type_for_view,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY published_status ASC, " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
        return $query;
    }

    /**
     * COUNT(*) variant of buildViewDoneReadQuery. Same WHERE clauses, no
     * ORDER BY, no LIMIT.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        global $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: HANDLED filter shows active rows only
        // (disabled = 0), same as every non-TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $scoreRange = is_string($tableOptions['score_range'] ?? '')
            ? (string)($tableOptions['score_range'] ?? 'all') : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null)
            ? (string)$tableOptions['filterText'] : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $done = $this->viewDoneTableName();
        $query = "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
        return $query;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveStatusTypeList(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;
        $filter = $tableOptions['filter'] ?? 0;
        $statusTypes = '';
        if ($filter == 0 || $filter == ABJ404_TRASH_FILTER) {
            if ($sub === 'abj404_redirects') {
                $statusTypes = implode(', ', is_array($abj404_redirect_types) ? $abj404_redirect_types : array());
            } else if ($sub === 'abj404_captured') {
                $statusTypes = implode(', ', is_array($abj404_captured_types) ? $abj404_captured_types : array());
            }
        } else if ($filter == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($filter == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = (string)$filter;
        }
        $cleaned = preg_replace('/[^\d, ]/', '', $statusTypes);
        return is_string($cleaned) ? $cleaned : '';
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            // Same as legacy: treat empty dest as last.
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'url';
        }
        return $orderBy;
    }

    /**
     * Translations for status_for_view, type_for_view, and the special
     * 404-displayed label. Everything else (`{wp_*}`, `{ABJ404_TYPE_X}`)
     * is handled by doTableNameReplacements + doNormalReplacements.
     *
     * @return array<string, string>
     */
    private function viewBuildOnlyTranslations(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Manual', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}'   => __('Automatic', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}'  => __('Regex', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}'      => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}'      => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}'     => __('Home', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(404 page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}'  => __('Special', '404-solution'),
        );
    }

    /**
     * Execute a staged SQL file with placeholder substitution and the
     * standard error-handling pipeline.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    private function runStagedSqlFile(string $relativePath, array $extraTranslations): void {
        $path = __DIR__ . '/sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_Functions::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \Exception("Staged SQL template missing or empty: $relativePath");
        }
        $sql = $this->doTableNameReplacements($template);
        // extraTranslations (status_for_view / type_for_view labels) must
        // run BEFORE doNormalReplacements: doNormalReplacements falls back
        // to __() for any {key} it does not know, which strips the braces
        // and prevents the str_replace below from matching.
        if (!empty($extraTranslations)) {
            $sql = $this->f->str_replace(array_keys($extraTranslations), array_values($extraTranslations), $sql);
        }
        $sql = $this->f->doNormalReplacements($sql);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('Staged SQL ' . $relativePath . ' failed: ' . $err);
        }
    }

    /**
     * @return array<string, mixed> Options for queryAndGetResults that
     * inherit the warmup pipeline's per-stage timeout when set.
     */
    private function stagedQueryOptions(): array {
        if ($this->stagedQueryTimeoutSeconds > 0) {
            return array('timeout' => $this->stagedQueryTimeoutSeconds);
        }
        return array();
    }

    /** @return bool */
    private function viewDoneTableExists(): bool {
        return $this->stagedTableExists($this->viewDoneTableName());
    }

    /** @param string $tableName @return bool */
    private function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        if (!is_string($sql) || $sql === '') {
            return false;
        }
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return false;
        }
        $first = $rows[0];
        $first = is_array($first) ? $first : array();
        $value = reset($first);
        return ((string)$value === $tableName);
    }

    /** @return bool */
    private function viewDoneIsFresh(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $built = get_option($this->viewDoneFreshnessOptionName(), 0);
        $builtAt = is_scalar($built) ? intval($built) : 0;
        if ($builtAt <= 0) {
            return false;
        }
        return (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /** @return bool */
    private function acquireViewBuildLock(): bool {
        $name = $this->getLowercasePrefix() . self::VIEW_DONE_BUILD_LOCK_NAME;
        $sql = "SELECT GET_LOCK('" . esc_sql($name) . "', 0) AS got";
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return false;
        }
        $got = $rows[0]['got'] ?? 0;
        return is_scalar($got) && intval($got) === 1;
    }

    /** @return void */
    private function releaseViewBuildLock(): void {
        $name = $this->getLowercasePrefix() . self::VIEW_DONE_BUILD_LOCK_NAME;
        $this->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')",
            array('log_errors' => false));
    }

    /**
     * Wait up to $budgetMs for view_done to materialize. Used when the
     * build lock was unavailable on a first-ever request and we need
     * the other builder to finish before we can serve.
     *
     * @param int $budgetMs
     * @return bool true when view_done becomes available within the budget
     */
    private function pollForViewDone(int $budgetMs): bool {
        $deadline = microtime(true) + ($budgetMs / 1000);
        while (microtime(true) < $deadline) {
            if ($this->viewDoneTableExists()) {
                return true;
            }
            usleep(self::VIEW_DONE_FIRST_BUILD_POLL_INTERVAL_MS * 1000);
        }
        return $this->viewDoneTableExists();
    }

    /** @return void */
    private function scheduleViewDoneRebuild(): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            $hook = 'abj404_rebuildViewDone';
            $next = wp_next_scheduled($hook);
            if ($next === false) {
                wp_schedule_single_event(time() + 1, $hook);
            }
        }
    }

    /**
     * Surface a single admin notice on the plugin's own admin pages when
     * the first-ever build is still pending. Per CLAUDE.md: never email,
     * never wp-admin-wide. Existing transient-based dedupe keeps it to one
     * notice per 24h per failure type.
     *
     * @param string $message
     * @return void
     */
    private function surfaceViewBuildAdminNotice(string $message): void {
        if (function_exists('set_transient')) {
            set_transient('abj404_view_build_pending_notice', $message, 60);
        }
    }
}
