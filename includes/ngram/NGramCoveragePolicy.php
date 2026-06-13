<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the N-gram cache coverage state.
 *
 * Three responsibilities, all about "is the N-gram cache complete enough
 * to trust on this request": coverage transient (ratio of ngram entries to
 * permalink entries with version-based invalidation), multisite-aware
 * initialization flag, and the invalidation primitive every write path
 * calls after touching the underlying tables.
 *
 * Owns its own count memo because the ratio computation needs both ngram
 * and permalink counts in lockstep; the repository's own memo is for a
 * different consumer (findSimilarPages strategy choice) and the duplicated
 * COUNT(*) is one indexed query per request worst case.
 */
class ABJ_404_Solution_NGramCoveragePolicy {

    /** Cache TTL for coverage ratio transient (seconds). */
    const COVERAGE_RATIO_CACHE_TTL = 300; // 5 minutes

    /** TTL for coverage version transient (seconds). */
    const COVERAGE_VERSION_TTL = 86400; // 1 day

    /** Transient key for coverage ratio cache version. */
    const COVERAGE_VERSION_KEY = 'abj404_ngram_coverage_version';

    /** Transient key for coverage ratio cache data. */
    const COVERAGE_RATIO_KEY = 'abj404_ngram_coverage_ratio';

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var array<string, mixed>|null Per-request memoized coverage ratio data */
    private $coverageRatioMemo = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore
     */
    public function __construct($dbCore = null) {
        $this->dbCore = $dbCore !== null ? $dbCore : abj_service('db_core');
    }

    /**
     * Invalidate coverage ratio caches (transient and per-request memos).
     *
     * Call this whenever N-gram or permalink counts change, including after
     * TRUNCATE operations during cache rebuilds.
     *
     * Uses timestamp-based versioning: sets version to current time().
     * Cached ratios with older timestamps are stale. This approach is:
     * - Overflow-safe: no accumulating counter
     * - Race-safe: concurrent invalidations both write current time
     *
     * @return void
     */
    public function invalidateCoverageCaches() {
        // allow-cache-empty: timestamp marker versions coverage caches after invalidation; not a cached query payload.
        set_transient(self::COVERAGE_VERSION_KEY, abj_clock()->now(), self::COVERAGE_VERSION_TTL);
        delete_transient(self::COVERAGE_RATIO_KEY);
        $this->coverageRatioMemo = null;
    }

    /**
     * Check if the N-gram cache is initialized (multisite-aware).
     *
     * On multisite, checks both get_site_option() (network activation) and
     * get_option() (per-site activation) since we can't reliably determine
     * activation mode on frontend requests where is_plugin_active_for_network()
     * isn't available.
     *
     * @return bool True if cache is initialized
     */
    public function isCacheInitialized() {
        $optionName = 'abj404_ngram_cache_initialized';

        if (is_multisite()) {
            $siteValue = get_site_option($optionName);
            if ($siteValue === '1') {
                return true;
            }
            // Fall through to check per-site option
        }

        return get_option($optionName) === '1';
    }

    /**
     * Get cache coverage ratio (ngram entries / permalink entries).
     *
     * Used to detect stale or incomplete caches. A ratio < 1.0 indicates
     * some permalink entries are not in the N-gram cache.
     *
     * Memoized per-request and cached in a transient for 5 minutes with
     * version-based validation to avoid expensive COUNT(*) queries.
     *
     * @return float Coverage ratio (0.0 to 1.0+), or 1.0 if permalink cache is empty
     */
    public function getCacheCoverageRatio() {
        if ($this->coverageRatioMemo !== null) {
            $ratioVal = isset($this->coverageRatioMemo['ratio']) ? $this->coverageRatioMemo['ratio'] : 0;
            return is_scalar($ratioVal) ? (float)$ratioVal : 0.0;
        }

        $versionTransient = get_transient(self::COVERAGE_VERSION_KEY);
        $currentVersion = is_scalar($versionTransient) ? (int)$versionTransient : 0;

        $cached = get_transient(self::COVERAGE_RATIO_KEY);
        if ($cached !== false && is_array($cached)
            && isset($cached['ratio'], $cached['version'])
            && is_scalar($cached['version']) && (int)$cached['version'] === $currentVersion) {
            // Valid: version matches, trust the cached ratio without COUNT queries
            /** @var array<string, mixed> $cachedMap */
            $cachedMap = $cached;
            $this->coverageRatioMemo = $cachedMap;
            $ratioOut = isset($cachedMap['ratio']) && is_scalar($cachedMap['ratio']) ? (float)$cachedMap['ratio'] : 0.0;
            return $ratioOut;
        }

        // Transient miss or version mismatch - compute fresh ratio
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $ngramCount = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$ngramTable}");
        $permalinkCount = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$permalinkTable}");

        if ($permalinkCount === 0) {
            // Empty permalink cache with existing N-grams = stale state (during rebuild)
            // Return 0.0 to skip prefiltering until both caches are populated
            $ratio = ($ngramCount === 0) ? 1.0 : 0.0;
        } else {
            $ratio = $ngramCount / $permalinkCount;
        }

        $this->coverageRatioMemo = [
            'ratio' => $ratio,
            'ngram_count' => $ngramCount,
            'permalink_count' => $permalinkCount,
            'version' => $currentVersion
        ];

        // @cache-write-audit: opt-out — self-validating cache. The cached
        // payload carries the coverage version key; any mutation to the
        // underlying tables calls invalidateCoverageCaches() which bumps
        // COVERAGE_VERSION_KEY, so a stale entry is invalidated by the next
        // mutation rather than by an explicit last_error/timed_out check.
        // Reference fixes: 6315bcb8, c8fba7ee, 2a0a2dd6.
        set_transient(self::COVERAGE_RATIO_KEY, $this->coverageRatioMemo, self::COVERAGE_RATIO_CACHE_TTL);

        return $ratio;
    }
}
