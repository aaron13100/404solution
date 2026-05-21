<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DatabaseUpgradesEtc_MultiSiteTrait {

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

        if (wp_next_scheduled($hookName)) {
            $this->logger->debugMessage("Background multisite $label already scheduled.");
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 30, $hookName);

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

        $allSites = get_sites(array('fields' => 'ids', 'number' => 0));
        $remainingSites = array_diff($allSites, $processedBlogs);

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
            wp_schedule_single_event(time() + 30, $hookName);
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
    private function scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_activation', 'abj404_network_activation_background', 'activation', $alreadyProcessedBlogId
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
            'abj404_network_activation_background',
            'activation',
            function (int $siteId): void {
                add_option('abj404_settings', '', '', false);

                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();
                $this->renameAbj404TablesToLowerCase();

                // Canonical self-heal prologue runs after schema creation so
                // SelfHealingPrologueReachabilityTest sees per-subsite activation
                // reach the same recovery primitives as the daily cron.
                $this->runSelfHealPrologue();

                ABJ_404_Solution_PluginLogic::doRegisterCrons();

                $logic = abj_service('plugin_logic');
                $logic->doUpdateDBVersionOption();
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
    private function scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_upgrade', 'abj404_network_upgrade_background', 'upgrade', $alreadyProcessedBlogId
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
            'abj404_network_upgrade_background',
            'upgrade',
            function (int $siteId): void {
                // Run the full upgrade sequence for this site without going through
                // createDatabaseTables() — that would re-schedule more background tasks.
                $this->correctIssuesBefore();
                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();
                $this->renameAbj404TablesToLowerCase();
                $this->correctIssuesAfter();

                // Canonical self-heal prologue closes the per-subsite upgrade
                // batch so SelfHealingPrologueReachabilityTest can prove the
                // multisite upgrade path reaches the same recovery primitives
                // as the daily cron tick.
                $this->runSelfHealPrologue();

                $logic = abj_service('plugin_logic');
                $logic->doUpdateDBVersionOption();
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
    /**
     * @return void
     * @phpstan-ignore-next-line method.unused
     */
    private function createTablesForAllSites() {
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
                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();

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
