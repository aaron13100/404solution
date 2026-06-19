<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by MultisiteNGramRaceConditionTest via the create-tables upgrade flow

/**
 * One-time initialization of the N-gram spell-check cache.
 *
 * Owns the "has the cache ever been built?" decision and, when it has not,
 * schedules an asynchronous WP-Cron rebuild (instead of blocking plugin
 * activation) and surfaces a "build scheduled" admin notice on a version
 * upgrade. Extracted from the table-bootstrap orchestrator because building
 * the spell-check cache is a distinct concern from creating the schema: it
 * has its own multisite-awareness (network-aware initialization flag), its
 * own admin-notice UX, and is reached only after the tables already exist.
 *
 * Collaborator of ABJ_404_Solution_DatabaseUpgradeBootstrap, which constructs
 * it with the upgrade coordinator (to reach the N-gram upgrade delegate) and
 * the plugin logger.
 */
class ABJ_404_Solution_DatabaseUpgradeNGramCacheInitializer {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $coordinator;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator,
            ABJ_404_Solution_Logging $logger) {
        $this->coordinator = $coordinator;
        $this->logger = $logger;
    }

    /**
     * If the N-gram cache has never been built, schedule an async rebuild via
     * WP-Cron and (on a version upgrade) show a "build scheduled" admin notice.
     * No-op once the cache is already initialized.
     *
     * @param bool $updatingToNewVersion Whether this run is a version upgrade
     *   (controls whether the admin notice is surfaced).
     * @return void
     */
    public function scheduleRebuildIfUninitialized($updatingToNewVersion) {
        // One-time N-gram cache initialization (async via WP-Cron to prevent blocking)
        // MULTISITE: Use network-aware option getter to check initialization status
        if ($this->coordinator->nGramUpgrade()->getNetworkAwareOption('abj404_ngram_cache_initialized') !== '1') {
            $this->logger->debugMessage("N-gram cache not initialized. Scheduling background build...");

            // Schedule async rebuild via WP-Cron instead of blocking activation
            $this->coordinator->nGramUpgrade()->scheduleNGramCacheRebuild();

            // Show admin notice that build is scheduled
            if ($updatingToNewVersion && function_exists('add_settings_error')) {
                $context = is_multisite() && $this->coordinator->nGramUpgrade()->isNetworkActivated() ? ' across all sites in the network' : '';
                $message = sprintf(
                    __('404 Solution: N-gram spell check cache is being built in the background%s to optimize performance. This may take a few minutes on large sites.', '404-solution'),
                    $context
                );
                add_settings_error('abj404_settings', 'ngram_cache_scheduled', $message, 'updated');
            }

            $this->logger->infoMessage("N-gram cache rebuild scheduled via WP-Cron.");
        } else {
            $this->logger->debugMessage("N-gram cache already initialized. Skipping rebuild.");
        }
    }
}
