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
            $this->progress->useNetworkCursor();
            $this->runMultisiteBatch();
            return;
        }
        $this->progress->useSingleSiteCursor();
        $this->runSingleSiteBatch();
    }

    /**
     * Per-batch worker for multisite: walk to the site this tick owns, drain up
     * to MAX_BATCHES_PER_RUN batches of it, then either retire it or come back
     * to it on the next tick.
     */
    private function runMultisiteBatch(): void {
        if (!$this->progress->networkWalkStarted()) {
            $this->progress->beginNetworkWalk($this->countNetworkSites());
        }

        $totalSites = $this->progress->totalSites(0);
        $completedSites = $this->progress->sitesCompleted();

        if ($totalSites <= 0 || $completedSites >= $totalSites) {
            $this->progress->markNetworkComplete();
            $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
            return;
        }

        $currentSiteId = $this->siteAtWalkOffset($completedSites);
        if ($currentSiteId === null) {
            // The walk says a site should be here and the network says otherwise.
            // Sites were deleted mid-walk, or the query failed. Either way the
            // network has NOT been fully drained, so completion must not be
            // recorded; re-seed and let the next tick walk the real list.
            $this->recordBatchFailure(sprintf(
                'N-gram rebuild could not resolve network site %d of %d; re-seeding the walk.',
                $completedSites + 1,
                $totalSites
            ));
            $this->progress->beginNetworkWalk($this->countNetworkSites());
            $this->rescheduleChain(10, array(), 0, 0.0);
            return;
        }

        // Everything from here to the matching restore runs against another
        // site's tables. A throw anywhere in that span -- the row count, the
        // option store, the logger, the scheduler -- used to escape with the
        // switch still in effect, leaving the REST of this cron request reading
        // and writing the wrong site. finally makes the unwind unconditional.
        switch_to_blog($currentSiteId);
        try {
            $sitePages = $this->countPermalinkCacheRows();

            if ($sitePages == 0) {
                $this->progress->advanceToNextSite();
                $this->logger->infoMessage(sprintf(
                    "Site %d has no pages. Moving to next site. Progress: %d/%d sites completed.",
                    $currentSiteId, $completedSites + 1, $totalSites
                ));
                $this->rescheduleChain(0, array(), 0, 100.0);
                return;
            }

            $this->logger->infoMessage(sprintf(
                "Processing N-gram cache for site %d (Site %d of %d): Offset %d of %d pages",
                $currentSiteId, $completedSites + 1, $totalSites,
                $this->progress->cursor(), $sitePages
            ));

            $outcome = $this->drainBatches($sitePages, "site {$currentSiteId}");

            $this->logger->infoMessage(sprintf(
                "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
                $currentSiteId, $outcome['percent'], $outcome['offset'], $sitePages,
                $outcome['success'], $outcome['rowsFailed']
            ));

            if ($this->drainIsClean($outcome, $sitePages)) {
                $this->progress->advanceToNextSite();
                $this->progress->clearFailures();
                $this->logger->infoMessage(sprintf(
                    "Site %d complete! Progress: %d/%d sites completed.",
                    $currentSiteId, $completedSites + 1, $totalSites
                ));
            } else if (!$outcome['threw'] && $outcome['rowsFailed'] === 0) {
                $this->progress->clearFailures();
            }

            $this->rescheduleChain(10, array(), $outcome['offset'], $outcome['percent']);
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Per-batch worker for single-site: drain up to MAX_BATCHES_PER_RUN batches,
     * then either complete (mark initialized) or reschedule for the next chunk.
     */
    private function runSingleSiteBatch(): void {
        $totalPages = $this->countPermalinkCacheRows();

        if ($totalPages == 0) {
            $this->logger->debugMessage("No pages to process. Setting initialized flag.");
            $this->progress->markComplete();
            return;
        }

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild: Processing batch at offset %d of %d total pages",
            $this->progress->cursor(), $totalPages
        ));

        $outcome = $this->drainBatches($totalPages, 'this site');

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $outcome['percent'], $outcome['offset'], $totalPages,
            $outcome['success'], $outcome['rowsFailed']
        ));

        // A run that failed is NOT complete, however far the cursor got
        // beforehand. Marking it initialized here is what published an empty or
        // partial cache as fully built, with nothing left to re-arm a rebuild.
        if (!$this->drainIsClean($outcome, $totalPages)) {
            $this->rescheduleChain(10, [$outcome['offset']], $outcome['offset'], $outcome['percent']);
            return;
        }

        $this->progress->markComplete();
        $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$outcome['processed']} processed, "
            . "{$outcome['success']} success, {$outcome['rowsFailed']} failed.");
    }

    /**
     * Drain this tick's batches against whatever is currently switched in,
     * advancing the active cursor as each batch lands.
     *
     * ONE loop serves both the network and single-site paths. They were
     * separate copies of the same algorithm, and the copies had already drifted
     * apart in production: the single-site path checked whether its reschedule
     * was accepted and the multisite path did not. A second copy of a loop that
     * owns a cursor invariant is a second place for that invariant to rot.
     *
     * @param int $totalRows Rows in the set being rebuilt.
     * @param string $context Human-readable subject, for failure messages.
     * @return array{offset:int, threw:bool, processed:int, success:int, rowsFailed:int, percent:float}
     */
    private function drainBatches(int $totalRows, string $context): array {
        $offset = $this->progress->cursor();
        $batchesProcessed = 0;
        $processed = 0;
        $success = 0;
        $rowsFailed = 0;
        $threw = false;

        while ($batchesProcessed < self::MAX_BATCHES_PER_RUN && $offset < $totalRows) {
            try {
                $stats = $this->runRebuildBatch($offset);
            } catch (Throwable $e) {
                // Do NOT advance the cursor. The rows in this batch were not
                // rebuilt, and an advanced cursor is never revisited -- that is
                // what silently skipped them and then let the caller declare the
                // cache complete without them.
                $this->recordBatchFailure(
                    "Error during N-gram rebuild for {$context} at offset {$offset}: " . $e->getMessage()
                );
                $threw = true;
                break;
            }

            $processed += $stats['processed'];
            $success += $stats['success'];
            $rowsFailed += $stats['failed'];

            $offset += self::BATCH_SIZE;
            $batchesProcessed++;
            $this->progress->setCursor($offset);

            if ($stats['processed'] < self::BATCH_SIZE) {
                break;
            }
        }

        return array(
            'offset' => $offset,
            'threw' => $threw,
            'processed' => $processed,
            'success' => $success,
            'rowsFailed' => $rowsFailed,
            'percent' => $totalRows > 0
                ? (float)min(100, round(($offset / $totalRows) * 100, 1)) : 100.0,
        );
    }

    /**
     * Whether a drain covered its whole set with nothing left behind.
     *
     * Reaching the end of the set is not sufficient: a batch that REPORTED
     * failed rows advanced the cursor past them, so a run can arrive at the end
     * having skipped rows. Retiring on offset alone is how a partial cache gets
     * published as complete, which is the same defect as advancing past a
     * thrown batch, one level down.
     *
     * @param array{offset:int, threw:bool, rowsFailed:int} $outcome
     * @param int $totalRows
     * @return bool
     */
    private function drainIsClean(array $outcome, int $totalRows): bool {
        return !$outcome['threw']
            && $outcome['rowsFailed'] === 0
            && $outcome['offset'] >= $totalRows;
    }

    /**
     * How many sites the network has.
     *
     * @return int
     */
    private function countNetworkSites(): int {
        $count = get_sites(array('count' => true));
        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * The site at a given position in the walk, or null when the network cannot
     * answer.
     *
     * One site is fetched per tick rather than the whole list: a stored list of
     * every site id is unbounded in both memory and option size, and this walk
     * only ever needs the site it is about to drain.
     *
     * @param int $walkOffset
     * @return int|null
     */
    private function siteAtWalkOffset(int $walkOffset) {
        $sites = get_sites(array(
            'fields' => 'ids',
            'number' => 1,
            'offset' => $walkOffset,
            'orderby' => 'id',
            'order' => 'ASC',
        ));
        // A non-array answer is a failed query, NOT the end of the network.
        // Reading it as the end is what would let a rebuild that never
        // discovered a single site record itself as covering all of them.
        if (!is_array($sites) || empty($sites)) {
            return null;
        }
        $siteId = reset($sites);
        return is_numeric($siteId) ? (int)$siteId : null;
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

        // Range-check, not just presence-check. A negative count would walk the
        // cursor BACKWARDS into an endless loop, and a count larger than the
        // batch we asked for means the collaborator did something other than
        // what was requested -- in both cases the number is not a description of
        // this batch, and letting it advance the cursor publishes a cache whose
        // coverage nobody can account for.
        $processed = (int)$stats['processed'];
        if ($processed < 0 || $processed > self::BATCH_SIZE) {
            throw new RuntimeException(
                'N-gram rebuilder reported ' . $processed . ' rows processed for a batch of '
                . self::BATCH_SIZE . ' at offset ' . $offset . '; refusing to advance on a count '
                . 'that cannot describe this batch.'
            );
        }

        $success = isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0;
        $failed = isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0;

        return [
            'processed' => $processed,
            // Clamped rather than trusted: these two only drive reporting and
            // the completion guard, so an out-of-range value must not be able to
            // make a run look cleaner than it was.
            'success' => max(0, min($success, $processed)),
            'failed' => max(0, min($failed, $processed)),
        ];
    }
}
