<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-stage callback implementations for the staged view-build pipeline.
 *
 * Each `stage*` method here is invoked from the orchestrator's
 * runStagedBuildOnce() loop in
 * {@see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait}. The orchestrator
 * owns the `current_stage` progression, per-stage timing/logging, kill-streak
 * escape, and request-scope guards; this trait owns just the per-stage
 * SQL/work performed at each step:
 *
 *   - dropTransientStagedTables / dropDeletemeTable: S0 cleanup.
 *   - stageCreateBuildTable: S1 CREATE with engine fallback.
 *   - stageInsertRedirectsBatched: S2 resumable INSERT loop.
 *   - stageAddPreJoinIndexes: S3 ALTER TABLE for join indexes.
 *   - stageUpdatePostsBatched / stageUpdateTermsBatched: S4/S5 resumable
 *     id-range UPDATE-JOINs (delegated to runIdRangeBatchedUpdate below).
 *   - stageUpdateHome / stageUpdateExternal / stageUpdateSpecial: S6/S7/S8
 *     non-batched UPDATEs.
 *   - stageUpdateHits: S9 hits aggregation.
 *   - stageAddSortIndexes: S10 ALTER TABLE for sort indexes.
 *   - stageRenameSwap: S11 atomic RENAME TABLE swap to publish the buffer.
 *
 * Plus shared helpers (runInsertBatch, runIdRangeBatchedUpdate,
 * countLiveRedirects, countViewBuildRows, maxBuildBufferId,
 * humanBatchProgress) that compose these stages.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait and
 * ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait; all three are mixed
 * into ABJ_404_Solution_DataAccess. Properties / helper methods declared on
 * those traits (markBuildStage, runStagedSqlFile, viewBuildTableName,
 * stagedQueryOptions, etc.) are visible inside the composing class.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait {

    /** Drop both the build buffer and the leftover deleteme.  Used on fresh-start only. */
    private function dropTransientStagedTables(): void {
        $buildTempTable = $this->viewBuildTableName();
        $deletemeTempTable = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $buildTempTable . '`',
            array('log_errors' => false));
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /** Drop only the deleteme leftover from a prior crashed RENAME swap. */
    private function dropDeletemeTable(): void {
        $deletemeTempTable = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
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
            'default' => $base,
            'MyISAM'  => $base . ' ENGINE=MyISAM',
            'InnoDB'  => $base . ' ENGINE=InnoDB',
        );
        $lastError = '';
        $errorsSoFar = array();
        $opts = $this->stagedQueryOptions();
        $opts['log_errors'] = false;
        foreach ($attempts as $engineLabel => $sql) {
            $result = $this->queryAndGetResults($sql, $opts);
            $err = isset($result['last_error']) && is_string($result['last_error'])
                ? trim($result['last_error']) : '';
            if ($err === '' && empty($result['timed_out'])) {
                if ($engineLabel !== 'default') {
                    // Default engine failed but a fallback won. Worth knowing
                    // because hosts that need a fallback often have other
                    // engine-specific quirks downstream (lock waits, ALTER
                    // semantics, etc.).
                    $this->logger->warn(sprintf(
                        '[staged] S1 createViewBuildTable: default engine '
                        . 'failed (%s); succeeded on fallback %s.',
                        substr(implode('; ', $errorsSoFar), 0, 200),
                        $engineLabel
                    ));
                }
                return;
            }
            $lastError = $err !== '' ? $err : 'unknown';
            $errorsSoFar[] = $engineLabel . ': ' . $lastError;
        }
        throw new \Exception('Could not create view build table on any storage engine: ' . $lastError);
    }

    /**
     * S2: bulk-load redirects into the build buffer, in resumable batches.
     *
     * Source of truth for the high-water is `MAX(id)` of the build buffer
     * itself; option `s2_high_water` is written for diagnostics / visibility
     * but is never consulted.  Using buffer MAX(id) directly makes resumption
     * crash-safe: if PHP dies between an INSERT and the option write, the
     * next request still picks up from exactly where the INSERT left off.
     *
     * Each batch INSERTs the next BATCH_SIZE rows from wp_abj404_redirects
     * with `id > <buffer MAX(id)>`.  Per-stage budget caps wall-clock time
     * so the request can finish even when the dataset is too big to copy in
     * one shot.
     *
     * @return bool  true when the entire redirects table has been copied;
     *               false when the per-stage budget was exhausted mid-stage.
     */
    private function stageInsertRedirectsBatched(): bool {
        $this->markBuildStage('staged_build_s2_insert');
        $deadline = microtime(true) + $this->viewBuildPerStageBudgetSeconds();
        // Pre-flight check uses the SQL hint (smaller than the wall-clock
        // budget by design), not the budget itself. This is the worst-case
        // time a single batch can take before SET STATEMENT max_statement_time
        // fires. The budget is a loop-level wall clock; a single batch never
        // takes a full budget to run.
        $perQueryLimit = max(1.0, (float)$this->intelligentStagedQueryTimeoutSeconds());

        $totalCount = $this->countLiveRedirects();
        if ($totalCount <= 0) {
            // Empty redirects table; nothing to copy.
            $this->writeProgressOption('s2_high_water', 0);
            return true;
        }

        $batchNumber = 0;
        while (true) {
            $copiedSoFar = $this->countViewBuildRows();
            if ($copiedSoFar >= $totalCount) {
                break; // covered the table
            }
            // Wall-clock yield (Path A): per-stage budget exhausted. NOT a
            // batch-size problem; do not shrink.
            if (microtime(true) >= $deadline) {
                $this->markBuildStage('staged_build_s2_insert',
                    'batch ' . $this->humanBatchProgress($copiedSoFar, $totalCount) . ' (yielded)');
                return false;
            }
            // Pre-flight: only start a batch when the request has enough PHP
            // time left to finish it at our SQL hint. Without this, a batch
            // we started with too little time would get killed mid-flight by
            // PHP's max_execution_time and we could not safely tell whether
            // the kill was a real batch-too-big problem or just request-time
            // exhaustion. Yield without shrinking.
            //
            // Always allow the first batch of a tick to run, even when PHP
            // time looks tight: phpTimeRemainingSeconds() reflects the time
            // left at the START of the stage, which on a typical 30s shared
            // host is already below the SQL hint after WP boot. Without this
            // first-batch escape, the build would yield on every request
            // without ever inserting a row -- exactly the "stuck at stage
            // 1/11" symptom that stranded large-site installs.
            if ($batchNumber > 0 && $this->phpTimeRemainingSeconds() < $perQueryLimit + 1.0) {
                $this->markBuildStage('staged_build_s2_insert',
                    'batch ' . $this->humanBatchProgress($copiedSoFar, $totalCount) . ' (yielded; tight time)');
                return false;
            }

            $batchSize = $this->viewBuildBatchSizeForStage('s2_batch_size');
            $batchNumber++;
            $loBound = $this->maxBuildBufferId();
            $beforeMax = $loBound;
            try {
                // Public extension point. Sites hook this for per-batch
                // telemetry; tests bind a callback that throws to simulate
                // a host kill. Inside the try/catch so a hook-thrown
                // resumable error is handled exactly the same way as a
                // real kill from the SQL call below.
                if (function_exists('do_action')) {
                    do_action('abj404_view_build_batch_starting', 's2_insert', $batchNumber, $batchSize);
                }
                $afterMax = $this->runInsertBatch($loBound, $batchSize);
            } catch (\Throwable $e) {
                if ($this->isResumableStagedKill($e->getMessage())) {
                    // Path B: batch genuinely too big at the host limit.
                    // Halve, persist, yield. Next tick uses smaller size.
                    $newSize = $this->recordStageBatchKilled('s2_batch_size');
                    $this->logger->warn(sprintf(
                        '[staged] S2 batch killed by host at size %d; '
                        . 'shrunk s2_batch_size to %d. Trigger: %s',
                        $batchSize, $newSize, substr($e->getMessage(), 0, 200)
                    ));
                    $this->markBuildStage('staged_build_s2_insert',
                        'batch killed at size ' . $batchSize
                        . '; shrunk to ' . $newSize . ', yielded');
                    return false;
                }
                throw $e;
            }
            if ($afterMax === $beforeMax) {
                // No rows above $loBound to copy. Either the redirects table
                // shrank during the build, or all remaining ids are <= loBound
                // (impossible given strict id-range semantics, but defensive).
                // Treat as done; the read query will reflect whatever was
                // captured.
                $this->logger->warn(sprintf(
                    '[staged] S2 stopping early: INSERT batch did not advance '
                    . 'MAX(id) (loBound=%d, beforeMax=%d, afterMax=%d, '
                    . 'copiedSoFar=%d, totalCount=%d). Treating as done.',
                    $loBound, $beforeMax, $afterMax, $copiedSoFar, $totalCount
                ));
                break;
            }
            // Mirror MAX(id) into the option for diagnostics. This is
            // best-effort; correctness does NOT depend on this write.
            $this->writeProgressOption('s2_high_water', $afterMax);

            $this->markBuildStage('staged_build_s2_insert',
                'batch ' . $this->humanBatchProgress($this->countViewBuildRows(), $totalCount));
        }

        $this->writeProgressOption('s2_high_water', 0);
        return true;
    }

    /** @return void */
    private function stageAddPreJoinIndexes(): void {
        // S3 indexes are added with IF NOT EXISTS semantics emulated by
        // catching "Duplicate key name" on retry. See runStagedSqlFile
        // tolerance below.  ALTER TABLE itself is fast on the buffer.
        $this->runStagedSqlFileTolerantOfDuplicateKey('03_index_fd.sql', array());
    }

    /**
     * S4: resolve POST-typed redirects against wp_posts in resumable batches
     * keyed by view_build.id range.
     *
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function stageUpdatePostsBatched(): bool {
        return $this->runIdRangeBatchedUpdate(
            'staged_build_s4_update_posts',
            's4_high_water',
            '04_update_posts.sql'
        );
    }

    /**
     * S5: resolve CAT/TAG-typed redirects against wp_terms in resumable
     * batches keyed by view_build.id range.
     *
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function stageUpdateTermsBatched(): bool {
        return $this->runIdRangeBatchedUpdate(
            'staged_build_s5_update_terms',
            's5_high_water',
            '05_update_terms.sql'
        );
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
        $this->runStagedSqlFile('09a_drop_hits_temp.sql', array());
        $this->runStagedSqlFile('09b_create_hits_temp.sql', array());
        $this->runStagedSqlFile('09c_insert_hits_temp.sql', array());
        $this->runStagedSqlFile('09_update_hits.sql', array());
        $this->runStagedSqlFile('09a_drop_hits_temp.sql', array());
    }

    /** @return void */
    private function stageAddSortIndexes(): void {
        $this->runStagedSqlFileTolerantOfDuplicateKey('10_index_sort.sql', array());
    }

    /**
     * Run one INSERT batch for S2.  Inserts the next BATCH_SIZE rows from
     * wp_abj404_redirects with `id > $loBound` (ORDER BY id ASC LIMIT
     * BATCH_SIZE) into the build buffer.  Returns the new MAX(id) of the
     * buffer so the caller can detect "no more rows" (buffer max didn't
     * change after the insert).
     *
     * @param int $loBound    MAX(id) of the buffer at batch start.
     * @param int $batchSize
     * @return int  New MAX(id) of the buffer after this batch (== $loBound
     *              when no rows were inserted).
     */
    private function runInsertBatch(int $loBound, int $batchSize): int {
        $loBound = max(0, intval($loBound));
        $batchSize = max(1, intval($batchSize));

        $extra = $this->viewBuildOnlyTranslations();
        $extra['{LO_BOUND}']   = (string)$loBound;
        $extra['{BATCH_SIZE}'] = (string)$batchSize;
        $this->runStagedSqlFile('02_insert.sql', $extra);

        return $this->maxBuildBufferId();
    }

    /**
     * Run an UPDATE-JOIN stage in id-range batches against the build buffer.
     *
     * The SQL fragment must use `WHERE t.id > {LO_BOUND} AND t.id <= {HI_BOUND}`
     * (the staged 04/05 SQL files do this once converted) so we can stride
     * forward by id without a per-batch COUNT.
     *
     * @param string $stageKey         Sub-stage label, e.g. 'staged_build_s4_update_posts'.
     * @param string $highWaterKey     Progress option key, e.g. 's4_high_water'.
     * @param string $sqlFile          Filename under sql/getRedirectsForViewStaged/.
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function runIdRangeBatchedUpdate(string $stageKey, string $highWaterKey, string $sqlFile): bool {
        $this->markBuildStage($stageKey);
        $deadline = microtime(true) + $this->viewBuildPerStageBudgetSeconds();
        $perQueryLimit = max(1.0, (float)$this->intelligentStagedQueryTimeoutSeconds());
        // s4_high_water -> s4_batch_size; s5_high_water -> s5_batch_size.
        $batchSizeKey = str_replace('_high_water', '_batch_size', $highWaterKey);

        $highWater = $this->readProgressOption($highWaterKey, 0);
        $totalMaxId = $this->maxBuildBufferId();
        if ($totalMaxId <= 0) {
            // Buffer is empty (no redirects). Nothing to update.
            $this->writeProgressOption($highWaterKey, 0);
            return true;
        }

        $batchNumber = 0;
        while ($highWater < $totalMaxId) {
            // Wall-clock yield (Path A); not a batch-size problem.
            if (microtime(true) >= $deadline) {
                $this->markBuildStage($stageKey,
                    'batch ' . $this->humanBatchProgress($highWater, $totalMaxId) . ' (yielded)');
                return false;
            }
            // Pre-flight: yield without shrinking when there is not enough
            // PHP request time left to finish a batch at the SQL hint. Same
            // rationale as in stageInsertRedirectsBatched, including the
            // first-batch escape so a tight-PHP-time request still makes
            // forward progress instead of yielding indefinitely.
            if ($batchNumber > 0 && $this->phpTimeRemainingSeconds() < $perQueryLimit + 1.0) {
                $this->markBuildStage($stageKey,
                    'batch ' . $this->humanBatchProgress($highWater, $totalMaxId) . ' (yielded; tight time)');
                return false;
            }

            $batchSize = $this->viewBuildBatchSizeForStage($batchSizeKey);
            $batchNumber++;
            $hiBound = min($totalMaxId, $highWater + $batchSize);
            $extra = array(
                '{LO_BOUND}' => (string)$highWater,
                '{HI_BOUND}' => (string)$hiBound,
            );
            try {
                if (function_exists('do_action')) {
                    do_action('abj404_view_build_batch_starting', $stageKey, $batchNumber, $batchSize);
                }
                $this->runStagedSqlFile($sqlFile, $extra);
            } catch (\Throwable $e) {
                if ($this->isResumableStagedKill($e->getMessage())) {
                    $newSize = $this->recordStageBatchKilled($batchSizeKey);
                    $this->logger->warn(sprintf(
                        '[staged] %s batch killed by host at size %d; '
                        . 'shrunk %s to %d. Trigger: %s',
                        $stageKey, $batchSize, $batchSizeKey, $newSize,
                        substr($e->getMessage(), 0, 200)
                    ));
                    $this->markBuildStage($stageKey,
                        'batch killed at size ' . $batchSize
                        . '; shrunk to ' . $newSize . ', yielded');
                    return false;
                }
                throw $e;
            }
            $highWater = $hiBound;
            $this->writeProgressOption($highWaterKey, $highWater);

            $this->markBuildStage($stageKey,
                'batch ' . $this->humanBatchProgress($highWater, $totalMaxId));
        }

        // Stage done; reset high-water for the next rebuild.
        $this->writeProgressOption($highWaterKey, 0);
        return true;
    }

    /** @return int  Total active+inactive rows in wp_abj404_redirects. */
    private function countLiveRedirects(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int  Rows currently in the build buffer. */
    private function countViewBuildRows(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int  Max(id) in the build buffer, 0 when empty. */
    private function maxBuildBufferId(): int {
        $sql = 'SELECT MAX(id) AS max_id FROM '
            . $this->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $rawMax = $rows[0]['max_id'] ?? null;
        if ($rawMax === null || $rawMax === '') {
            return 0;
        }
        return max(0, intval($rawMax));
    }

    /**
     * @param int $done
     * @param int $total
     * @return string  e.g. "12/45" or "complete" when done==total.
     */
    private function humanBatchProgress(int $done, int $total): string {
        if ($total <= 0) {
            return 'complete';
        }
        return min($done, $total) . '/' . $total;
    }

    /**
     * S11: atomic RENAME TABLE swap. Build buffer becomes the new served
     * table; the previous served table (if any) becomes deleteme and is
     * dropped.
     *
     * @return void
     */
    private function stageRenameSwap(): void {
        $buildTempTable = $this->viewBuildTableName();
        $done = $this->viewDoneTableName();
        $deletemeTempTable = $this->viewDeletemeTableName();

        // Defensive: ensure deleteme is gone before the swap (S0 already did
        // this, but a poorly-timed parallel rebuild could have created it).
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));

        if ($this->viewDoneTableExists()) {
            $sql = 'RENAME TABLE `' . $done . '` TO `' . $deletemeTempTable . '`,'
                 . ' `' . $buildTempTable . '` TO `' . $done . '`';
        } else {
            $sql = 'RENAME TABLE `' . $buildTempTable . '` TO `' . $done . '`';
        }

        $result = $this->queryAndGetResults($sql, array('log_errors' => true));
        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('RENAME TABLE swap failed: ' . $err);
        }

        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }
}
