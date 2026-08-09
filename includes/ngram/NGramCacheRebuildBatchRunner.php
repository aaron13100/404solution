<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/NGramRebuildProgressState.php';
require_once __DIR__ . '/NGramRescheduleFailureReport.php';

/**
 * Runs the N-gram cache rebuild: one bounded chunk of batches per WP-Cron
 * tick, advancing the rebuild cursor and rescheduling the chain until the
 * whole content set is covered.
 *
 * Reached only from the cron callback
 * (ABJ_404_Solution_DatabaseUpgradeNGram::rebuildNGramCacheAsync). Deciding
 * WHETHER a rebuild needs to start or resume is a separate concern with
 * different callers -- the 404 request path, the daily reconciler, the admin
 * rebuild button, the activation initializer -- and lives in
 * {@see ABJ_404_Solution_NGramCacheRebuildScheduler}.
 *
 * Multisite-aware: a network-activated install drains one site at a time,
 * tracking pending sites and the per-site cursor through the network option
 * store, so a large network converges across ticks without ever holding more
 * than one site's batch in memory.
 *
 * Lock acquisition is owned by the orchestrator (DatabaseUpgradeNGram). This
 * collaborator assumes the 'ngram_rebuild' SyncUtils lock is already held when
 * its methods are called.
 */
class ABJ_404_Solution_NGramCacheRebuildBatchRunner {

    /**
     * WP-Cron hook this runner reschedules itself on. The canonical
     * definition lives on the cron adapter that owns hook names
     * (ABJ_404_Solution_CronScheduler::HOOK_REBUILD_NGRAM_CACHE); the literal
     * is repeated here so the Pattern 8 coordination-key audit can see the
     * key in the file that enqueues it.
     */
    const REBUILD_CRON_HOOK = 'abj404_rebuild_ngram_cache_hook';

    /**
     * Rows rebuilt per batch, and batches per cron tick.
     *
     * These are constants rather than parameters threaded down through the
     * private methods deliberately. They were previously passed as two adjacent
     * ints, which meant every call site could transpose them and silently
     * change the cron workload by a factor of 2.5 with nothing to catch it.
     * Constants make that transposition unrepresentable.
     */
    const BATCH_SIZE = 50;
    const MAX_BATCHES_PER_RUN = 20;

    /**
     * Sites pulled from the network per discovery call.
     *
     * Site discovery is paged rather than fetched all at once: a network with
     * tens of thousands of sites would otherwise load every site ID into memory
     * AND persist the whole list into a single option row on every tick.
     */
    const SITES_PER_DISCOVERY = 100;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * The one operation this runner needs from its rebuilder, bound at
     * construction once the collaborator has been validated.
     *
     * Held as a callable rather than as the whole object because rebuildCache()
     * is genuinely all this class uses, and because the rebuilder arrives
     * duck-typed: DatabaseUpgradeNGram::resolveNGramRebuilder() may hand over an
     * ABJ_404_Solution_NGramRebuilder, a legacy facade, or any object exposing
     * the method, so there is no single interface to typehint against.
     *
     * @var callable(int, int): mixed
     */
    private $rebuildCache;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramNetworkOptionStore */
    private $optionStore;

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /** @var ABJ_404_Solution_NGramRebuildProgressState */
    private $progress;

    /** @var ABJ_404_Solution_NGramRescheduleFailureReport */
    private $rescheduleFailureReport;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $rebuilder Object exposing rebuildCache().
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_NGramNetworkOptionStore $optionStore
     * @param ABJ_404_Solution_CronScheduler|null $cronScheduler
     * @throws InvalidArgumentException When $rebuilder cannot rebuild.
     */
    public function __construct($dbCore, $rebuilder, $logger, $optionStore, ?ABJ_404_Solution_CronScheduler $cronScheduler = null) {
        // Reject a rebuilder that cannot rebuild HERE, where the wiring mistake
        // actually is. Accepting anything and only checking three dispatch
        // layers down turned a container misconfiguration into a batch failure
        // that recurred on every cron tick and read like a data problem.
        if (!is_object($rebuilder) || !method_exists($rebuilder, 'rebuildCache')) {
            throw new InvalidArgumentException(
                'NGramCacheRebuildBatchRunner requires a rebuilder exposing rebuildCache(); got ' .
                (is_object($rebuilder) ? get_class($rebuilder) : gettype($rebuilder)) . '.'
            );
        }
        $this->dbCore = $dbCore;
        $this->rebuildCache = array($rebuilder, 'rebuildCache');
        $this->logger = $logger;
        $this->optionStore = $optionStore;
        $this->progress = new ABJ_404_Solution_NGramRebuildProgressState($optionStore);
        $this->cronScheduler = $cronScheduler instanceof ABJ_404_Solution_CronScheduler
            ? $cronScheduler
            : abj_cron_scheduler();
        $this->rescheduleFailureReport = new ABJ_404_Solution_NGramRescheduleFailureReport(
            $this->dbCore, $this->cronScheduler, $this->progress, $this->logger);
    }

    /**
     * Rows currently in the permalink cache -- the set a rebuild walks.
     *
     * Delegated to the repository that owns permalink-cache reads rather than
     * issuing the COUNT here: an orchestrator that also writes its own SQL is
     * two layers in one method, and this exact count already had an
     * authoritative implementation.
     *
     * @return int
     */
    private function countPermalinkCacheRows(): int {
        $repository = new ABJ_404_Solution_PermalinkCacheRepository($this->dbCore);
        return $repository->getPermalinkCacheCount();
    }

    /**
     * Reschedule the chain, reporting a refusal instead of dropping it.
     *
     * Every reschedule in this class goes through here. The single-site path
     * used to check the return value and the multisite paths did not, so a
     * WP-Cron refusal stranded a multisite rebuild silently -- the one failure
     * mode where nothing else re-arms the chain.
     *
     * @param int $delaySeconds
     * @param array<int, mixed> $args
     * @param int $offset Offset the chain would resume from, for the report.
     * @param float $progress Percent complete, for the report.
     * @return void
     */
    private function rescheduleChain(int $delaySeconds, array $args, int $offset, float $progress): void {
        if ($this->cronScheduler->scheduleSingle(self::REBUILD_CRON_HOOK, $delaySeconds, $args) === false) {
            $this->rescheduleFailureReport->report(self::REBUILD_CRON_HOOK, $offset, $progress);
        }
    }

    /**
     * Record that this tick failed, and report when failures stop being
     * transient.
     *
     * @param string $context Human-readable description of what failed.
     * @return void
     */
    private function recordBatchFailure(string $context): void {
        $failures = $this->progress->recordFailure();
        $this->logger->errorMessage(
            $context . " (consecutive failure {$failures} of "
            . ABJ_404_Solution_NGramRebuildProgressState::MAX_CONSECUTIVE_FAILURES . ')'
        );
    }

    /**
     * WP-Cron callback: process one chunk of rebuild batches and
     * reschedule for the next chunk until the entire content set is
     * covered. Multisite-aware: drains one site at a time before
     * moving on.
     *
     * @return void
     */
    public function runAsyncBatch() {
        if ($this->progress->hasExhaustedRetries()) {
            $this->logger->errorMessage(
                'N-gram cache rebuild stopped: '
                . ABJ_404_Solution_NGramRebuildProgressState::MAX_CONSECUTIVE_FAILURES
                . ' consecutive batch failures. The cache is left uninitialized so a later '
                . 'rebuild can resume it; clear '
                . ABJ_404_Solution_NGramRebuildProgressState::OPTION_CONSECUTIVE_FAILURES
                . ' to retry sooner.'
            );
            return;
        }

        if ($this->optionStore->isNetworkActivated()) {
            $this->runMultisiteBatch();
        } else {
            $this->runSingleSiteBatch();
        }
    }

    /**
     * Per-batch worker for multisite: switch to a pending site, process up to
     * MAX_BATCHES_PER_RUN batches of BATCH_SIZE, then either advance to the
     * next site (when the current site is drained) or reschedule for the next
     * chunk of the same site.
     */
    private function runMultisiteBatch(): void {
        $pendingSites = $this->progress->pendingSites();
        $neverSeeded = ($pendingSites === null);
        if ($pendingSites === null) {
            $pendingSites = [];
        }

        if ($neverSeeded) {
            // First run: seed the pending-site list. Paged rather than fetched
            // whole: 'number' => 0 asks the network for every site at once,
            // which on a large network is both an unbounded query and an
            // unbounded option row once the list is persisted below.
            $pendingSites = $this->discoverNetworkSites();
            $this->progress->setPendingSites($pendingSites);
            $this->progress->setTotalSites(count($pendingSites));
            $this->progress->setCurrentSiteOffset(0);
        }

        if (empty($pendingSites)) {
            // Every site drained. Clear the progress fields FIRST and set the
            // completion flag LAST: readers gate on the flag, so writing it
            // first leaves a window where a process death strands "initialized"
            // beside a stale pending list that disagrees with it.
            $this->progress->markNetworkComplete();
            $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
            return;
        }

        $currentSiteId = (int)$pendingSites[0];
        $offset = $this->progress->currentSiteOffset();
        $totalSites = $this->progress->totalSites(count($pendingSites));
        $completedSites = $totalSites - count($pendingSites);

        // Everything from here to the matching restore runs against another
        // site's tables. A throw anywhere in that span -- the row count, the
        // option store, the logger, the scheduler -- used to escape with the
        // switch still in effect, leaving the REST of this cron request reading
        // and writing the wrong site. finally makes the unwind unconditional.
        switch_to_blog($currentSiteId);
        try {
            $sitePages = $this->countPermalinkCacheRows();

            if ($sitePages == 0) {
                array_shift($pendingSites);
                $this->progress->setPendingSites($pendingSites);
                $this->progress->setCurrentSiteOffset(0);

                $this->logger->infoMessage(sprintf(
                    "Site %d has no pages. Moving to next site. Progress: %d/%d sites completed.",
                    $currentSiteId,
                    $completedSites + 1,
                    $totalSites
                ));

                $this->rescheduleChain(0, array(), 0, 100.0);
                return;
            }

            $this->logger->infoMessage(sprintf(
                "Processing N-gram cache for site %d (Site %d of %d): Offset %d of %d pages",
                $currentSiteId,
                $completedSites + 1,
                $totalSites,
                $offset,
                $sitePages
            ));

            $this->drainSite($currentSiteId, $offset, $sitePages, $pendingSites, $totalSites, $completedSites);
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Run this tick's batches against the site that is currently switched in.
     *
     * @param int $currentSiteId
     * @param int $offset Cursor this tick starts from.
     * @param int $sitePages Rows in this site's permalink cache.
     * @param array<int, int> $pendingSites Sites still to drain, current site first.
     * @param int $totalSites
     * @param int $completedSites
     * @return void
     */
    private function drainSite(int $currentSiteId, int $offset, int $sitePages,
            array $pendingSites, int $totalSites, int $completedSites): void {

        $batchesProcessed = 0;
        $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];
        $failed = false;

        while ($batchesProcessed < self::MAX_BATCHES_PER_RUN && $offset < $sitePages) {
            try {
                $stats = $this->runRebuildBatch($offset);
            } catch (Throwable $e) {
                // Do NOT advance the cursor. The rows in this batch were not
                // rebuilt, and an advanced cursor is never revisited -- that is
                // what silently skipped them and then let the run below declare
                // the cache complete without them.
                $this->recordBatchFailure(
                    "Error during N-gram rebuild for site {$currentSiteId} at offset {$offset}: " . $e->getMessage()
                );
                $failed = true;
                break;
            }

            $totalStats['processed'] += $stats['processed'];
            $totalStats['success'] += $stats['success'];
            $totalStats['failed'] += $stats['failed'];

            $offset += self::BATCH_SIZE;
            $batchesProcessed++;

            $this->progress->setCurrentSiteOffset($offset);

            if ($stats['processed'] < self::BATCH_SIZE) {
                break;
            }
        }

        $progress = $sitePages > 0 ? min(100, round(($offset / $sitePages) * 100, 1)) : 100;

        $this->logger->infoMessage(sprintf(
            "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $currentSiteId,
            $progress,
            $offset,
            $sitePages,
            $totalStats['success'],
            $totalStats['failed']
        ));

        // Only a clean pass may retire the site. A tick that failed leaves the
        // site pending at its failed offset so the next tick retries it.
        if (!$failed && $offset >= $sitePages) {
            array_shift($pendingSites);
            $this->progress->setPendingSites($pendingSites);
            $this->progress->setCurrentSiteOffset(0);
            $this->progress->clearFailures();

            $this->logger->infoMessage(sprintf(
                "Site %d complete! Progress: %d/%d sites completed.",
                $currentSiteId,
                $completedSites + 1,
                $totalSites
            ));
        } else if (!$failed) {
            $this->progress->clearFailures();
        }

        $this->rescheduleChain(10, array(), $offset, (float)$progress);
    }

    /**
     * Per-batch worker for single-site: process up to MAX_BATCHES_PER_RUN
     * batches against the current site, then either complete (mark
     * initialized) or reschedule for the next chunk.
     */
    private function runSingleSiteBatch(): void {
        $offset = $this->progress->singleSiteOffset();
        $totalPages = $this->countPermalinkCacheRows();

        if ($totalPages == 0) {
            $this->logger->debugMessage("No pages to process. Setting initialized flag.");
            $this->progress->markComplete();
            return;
        }

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild: Processing batch at offset %d of %d total pages",
            $offset,
            $totalPages
        ));

        $batchesProcessed = 0;
        $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];
        $failed = false;

        while ($batchesProcessed < self::MAX_BATCHES_PER_RUN && $offset < $totalPages) {
            try {
                $stats = $this->runRebuildBatch($offset);
            } catch (Throwable $e) {
                // Cursor stays put: see drainSite() for why advancing past a
                // failed batch is what made the skipped rows unrecoverable.
                $this->recordBatchFailure(
                    "Error during async N-gram cache rebuild at offset {$offset}: " . $e->getMessage()
                );
                $failed = true;
                break;
            }

            $totalStats['processed'] += $stats['processed'];
            $totalStats['success'] += $stats['success'];
            $totalStats['failed'] += $stats['failed'];

            $offset += self::BATCH_SIZE;
            $batchesProcessed++;

            $this->progress->setSingleSiteOffset($offset);

            if ($stats['processed'] < self::BATCH_SIZE) {
                break;
            }
        }

        $progress = $totalPages > 0 ? min(100, round(($offset / $totalPages) * 100, 1)) : 100;

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $progress,
            $offset,
            $totalPages,
            $totalStats['success'],
            $totalStats['failed']
        ));

        // A run that failed a batch is NOT complete, however far the cursor got
        // beforehand. Marking it initialized here is what published an empty or
        // partial cache as fully built, with nothing left to re-arm a rebuild.
        if ($failed || $offset < $totalPages) {
            $this->rescheduleChain(10, [$offset], $offset, (float)$progress);
            return;
        }

        $this->progress->markComplete();
        $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$totalStats['processed']} processed, {$totalStats['success']} success, {$totalStats['failed']} failed.");
    }

    /**
     * Every site in the network, read in bounded pages.
     *
     * @return array<int, int>
     */
    private function discoverNetworkSites(): array {
        $sites = array();
        $offset = 0;
        do {
            $page = get_sites(array(
                'fields' => 'ids',
                'number' => self::SITES_PER_DISCOVERY,
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ));
            if (!is_array($page) || empty($page)) {
                break;
            }
            foreach ($page as $siteId) {
                $sites[] = (int)$siteId;
            }
            $offset += self::SITES_PER_DISCOVERY;
        } while (count($page) === self::SITES_PER_DISCOVERY);

        return $sites;
    }


    /**
     * Rebuild one batch and return its stats.
     *
     * A rebuilder that does not report a processed count is a broken
     * collaborator, and this method now says so instead of substituting 0.
     * Substituting 0 was indistinguishable from "this batch found no more
     * rows", which is the loop's end-of-data signal -- so a rebuilder returning
     * junk read as a finished rebuild and the cache was marked complete.
     *
     * Takes only the offset: the batch size is a class constant, so there is no
     * longer a pair of adjacent ints a caller can transpose.
     *
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     * @throws RuntimeException When the rebuilder's result cannot be read.
     */
    private function runRebuildBatch(int $offset): array {
        $stats = ($this->rebuildCache)(self::BATCH_SIZE, $offset);

        if (!is_array($stats) || !isset($stats['processed']) || !is_numeric($stats['processed'])) {
            throw new RuntimeException(
                'N-gram rebuilder returned no readable processed count at offset ' . $offset .
                ' (got ' . gettype($stats) . '); refusing to read that as end-of-data.'
            );
        }

        return [
            'processed' => (int)$stats['processed'],
            'success' => isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0,
            'failed' => isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0,
        ];
    }
}
