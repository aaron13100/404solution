<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The n-gram cache rebuild's progress record: where the rebuild has got to,
 * whether it finished, and how many consecutive ticks have failed.
 *
 * Why this is its own module rather than fields on the batch runner: the
 * rebuild's progress is read and written by seven different places (the batch
 * runner, NGramCacheRebuildScheduler, NGramCoveragePolicy,
 * DatabaseUpgradeNGramCacheInitializer, PluginLogicLifecycle, WPCLICommands and
 * the uninstaller), each of which used to spell the option names as raw string
 * literals. A coordination key repeated across seven files with no single owner
 * is a rename waiting to go half-finished.
 *
 * It also owns one invariant that is easy to break and expensive when broken:
 * the "initialized" flag is the field every reader gates on, so it must be the
 * LAST write of a completion sequence. Written first -- as it was -- a process
 * death between the writes strands "initialized" beside a stale cursor and a
 * pending-site list that contradict it, and nothing reconciles the two
 * afterwards. Keeping the completion sequence behind markComplete() /
 * markNetworkComplete() means no caller can get the order wrong.
 *
 * Multisite-aware only through the option store it is handed: on a
 * network-activated install the store routes to site options, so the whole
 * network shares one progress record.
 */
class ABJ_404_Solution_NGramRebuildProgressState {

    /**
     * Coordination keys. Public so the readers listed above can stop spelling
     * them by hand.
     */
    const OPTION_PENDING_SITES = 'abj404_ngram_pending_sites';
    const OPTION_SITES_COMPLETED = 'abj404_ngram_sites_completed';
    const OPTION_LAST_SITE_ID = 'abj404_ngram_last_site_id';
    const OPTION_TOTAL_SITES = 'abj404_ngram_total_sites';
    const OPTION_CURRENT_SITE_OFFSET = 'abj404_ngram_current_site_offset';
    const OPTION_REBUILD_OFFSET = 'abj404_ngram_rebuild_offset';
    const OPTION_CACHE_INITIALIZED = 'abj404_ngram_cache_initialized';
    const OPTION_CONSECUTIVE_FAILURES = 'abj404_ngram_consecutive_failures';

    /**
     * Consecutive failing ticks before the chain stops re-arming itself.
     * Retrying forever would hammer a host that is genuinely broken; stopping
     * leaves the cache honestly uninitialized so a later rebuild resumes it.
     */
    const MAX_CONSECUTIVE_FAILURES = 5;

    /** @var ABJ_404_Solution_NGramNetworkOptionStore */
    private $optionStore;

    /**
     * Which cursor cursor()/setCursor() address. A cron tick is either draining
     * a network or a single site, never both, so the mode is chosen once per
     * tick and the shared drain loop then needs no idea which it is in.
     *
     * @var bool
     */
    private $networkMode = false;

    /**
     * @param ABJ_404_Solution_NGramNetworkOptionStore $optionStore
     */
    public function __construct($optionStore) {
        $this->optionStore = $optionStore;
    }

    /**
     * Read an option as an int, tolerating the string forms the options table
     * returns.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    private function readInt(string $key, int $default = 0): int {
        $raw = $this->optionStore->getOption($key, $default);
        return is_scalar($raw) ? (int)$raw : $default;
    }

    /**
     * Address the per-site cursor of a network walk.
     *
     * @return void
     */
    public function useNetworkCursor(): void {
        $this->networkMode = true;
    }

    /**
     * Address the single-site rebuild cursor.
     *
     * @return void
     */
    public function useSingleSiteCursor(): void {
        $this->networkMode = false;
    }

    /** @return int The active cursor. */
    public function cursor(): int {
        return $this->networkMode ? $this->currentSiteOffset() : $this->singleSiteOffset();
    }

    /**
     * @param int $offset
     * @return void
     */
    public function setCursor(int $offset): void {
        if ($this->networkMode) {
            $this->setCurrentSiteOffset($offset);
            return;
        }
        $this->setSingleSiteOffset($offset);
    }

    /** @return int Cursor for the single-site rebuild. */
    public function singleSiteOffset(): int {
        return $this->readInt(self::OPTION_REBUILD_OFFSET);
    }

    /**
     * @param int $offset
     * @return void
     */
    public function setSingleSiteOffset(int $offset): void {
        $this->optionStore->updateOption(self::OPTION_REBUILD_OFFSET, $offset);
    }

    /** @return int Cursor within the multisite network's current site. */
    public function currentSiteOffset(): int {
        return $this->readInt(self::OPTION_CURRENT_SITE_OFFSET);
    }

    /**
     * @param int $offset
     * @return void
     */
    public function setCurrentSiteOffset(int $offset): void {
        $this->optionStore->updateOption(self::OPTION_CURRENT_SITE_OFFSET, $offset);
    }

    /**
     * How many network sites have been fully drained. Progress reporting only:
     * nothing decides where the walk goes next, or whether it is finished, from
     * this number.
     *
     * It used to be both, and that was the defect. A count doubling as a
     * POSITION means the walk asks for "the site at position N" of a list whose
     * positions are assigned at read time, so a site deleted earlier in the
     * list slides every later site down one and the next tick steps over the
     * one in the gap. The walk is keyed on {@see lastCompletedSiteId()} now,
     * which names the same site however the list changes around it.
     *
     * @return int
     */
    public function sitesCompleted(): int {
        $this->migrateToKeysetWalk();
        return $this->readInt(self::OPTION_SITES_COMPLETED);
    }

    /**
     * The last site id this walk finished; 0 before the first one lands.
     *
     * This is the walk's cursor. Blog ids are immutable and ascending, so the
     * next site is always "the smallest id greater than this", a question whose
     * answer cannot be moved by a site being created or deleted elsewhere in
     * the network.
     *
     * @return int
     */
    public function lastCompletedSiteId(): int {
        $this->migrateToKeysetWalk();
        return max(0, $this->readInt(self::OPTION_LAST_SITE_ID));
    }

    /**
     * Retire the site just drained and move the cursor onto it.
     *
     * The write order is the point, twice over. The per-site row cursor is
     * cleared FIRST: a death between the writes then re-drains the current site
     * from 0 -- idempotent, and it costs one pass -- whereas advancing first
     * makes the next site inherit this site's row offset and silently skip that
     * many rows. The walk cursor moves before the completed COUNT for the same
     * reason in reverse: the count is only reporting, so a death between them
     * costs a display number, never a site.
     *
     * @param int $completedSiteId The site that just finished draining.
     * @return void
     */
    public function advanceToNextSite(int $completedSiteId): void {
        $this->setCurrentSiteOffset(0);
        $this->optionStore->updateOption(self::OPTION_LAST_SITE_ID, max(0, $completedSiteId));
        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, $this->sitesCompleted() + 1);
    }

    /**
     * Seed the network walk.
     *
     * @param int $totalSites Sites the network held when the walk began, for
     *                        progress reporting only.
     * @return void
     */
    public function beginNetworkWalk(int $totalSites): void {
        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, 0);
        // Written explicitly rather than left absent: an absent cursor is what
        // marks a pre-keyset record, and a fresh walk must not look like one.
        $this->optionStore->updateOption(self::OPTION_LAST_SITE_ID, 0);
        $this->setTotalSites($totalSites);
        $this->setCurrentSiteOffset(0);
    }

    /** @return bool Whether the network walk has been seeded at all. */
    public function networkWalkStarted(): bool {
        $this->migrateToKeysetWalk();
        return $this->optionStore->getOption(self::OPTION_TOTAL_SITES, null) !== null;
    }

    /**
     * Bring a rebuild that was mid-flight under an older progress format onto
     * the keyset cursor, and drop the formats it replaced so this runs once.
     *
     * Both older formats recorded a POSITION (a pending-site list, then a
     * completed count) and neither can be translated into a site id without
     * re-reading the list by position -- the very read that can step over a
     * site. So an in-flight walk with no cursor RESTARTS at the first site.
     * Re-draining sites is idempotent and costs one pass of cron work; guessing
     * the cursor can lose a site for the whole rebuild, and losing one is the
     * failure this cursor exists to make impossible.
     *
     * @return void
     */
    private function migrateToKeysetWalk(): void {
        $legacyPendingList = $this->optionStore->getOption(self::OPTION_PENDING_SITES, null);
        $hasCursor = $this->optionStore->getOption(self::OPTION_LAST_SITE_ID, null) !== null;
        $walkInFlight = $this->optionStore->getOption(self::OPTION_TOTAL_SITES, null) !== null;

        if ($legacyPendingList === null && ($hasCursor || !$walkInFlight)) {
            return;
        }

        if ($legacyPendingList !== null) {
            $this->optionStore->updateOption(self::OPTION_PENDING_SITES, null);
        }
        if (!$walkInFlight || $hasCursor) {
            // Nothing in flight to carry over, or it is already on the cursor.
            return;
        }

        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, 0);
        $this->setCurrentSiteOffset(0);
        $this->optionStore->updateOption(self::OPTION_LAST_SITE_ID, 0);
    }

    /**
     * @param int $default Reported when the total was never recorded.
     * @return int
     */
    public function totalSites(int $default = 0): int {
        return $this->readInt(self::OPTION_TOTAL_SITES, $default);
    }

    /**
     * @param int $total
     * @return void
     */
    public function setTotalSites(int $total): void {
        $this->optionStore->updateOption(self::OPTION_TOTAL_SITES, $total);
    }

    /** @return bool Whether the cache has been recorded as fully built. */
    public function isInitialized(): bool {
        $raw = $this->optionStore->getOption(self::OPTION_CACHE_INITIALIZED, '');
        return is_scalar($raw) && (string)$raw === '1';
    }

    /** @return string The raw flag value, for diagnostics that report it verbatim. */
    public function rawInitializedValue(): string {
        $raw = $this->optionStore->getOption(self::OPTION_CACHE_INITIALIZED, 'not set');
        return is_scalar($raw) ? (string)$raw : 'not set';
    }

    /**
     * Record a single-site rebuild as complete.
     *
     * Order is the point: the cursor is cleared and the failure count reset
     * BEFORE the flag readers gate on is set, so there is no window in which
     * "initialized" coexists with progress that contradicts it.
     *
     * @return void
     */
    public function markComplete(): void {
        $this->setSingleSiteOffset(0);
        $this->clearFailures();
        $this->optionStore->updateOption(self::OPTION_CACHE_INITIALIZED, '1');
    }

    /**
     * Record a whole network as complete. Same ordering rule as markComplete().
     *
     * @return void
     */
    public function markNetworkComplete(): void {
        $this->optionStore->updateOption(self::OPTION_PENDING_SITES, null);
        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, null);
        $this->optionStore->updateOption(self::OPTION_LAST_SITE_ID, null);
        $this->optionStore->updateOption(self::OPTION_TOTAL_SITES, null);
        $this->optionStore->updateOption(self::OPTION_CURRENT_SITE_OFFSET, null);
        $this->clearFailures();
        $this->optionStore->updateOption(self::OPTION_CACHE_INITIALIZED, '1');
    }

    /**
     * Count one failed tick.
     *
     * @return int The new consecutive-failure total.
     */
    public function recordFailure(): int {
        $failures = $this->readInt(self::OPTION_CONSECUTIVE_FAILURES) + 1;
        $this->optionStore->updateOption(self::OPTION_CONSECUTIVE_FAILURES, $failures);
        return $failures;
    }

    /**
     * Failing ticks in a row, 0 on a healthy chain.
     *
     * Exposed as a reader because the retry cadence is a function of it:
     * {@see ABJ_404_Solution_NGramRebuildRetryPolicy} answers "how long until
     * the next attempt" from this number, so the count and the delay cannot
     * disagree about how far into an outage the chain is.
     *
     * @return int
     */
    public function consecutiveFailures(): int {
        return max(0, $this->readInt(self::OPTION_CONSECUTIVE_FAILURES));
    }

    /** @return void */
    public function clearFailures(): void {
        $this->optionStore->updateOption(self::OPTION_CONSECUTIVE_FAILURES, 0);
    }

    /** @return bool Whether the chain has failed too often to keep retrying. */
    public function hasExhaustedRetries(): bool {
        return $this->readInt(self::OPTION_CONSECUTIVE_FAILURES) >= self::MAX_CONSECUTIVE_FAILURES;
    }
}
