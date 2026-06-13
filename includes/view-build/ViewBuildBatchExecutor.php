<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resumable batch executor for staged view-build S2, S4, and S5.
 *
 * Owns batch sizing, high-water persistence, host-kill shrink/yield behavior,
 * and progress labels for the staged build's batched phases. Non-batched
 * stage definitions remain in ABJ_404_Solution_ViewBuildStageCallbacks.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildBatchExecutor extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * S2: bulk-load redirects into the build buffer, in resumable batches.
     *
     * Source of truth for the high-water is `MAX(id)` of the build buffer
     * itself; option `s2_high_water` is written for diagnostics / visibility
     * but is never consulted. Using buffer MAX(id) directly makes resumption
     * crash-safe: if PHP dies between an INSERT and the option write, the
     * next request still picks up from exactly where the INSERT left off.
     *
     * Each batch INSERTs the next BATCH_SIZE rows from wp_abj404_redirects
     * with `id > <buffer MAX(id)>`. Per-stage budget caps wall-clock time
     * so the request can finish even when the dataset is too big to copy in
     * one shot.
     *
     * @return bool True when the entire redirects table has been copied;
     *              false when the per-stage budget was exhausted mid-stage.
     */
    public function stageInsertRedirectsBatched(): bool {
        $this->host->stageServices()->stageLogPresenter()->markBuildStage('staged_build_s2_insert');
        $deadline = abj_clock()->nowFloat() + $this->host->stageServices()->stagePipeline()->viewBuildPerStageBudgetSeconds();
        // Pre-flight check uses the SQL hint (smaller than the wall-clock
        // budget by design), not the budget itself. This is the worst-case
        // time a single batch can take before SET STATEMENT max_statement_time
        // fires. The budget is a loop-level wall clock; a single batch never
        // takes a full budget to run.
        $perQueryLimit = max(1.0, (float)$this->host->stageServices()->adaptive()->intelligentStagedQueryTimeoutSeconds());

        $totalCount = $this->countLiveRedirects();
        if ($totalCount <= 0) {
            // Empty redirects table; nothing to copy.
            $this->host->stageServices()->progressOptions()->writeProgressOption('s2_high_water', 0);
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
            if (abj_clock()->nowFloat() >= $deadline) {
                $this->host->stageServices()->stageLogPresenter()->markBuildStage('staged_build_s2_insert',
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
            if ($batchNumber > 0 && $this->host->stageServices()->adaptive()->phpTimeRemainingSeconds() < $perQueryLimit + 1.0) {
                $this->host->stageServices()->stageLogPresenter()->markBuildStage('staged_build_s2_insert',
                    'batch ' . $this->humanBatchProgress($copiedSoFar, $totalCount) . ' (yielded; tight time)');
                return false;
            }

            $batchSize = $this->host->stageServices()->adaptive()->viewBuildBatchSizeForStage('s2_batch_size');
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
                if ($this->host->dataBoundary()->isResumableStagedKill($e->getMessage())) {
                    // Path B: batch genuinely too big at the host limit.
                    // Halve, persist, yield. Next tick uses smaller size.
                    $newSize = $this->host->stageServices()->adaptive()->recordStageBatchKilled('s2_batch_size');
                    $this->host->dataBoundary()->logger()->warn(sprintf(
                        '[staged] S2 batch killed by host at size %d; '
                        . 'shrunk s2_batch_size to %d. Trigger: %s',
                        $batchSize, $newSize, substr($e->getMessage(), 0, 200)
                    ));
                    $this->host->stageServices()->stageLogPresenter()->markBuildStage('staged_build_s2_insert',
                        'batch killed at size ' . $batchSize
                        . '; shrunk to ' . $newSize . ', yielded');
                    return false;
                }
                throw $e;
            }
            if ($afterMax === $beforeMax) {
                // Distinguish "no new rows to copy" from "buffer missing".
                // Without this check, a missing view_build looks identical
                // to a real shrink-during-build because maxBuildBufferId
                // returns 0 in both cases. The missing-buffer scenario is a
                // pipeline corruption that must halt, not silently mark
                // S2 complete. See Pattern 13.
                if (!$this->host->stageServices()->stateProbe()->stagedTableExists($this->host->stageServices()->stagePipeline()->viewBuildTableName())) {
                    throw new \Exception( // allow-raw-error: preserves staged-build classifier marker for resumable missing-buffer yield
                        'Staged view-build buffer missing during S2 INSERT; '
                        . 'pipeline state diverged from disk. Halting stage.'
                    );
                }
                // No rows above $loBound to copy. Either the redirects table
                // shrank during the build, or all remaining ids are <= loBound
                // (impossible given strict id-range semantics, but defensive).
                // Treat as done; the read query will reflect whatever was
                // captured.
                $this->host->dataBoundary()->logger()->warn(sprintf(
                    '[staged] S2 stopping early: INSERT batch did not advance '
                    . 'MAX(id) (loBound=%d, beforeMax=%d, afterMax=%d, '
                    . 'copiedSoFar=%d, totalCount=%d). Treating as done.',
                    $loBound, $beforeMax, $afterMax, $copiedSoFar, $totalCount
                ));
                break;
            }
            // Mirror MAX(id) into the option for diagnostics. This is
            // best-effort; correctness does NOT depend on this write.
            $this->host->stageServices()->progressOptions()->writeProgressOption('s2_high_water', $afterMax);

            $this->host->stageServices()->stageLogPresenter()->markBuildStage('staged_build_s2_insert',
                'batch ' . $this->humanBatchProgress($this->countViewBuildRows(), $totalCount));
        }

        $this->host->stageServices()->progressOptions()->writeProgressOption('s2_high_water', 0);
        return true;
    }

    /**
     * S4: resolve POST-typed redirects against wp_posts in resumable batches
     * keyed by view_build.id range.
     *
     * @return bool True when stage completed; false when budget exhausted.
     */
    public function stageUpdatePostsBatched(): bool {
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
     * @return bool True when stage completed; false when budget exhausted.
     */
    public function stageUpdateTermsBatched(): bool {
        return $this->runIdRangeBatchedUpdate(
            'staged_build_s5_update_terms',
            's5_high_water',
            '05_update_terms.sql'
        );
    }

    /**
     * Run one INSERT batch for S2.
     *
     * Inserts the next BATCH_SIZE rows from wp_abj404_redirects with
     * `id > $loBound` into the build buffer. Returns the new MAX(id) of the
     * buffer so the caller can detect "no more rows".
     *
     * @param int $loBound MAX(id) of the buffer at batch start.
     * @param int $batchSize
     * @return int New MAX(id) of the buffer after this batch.
     */
    public function runInsertBatch(int $loBound, int $batchSize): int {
        $loBound = max(0, intval($loBound));
        $batchSize = max(1, intval($batchSize));

        $extra = array();
        $extra['{LO_BOUND}']   = (string)$loBound;
        $extra['{BATCH_SIZE}'] = (string)$batchSize;
        $this->host->stageServices()->stagedSqlExecutor()->runStagedSqlFile('02_insert.sql', $extra);

        return $this->maxBuildBufferId();
    }

    /**
     * Run an UPDATE-JOIN stage in id-range batches against the build buffer.
     *
     * The SQL fragment must use `WHERE t.id > {LO_BOUND} AND t.id <= {HI_BOUND}`
     * so we can stride forward by id without a per-batch COUNT.
     *
     * @param string $stageKey Sub-stage label, e.g. 'staged_build_s4_update_posts'.
     * @param string $highWaterKey Progress option key, e.g. 's4_high_water'.
     * @param string $sqlFile Filename under sql/getRedirectsForViewStaged/.
     * @return bool True when stage completed; false when budget exhausted.
     */
    public function runIdRangeBatchedUpdate(string $stageKey, string $highWaterKey, string $sqlFile): bool {
        $this->host->stageServices()->stageLogPresenter()->markBuildStage($stageKey);
        $deadline = abj_clock()->nowFloat() + $this->host->stageServices()->stagePipeline()->viewBuildPerStageBudgetSeconds();
        $perQueryLimit = max(1.0, (float)$this->host->stageServices()->adaptive()->intelligentStagedQueryTimeoutSeconds());
        // s4_high_water -> s4_batch_size; s5_high_water -> s5_batch_size.
        $batchSizeKey = str_replace('_high_water', '_batch_size', $highWaterKey);

        $highWater = $this->host->stageServices()->progressOptions()->readProgressOption($highWaterKey, 0);
        $totalMaxId = $this->maxBuildBufferId();
        if ($totalMaxId <= 0) {
            // Distinguish "buffer is empty" from "buffer is missing". The
            // former is fine; the latter must halt and let the orchestrator
            // restart cleanly on the next tick.
            if (!$this->host->stageServices()->stateProbe()->stagedTableExists($this->host->stageServices()->stagePipeline()->viewBuildTableName())) {
                throw new \Exception(sprintf( // allow-raw-error: preserves staged-build classifier marker for resumable missing-buffer yield
                    'Staged view-build buffer missing at %s entry; pipeline state '
                    . 'diverged from disk. Halting stage.',
                    $stageKey
                ));
            }
            // Buffer is empty (no redirects). Nothing to update.
            $this->host->stageServices()->progressOptions()->writeProgressOption($highWaterKey, 0);
            return true;
        }

        $batchNumber = 0;
        while ($highWater < $totalMaxId) {
            // Wall-clock yield (Path A); not a batch-size problem.
            if (abj_clock()->nowFloat() >= $deadline) {
                $this->host->stageServices()->stageLogPresenter()->markBuildStage($stageKey,
                    'batch ' . $this->humanBatchProgress($highWater, $totalMaxId) . ' (yielded)');
                return false;
            }
            // Pre-flight: yield without shrinking when there is not enough
            // PHP request time left to finish a batch at the SQL hint.
            if ($batchNumber > 0 && $this->host->stageServices()->adaptive()->phpTimeRemainingSeconds() < $perQueryLimit + 1.0) {
                $this->host->stageServices()->stageLogPresenter()->markBuildStage($stageKey,
                    'batch ' . $this->humanBatchProgress($highWater, $totalMaxId) . ' (yielded; tight time)');
                return false;
            }

            $batchSize = $this->host->stageServices()->adaptive()->viewBuildBatchSizeForStage($batchSizeKey);
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
                $this->host->stageServices()->stagedSqlExecutor()->runStagedSqlFile($sqlFile, $extra);
            } catch (\Throwable $e) {
                if ($this->host->dataBoundary()->isResumableStagedKill($e->getMessage())) {
                    $newSize = $this->host->stageServices()->adaptive()->recordStageBatchKilled($batchSizeKey);
                    $this->host->dataBoundary()->logger()->warn(sprintf(
                        '[staged] %s batch killed by host at size %d; '
                        . 'shrunk %s to %d. Trigger: %s',
                        $stageKey, $batchSize, $batchSizeKey, $newSize,
                        substr($e->getMessage(), 0, 200)
                    ));
                    $this->host->stageServices()->stageLogPresenter()->markBuildStage($stageKey,
                        'batch killed at size ' . $batchSize
                        . '; shrunk to ' . $newSize . ', yielded');
                    return false;
                }
                throw $e;
            }
            $highWater = $hiBound;
            $this->host->stageServices()->progressOptions()->writeProgressOption($highWaterKey, $highWater);

            $this->host->stageServices()->stageLogPresenter()->markBuildStage($stageKey,
                'batch ' . $this->humanBatchProgress($highWater, $totalMaxId));
        }

        // Stage done; reset high-water for the next rebuild.
        $this->host->stageServices()->progressOptions()->writeProgressOption($highWaterKey, 0);
        return true;
    }

    /** @return int Total active+inactive rows in wp_abj404_redirects. */
    public function countLiveRedirects(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->host->dataBoundary()->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, $this->host->stageServices()->stagedSqlExecutor()->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int Rows currently in the build buffer. */
    public function countViewBuildRows(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->host->dataBoundary()->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int Max(id) in the build buffer, 0 when empty. */
    public function maxBuildBufferId(): int {
        $sql = 'SELECT MAX(id) AS max_id FROM '
            . $this->host->dataBoundary()->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $rawMax = $rows[0]['max_id'] ?? null;
        if ($rawMax === null || $rawMax === '') {
            return 0;
        }
        return is_scalar($rawMax) ? max(0, intval($rawMax)) : 0;
    }

    /**
     * @param int $done
     * @param int $total
     * @return string e.g. "12/45" or "complete" when done==total.
     */
    public function humanBatchProgress(int $done, int $total): string {
        if ($total <= 0) {
            return 'complete';
        }
        return min($done, $total) . '/' . $total;
    }
}
