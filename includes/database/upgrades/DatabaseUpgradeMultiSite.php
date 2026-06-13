<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseUpgradeMultiSite extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Schedule a background multisite batch operation.
     *
     * @param string $optionPrefix e.g. 'abj404_activation' or 'abj404_upgrade'
     * @param string $hookName     e.g. 'abj404_network_activation_background'
     * @param string $label        Human-readable label for log messages, e.g. 'activation'
     * @param int $alreadyProcessedBlogId Blog ID already processed on this request.
     * @return void
     */
    private function scheduleBackgroundMultisiteBatch(string $optionPrefix, string $hookName, string $label, int $alreadyProcessedBlogId): void {
        update_site_option($optionPrefix . '_processed_blogs', array($alreadyProcessedBlogId));
        update_site_option($optionPrefix . '_in_progress', true);

        if (abj_cron_scheduler()->nextScheduled($hookName)) {
            $this->logger->debugMessage("Background multisite $label already scheduled.");
            return;
        }

        $scheduled = abj_cron_scheduler()->scheduleSingle($hookName, 30);

        if ($scheduled === false) {
            $this->logger->errorMessage("Failed to schedule background multisite $label. Remaining sites will not be processed automatically.");
        } else {
            $this->logger->infoMessage("Background multisite $label scheduled successfully.");
        }
    }

    /**
     * Process a batch of multisite sites with the given per-site action.
     *
     * @param string $optionPrefix e.g. 'abj404_activation' or 'abj404_upgrade'
     * @param string $hookName     e.g. 'abj404_network_activation_background'
     * @param string $label        Human-readable label for log messages, e.g. 'activation'
     * @param callable $perSiteAction Called for each site (receives int $siteId).
     * @return bool True if all sites are done, false if more batches needed.
     */
    public function processMultisiteBatch(string $optionPrefix, string $hookName, string $label, callable $perSiteAction): bool {
        $processedBlogs = get_site_option($optionPrefix . '_processed_blogs', array());
        if (!is_array($processedBlogs)) {
            $processedBlogs = array();
        }

        $allSitesRaw = get_sites(array('fields' => 'ids', 'number' => 0));
        $allSites = array();
        if (is_array($allSitesRaw)) {
            foreach ($allSitesRaw as $siteId) {
                if (is_numeric($siteId)) {
                    $allSites[] = (int)$siteId;
                }
            }
        }
        $processedBlogIds = array();
        foreach ($processedBlogs as $blogId) {
            if (is_numeric($blogId)) {
                $processedBlogIds[] = (int)$blogId;
            }
        }
        $remainingSites = array_diff($allSites, $processedBlogIds);

        if (empty($remainingSites)) {
            delete_site_option($optionPrefix . '_processed_blogs');
            delete_site_option($optionPrefix . '_in_progress');
            $this->logger->infoMessage("Background multisite $label complete. All sites processed.");
            return true;
        }

        $batchSize = 10;
        $sitesToProcess = array_slice($remainingSites, 0, $batchSize);

        $this->logger->infoMessage(sprintf(
            "Processing multisite $label batch: %d sites (of %d remaining)",
            count($sitesToProcess),
            count($remainingSites)
        ));

        foreach ($sitesToProcess as $siteId) {
            try {
                switch_to_blog($siteId);
                $this->logger->debugMessage(sprintf("Processing $label for site ID %d...", $siteId));

                $perSiteAction((int)$siteId);

                $processedBlogs[] = $siteId;
                update_site_option($optionPrefix . '_processed_blogs', $processedBlogs);

                $this->logger->debugMessage(sprintf("Successfully processed $label for site ID %d", $siteId));
            } catch (Throwable $e) {
                $this->logger->errorMessage(sprintf(
                    "Failed to process $label for site ID %d: %s",
                    $siteId,
                    $e->getMessage()
                ));
                $processedBlogs[] = $siteId;
                update_site_option($optionPrefix . '_processed_blogs', $processedBlogs);
            } finally {
                restore_current_blog();
            }
        }

        $stillRemaining = count($remainingSites) - count($sitesToProcess);
        if ($stillRemaining > 0) {
            $this->logger->infoMessage(sprintf(
                "Batch complete. Rescheduling for %d remaining sites.",
                $stillRemaining
            ));
            abj_cron_scheduler()->scheduleSingle($hookName, 30);
            return false;
        } else {
            delete_site_option($optionPrefix . '_processed_blogs');
            delete_site_option($optionPrefix . '_in_progress');
            $this->logger->infoMessage("Background multisite $label complete. All sites processed.");
            return true;
        }
    }

    /**
     * Schedule a background activation for all network sites except the one that
     * was just activated synchronously.
     *
     * @param int $alreadyProcessedBlogId Blog ID of the site already activated.
     * @return void
     */
    public function scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_activation',
            ABJ_404_Solution_CronScheduler::HOOK_NETWORK_ACTIVATION_BACKGROUND,
            'activation',
            $alreadyProcessedBlogId
        );
    }

    /**
     * Process multisite activation in batches (called by WP-Cron).
     *
     * Processes remaining sites that weren't handled during initial activation.
     * Processes up to 10 sites per run to avoid timeouts, then reschedules itself
     * if more sites remain.
     *
     * @return bool True if all sites processed, false if more remain
     */
    public function processMultisiteActivationBatch(): bool {
        return $this->processMultisiteBatch(
            'abj404_activation',
            ABJ_404_Solution_CronScheduler::HOOK_NETWORK_ACTIVATION_BACKGROUND,
            'activation',
            function (int $siteId): void {
                add_option('abj404_settings', '', '', false);

                $this->upgrades()->bootstrapUpgrade()->runInitialCreateTables();
                $this->upgrades()->collationDriftUpgrade()->correctCollations();
                $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
                $this->upgrades()->indexesUpgrade()->createIndexes();
                $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();
                $this->upgrades()->bootstrapUpgrade()->renameAbj404TablesToLowerCase();

                // Canonical self-heal prologue runs after schema creation so
                // SelfHealingPrologueReachabilityTest sees per-subsite activation
                // reach the same recovery primitives as the daily cron.
                $this->upgrades()->selfHealUpgrade()->runSelfHealPrologue();

                $this->logic->registerCrons();

                abj_service('version_upgrade')->stampDbVersion();
            }
        );
    }

    /**
     * Schedule a background upgrade for all network sites except the one that
     * was just upgraded synchronously.
     *
     * @param int $alreadyProcessedBlogId Blog ID of the site already upgraded.
     * @return void
     */
    public function scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_upgrade',
            ABJ_404_Solution_CronScheduler::HOOK_NETWORK_UPGRADE_BACKGROUND,
            'upgrade',
            $alreadyProcessedBlogId
        );
    }

    /**
     * Process multisite plugin upgrade in batches (called by WP-Cron).
     *
     * Upgrades remaining sites that weren't handled during the initial upgrade.
     * Processes up to 10 sites per run to avoid timeouts, then reschedules itself
     * if more sites remain.
     *
     * @return bool True if all sites processed, false if more remain.
     */
    public function processMultisiteUpgradeBatch(): bool {
        return $this->processMultisiteBatch(
            'abj404_upgrade',
            ABJ_404_Solution_CronScheduler::HOOK_NETWORK_UPGRADE_BACKGROUND,
            'upgrade',
            function (int $siteId): void {
                // Run the full upgrade sequence for this site without going through
                // createDatabaseTables() — that would re-schedule more background tasks.
                $this->upgrades()->tableRepairUpgrade()->correctIssuesBefore();
                $this->upgrades()->bootstrapUpgrade()->runInitialCreateTables();
                $this->upgrades()->collationDriftUpgrade()->correctCollations();
                $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
                $this->upgrades()->indexesUpgrade()->createIndexes();
                $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();
                $this->upgrades()->bootstrapUpgrade()->renameAbj404TablesToLowerCase();
                $this->upgrades()->tableRepairUpgrade()->correctIssuesAfter();

                // Canonical self-heal prologue closes the per-subsite upgrade
                // batch so SelfHealingPrologueReachabilityTest can prove the
                // multisite upgrade path reaches the same recovery primitives
                // as the daily cron tick.
                $this->upgrades()->selfHealUpgrade()->runSelfHealPrologue();

                abj_service('version_upgrade')->stampDbVersion();
            }
        );
    }

    /**
     * Create tables for all sites in a multisite network.
     *
     * This function iterates through all sites in the network and creates
     * the plugin's database tables for each site. This ensures that when
     * the plugin is network-activated, all sites have the necessary tables.
     *
     * @since 3.0.1
     */
    /** @return void */
    public function createTablesForAllSites() {
        global $wpdb;

        // Get all sites in the network
        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        $totalSites = count($sites);
        $successCount = 0;
        $failureCount = 0;

        $this->logger->infoMessage(sprintf(
            "Starting network-wide table creation for %d sites.",
            $totalSites
        ));

        foreach ($sites as $siteId) {
            try {
                // Switch to the site
                switch_to_blog($siteId);

                $currentPrefix = $wpdb->prefix;
                $this->logger->debugMessage(sprintf(
                    "Creating tables for site ID %d (prefix: %s)...",
                    $siteId,
                    $currentPrefix
                ));

                // Create tables for this site
                $this->upgrades()->bootstrapUpgrade()->runInitialCreateTables();
                $this->upgrades()->collationDriftUpgrade()->correctCollations();
                $this->upgrades()->engineNormalizationUpgrade()->updateTableEngineToInnoDB();
                $this->upgrades()->indexesUpgrade()->createIndexes();
                $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl();

                $successCount++;
                $this->logger->debugMessage(sprintf(
                    "Successfully created tables for site ID %d (prefix: %s)",
                    $siteId,
                    $currentPrefix
                ));

            } catch (Throwable $e) {
                $failureCount++;
                $this->logger->errorMessage(sprintf(
                    "Failed to create tables for site ID %d (prefix: %s): %s",
                    $siteId,
                    $wpdb->prefix,
                    $e->getMessage()
                ));
            } finally {
                // Always restore blog context
                restore_current_blog();
            }
        }

        // Log summary
        $this->logger->infoMessage(sprintf(
            "Network-wide table creation complete: %d successful, %d failed out of %d total sites.",
            $successCount,
            $failureCount,
            $totalSites
        ));

        if ($failureCount > 0) {
            $this->logger->errorMessage(sprintf(
                "Warning: Table creation failed for %d sites. Check error logs for details.",
                $failureCount
            ));
        }
    }

}
