<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side gateway for the staged redirects admin view.
 *
 * Owns the stale-while-revalidate decision for page and count reads. It never
 * runs the staged build inline; unavailable snapshots are surfaced as pending
 * so AJAX/REST callers can retry through the advance endpoint.
 *
 */
class ABJ_404_Solution_ViewBuildReadGateway extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewBuildPendingException
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        $this->setReadQueryTimeout($tableOptions);
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            $this->host->recoveryServices()->forceRestart()->forceRestartViewBuild(0);
        }
        $builtAt = $this->host->stageServices()->viewDoneState()->viewDoneBuiltAt();
        $isFresh = $builtAt > 0
            && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->host->stageServices()->viewDoneState()->viewDoneIsServeable();

        if ($isFresh) {
            return $this->host->dataBoundary()->readFromViewDone($sub, $tableOptions);
        }

        if ($this->host->stageServices()->viewDoneState()->viewDoneIsServeable()) {
            $this->host->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild();
            return $this->host->dataBoundary()->readFromViewDone($sub, $tableOptions);
        }

        $this->host->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild();
        $progress = $this->host->stageServices()->stateProbe()->describeBuildProgressForNotice();
        throw new ABJ_404_Solution_ViewBuildPendingException(
            'Staged view build pending; background rebuild scheduled. Progress: ' . $progress,
            $progress
        );
    }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array {
        $stage = $this->host->stageServices()->progressOptions()->readProgressOption('current_stage', 0);
        $startedAt = $this->host->stageServices()->progressOptions()->readProgressOption('started_at', 0);
        $status = $this->host->stageServices()->viewDoneState()->viewDoneIsServeable() ? 'ready' : 'pending';
        return array(
            'status' => $status,
            'stage' => max(0, $stage),
            'of' => 11,
            'build_started' => max(0, $startedAt),
            'progress_text' => $stage > 0 ? ('stage ' . $stage . '/11') : 'not yet started',
            'fingerprint' => $this->host->dataBoundary()->getViewBuildProgressFingerprint(),
        );
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     * @throws ABJ_404_Solution_ViewBuildPendingException
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        $this->setReadQueryTimeout($tableOptions);
        $builtAt = $this->host->stageServices()->viewDoneState()->viewDoneBuiltAt();
        $isFresh = $builtAt > 0
            && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->host->stageServices()->viewDoneState()->viewDoneIsServeable();

        if (!$this->host->stageServices()->viewDoneState()->viewDoneIsServeable()) {
            $this->host->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild();
            $progress = $this->host->stageServices()->stateProbe()->describeBuildProgressForNotice();
            throw new ABJ_404_Solution_ViewBuildPendingException(
                'Staged view-count build pending; background rebuild scheduled. Progress: ' . $progress,
                $progress
            );
        }

        if (!$isFresh) {
            $this->host->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild();
        }

        $sql = $this->host->dataBoundary()->buildViewDoneCountQuery($sub, $tableOptions);
        $result = $this->host->dataBoundary()->queryAndGetResults($sql, $this->host->stageServices()->stagedSqlExecutor()->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $row = is_array($rows[0]) ? $rows[0] : array();
        $raw = $row['cnt'] ?? reset($row);
        return is_scalar($raw) ? intval($raw) : 0;
    }

    /** @param array<string, mixed> $tableOptions @return void */
    private function setReadQueryTimeout(array $tableOptions): void {
        $this->host->stageServices()->stagedSqlExecutor()->setStagedQueryTimeoutSeconds(isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0);
    }
}
