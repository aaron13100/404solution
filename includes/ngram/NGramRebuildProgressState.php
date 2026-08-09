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
     * Sites still to drain, or NULL when the list has never been seeded (which
     * is what tells the batch runner this is the first tick of a network
     * rebuild). Distinct from an empty array, which means "all sites drained".
     *
     * @return array<int, int>|null
     */
    public function pendingSites() {
        $raw = $this->optionStore->getOption(self::OPTION_PENDING_SITES, null);
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            return array();
        }
        $sites = array();
        foreach ($raw as $siteId) {
            // Site ids come back from the options table as ints or as their
            // string forms depending on how they were serialized; anything else
            // is not a site id and is dropped rather than coerced to 0, which
            // would be a real site's id.
            if (is_scalar($siteId) && is_numeric($siteId)) {
                $sites[] = (int)$siteId;
            }
        }
        return $sites;
    }

    /**
     * @param array<int, int> $sites
     * @return void
     */
    public function setPendingSites(array $sites): void {
        $this->optionStore->updateOption(self::OPTION_PENDING_SITES, $sites);
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
