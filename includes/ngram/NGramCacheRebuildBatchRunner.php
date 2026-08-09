<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/NGramRebuildProgressState.php';
require_once __DIR__ . '/NGramRescheduleFailureReport.php';
require_once __DIR__ . '/NGramRebuildDrain.php';

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

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * Owns the rebuild cursor and the invariant that it never passes rows that
     * did not rebuild. This runner decides WHICH set to drain and what to do
     * about a drain that fell short; it does not touch the cursor itself.
     *
     * @var ABJ_404_Solution_NGramRebuildDrain
     */
    private $drain;

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
        $this->logger = $logger;
        $this->optionStore = $optionStore;
        $this->progress = new ABJ_404_Solution_NGramRebuildProgressState($optionStore);
        $this->drain = new ABJ_404_Solution_NGramRebuildDrain(
            array($rebuilder, 'rebuildCache'), $this->progress);
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
     * The offset the report needs is read from the cursor rather than passed
     * alongside the delay: as adjacent int parameters the two were transposable
     * at every call site, and a transposition would silently change both when
     * the chain resumes and where it resumes from.
     *
     * @param int $delaySeconds
     * @param array<int, mixed> $args
     * @param float $progress Percent complete, for the report.
     * @return void
     */
    private function rescheduleChain(int $delaySeconds, array $args, float $progress): void {
        if ($this->cronScheduler->scheduleSingle(self::REBUILD_CRON_HOOK, $delaySeconds, $args) === false) {
            $this->rescheduleFailureReport->report(
                self::REBUILD_CRON_HOOK, $this->progress->cursor(), $progress);
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
     * Log whatever stopped a drain short, if anything did.
     *
     * The drain reports its failure rather than logging it, so the consecutive
     * failure ledger and the message format stay in one place here -- the same
     * place the network walk's own failures go through. A drain stops at its
     * first failure, so there is at most one to report per call.
     *
     * @param array{failureContext?: string|null} $outcome
     * @return void
     */
    private function reportDrainFailure(array $outcome): void {
        $context = isset($outcome['failureContext']) ? $outcome['failureContext'] : null;
        if (is_string($context) && $context !== '') {
            $this->recordBatchFailure($context);
        }
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
     * Per-batch worker for multisite: walk to the site this tick owns, drain one
     * chunk of batches of it, then either retire it or come back to it on the
     * next tick.
     */
    private function runMultisiteBatch(): void {
        if (!$this->progress->networkWalkStarted()) {
            $liveCount = $this->countNetworkSites();
            if ($liveCount === null) {
                $this->recordBatchFailure(
                    'N-gram rebuild could not count the sites in this network; leaving the walk unstarted.'
                );
                $this->rescheduleChain(10, array(), 0.0);
                return;
            }
            $this->progress->beginNetworkWalk($liveCount);
        }

        $totalSites = $this->progress->totalSites(0);
        $completedSites = $this->progress->sitesCompleted();

        // Completion is confirmed by ASKING the network, never by trusting the
        // stored total. Sites can be created or deleted mid-walk, which shifts
        // every later position; a walk that believes it is finished because a
        // snapshot said so can have skipped a site that still exists.
        $currentSiteId = $this->siteAtWalkOffset($completedSites);
        if ($currentSiteId === null) {
            $liveCount = $this->countNetworkSites();
            if ($liveCount !== null && $completedSites >= $liveCount) {
                $this->progress->markNetworkComplete();
                $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
                return;
            }
            // No site at this position, but the network says there should be
            // one (or could not be asked). Sites moved under the walk, or the
            // query failed. Either way this network has NOT been drained, so
            // completion must not be recorded; re-seed and walk it again.
            $this->recordBatchFailure(sprintf(
                'N-gram rebuild could not resolve network site %d of %d; re-seeding the walk.',
                $completedSites + 1,
                $totalSites
            ));
            if ($liveCount !== null) {
                $this->progress->beginNetworkWalk($liveCount);
            }
            $this->rescheduleChain(10, array(), 0.0);
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
                $this->rescheduleChain(0, array(), 100.0);
                return;
            }

            $this->logger->infoMessage(sprintf(
                "Processing N-gram cache for site %d (Site %d of %d): Offset %d of %d pages",
                $currentSiteId, $completedSites + 1, $totalSites,
                $this->progress->cursor(), $sitePages
            ));

            $outcome = $this->drain->drain($sitePages, "site {$currentSiteId}");
            $this->reportDrainFailure($outcome);

            $this->logger->infoMessage(sprintf(
                "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
                $currentSiteId, $outcome['percent'], $outcome['offset'], $sitePages,
                $outcome['success'], $outcome['rowsFailed']
            ));

            if (ABJ_404_Solution_NGramRebuildDrain::isClean($outcome, $sitePages)) {
                $this->progress->advanceToNextSite();
                $this->progress->clearFailures();
                $this->logger->infoMessage(sprintf(
                    "Site %d complete! Progress: %d/%d sites completed.",
                    $currentSiteId, $completedSites + 1, $totalSites
                ));
            } else if (!$outcome['threw'] && $outcome['rowsFailed'] === 0) {
                $this->progress->clearFailures();
            }

            $this->rescheduleChain(10, array(), $outcome['percent']);
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Per-batch worker for single-site: drain one chunk of batches,
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

        $outcome = $this->drain->drain($totalPages, 'this site');
        $this->reportDrainFailure($outcome);

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $outcome['percent'], $outcome['offset'], $totalPages,
            $outcome['success'], $outcome['rowsFailed']
        ));

        // A run that failed is NOT complete, however far the cursor got
        // beforehand. Marking it initialized here is what published an empty or
        // partial cache as fully built, with nothing left to re-arm a rebuild.
        if (!ABJ_404_Solution_NGramRebuildDrain::isClean($outcome, $totalPages)) {
            $this->rescheduleChain(10, [$outcome['offset']], $outcome['percent']);
            return;
        }

        $this->progress->markComplete();
        $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$outcome['processed']} processed, "
            . "{$outcome['success']} success, {$outcome['rowsFailed']} failed.");
    }

    /**
     * How many sites the network has, or null when the network cannot answer.
     *
     * The null is load-bearing: it is the only thing that distinguishes "this
     * network has no sites" from "the count could not be read", and the walk
     * treats the first as a finished rebuild.
     *
     * @return int|null
     */
    private function countNetworkSites(): ?int {
        $count = get_sites(array('count' => true));
        // NULL, not 0: a count query that failed is not a network with no sites
        // in it, and reading it as one records a rebuild that processed nothing
        // as having covered everything.
        if (!is_numeric($count)) {
            return null;
        }
        $count = (int)$count;
        return $count >= 0 ? $count : null;
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

}
