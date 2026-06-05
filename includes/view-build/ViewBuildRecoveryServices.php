<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read, advance, lock, cron, and host-recovery collaborator bundle.
 */
class ABJ_404_Solution_ViewBuildRecoveryServices {

    /** @var ABJ_404_Solution_ViewBuildPageLoadFallback */
    private $pageLoadFallback;
    /** @var ABJ_404_Solution_ViewBuildForegroundLease */
    private $foregroundLease;
    /** @var ABJ_404_Solution_ViewBuildReadGateway */
    private $readGateway;
    /** @var ABJ_404_Solution_ViewBuildAdvanceCoordinator */
    private $advanceCoordinator;
    /** @var ABJ_404_Solution_ViewBuildRebuildReconcile */
    private $rebuildReconcile;
    /** @var ABJ_404_Solution_ViewBuildLockCoordinator */
    private $lockCoordinator;
    /** @var ABJ_404_Solution_ViewBuildCronScheduler */
    private $cronScheduler;
    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentProbe */
    private $hostEnvironmentProbe;
    /** @var ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe */
    private $filesystemEnvironmentProbe;
    /** @var ABJ_404_Solution_ViewBuildSessionVariablesProbe */
    private $sessionVariablesProbe;
    /** @var ABJ_404_Solution_ViewBuildHostFailureNotices */
    private $hostFailureNotices;
    /** @var ABJ_404_Solution_ViewBuildHostFailureState */
    private $hostFailureState;
    /** @var ABJ_404_Solution_ViewBuildHostFailurePolicy */
    private $hostFailurePolicy;
    /** @var ABJ_404_Solution_ViewBuildForceRestart */
    private $forceRestart;

    /**
     * @param ABJ_404_Solution_ViewBuildPageLoadFallback $pageLoadFallback
     * @param ABJ_404_Solution_ViewBuildForegroundLease $foregroundLease
     * @param ABJ_404_Solution_ViewBuildReadGateway $readGateway
     * @param ABJ_404_Solution_ViewBuildAdvanceCoordinator $advanceCoordinator
     * @param ABJ_404_Solution_ViewBuildRebuildReconcile $rebuildReconcile
     * @param ABJ_404_Solution_ViewBuildLockCoordinator $lockCoordinator
     * @param ABJ_404_Solution_ViewBuildCronScheduler $cronScheduler
     * @param ABJ_404_Solution_ViewBuildHostEnvironmentProbe $hostEnvironmentProbe
     * @param ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe $filesystemEnvironmentProbe
     * @param ABJ_404_Solution_ViewBuildSessionVariablesProbe $sessionVariablesProbe
     * @param ABJ_404_Solution_ViewBuildHostFailureNotices $hostFailureNotices
     * @param ABJ_404_Solution_ViewBuildHostFailureState $hostFailureState
     * @param ABJ_404_Solution_ViewBuildHostFailurePolicy $hostFailurePolicy
     * @param ABJ_404_Solution_ViewBuildForceRestart $forceRestart
     */
    public function __construct(
        ABJ_404_Solution_ViewBuildPageLoadFallback $pageLoadFallback,
        ABJ_404_Solution_ViewBuildForegroundLease $foregroundLease,
        ABJ_404_Solution_ViewBuildReadGateway $readGateway,
        ABJ_404_Solution_ViewBuildAdvanceCoordinator $advanceCoordinator,
        ABJ_404_Solution_ViewBuildRebuildReconcile $rebuildReconcile,
        ABJ_404_Solution_ViewBuildLockCoordinator $lockCoordinator,
        ABJ_404_Solution_ViewBuildCronScheduler $cronScheduler,
        ABJ_404_Solution_ViewBuildHostEnvironmentProbe $hostEnvironmentProbe,
        ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe $filesystemEnvironmentProbe,
        ABJ_404_Solution_ViewBuildSessionVariablesProbe $sessionVariablesProbe,
        ABJ_404_Solution_ViewBuildHostFailureNotices $hostFailureNotices,
        ABJ_404_Solution_ViewBuildHostFailureState $hostFailureState,
        ABJ_404_Solution_ViewBuildHostFailurePolicy $hostFailurePolicy,
        ABJ_404_Solution_ViewBuildForceRestart $forceRestart
    ) {
        $this->pageLoadFallback = $pageLoadFallback;
        $this->foregroundLease = $foregroundLease;
        $this->readGateway = $readGateway;
        $this->advanceCoordinator = $advanceCoordinator;
        $this->rebuildReconcile = $rebuildReconcile;
        $this->lockCoordinator = $lockCoordinator;
        $this->cronScheduler = $cronScheduler;
        $this->hostEnvironmentProbe = $hostEnvironmentProbe;
        $this->filesystemEnvironmentProbe = $filesystemEnvironmentProbe;
        $this->sessionVariablesProbe = $sessionVariablesProbe;
        $this->hostFailureNotices = $hostFailureNotices;
        $this->hostFailureState = $hostFailureState;
        $this->hostFailurePolicy = $hostFailurePolicy;
        $this->forceRestart = $forceRestart;
    }

    /** @return ABJ_404_Solution_ViewBuildPageLoadFallback */
    public function pageLoadFallback(): ABJ_404_Solution_ViewBuildPageLoadFallback { return $this->pageLoadFallback; }
    /** @return ABJ_404_Solution_ViewBuildForegroundLease */
    public function foregroundLease(): ABJ_404_Solution_ViewBuildForegroundLease { return $this->foregroundLease; }
    /** @return ABJ_404_Solution_ViewBuildReadGateway */
    public function readGateway(): ABJ_404_Solution_ViewBuildReadGateway { return $this->readGateway; }
    /** @return ABJ_404_Solution_ViewBuildAdvanceCoordinator */
    public function advanceCoordinator(): ABJ_404_Solution_ViewBuildAdvanceCoordinator { return $this->advanceCoordinator; }
    /** @return ABJ_404_Solution_ViewBuildRebuildReconcile */
    public function rebuildReconcile(): ABJ_404_Solution_ViewBuildRebuildReconcile { return $this->rebuildReconcile; }
    /** @return ABJ_404_Solution_ViewBuildLockCoordinator */
    public function lockCoordinator(): ABJ_404_Solution_ViewBuildLockCoordinator { return $this->lockCoordinator; }
    /** @return ABJ_404_Solution_ViewBuildCronScheduler */
    public function cronScheduler(): ABJ_404_Solution_ViewBuildCronScheduler { return $this->cronScheduler; }
    /** @return ABJ_404_Solution_ViewBuildHostEnvironmentProbe */
    public function hostEnvironmentProbe(): ABJ_404_Solution_ViewBuildHostEnvironmentProbe { return $this->hostEnvironmentProbe; }
    /** @return ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe */
    public function filesystemEnvironmentProbe(): ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe { return $this->filesystemEnvironmentProbe; }
    /** @return ABJ_404_Solution_ViewBuildSessionVariablesProbe */
    public function sessionVariablesProbe(): ABJ_404_Solution_ViewBuildSessionVariablesProbe { return $this->sessionVariablesProbe; }
    /** @return ABJ_404_Solution_ViewBuildHostFailureNotices */
    public function hostFailureNotices(): ABJ_404_Solution_ViewBuildHostFailureNotices { return $this->hostFailureNotices; }
    /** @return ABJ_404_Solution_ViewBuildHostFailureState */
    public function hostFailureState(): ABJ_404_Solution_ViewBuildHostFailureState { return $this->hostFailureState; }
    /** @return ABJ_404_Solution_ViewBuildHostFailurePolicy */
    public function hostFailurePolicy(): ABJ_404_Solution_ViewBuildHostFailurePolicy { return $this->hostFailurePolicy; }
    /** @return ABJ_404_Solution_ViewBuildForceRestart */
    public function forceRestart(): ABJ_404_Solution_ViewBuildForceRestart { return $this->forceRestart; }

    /** @return array<int, object> */
    public function collaboratorsForTest(): array {
        return array(
            $this->pageLoadFallback,
            $this->foregroundLease,
            $this->readGateway,
            $this->advanceCoordinator,
            $this->rebuildReconcile,
            $this->lockCoordinator,
            $this->cronScheduler,
            $this->hostEnvironmentProbe,
            $this->filesystemEnvironmentProbe,
            $this->sessionVariablesProbe,
            $this->hostFailureNotices,
            $this->hostFailureState,
            $this->hostFailurePolicy,
            $this->forceRestart,
        );
    }
}
