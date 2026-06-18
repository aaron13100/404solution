<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether the wp_abj404_logs_hits aggregate-rollup table needs a
 * rebuild before the admin redirect-list view renders a hits/last-used column.
 *
 * The decision short-circuits in four cases:
 *   1. Non-essential DB writes are in cooldown.
 *   2. Hits table is missing -- defer creation to the rebuild scheduler.
 *   3. logsRepo reports no rebuild needed.
 *   4. Otherwise, ask logsRepo to schedule the deferred rebuild.
 *
 * Extracted from ViewReadService (i858 / design-audit-2026-06-04 M201) so the
 * view-read facade keeps a single responsibility: serving the staged view-read
 * pipeline. This class owns the rebuild-or-skip lifecycle.
 */
class ABJ_404_Solution_HitsTableRebuildPolicy {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->logger = $logger;
    }

    /**
     * Schedule a rebuild only if logsRepo reports the rollup is stale
     * relative to logsv2.
     *
     * @return void
     */
    public function maybeUpdateRedirectsForViewHitsTable(): void {
        if ($this->dbCore->noticeState()->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__METHOD__ . ' skipped due to temporary DB write cooldown.');
            return;
        }

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__METHOD__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->logsRepo->scheduleHitsTableRebuild();
            return;
        }

        $this->logsRepo->recordLogsHitsRollupStalenessSignal();

        if (!$this->logsRepo->hitsTableNeedsRebuild()) {
            return;
        }

        $this->logsRepo->scheduleHitsTableRebuild();
    }
}
