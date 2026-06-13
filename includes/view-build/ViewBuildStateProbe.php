<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only probes against the view-build pipeline's persistent state:
 * table existence (view_done, view_build, view_deleteme, arbitrary staged
 * names), view_done freshness gate (built_at TTL), data-built-at timestamp
 * preserved across invalidations (the cold-install cycle-breaker signal
 * read by ViewDoneState::viewDoneIsServeable), and the human-readable
 * progress description used in the pending-build exception message.
 *
 * Sibling responsibilities:
 *   - Progress checkpoint persistence: ABJ_404_Solution_ViewBuildProgressOptions
 *   - Staged SQL execution: ABJ_404_Solution_ViewBuildStagedSqlExecutor
 *
 * All three classes are reached through the context's stage-service bundle.
 * stagedTableExists() and viewDoneHasRows() route their SHOW TABLES /
 * SELECT 1 queries through the data boundary.
 *
 */
class ABJ_404_Solution_ViewBuildStateProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Render a short human-readable summary of how far a resumable build has
     * progressed.  Used in the admin notice and the throw message when a
     * request can't yet serve view_done because the build is still running
     * across requests.
     *
     * @return string  e.g. "stage 2/11, 3000/12000 rows" or "not yet started".
     */
    public function describeBuildProgressForNotice(): string {
        $stage = $this->host->stageServices()->progressOptions()->readProgressOption('current_stage', 0);
        if ($stage <= 0) {
            return 'not yet started';
        }
        $parts = array('stage ' . $stage . '/11');
        if ($stage < 2) {
            // S2 is the heaviest; surface buffer/redirect counts.
            $copied = $this->host->stageServices()->batchExecutor()->countViewBuildRows();
            $total = $this->host->stageServices()->batchExecutor()->countLiveRedirects();
            if ($total > 0) {
                $parts[] = $copied . '/' . $total . ' rows';
            }
        }
        return implode(', ', $parts);
    }

    /** @return bool */
    public function viewDoneTableExists(): bool {
        return $this->stagedTableExists($this->host->stageServices()->stagePipeline()->viewDoneTableName());
    }

    /**
     * Cheap "does view_done have at least one row" probe used by
     * viewDoneIsServeable() to make the post-invalidate stale-but-present
     * decision honest. Without this, viewDoneIsServeable() might report a
     * just-promoted-but-empty buffer as serveable; the admin would render
     * an empty redirects screen indefinitely with no rebuild scheduled.
     *
     * SELECT 1 ... LIMIT 1 is the cheapest existence query MySQL can do;
     * within a request the result is memoized inside viewDoneIsServeable()
     * so the probe fires once even on hot AJAX paths.
     *
     * @return bool
     */
    public function viewDoneHasRows(): bool {
        if (!$this->viewDoneTableExists()) {
            return false;
        }
        $sql = 'SELECT 1 FROM `' . $this->host->stageServices()->stagePipeline()->viewDoneTableName() . '` LIMIT 1';
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /**
     * Option name for the floor timestamp on the data currently stored in
     * the view_done table. Distinct from viewDoneFreshnessOptionName():
     *
     *   - viewDoneFreshnessOptionName() (built_at): cleared on invalidate.
     *     "Last build that has not been invalidated." Drives the freshness
     *     TTL gate that decides whether to schedule a background rebuild.
     *
     *   - viewDoneDataBuiltAtOptionName() (data_built_at): preserved across
     *     invalidate. "Has a build ever completed?" Read by
     *     ViewDoneState::viewDoneIsServeable() to distinguish "no build has
     *     ever run" from "build completed and the dataset is legitimately
     *     empty" (fresh install with no redirects, or admin truncated the
     *     redirects table). Without this signal a cold install loops the
     *     JS poller forever: every build produces an empty view_done, the
     *     poller fires another advance, repeat.
     *
     * @return string
     */
    public function viewDoneDataBuiltAtOptionName(): string {
        return $this->host->dataBoundary()->getLowercasePrefix() . 'abj404_view_done_data_built_at';
    }

    /**
     * Unix timestamp of any successful build, preserved across
     * freshness-signal clears (admin mutation, cron-fired rebuild,
     * force-restart). Used by ViewDoneState::viewDoneIsServeable() to
     * distinguish "build never completed" from "build completed,
     * dataset legitimately empty"; without this, cold installs would
     * loop the JS poller indefinitely.
     *
     * @return int
     */
    public function viewDoneDataBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->viewDoneDataBuiltAtOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /** @param string $tableName @return bool */
    public function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        /** @var \wpdb $wpdb */
        // DAO-bypass-approved: prepare only; execution still routes through queryAndGetResults().
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        if (!is_string($sql) || $sql === '') {
            return false;
        }
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return false;
        }
        $first = $rows[0];
        $first = is_array($first) ? $first : array();
        $value = reset($first);
        $valueStr = is_scalar($value) ? (string)$value : '';
        return ($valueStr === $tableName);
    }

    /** @return bool */
    public function viewDoneIsFresh(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $built = get_option($this->host->stageServices()->viewDoneState()->viewDoneFreshnessOptionName(), 0);
        $builtAt = is_scalar($built) ? intval($built) : 0;
        if ($builtAt <= 0) {
            return false;
        }
        return (abj_clock()->now() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /**
     * Localize an admin-notice template via WordPress when loaded, else
     * return the raw English. Used by sibling collaborators that build
     * notice payloads (cron-stuck / cron-schedule-failed) without
     * reaching into DataAccess's private localizeOrDefault().
     *
     * @param string $text
     * @return string
     */
    public function localizeOrDefaultViewBuildNotice(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }
}
