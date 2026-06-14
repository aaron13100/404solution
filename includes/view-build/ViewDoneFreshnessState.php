<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks whether the `view_done` snapshot is serveable and fresh, and shapes
 * the one-step progress payload the orchestrator returns to callers.
 *
 * "Serveable" means view_done exists and either has rows or has a recorded
 * data-built-at stamp. "Fresh" additionally requires the build to be within
 * its freshness TTL. This class owns the freshness option reads/writes, the
 * per-request serveability memo, and progress formatting; it performs no
 * rebuild work and acquires no locks.
 */
class ABJ_404_Solution_ViewDoneFreshnessState {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_ViewBuildTableNames */
    private $tableNames;
    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;
    /** @var bool|null */
    private $serveableCache = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewBuildTableNames $tableNames
     */
    public function __construct($dbCore, ABJ_404_Solution_ViewBuildTableNames $tableNames) {
        $this->dbCore = $dbCore;
        $this->tableNames = $tableNames;
    }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService @return void */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->viewReadService = $viewReadService;
    }

    /** @return bool */
    public function isServeable(): bool {
        if ($this->serveableCache !== null) {
            return $this->serveableCache;
        }
        if (!$this->tableNames->tableExists($this->tableNames->viewDone())) {
            $this->serveableCache = false;
            return false;
        }
        if ($this->hasRows()) {
            $this->serveableCache = true;
            return true;
        }
        $this->serveableCache = $this->dataBuiltAt() > 0;
        return $this->serveableCache;
    }

    /** @return bool */
    public function isFresh(): bool {
        $builtAt = $this->builtAtTimestamp();
        return $builtAt > 0
            && (abj_clock()->now() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->isServeable();
    }

    /** @return int */
    public function builtAtTimestamp(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $value = get_option($this->tableNames->viewDoneFreshnessOption(), 0);
        return is_scalar($value) ? max(0, intval($value)) : 0;
    }

    /** @return void */
    public function markBuildCompleted(): void {
        if (function_exists('update_option')) {
            $now = abj_clock()->now();
            update_option($this->tableNames->viewDoneFreshnessOption(), $now, false);
            update_option($this->tableNames->viewDoneDataBuiltAtOption(), $now, false);
        }
        $this->invalidateCache();
    }

    /** @return void */
    public function invalidateCache(): void {
        $this->serveableCache = null;
    }

    /** @return array<string, mixed> */
    public function getProgress(): array {
        $serveable = $this->isServeable();
        // The general build-status query reflects the staged pipeline, so it
        // reports the staged stage total (matching the other staged-pending
        // producers). The direct (non-staged) rebuild paths call formatProgress
        // directly with the default total of 1.
        return $this->formatProgress(
            $serveable ? 'ready' : 'pending',
            $serveable ? 'ready' : 'not yet started',
            ABJ_404_Solution_ViewBuildConfig::totalStages()
        );
    }

    /**
     * @param string $status
     * @param string $text
     * @param int    $of     Total number of stages this progress is measured
     *                       against. Defaults to 1 for the single-shot direct
     *                       rebuild / lock-held / ready short-circuit paths;
     *                       staged callers pass
     *                       ABJ_404_Solution_ViewBuildConfig::totalStages().
     * @return array<string, mixed>
     */
    public function formatProgress(string $status, string $text, int $of = 1): array {
        $fingerprint = array();
        if ($this->viewReadService instanceof ABJ_404_Solution_ViewReadService) {
            $fingerprint = $this->viewReadService->getViewBuildProgressFingerprint();
        }
        return array(
            'status' => $status,
            'stage' => $status === 'ready' ? 1 : 0,
            'of' => $of,
            'build_started' => 0,
            'progress_text' => $text,
            'fingerprint' => $fingerprint,
        );
    }

    /** @return bool */
    private function hasRows(): bool {
        $result = $this->dbCore->queryAndGetResults('SELECT 1 FROM `' . $this->tableNames->viewDone() . '` LIMIT 1',
            array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /** @return int */
    private function dataBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $value = get_option($this->tableNames->viewDoneDataBuiltAtOption(), 0);
        return is_scalar($value) ? max(0, intval($value)) : 0;
    }
}
