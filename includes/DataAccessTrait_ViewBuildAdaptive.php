<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adaptive-runtime helpers for the staged view-build pipeline.
 *
 * Two responsibilities, both keyed off behavior the build only learns at
 * runtime on the actual host:
 *
 *   1. Adaptive batch size: when a host kills a batched stage's per-query
 *      (max_statement_time exceeded, lock-wait, gone-away), halve the
 *      batch size for that stage and persist it. Subsequent ticks of the
 *      same build use the smaller size, so a slow shared host eventually
 *      converges to a batch size it can actually finish.
 *
 *   2. Intelligent per-query timeout: probe the host's session-level
 *      max_statement_time (MariaDB) or max_execution_time (MySQL) once
 *      per request, then size our own per-query SET STATEMENT hint to
 *      fire just before the host's silent kill would. This converts a
 *      generic connection drop into a clean classifiable kill the
 *      pipeline can resume from.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait; both are
 * mixed into ABJ_404_Solution_DataAccess. Private members declared here
 * are visible to the staged-build trait inside the composing class.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildAdaptiveTrait {

    /**
     * Request-lifetime cache of the host's per-statement timeout, in
     * seconds. -1 means "not yet probed", 0 means "no host limit", > 0
     * is the limit the host enforces. Probed lazily by
     * detectHostStagedQueryLimitSeconds().
     *
     * @var float
     */
    private $hostStagedQueryLimitSecondsCache = -1.0;

    /**
     * Per-stage batch size with adaptive-shrink memory. Reads the
     * persisted shrink option for this stage (s2_batch_size /
     * s4_batch_size / s5_batch_size); falls back to the global default
     * when none is set. The persisted value survives across requests so
     * a host that has already shown it cannot handle 2000-row batches
     * keeps using the smaller size for the rest of the build.
     *
     * @param string $stageShortKey  One of 's2_batch_size', 's4_batch_size', 's5_batch_size'.
     * @return int  Always >= VIEW_BUILD_MIN_BATCH_SIZE.
     */
    private function viewBuildBatchSizeForStage(string $stageShortKey): int {
        $defaultSize = $this->viewBuildBatchSize();
        $persisted = $this->readProgressOption($stageShortKey, 0);
        $effective = $persisted > 0 ? $persisted : $defaultSize;
        return max(ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_MIN_BATCH_SIZE, $effective);
    }

    /**
     * Record that a batch in stage $stageShortKey was killed by the host
     * (resumable kill class: max_statement_time exceeded, gone-away,
     * lock-wait). Halves the batch size, floors at
     * VIEW_BUILD_MIN_BATCH_SIZE, persists. Subsequent ticks pick up the
     * smaller size via viewBuildBatchSizeForStage().
     *
     * @param string $stageShortKey
     * @return int  the new batch size.
     */
    private function recordStageBatchKilled(string $stageShortKey): int {
        $current = $this->viewBuildBatchSizeForStage($stageShortKey);
        $shrunk = (int)max(
            ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_MIN_BATCH_SIZE,
            (int)floor($current / 2)
        );
        $this->writeProgressOption($stageShortKey, $shrunk);
        return $shrunk;
    }

    /**
     * Seconds of PHP request time remaining before max_execution_time
     * fires. PHP_INT_MAX when no limit is set (CLI / unbounded cron).
     *
     * Used by the batched stages to decide whether there is room to
     * start another batch at our full per-query limit. If not, the stage
     * yields via the wall-clock path (no batch attempt, no shrink) and
     * the next request resumes with a fresh PHP time budget.
     *
     * @return float
     */
    private function phpTimeRemainingSeconds(): float {
        $limit = (int)ini_get('max_execution_time');
        if ($limit <= 0) {
            return (float)PHP_INT_MAX;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float)$_SERVER['REQUEST_TIME_FLOAT']
            : (float)microtime(true);
        $elapsed = max(0.0, microtime(true) - $start);
        return max(0.0, (float)$limit - $elapsed);
    }

    /**
     * Probe the host for its session-level per-statement timeout. Reads
     * the MariaDB session variable max_statement_time (seconds, decimal)
     * first, then falls back to MySQL max_execution_time (milliseconds).
     * Returns 0.0 when no host limit is set. Cached on the instance for
     * the request lifetime so the build pays the SHOW VARIABLES cost
     * once, not once per stage.
     *
     * @return float  Seconds, or 0.0 for "no host limit".
     */
    private function detectHostStagedQueryLimitSeconds(): float {
        if ($this->hostStagedQueryLimitSecondsCache >= 0.0) {
            return $this->hostStagedQueryLimitSecondsCache;
        }
        $limitSeconds = 0.0;

        $result = $this->queryAndGetResults(
            "SHOW SESSION VARIABLES LIKE 'max_statement_time'",
            array('log_errors' => false)
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (!empty($rows) && is_array($rows[0])) {
            $value = $rows[0]['Value'] ?? ($rows[0]['value'] ?? null);
            if ($value !== null && is_numeric($value) && (float)$value > 0.0) {
                $limitSeconds = (float)$value;
            }
        }

        if ($limitSeconds <= 0.0) {
            $result = $this->queryAndGetResults(
                "SHOW SESSION VARIABLES LIKE 'max_execution_time'",
                array('log_errors' => false)
            );
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (!empty($rows) && is_array($rows[0])) {
                $value = $rows[0]['Value'] ?? ($rows[0]['value'] ?? null);
                if ($value !== null && is_numeric($value) && (int)$value > 0) {
                    $limitSeconds = ((int)$value) / 1000.0;
                }
            }
        }

        $this->hostStagedQueryLimitSecondsCache = max(0.0, $limitSeconds);
        return $this->hostStagedQueryLimitSecondsCache;
    }

    /**
     * Compute the per-query timeout we hint to the database for a
     * single staged-build query. The result is the smallest of:
     *
     *   - our per-stage budget minus a 2s margin (so the hint fires
     *     before PHP max_execution_time can interrupt the request)
     *   - the host max_statement_time minus 1s (so the hint fires
     *     before the host silent kill, giving us a clean classifiable
     *     error rather than a dropped connection)
     *
     * Floored at 5s so trivial probes always have headroom. When the
     * host has no limit set, only the per-stage budget applies.
     *
     * @return float  Seconds.
     */
    private function intelligentStagedQueryTimeoutSeconds(): float {
        $ourLimit = max(5.0, (float)$this->viewBuildPerStageBudgetSeconds() - 2.0);
        $hostLimit = $this->detectHostStagedQueryLimitSeconds();
        if ($hostLimit > 0.0) {
            return max(5.0, min($ourLimit, $hostLimit - 1.0));
        }
        return $ourLimit;
    }
}
