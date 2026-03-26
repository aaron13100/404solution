<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DatabaseUpgradesEtc_NGramTrait {

    /** @return bool */
    function scheduleNGramCacheRebuild() {
        global $wpdb;

        // MULTISITE: Acquire network-wide lock to prevent race conditions during scheduling
        $lockKey = 'ngram_schedule';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);

        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram rebuild scheduling: Another process holds the lock. Skipping.");
            return true; // Another site is already handling scheduling
        }

        try {
            // MULTISITE: Use network-aware option getter
            $rawCurrentOffset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
            $currentOffset = is_scalar($rawCurrentOffset) ? (int)$rawCurrentOffset : 0;

            // MULTISITE: Count pages across all sites if network-activated
            $totalPages = $this->countTotalPagesForNGramRebuild();

            // If offset is between 0 and total (exclusive), rebuild is in progress
            if ($currentOffset > 0 && $currentOffset < $totalPages) {
                $this->logger->debugMessage("N-gram cache rebuild already in progress at offset {$currentOffset} of {$totalPages}");
                return true;
            }

            // Check if already scheduled
            $nextScheduled = wp_next_scheduled('abj404_rebuild_ngram_cache_hook');
            if ($nextScheduled) {
                $this->logger->debugMessage("N-gram cache rebuild already scheduled for " . date('Y-m-d H:i:s', $nextScheduled));
                return true;
            }

            // MULTISITE: Reset offset using network-aware setter
            $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);

            // Schedule to run in 30 seconds (gives time for activation to complete)
            $scheduleTime = time() + 30;
            $hookName = 'abj404_rebuild_ngram_cache_hook';
            $scheduled = wp_schedule_single_event($scheduleTime, $hookName);

            if ($scheduled === false) {
                // Quick check for DISABLE_WP_CRON as immediate diagnostic
                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                    $this->logger->errorMessage(
                        "Cannot schedule N-gram cache rebuild: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                        "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
                    );
                    return false;
                }

                global $wpdb;

                // Gather comprehensive diagnostic information for troubleshooting
                $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                $alreadyScheduled = wp_next_scheduled($hookName);
                $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
                $rawRebuildOffset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 'not set');
                $rebuildOffset = is_scalar($rawRebuildOffset) ? (string)$rawRebuildOffset : 'not set';
                $rawCacheInit = $this->getNetworkAwareOption('abj404_ngram_cache_initialized', 'not set');
                $cacheInitialized = is_scalar($rawCacheInit) ? (string)$rawCacheInit : 'not set';

                $errorMsg = sprintf(
                    "Failed to schedule N-gram cache rebuild. Hook: %s, Schedule time: %d (current: %d), " .
                    "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
                    "Rebuild offset: %s, Cache initialized: %s, Multisite: %s, Blog ID: %d",
                    $hookName,
                    $scheduleTime,
                    time(),
                    $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
                    $cronDisabled ? 'yes' : 'no',
                    $dbError,
                    $rebuildOffset,
                    $cacheInitialized,
                    is_multisite() ? 'yes' : 'no',
                    get_current_blog_id()
                );

                $this->logger->errorMessage($errorMsg);
                return false;
            }

            $context = is_multisite() ? ' (network-wide)' : '';
            $this->logger->infoMessage("N-gram cache rebuild scheduled to start in 30 seconds{$context}.");
            return true;

        } finally {
            // Always release the lock
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * WP-Cron callback: Rebuild N-gram cache in batches (async).
     *
     * Lock Acquisition Flow (per-batch):
     * 1. Create unique ID
     * 2. Write unique ID to lock if empty
     * 3. Sleep 30ms (allows race condition resolution)
     * 4. Read lock back and verify ownership
     * 5. Process batch only if lock belongs to this process
     * 6. Release lock in finally block
     *
     * MULTISITE BEHAVIOR (FIXED):
     * - Processes one site at a time completely before moving to the next site
     * - Uses network options to track: pending sites, current site, and offset within current site
     * - Switches to each site's blog context before processing its pages
     * - Prevents the bug where only the first site got cache entries
     * - Progress tracking shows per-site and network-wide completion status
     *
     * SINGLE SITE BEHAVIOR:
     * - Uses simple offset tracking with network-aware options
     * - Processes all pages in batches until complete
     *
     * @param int $offset Current batch offset (default: 0, overridden by network options)
     * @return void
     */
    function rebuildNGramCacheAsync($offset = 0) {
        global $wpdb;

        // Acquire lock using SynchronizationUtils (per-batch lock)
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry('ngram_rebuild');
        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram async rebuild batch already processing (another process holds lock). Skipping.");
            return;
        }

        try {
            $batchSize = 50; // Smaller batches for async processing
            $maxBatchesPerRun = 20; // Process up to 1000 pages per cron run

            // MULTISITE: Process one site at a time to ensure all sites get cache entries
            if ($this->isNetworkActivated()) {
                // Get or initialize list of pending sites
                $pendingSitesRaw = $this->getNetworkAwareOption('abj404_ngram_pending_sites', null);
                /** @var array<int, int> $pendingSites */
                $pendingSites = is_array($pendingSitesRaw) ? $pendingSitesRaw : [];

                if ($pendingSitesRaw === null) {
                    // First run: Initialize site list and tracking
                    $sites = get_sites(array('fields' => 'ids', 'number' => 0));
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $sites);
                    $this->updateNetworkAwareOption('abj404_ngram_total_sites', count($sites));
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                    $pendingSites = $sites;
                }

                if (empty($pendingSites)) {
                    // All sites processed!
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', null);
                    $this->updateNetworkAwareOption('abj404_ngram_total_sites', null);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', null);
                    $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
                    return;
                }

                // Get current site to process
                $currentSiteId = (int)$pendingSites[0];
                $rawOffset = $this->getNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                $offset = is_scalar($rawOffset) ? (int)$rawOffset : 0;
                $rawTotalSites = $this->getNetworkAwareOption('abj404_ngram_total_sites', count($pendingSites));
                $totalSites = is_scalar($rawTotalSites) ? (int)$rawTotalSites : count($pendingSites);
                $completedSites = $totalSites - count($pendingSites);

                // Switch to the site being processed
                switch_to_blog($currentSiteId);

                // Count pages for THIS site only
                $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
                $sitePages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

                if ($sitePages == 0) {
                    // This site has no pages, move to next site
                    array_shift($pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                    restore_current_blog();

                    $this->logger->infoMessage(sprintf(
                        "Site %d has no pages. Moving to next site. Progress: %d/%d sites completed.",
                        $currentSiteId,
                        $completedSites + 1,
                        $totalSites
                    ));

                    // Reschedule immediately for next site
                    wp_schedule_single_event(time(), 'abj404_rebuild_ngram_cache_hook');
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

                // Process batches for current site
                $batchesProcessed = 0;
                $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

                while ($batchesProcessed < $maxBatchesPerRun && $offset < $sitePages) {
                    try {
                        // Process batch (already switched to correct blog)
                        $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                        $totalStats['processed'] += $stats['processed'];
                        $totalStats['success'] += $stats['success'];
                        $totalStats['failed'] += $stats['failed'];

                        $offset += $batchSize;
                        $batchesProcessed++;

                        // Update offset for current site
                        $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', $offset);

                        // Stop if we processed fewer pages than expected (end of site data)
                        if ($stats['processed'] < $batchSize) {
                            break;
                        }

                    } catch (Exception $e) {
                        $this->logger->errorMessage("Error during N-gram rebuild for site {$currentSiteId} at offset {$offset}: " . $e->getMessage());
                        $totalStats['failed'] += $batchSize;
                        $offset += $batchSize;
                        $batchesProcessed++;
                        $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', $offset);
                    }
                }

                $progress = $sitePages > 0 ? round(($offset / $sitePages) * 100, 1) : 100;

                $this->logger->infoMessage(sprintf(
                    "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
                    $currentSiteId,
                    $progress,
                    $offset,
                    $sitePages,
                    $totalStats['success'],
                    $totalStats['failed']
                ));

                // Check if current site is complete
                if ($offset >= $sitePages) {
                    // Site complete! Move to next site
                    array_shift($pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);

                    $this->logger->infoMessage(sprintf(
                        "Site %d complete! Progress: %d/%d sites completed.",
                        $currentSiteId,
                        $completedSites + 1,
                        $totalSites
                    ));
                }

                restore_current_blog();

                // Reschedule for next batch or next site
                wp_schedule_single_event(time() + 10, 'abj404_rebuild_ngram_cache_hook');

            } else {
                // SINGLE SITE: Use original simple logic
                $rawSingleOffset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                $offset = is_scalar($rawSingleOffset) ? (int)$rawSingleOffset : 0;
                $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
                $totalPages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

                if ($totalPages == 0) {
                    $this->logger->debugMessage("No pages to process. Setting initialized flag.");
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                    return;
                }

                $this->logger->infoMessage(sprintf(
                    "Async N-gram rebuild: Processing batch at offset %d of %d total pages",
                    $offset,
                    $totalPages
                ));

                // Process batches
                $batchesProcessed = 0;
                $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

                while ($batchesProcessed < $maxBatchesPerRun && $offset < $totalPages) {
                    try {
                        $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                        $totalStats['processed'] += $stats['processed'];
                        $totalStats['success'] += $stats['success'];
                        $totalStats['failed'] += $stats['failed'];

                        $offset += $batchSize;
                        $batchesProcessed++;

                        $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', $offset);

                        if ($stats['processed'] < $batchSize) {
                            break;
                        }

                    } catch (Exception $e) {
                        $this->logger->errorMessage("Error during async N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                        $totalStats['failed'] += $batchSize;
                        $offset += $batchSize;
                        $batchesProcessed++;
                        $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', $offset);
                    }
                }

                $progress = $totalPages > 0 ? round(($offset / $totalPages) * 100, 1) : 100;

                $this->logger->infoMessage(sprintf(
                    "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
                    $progress,
                    $offset,
                    $totalPages,
                    $totalStats['success'],
                    $totalStats['failed']
                ));

                if ($offset < $totalPages) {
                    $scheduleTime = time() + 10;
                    $hookName = 'abj404_rebuild_ngram_cache_hook';
                    $scheduled = wp_schedule_single_event($scheduleTime, $hookName, [$offset]);

                    if ($scheduled === false) {
                        // Quick check for DISABLE_WP_CRON as immediate diagnostic
                        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                            $this->logger->errorMessage(
                                "Cannot schedule next N-gram rebuild batch at offset {$offset}: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                                "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
                            );
                            // Don't return - let the rebuild complete gracefully, just log the issue
                        } else {
                            global $wpdb;

                            // Gather comprehensive diagnostic information for troubleshooting
                            $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                        $alreadyScheduled = wp_next_scheduled($hookName, [$offset]);
                        $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
                        $rawCacheInit2 = $this->getNetworkAwareOption('abj404_ngram_cache_initialized', 'not set');
                        $cacheInitialized = is_scalar($rawCacheInit2) ? (string)$rawCacheInit2 : 'not set';

                        $errorMsg = sprintf(
                            "Failed to schedule next N-gram rebuild batch at offset %d. Hook: %s, Schedule time: %d (current: %d), " .
                            "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
                            "Cache initialized: %s, Progress: %.1f%%, Multisite: %s, Blog ID: %d",
                            $offset,
                            $hookName,
                            $scheduleTime,
                            time(),
                            $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
                            $cronDisabled ? 'yes' : 'no',
                            $dbError,
                            $cacheInitialized,
                            $progress,
                            is_multisite() ? 'yes' : 'no',
                            get_current_blog_id()
                        );

                            $this->logger->errorMessage($errorMsg);
                        }
                    }
                } else {
                    // All done!
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                    $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$totalStats['processed']} processed, {$totalStats['success']} success, {$totalStats['failed']} failed.");
                }
            }

        } finally {
            // Always release lock, even if exception occurs
            $this->syncUtils->synchronizerReleaseLock($uniqueID, 'ngram_rebuild');
        }
    }

    /**
     * Rebuild the N-gram cache for all pages (synchronous).
     *
     * WARNING: This method is synchronous and can take minutes on large sites.
     * Use scheduleNGramCacheRebuild() instead for non-blocking background processing.
     *
     * This method is kept for manual rebuilds and testing purposes.
     *
     * @param int $batchSize Number of pages to process per batch (default: 100)
     * @param bool $forceRebuild Force rebuild even if cache is already populated (default: false)
     * @return array<string, mixed> Statistics: ['total_pages' => int, 'processed' => int, 'success' => int, 'failed' => int]
     */
    function rebuildNGramCache($batchSize = 100, $forceRebuild = false) {
        global $wpdb;

        // Use the same SynchronizationUtils lock as rebuildNGramCacheAsync() to
        // prevent TRUNCATE TABLE from racing with async batch inserts.
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->infoMessage("N-gram rebuild already in progress (locked). Skipping.");
            return [
                'total_pages' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'locked' => true
            ];
        }

        try {
            $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

            // Check if cache is already populated (unless force rebuild)
            if (!$forceRebuild) {
                $existingCount = $wpdb->get_var("SELECT COUNT(*) FROM {$ngramTable}");
                if ($existingCount > 0) {
                    $this->logger->debugMessage("N-gram cache already contains {$existingCount} entries. Skipping rebuild (use forceRebuild=true to override).");
                    return [
                        'total_pages' => $existingCount,
                        'processed' => 0,
                        'success' => $existingCount,
                        'failed' => 0,
                        'skipped' => true
                    ];
                }
            }

            $this->logger->debugMessage("Starting N-gram cache rebuild...");

            // Clear existing N-gram cache (only if force rebuild or empty)
            $result = $wpdb->query("TRUNCATE TABLE {$ngramTable}");
            if ($result === false) {
                $this->logger->errorMessage("Failed to truncate N-gram cache table: " . $wpdb->last_error);
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $wpdb->last_error];
            }

            // Invalidate coverage ratio caches immediately after truncate
            // This prevents stale transient data from making SpellChecker believe
            // the cache is populated when it's actually empty
            $this->ngramFilter->invalidateCoverageCaches();

            // Get total page count from permalink cache
            $totalPages = $wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

            if ($totalPages === null) {
                $this->logger->errorMessage("Failed to query permalink cache table: " . $wpdb->last_error);
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $wpdb->last_error];
            }

            if ($totalPages == 0) {
                $this->logger->debugMessage("No pages in permalink cache. N-gram cache rebuild skipped (will rebuild when pages are added).");
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 0];
            }

            $this->logger->infoMessage("Rebuilding N-gram cache for {$totalPages} pages in batches of {$batchSize}...");

            // Process in batches
            $offset = 0;
            $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

            while ($offset < $totalPages) {
                try {
                    $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                    $totalStats['processed'] += $stats['processed'];
                    $totalStats['success'] += $stats['success'];
                    $totalStats['failed'] += $stats['failed'];

                    $offset += $batchSize;

                    // Stop if we processed fewer pages than expected (end of data)
                    if ($stats['processed'] < $batchSize) {
                        break;
                    }

                } catch (Exception $e) {
                    $this->logger->errorMessage("Error during N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                    $totalStats['failed'] += $batchSize; // Mark batch as failed
                    $offset += $batchSize; // Continue to next batch
                }
            }

            $totalStats['total_pages'] = $totalPages;

            $successRate = $totalStats['processed'] > 0 ?
                round(($totalStats['success'] / $totalStats['processed']) * 100, 1) : 0;

            $this->logger->infoMessage(sprintf(
                "N-gram cache rebuild complete: %d pages processed, %d success, %d failed (%.1f%% success rate)",
                $totalStats['processed'],
                $totalStats['success'],
                $totalStats['failed'],
                $successRate
            ));

            return $totalStats;

        } finally {
            // Always release the lock
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * Sync missing ngram entries for posts/pages and categories that don't have them yet.
     * This runs as a background task to add entries for newly published content.
     *
     * Uses the same lock as rebuildNGramCache to prevent concurrent execution.
     *
     * @param int $batchSize Number of entries to process per batch (default: 50)
     * @return array<string, mixed> Statistics: ['posts_added' => int, 'posts_failed' => int, 'categories_added' => int, 'categories_failed' => int]
     */
    function syncMissingNGrams($batchSize = 50) {
        global $wpdb;

        // Use the same SynchronizationUtils lock as rebuildNGramCacheAsync() and
        // rebuildNGramCache() to prevent concurrent modification of the ngram table.
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->debugMessage("Ngram sync skipped - rebuild/sync already in progress.");
            return ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0, 'locked' => true];
        }

        try {
            $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

            $stats = ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0];

            // ===== SYNC POSTS =====
            // Find posts in permalink cache that don't have ngram entries
            // Using LEFT JOIN to find missing entries
            $query = $wpdb->prepare(
                "SELECT pc.id
                 FROM {$permalinkCacheTable} pc
                 LEFT JOIN {$ngramTable} ng ON pc.id = ng.id AND ng.type = 'post'
                 WHERE ng.id IS NULL
                 LIMIT %d",
                $batchSize
            );

            $missingIds = $wpdb->get_col($query);

            if ($wpdb->last_error) {
                $this->logger->errorMessage("Failed to query for missing post ngram entries: " . $wpdb->last_error);
                return array_merge($stats, ['error' => $wpdb->last_error]);
            }

            if (!empty($missingIds)) {
                $this->logger->infoMessage("Found " . count($missingIds) . " posts missing ngram entries. Adding...");

                // Add ngrams for missing posts
                $result = $this->ngramFilter->updateNGramsForPages($missingIds);

                $stats['posts_added'] = $result['success'];
                $stats['posts_failed'] = $result['failed'];
            } else {
                $this->logger->debugMessage("No missing post ngram entries found. All posts are synced.");
            }

            // ===== SYNC CATEGORIES =====
            // Get all published categories
            $categories = $this->dao->getPublishedCategories();

            if (!empty($categories)) {
                $missingCategories = [];

                // Check which categories are missing from ngram cache
                foreach ($categories as $category) {
                    /** @var object{term_id: int, url: string} $category */
                    $termId = (int)$category->term_id;

                    // Check if this category already has an ngram entry
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$ngramTable} WHERE id = %d AND type = 'category'",
                        $termId
                    ));

                    if ($exists == 0) {
                        $missingCategories[] = $category;
                    }
                }

                if (!empty($missingCategories)) {
                    $this->logger->infoMessage("Found " . count($missingCategories) . " categories missing ngram entries. Adding...");

                    // Add ngrams for missing categories
                    foreach ($missingCategories as $category) {
                        try {
                            /** @var object{term_id: int, url: string} $category */
                            $termId = (int)$category->term_id;
                            $url = (string)$category->url;

                            if (empty($url) || $url === 'in code') {
                                $this->logger->debugMessage("Skipping category {$termId} - no valid URL");
                                continue;
                            }

                            // Normalize URL
                            $urlNormalized = $this->f->strtolower(trim($url));

                            // Extract N-grams
                            $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                            // Store with type='category'
                            $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'category');

                            if ($success) {
                                $stats['categories_added']++;
                            } else {
                                $stats['categories_failed']++;
                            }
                        } catch (Exception $e) {
                            $this->logger->errorMessage("Failed to add ngram for category {$termId}: " . $e->getMessage());
                            $stats['categories_failed']++;
                        }
                    }
                } else {
                    $this->logger->debugMessage("No missing category ngram entries found. All categories are synced.");
                }
            }

            $this->logger->infoMessage("Ngram sync complete: {$stats['posts_added']} posts added, {$stats['posts_failed']} posts failed, {$stats['categories_added']} categories added, {$stats['categories_failed']} categories failed.");

            return $stats;

        } finally {
            // Always release the lock
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * Cleanup orphaned ngram entries that don't have corresponding posts/pages or categories.
     * This removes stale entries when posts are deleted or categories are removed.
     *
     * @return array<string, mixed> Statistics: ['posts_deleted' => int, 'categories_deleted' => int, 'errors' => int]
     */
    function cleanupOrphanedNGrams() {
        global $wpdb;

        $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

        $this->logger->debugMessage("Checking for orphaned ngram entries...");

        $stats = ['posts_deleted' => 0, 'categories_deleted' => 0, 'errors' => 0];

        // ===== CLEANUP ORPHANED POSTS =====
        // Find ngram entries for posts that don't exist in permalink cache
        // Using LEFT JOIN to find orphaned entries
        $query = "SELECT ng.id, ng.type
                  FROM {$ngramTable} ng
                  LEFT JOIN {$permalinkCacheTable} pc ON ng.id = pc.id AND ng.type = 'post'
                  WHERE ng.type = 'post' AND pc.id IS NULL";

        $orphanedPosts = $wpdb->get_results($query);

        if ($wpdb->last_error) {
            $this->logger->errorMessage("Failed to query for orphaned post ngram entries: " . $wpdb->last_error);
            return array_merge($stats, ['error' => $wpdb->last_error]);
        }

        if (!empty($orphanedPosts)) {
            $this->logger->infoMessage("Found " . count($orphanedPosts) . " orphaned post ngram entries. Deleting...");

            // Delete each orphaned post entry
            foreach ($orphanedPosts as $entry) {
                /** @var object{id: int, type: string} $entry */
                $entryId = (int)$entry->id;
                $entryType = (string)$entry->type;
                $result = $wpdb->delete(
                    $ngramTable,
                    ['id' => $entryId, 'type' => $entryType],
                    ['%d', '%s']
                );

                if ($result === false) {
                    $deleteError = is_string($wpdb->last_error) ? $wpdb->last_error : '';
                    $this->logger->errorMessage("Failed to delete orphaned post ngram entry ID {$entryId}: " . $deleteError);
                    $stats['errors']++;
                } else {
                    $stats['posts_deleted']++;
                }
            }
        } else {
            $this->logger->debugMessage("No orphaned post ngram entries found.");
        }

        // ===== CLEANUP ORPHANED CATEGORIES =====
        // Get all published categories
        $publishedCategories = $this->dao->getPublishedCategories();
        $publishedCategoryIds = [];

        if (!empty($publishedCategories)) {
            foreach ($publishedCategories as $category) {
                /** @var object{term_id: int, url: string} $category */
                $publishedCategoryIds[] = (int)$category->term_id;
            }
        }

        // Get all category ngram entries
        $categoryNGramEntries = $wpdb->get_results(
            "SELECT DISTINCT id FROM {$ngramTable} WHERE type = 'category'"
        );

        if (!empty($categoryNGramEntries)) {
            $orphanedCategories = [];

            // Find category ngram entries that don't have corresponding published categories
            foreach ($categoryNGramEntries as $entry) {
                /** @var object{id: int} $entry */
                $entId = (int)$entry->id;
                if (!in_array($entId, $publishedCategoryIds)) {
                    $orphanedCategories[] = $entId;
                }
            }

            if (!empty($orphanedCategories)) {
                $this->logger->infoMessage("Found " . count($orphanedCategories) . " orphaned category ngram entries. Deleting...");

                // Delete orphaned category entries
                foreach ($orphanedCategories as $categoryId) {
                    $result = $wpdb->delete(
                        $ngramTable,
                        ['id' => $categoryId, 'type' => 'category'],
                        ['%d', '%s']
                    );

                    if ($result === false) {
                        $catDeleteError = is_string($wpdb->last_error) ? $wpdb->last_error : '';
                        $this->logger->errorMessage("Failed to delete orphaned category ngram entry ID {$categoryId}: " . $catDeleteError);
                        $stats['errors']++;
                    } else {
                        $stats['categories_deleted']++;
                    }
                }
            } else {
                $this->logger->debugMessage("No orphaned category ngram entries found.");
            }
        }

        $this->logger->infoMessage("Orphaned ngram cleanup complete: {$stats['posts_deleted']} posts deleted, {$stats['categories_deleted']} categories deleted, {$stats['errors']} errors.");

        return $stats;
    }

    /**
     * Build ngrams for all categories.
     * Should be called during initial setup or manual rebuild.
     *
     * @param int $batchSize Number of categories to process per batch (default: 50)
     * @return array<string, int> Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    function buildNGramsForCategories($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for categories...");

        $categories = $this->dao->getPublishedCategories();

        if (empty($categories)) {
            $this->logger->debugMessage("No published categories found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($categories as $category) {
            try {
                /** @var object{term_id: int, url: string} $category */
                $termId = (int)$category->term_id;
                $url = (string)$category->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping category {$termId} - no valid URL");
                    continue;
                }

                // Normalize URL
                $urlNormalized = $this->f->strtolower(trim($url));

                // Extract N-grams
                $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                // Store with type='category'
                $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'category');

                $stats['processed']++;
                if ($success) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to build ngram for category {$termId}: " . $e->getMessage());
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        $this->logger->infoMessage("Category N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Build ngrams for all tags.
     * Should be called during initial setup or manual rebuild.
     *
     * @param int $batchSize Number of tags to process per batch (default: 50)
     * @return array<string, int> Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    function buildNGramsForTags($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for tags...");

        $tags = $this->dao->getPublishedTags();

        if (empty($tags)) {
            $this->logger->debugMessage("No published tags found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($tags as $tag) {
            try {
                /** @var object{term_id: int, url: string} $tag */
                $termId = (int)$tag->term_id;
                $url = (string)$tag->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping tag {$termId} - no valid URL");
                    continue;
                }

                // Normalize URL
                $urlNormalized = $this->f->strtolower(trim($url));

                // Extract N-grams
                $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                // Store with type='tag'
                $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'tag');

                $stats['processed']++;
                if ($success) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to build ngram for tag {$termId}: " . $e->getMessage());
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        $this->logger->infoMessage("Tag N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Build ngrams for all content types (posts, pages, categories, tags).
     * This is the comprehensive rebuild that should be called from the Tools page.
     *
     * @param int $batchSize Number of items to process per batch
     * @return array<string, mixed> Combined statistics
     */
    function buildNGramsForAllContent($batchSize = 100) {
        $this->logger->infoMessage("Starting comprehensive N-gram cache build for all content types...");

        // Rebuild posts/pages (existing functionality)
        $postsStats = $this->rebuildNGramCache($batchSize, true);

        // Build categories
        $categoriesStats = $this->buildNGramsForCategories($batchSize);

        // Build tags
        $tagsStats = $this->buildNGramsForTags($batchSize);

        $totalStats = [
            'posts' => $postsStats,
            'categories' => $categoriesStats,
            'tags' => $tagsStats,
            'total_processed' => ($postsStats['processed'] ?? 0) + ($categoriesStats['processed'] ?? 0) + ($tagsStats['processed'] ?? 0),
            'total_success' => ($postsStats['success'] ?? 0) + ($categoriesStats['success'] ?? 0) + ($tagsStats['success'] ?? 0),
            'total_failed' => ($postsStats['failed'] ?? 0) + ($categoriesStats['failed'] ?? 0) + ($tagsStats['failed'] ?? 0)
        ];

        $this->logger->infoMessage("Comprehensive N-gram build complete: {$totalStats['total_processed']} total processed, {$totalStats['total_success']} success, {$totalStats['total_failed']} failed.");

        return $totalStats;
    }

    /**
     * Check if the plugin is network-activated in a multisite environment.
     *
     * @return bool True if network-activated, false otherwise
     */
    private function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Get an option value, using network-wide storage in multisite when network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Uses get_site_option() for network-wide state
     * - Single-site or per-site activation: Uses get_option() for site-specific state
     *
     * This ensures that N-gram rebuild state is shared across all sites in network-activated
     * scenarios, preventing race conditions and duplicate work.
     *
     * @param string $option_name The option name
     * @param mixed $default Default value if option doesn't exist
     * @return mixed The option value
     */
    private function getNetworkAwareOption($option_name, $default = false) {
        if ($this->isNetworkActivated()) {
            return get_site_option($option_name, $default);
        }
        return get_option($option_name, $default);
    }

    /**
     * Update an option value, using network-wide storage in multisite when network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Uses update_site_option() for network-wide state
     * - Single-site or per-site activation: Uses update_option() for site-specific state
     *
     * This ensures that N-gram rebuild state is shared across all sites in network-activated
     * scenarios, preventing race conditions and duplicate work.
     *
     * @param string $option_name The option name
     * @param mixed $value The value to store
     * @return bool True if updated successfully
     */
    private function updateNetworkAwareOption($option_name, $value) {
        if ($this->isNetworkActivated()) {
            return update_site_option($option_name, $value);
        }
        return update_option($option_name, $value);
    }

    /**
     * Count total pages for N-gram rebuild across all sites if network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Counts permalink cache entries across ALL sites in the network
     * - Single-site: Counts only current site's permalink cache entries
     *
     * This allows the rebuild process to accurately track progress when processing
     * pages from multiple sites.
     *
     * @return int Total number of pages to process
     */
    private function countTotalPagesForNGramRebuild() {
        global $wpdb;

        if (!$this->isNetworkActivated()) {
            // Single site: count only current site's pages
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
            return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");
        }

        // Multisite network-activated: count pages across all sites
        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        $totalPages = 0;

        foreach ($sites as $blog_id) {
            switch_to_blog($blog_id);
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
            $sitePages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");
            $totalPages += $sitePages;
            restore_current_blog();
        }

        return $totalPages;
    }
}
