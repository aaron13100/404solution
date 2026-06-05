<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background-rebuild scheduler entry + crashed-build reconciliation.
 *
 * Sibling of ABJ_404_Solution_ViewBuildStagePipeline. Owns three responsibilities
 * that previously bloated the staged build collaborator past the line ceiling:
 *
 *   1. {@see sweepStaleRebuildTransients()} - reclaim expired
 *      `abj404_inflight_*` transient rows and orphaned temp/preagg tables
 *      before the next rebuild attempt.
 *
 *   2. {@see rebuildViewDoneInBackground()} - the WP-Cron entry point. Takes
 *      the build lock, runs reconciliation, then drives runStagedBuildOnce()
 *      on the stage pipeline. Reschedules itself on yield and records
 *      health success/failure.
 *
 *   3. {@see reconcileStagedTablesAtRunnerStartup()} (+
 *      {@see bufferIntegrityPassesForPromote()}) - clean up table state from
 *      a prior crashed/halted run before this run's stages execute. Three
 *      cases: orphan deleteme, orphan view_build that can be promoted in
 *      place, both tables present.
 *
 * Behavior is unchanged from the original staged-build collaborator.
 * Cross-collaborator calls (e.g. `$this->host->stagePipeline()->runStagedBuildOnce()`,
 * `$this->host->lockCoordinator()->acquireViewBuildLock()`) flow through the orchestrator's reflection
 * routed __call exactly as before.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
 */
class ABJ_404_Solution_ViewBuildRebuildReconcile extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Sweep stale rebuild-related transients and orphaned temp tables.
     *
     * Runs cheap, conservative cleanup of plugin-owned ephemeral state:
     *   - Expired `abj404_inflight_*` transient rows (older than their
     *     timeout column).
     *   - Expired `abj404_view_cache_cleanup_marker` row when the
     *     underlying option lingers in some object caches.
     *   - Orphaned `wp_abj404_logs_hits_preagg` and `..._temp` tables.
     *
     * Called at the top of rebuildViewDoneInBackground() and
     * createRedirectsForViewHitsTable() to reclaim disk space and
     * prevent stale markers from interfering with the next rebuild
     * attempt. Targets only plugin-owned transient keys with known
     * prefixes.
     *
     * @return void
     */
    public function sweepStaleRebuildTransients(): void {
        global $wpdb;
        if (!isset($wpdb) || !function_exists('get_option')) {
            return;
        }

        // Sweep expired abj404_inflight_* transients (older than 5 minutes).
        $now = time();
        $prefix = $this->host->getLowercasePrefix();
        $inflightLike = '_transient_timeout_abj404_inflight_%';
        $timeoutRows = $wpdb->get_results(
            // DAO-bypass-approved: Rebuild cleanup must scan WordPress transient timeout rows directly.
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} "
                . "WHERE option_name LIKE %s",
                $inflightLike
            ),
            ARRAY_A
        );
        if (is_array($timeoutRows)) {
            foreach ($timeoutRows as $row) {
                $optName = is_array($row) ? ($row['option_name'] ?? '') : '';
                $optVal = is_array($row) ? ($row['option_value'] ?? '') : '';
                if (!is_string($optName) || $optName === '') {
                    continue;
                }
                $timeout = is_numeric($optVal) ? (int)$optVal : 0;
                if ($timeout > 0 && $timeout < $now) {
                    // Expired inflight transient. Delete both the timeout and the value.
                    $transientName = str_replace('_transient_timeout_', '', $optName);
                    if (function_exists('delete_transient')) {
                        delete_transient($transientName);
                    }
                }
            }
        }

        // Clear expired view_cache_cleanup_marker if present.
        if (function_exists('get_transient') && function_exists('delete_transient')) {
            $marker = get_transient('abj404_view_cache_cleanup_marker');
            // get_transient returns false when expired; the marker is only
            // useful while live, so no further action needed. But if the
            // underlying option row lingers (some object caches), explicitly
            // delete to reclaim the row.
            if ($marker === false && function_exists('delete_option')) {
                delete_option('_transient_abj404_view_cache_cleanup_marker');
                delete_option('_transient_timeout_abj404_view_cache_cleanup_marker');
            }
        }

        // Drop orphaned temp/preagg tables to reclaim disk before the
        // next rebuild attempt.
        $logsHitsTable = $this->host->doTableNameReplacements('{wp_abj404_logs_hits}');
        $preaggTable = $logsHitsTable . '_preagg';
        $tempTable = $logsHitsTable . '_temp';
        $this->host->queryAndGetResults("DROP TABLE IF EXISTS `" . esc_sql($preaggTable) . "`",
            array('log_errors' => false));
        $this->host->queryAndGetResults("DROP TABLE IF EXISTS `" . esc_sql($tempTable) . "`",
            array('log_errors' => false));
    }

    /**
     * Hook target for `wp_schedule_single_event('abj404_rebuildViewDone')`.
     * Rebuilds inline (under the build lock) so the next admin request
     * sees fresh data. Called from PluginLogic during cron registration.
     *
     * @return void
     */
    public function rebuildViewDoneInBackground(): void {
        $this->sweepStaleRebuildTransients();
        if ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->host->rebuildHealth()->beginExpensiveRebuildAttempt()) {
            $this->host->logger()->debugMessage(
                '[staged] rebuildViewDoneInBackground: skipped because rebuild health gate is closed.'
            );
            return;
        }
        if ($this->host->foregroundLease()->foregroundViewBuildLeaseActive()) {
            $this->host->logger()->debugMessage(
                '[staged] rebuildViewDoneInBackground: deferring; '
                . 'foreground build lease active. Rescheduled.'
            );
            $this->host->cronScheduler()->scheduleViewDoneRebuild(ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS);
            return;
        }
        if (!$this->host->lockCoordinator()->acquireViewBuildLock()) {
            $this->host->logger()->debugMessage(
                '[staged] rebuildViewDoneInBackground: lock not acquired '
                . '(another worker is building); skipping this cron tick.'
            );
            return;
        }
        try {
            // Runner-startup reconciliation: clean up inconsistent staged-
            // table state from a previous run that crashed mid-S11 or was
            // OOM-killed before the swap option write. Runs BEFORE
            // runStagedBuildOnce() so its halt-gate / once-guard short-
            // circuits cannot suppress the cleanup, and it can short-
            // circuit the rebuild itself when it manages to recover the
            // previous run's buffer in place.
            $reconcileResult = $this->reconcileStagedTablesAtRunnerStartup();
            if ($reconcileResult === 'promoted') {
                // The previous run's view_build was renamed to view_done
                // in place; freshness is recorded; view_done is now
                // serveable. No need to re-run the staged build this tick.
                return;
            }
            $isComplete = $this->host->stagePipeline()->runStagedBuildOnce();
            if (!$isComplete) {
                // Build yielded mid-stage; schedule another tick to continue.
                $this->host->cronScheduler()->scheduleViewDoneRebuild();
            } elseif ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->host->rebuildHealth()->recordSuccess();
            }
        } catch (Throwable $e) {
            // Log at warning level, not error: a failed background rebuild
            // leaves the plugin functional (the prior view_done snapshot,
            // if any, is still served).  Per CLAUDE.md self-healing rules,
            // infrastructure failures should not generate dev email reports.
            $this->host->logger()->warn('[staged] background rebuild yielded an error: ' . $e->getMessage());
            if ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->host->rebuildHealth()->recordFailure($e->getMessage(), $this->host->rebuildHealth()->classifyError($e->getMessage()));
            }
        } finally {
            $this->host->lockCoordinator()->releaseViewBuildLock();
        }
    }

    /**
     * Reconcile staged-build table state from a previous run that ended
     * in an inconsistent place, before this run's stages execute. Called
     * from rebuildViewDoneInBackground() AFTER the build lock is
     * acquired (so we cannot race a sibling worker on the same site)
     * and BEFORE runStagedBuildOnce() (so the staged orchestrator sees
     * a clean starting state regardless of which entry path took the
     * lock).
     *
     * Cases handled:
     *
     *   1. Orphan `{wp_abj404_view_deleteme}` from a prior crashed S11
     *      swap (or a critical-stage halt that left the previous run's
     *      deleteme on disk). Drop it. Always safe; deleteme is a
     *      transient by design.
     *
     *   2. `{wp_abj404_view_build}` exists, `{wp_abj404_view_done}` does
     *      NOT, AND no resumable build progress is recorded: the
     *      previous run completed S2-S10 but crashed before the S11
     *      RENAME swap published the buffer. Promote the buffer in
     *      place via `RENAME TABLE view_build TO view_done`, mark
     *      fresh, clear progress. Preserves the work of S2-S10 instead
     *      of throwing it away.
     *
     *   3. Both `{wp_abj404_view_build}` and `{wp_abj404_view_done}`
     *      exist, AND no resumable build progress is recorded: the
     *      previous run halted between stages with both tables on
     *      disk. Treat view_done as the live one and drop view_build
     *      so the next fresh build starts from a known empty buffer.
     *
     * Resumable progress = `started_at` within
     * VIEW_BUILD_RESUME_TTL_SECONDS and either a completed stage
     * (`current_stage` > 0) or a durable started-stage marker. When a
     * resumable build is in flight we leave view_build alone so the
     * next tick can continue from the persisted high-water id (cases
     * 2 and 3 are skipped; case 1 still runs).
     *
     * Reconciliation actions are best-effort: when DROP / RENAME is
     * denied by the host (privilege loss between runs), surface a
     * deduplicated admin notice naming the specific tables and
     * recommending manual cleanup. The build then falls through to
     * runStagedBuildOnce() which will hit its own host-failure
     * classifier.
     *
     * @return string  One of:
     *                 'none'     - no reconciliation needed.
     *                 'cleaned'  - orphan tables dropped; build can proceed.
     *                 'promoted' - view_build was renamed to view_done;
     *                              view_done is fresh; rebuild can be
     *                              skipped this tick.
     *                 'failed'   - reconciliation could not complete
     *                              (privilege denied); admin notice set.
     */
    public function reconcileStagedTablesAtRunnerStartup(): string {
        $tempDeletemeTable = $this->host->stagePipeline()->viewDeletemeTableName();
        $tempBuildTable    = $this->host->stagePipeline()->viewBuildTableName();
        $doneTable         = $this->host->stagePipeline()->viewDoneTableName();

        $action = 'none';
        $haveDeleteme = $this->host->stateProbe()->stagedTableExists($tempDeletemeTable);

        if ($haveDeleteme) {
            $r = $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $tempDeletemeTable . '`',
                array('log_errors' => false)
            );
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' || !$this->host->stateProbe()->stagedTableExists($tempDeletemeTable)) {
                $this->host->logger()->infoMessage(sprintf(
                    '[staged] reconcile: dropped orphan view_deleteme `%s` from a previous failed run',
                    $tempDeletemeTable
                ));
                $action = 'cleaned';
            } else {
                $this->host->logger()->warn(sprintf(
                    '[staged] reconcile: orphan view_deleteme `%s` could not be dropped: %s',
                    $tempDeletemeTable, substr($err, 0, 200)
                ));
                $this->host->hostFailureNotices()->setStagedBuildHaltNotice('orphan_deleteme', sprintf(
                    'An orphan staged-build buffer `%s` from a previous failed run could not be removed (privilege denied?): %s. Manual cleanup: drop the buffer table `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                    $tempDeletemeTable, substr($err, 0, 200), $tempDeletemeTable
                ));
                $action = 'failed';
            }
        }

        // Resumable build in flight? Leave $tempBuildTable / view_done alone
        // so the next tick can continue from the persisted high-water
        // id; orphan deleteme cleanup above already ran and is enough.
        $startedAt = $this->host->progressOptions()->readProgressOption('started_at', 0);
        $currentStage = $this->host->progressOptions()->readProgressOption('current_stage', 0);
        $lastStartedStage = $this->host->progressOptions()->readProgressOption('last_started_stage', 0);
        $resumeWindowOk = $startedAt > 0
            && (time() - $startedAt) <= ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_RESUME_TTL_SECONDS;
        if ($resumeWindowOk && ($currentStage > 0 || $lastStartedStage > 0)) {
            return $action;
        }

        $haveBuild = $this->host->stateProbe()->stagedTableExists($tempBuildTable);
        $haveDone  = $this->host->stateProbe()->stagedTableExists($doneTable);

        // Case 2: view_build exists, view_done missing. Promote the
        // buffer in place rather than re-running S1-S11 from scratch
        // -- but only when an integrity probe says the buffer is
        // plausibly complete. Without the integrity check we could
        // publish a partially-built buffer (S2 stopped halfway, or a
        // force-rebuild cleared progress while a redirect edit had
        // also added rows we never picked up).
        if ($haveBuild && !$haveDone) {
            if (!$this->bufferIntegrityPassesForPromote($tempBuildTable)) {
                $this->host->logger()->infoMessage(sprintf(
                    '[staged] reconcile: not promoting view_build `%s` (integrity probe failed); dropping for fresh rebuild',
                    $tempBuildTable
                ));
                $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $tempBuildTable . '`',
                    array('log_errors' => false));
                return 'cleaned';
            }
            $sql = 'RENAME TABLE `' . $tempBuildTable . '` TO `' . $doneTable . '`';
            $r = $this->host->queryAndGetResults($sql, array('log_errors' => true));
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' && $this->host->stateProbe()->stagedTableExists($doneTable)) {
                $this->host->logger()->infoMessage(sprintf(
                    '[staged] reconcile: promoted view_build to view_done '
                    . '(`%s` -> `%s`); previous run crashed before S11 swap',
                    $tempBuildTable, $doneTable
                ));
                // Same as the S11 swap completion: update both freshness
                // signals, clear hard-stale notice, reset serveability cache.
                $this->host->viewDoneState()->markViewDoneBuildCompleted();
                $this->host->progressOptions()->clearAllProgressOptions();
                return 'promoted';
            }
            $this->host->logger()->warn(sprintf(
                '[staged] reconcile: could not promote view_build to view_done: %s',
                substr($err, 0, 200)
            ));
            $this->host->hostFailureNotices()->setStagedBuildHaltNotice('promote_build_failed', sprintf(
                'A staged-build buffer `%s` exists from a previous run but could not be promoted to `%s` (privilege denied?): %s. Manual cleanup: rename the buffer `%s` to `%s`, or remove the buffer `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                $tempBuildTable, $doneTable, substr($err, 0, 200),
                $tempBuildTable, $doneTable, $tempBuildTable
            ));
            return 'failed';
        }

        // Case 3: both tables exist. view_done is the live one; the
        // orphan $tempBuildTable is from a halted previous run. Drop it so
        // the next fresh build starts from a known empty buffer.
        if ($haveBuild && $haveDone) {
            $r = $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $tempBuildTable . '`',
                array('log_errors' => false)
            );
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' || !$this->host->stateProbe()->stagedTableExists($tempBuildTable)) {
                // WARN (not INFO) so this signal survives a site with DEBUG
                // disabled. Carries the four progress fields support needs
                // to distinguish "build keeps restarting at S1" from
                // "build invalidated on every redirect edit / cron tick"
                // from "multi-tab/cron lock contention orphaning each
                // partial build" without asking for another debug zip.
                $lastCompletedStage = $this->host->progressOptions()->readProgressOption('last_completed_stage', 0);
                $age = $startedAt > 0 ? max(0, time() - $startedAt) : 0;
                $this->host->logger()->warn(sprintf(
                    '[staged] reconcile: dropped orphan view_build `%s` (view_done is live; previous run halted before swap); '
                    . 'current_stage=%d last_started_stage=%d last_completed_stage=%d started_at=%d age=%ds',
                    $tempBuildTable,
                    $currentStage,
                    $lastStartedStage,
                    $lastCompletedStage,
                    $startedAt,
                    $age
                ));
                return 'cleaned';
            }
            $this->host->logger()->warn(sprintf(
                '[staged] reconcile: orphan view_build `%s` could not be dropped: %s',
                $tempBuildTable, substr($err, 0, 200)
            ));
            $this->host->hostFailureNotices()->setStagedBuildHaltNotice('orphan_build', sprintf(
                'A staged-build buffer `%s` from a previous run still exists alongside the live view_done, but could not be removed: %s. Manual cleanup: drop the buffer `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                $tempBuildTable, substr($err, 0, 200), $tempBuildTable
            ));
            return 'failed';
        }

        return $action;
    }

    /**
     * Reconcile post-S11 state when a RENAME swap raised an error after the
     * rename committed but the client lost the OK packet.
     *
     * @return bool True when the committed swap was recovered as success.
     */
    public function reconcilePostStageElevenState(): bool {
        $viewDoneTable  = $this->host->stagePipeline()->viewDoneTableName();
        $viewBuildTable = $this->host->stagePipeline()->viewBuildTableName();

        if (!$this->host->stateProbe()->stagedTableExists($viewDoneTable)) {
            return false;
        }
        if ($this->host->stateProbe()->stagedTableExists($viewBuildTable)) {
            return false;
        }
        // RENAME swap committed: view_done exists, view_build was renamed
        // away. Treat as success even though the request flow saw an error.
        if (function_exists('update_option')) {
            update_option($this->host->viewDoneState()->viewDoneFreshnessOptionName(), $this->host->clock()->now(), false);
        }
        $this->host->progressOptions()->clearAllProgressOptions();
        $this->host->viewDoneState()->invalidateViewDoneServeableCache();
        return true;
    }

    /**
     * Integrity probe used by the case-2 promote branch of
     * reconcileStagedTablesAtRunnerStartup(). Returns true only when the
     * buffer is plausibly complete: row count matches the live redirects
     * table within a small tolerance (one redirect could have been
     * added during the build window). Returns false on any probe error
     * so a transient DB hiccup never publishes a buffer of unknown
     * shape as the live snapshot.
     *
     * Row-count parity is a coarse check (it cannot detect stale POST
     * resolutions when wp_posts has changed mid-flight). Promote is
     * already an opportunistic recovery; if we're wrong, the next
     * invalidate-driven rebuild will replace view_done.
     *
     * @param string $bufferTable
     * @return bool
     */
    public function bufferIntegrityPassesForPromote(string $bufferTable): bool {
        $bufferRows = $this->host->batchExecutor()->countViewBuildRows();
        if ($bufferRows <= 0) {
            return false;
        }
        $liveRows = $this->host->batchExecutor()->countLiveRedirects();
        if ($liveRows <= 0) {
            // No redirects in the live table -- treat any buffer as
            // unsafe to publish (a bug pruned all redirects, or the
            // count probe itself errored).
            return false;
        }
        // Allow the buffer to differ from live by up to one row in
        // either direction so an admin who created or deleted a single
        // redirect during the build window does not block promotion.
        $diff = abs($bufferRows - $liveRows);
        return $diff <= 1;
    }
}
