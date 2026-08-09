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
     * How many network sites have been fully drained.
     *
     * This replaced a stored list of every pending site id. The list was the
     * whole network in memory AND in one option row, which grows without bound
     * with the network; a count cannot. The current site is looked up by this
     * offset each tick instead (ordered by id), so the record stays two
     * integers however large the network is.
     *
     * The trade is that a site DELETED mid-drain shifts the ordering and can
     * cost one site its turn in that pass. That is recoverable -- the next full
     * rebuild picks it up, and a missing n-gram entry only weakens suggestion
     * ranking -- whereas an option row that grows with the network is not.
     *
     * @return int
     */
    public function sitesCompleted(): int {
        $this->migrateLegacyPendingSites();
        return $this->readInt(self::OPTION_SITES_COMPLETED);
    }

    /**
     * Retire the current site and move to the next.
     *
     * The cursor is cleared BEFORE the completed count advances, and the order
     * is the point. Cleared first, a death between the two writes re-drains the
     * current site from 0 -- idempotent, and it costs one pass. Advanced first,
     * the NEXT site inherits this site's offset and silently skips that many
     * rows, which nothing afterwards would ever detect.
     *
     * @return void
     */
    public function advanceToNextSite(): void {
        $this->setCurrentSiteOffset(0);
        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, $this->sitesCompleted() + 1);
    }

    /**
     * Seed the network walk.
     *
     * @param int $totalSites
     * @return void
     */
    public function beginNetworkWalk(int $totalSites): void {
        $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, 0);
        $this->setTotalSites($totalSites);
        $this->setCurrentSiteOffset(0);
    }

    /** @return bool Whether the network walk has been seeded at all. */
    public function networkWalkStarted(): bool {
        $this->migrateLegacyPendingSites();
        return $this->optionStore->getOption(self::OPTION_TOTAL_SITES, null) !== null;
    }

    /**
     * Carry a rebuild that was mid-flight under the old pending-list format
     * over to the completed-count cursor, then drop the legacy option so this
     * runs once. Without it, an upgrade landing mid-network-rebuild would
     * restart that network from site one.
     *
     * @return void
     */
    private function migrateLegacyPendingSites(): void {
        $legacy = $this->optionStore->getOption(self::OPTION_PENDING_SITES, null);
        if ($legacy === null) {
            return;
        }
        if (is_array($legacy)) {
            $total = $this->readInt(self::OPTION_TOTAL_SITES);
            $completed = $total > count($legacy) ? $total - count($legacy) : 0;
            $this->optionStore->updateOption(self::OPTION_SITES_COMPLETED, $completed);
        }
        $this->optionStore->updateOption(self::OPTION_PENDING_SITES, null);
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

    /** @return void */
    public function clearFailures(): void {
        $this->optionStore->updateOption(self::OPTION_CONSECUTIVE_FAILURES, 0);
    }

    /** @return bool Whether the chain has failed too often to keep retrying. */
    public function hasExhaustedRetries(): bool {
        return $this->readInt(self::OPTION_CONSECUTIVE_FAILURES) >= self::MAX_CONSECUTIVE_FAILURES;
    }
}
