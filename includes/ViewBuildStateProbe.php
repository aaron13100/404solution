<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only probes against the view-build pipeline's persistent state:
 * table existence (view_done, view_build, view_deleteme, arbitrary staged
 * names), view_done freshness gate (built_at TTL), data-built-at timestamp
 * preserved across invalidations, hard-stale notice surfacing, and the
 * human-readable progress description used in admin notices.
 *
 * Sibling responsibilities:
 *   - Progress checkpoint persistence: ABJ_404_Solution_ViewBuildProgressOptions
 *   - Staged SQL execution: ABJ_404_Solution_ViewBuildStagedSqlExecutor
 *
 * All three classes are registered as ViewBuildOrchestrator collaborators.
 * stagedTableExists() and viewDoneHasRows() route their SHOW TABLES /
 * SELECT 1 queries through queryAndGetResults() on the orchestrator host.
 *
 * @method array<mixed> queryAndGetResults(...$arguments)
 * @method string getLowercasePrefix(...$arguments)
 * @method int readProgressOption(...$arguments)
 * @method int countViewBuildRows(...$arguments)
 * @method int countLiveRedirects(...$arguments)
 * @method string viewDoneTableName(...$arguments)
 * @method string viewDoneFreshnessOptionName(...$arguments)
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
        $stage = $this->host->readProgressOption('current_stage', 0);
        if ($stage <= 0) {
            return 'not yet started';
        }
        $parts = array('stage ' . $stage . '/11');
        if ($stage < 2) {
            // S2 is the heaviest; surface buffer/redirect counts.
            $copied = $this->host->countViewBuildRows();
            $total = $this->host->countLiveRedirects();
            if ($total > 0) {
                $parts[] = $copied . '/' . $total . ' rows';
            }
        }
        return implode(', ', $parts);
    }

    /** @return bool */
    public function viewDoneTableExists(): bool {
        return $this->host->stagedTableExists($this->host->viewDoneTableName());
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
        if (!$this->host->viewDoneTableExists()) {
            return false;
        }
        $sql = 'SELECT 1 FROM `' . $this->host->viewDoneTableName() . '` LIMIT 1';
        $result = $this->host->queryAndGetResults($sql, array('log_errors' => false));
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
     *     invalidate. "When was the snapshot currently on disk produced."
     *     Drives the hard-stale notice and lets us answer "how old is the
     *     data the admin is looking at" honestly even after invalidation.
     *
     * @return string
     */
    public function viewDoneDataBuiltAtOptionName(): string {
        return $this->host->getLowercasePrefix() . 'abj404_view_done_data_built_at';
    }

    /**
     * Unix timestamp when the data currently in the view_done table was
     * produced. Survives every freshness-signal clear (admin mutation,
     * cron-fired rebuild, force-restart) so the read path can compute an
     * honest "data on disk is N hours old" age regardless of whether the
     * built_at marker has been reset.
     *
     * @return int
     */
    public function viewDoneDataBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->host->viewDoneDataBuiltAtOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /**
     * Set a deduplicated admin notice when the data in view_done is older
     * than VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS. Surfaced on the plugin's
     * own admin screen by abj404_show_view_build_cron_notices in
     * 404-solution.php; never sent via email or shown wp-admin-wide.
     *
     * Same 24h dedup TTL as the other view-build notices so the three
     * notice families share a consistent lifecycle.
     *
     * @param int $ageSeconds Current age of data on disk.
     * @return void
     */
    public function setViewDoneHardStaleNotice(int $ageSeconds): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_done_hard_stale';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return; // dedup window still active
        }
        $hours = max(1, intval(floor($ageSeconds / 3600)));
        $template = $this->host->localizeOrDefaultViewBuildNotice(
            'The 404 Solution redirects table data is more than %d hours old. '
            . 'A background rebuild is scheduled but has not completed; the '
            . 'redirects screen is showing the most recent successful snapshot. '
            . 'Check WordPress cron health and the staged-build progress.'
        );
        $payload = array(
            'type'         => 'view_done_hard_stale',
            'message'      => sprintf($template, $hours),
            'timestamp'    => time(),
            'error_string' => '',
            'age_hours'    => $hours,
        );
        // allow-cache-empty: notice payload is intentional; error_string is empty by definition for stale-data state.
        set_transient($key, $payload, ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS);
    }

    /**
     * Self-heal: clear the hard-stale notice when a successful build
     * completes and the data on disk is no longer stale. Called from
     * markViewDoneBuildCompleted() so the notice does not linger for the
     * full 24h dedup TTL after the build catches up.
     *
     * @return void
     */
    public function clearViewDoneHardStaleNotice(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_view_done_hard_stale');
        }
    }

    /**
     * Read-path hook: when serving stale data from view_done, surface the
     * hard-stale notice if the data is older than the configured threshold.
     *
     * No-ops when data_built_at is missing (legacy installs that pre-date
     * the data-built-at signal) so a one-time migration does not generate
     * spurious 24h notices on the first read after upgrade. The next
     * successful build sets the signal and from then on the staleness
     * check is honest.
     *
     * @return void
     */
    public function maybeRaiseViewDoneHardStaleNotice(): void {
        $built = $this->host->viewDoneDataBuiltAt();
        if ($built <= 0) {
            return;
        }
        $age = time() - $built;
        if ($age >= ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS) {
            $this->host->setViewDoneHardStaleNotice($age);
        }
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
        $result = $this->host->queryAndGetResults($sql, array('log_errors' => false));
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
        $built = get_option($this->host->viewDoneFreshnessOptionName(), 0);
        $builtAt = is_scalar($built) ? intval($built) : 0;
        if ($builtAt <= 0) {
            return false;
        }
        return (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /**
     * Tiny helper so the staged-build notices read the same way as the
     * existing setPluginDbNotice() copy: call __() when WordPress is loaded,
     * otherwise return the raw English. Kept local to the state-probe
     * collaborator (rather than DataAccess.php's private localizeOrDefault())
     * so the sibling lock-and-cron collaborator can reach it via $this-> on
     * the orchestrator host without exposing the private DataAccess method.
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
