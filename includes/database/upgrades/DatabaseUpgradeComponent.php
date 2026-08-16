<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared dependency carrier for DatabaseUpgradesEtc delegates.
 */
abstract class ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $owner;

    /** @var ABJ_404_Solution_DataAccess */
    protected $dao;

    /** @var ABJ_404_Solution_DatabaseCore */
    protected $dbCore;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    protected $contentRepo;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    protected $viewRead;

    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    protected $logsRepo;

    /** @var ABJ_404_Solution_PluginUpdateMetadataRepository */
    protected $pluginUpdateRepo;

    /** @var ABJ_404_Solution_Logging */
    protected $logger;

    /** @var ABJ_404_Solution_Functions */
    protected $f;

    /** @var ABJ_404_Solution_PermalinkCache */
    protected $permalinkCache;

    /** @var ABJ_404_Solution_SynchronizationUtils */
    protected $syncUtils;

    /** @var ABJ_404_Solution_PluginLogicInterface */
    protected $logic;

    /** @var ABJ_404_Solution_NGramFilter */
    protected $ngramFilter;

    /** @var mixed */
    protected $ngramExtractor;

    /** @var mixed */
    protected $ngramCacheRepository;

    /** @var mixed */
    protected $ngramCoveragePolicy;

    /** @var mixed */
    protected $ngramRebuilder;

    /** @var ABJ_404_Solution_CronScheduler|null Optional injected cron scheduler; null falls back to abj_cron_scheduler(). */
    protected $cronScheduler;

    /**
     * @param array<string, mixed> $deps
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $owner, array $deps) {
        $this->owner = $owner;
        $this->replaceDatabaseUpgradeDependencies($deps);
    }

    /**
     * @param array<string, mixed> $deps
     * @return void
     */
    public function replaceDatabaseUpgradeDependencies(array $deps) {
        foreach ($deps as $name => $value) {
            $this->$name = $value;
        }
    }

    protected function upgrades(): ABJ_404_Solution_DatabaseUpgradeCoordinator {
        return $this->owner;
    }

    /**
     * Case-insensitive "does this column exist" probe. Delegates to the
     * canonical-url backfill component's implementation so the SHOW COLUMNS
     * probe has a single source of truth. Components that own the real probe
     * (the canonical-url backfill) override this; every other component inherits
     * this shared delegator.
     *
     * @param string $tableName  Fully-qualified table name.
     * @param string $columnName Column to look for.
     * @return bool
     */
    public function columnExists(string $tableName, string $columnName): ?bool {
        return $this->upgrades()->canonicalUrlBackfillUpgrade()->columnExists($tableName, $columnName);
    }

    /**
     * Whether a failed schema statement failed only because the schema already
     * reflects the change it asked for.
     *
     * Every "ensure it exists" helper in this package is a SHOW COLUMNS or
     * SHOW INDEX followed by an ALTER, and MySQL has no way to make those two
     * one statement. On a plugin update that arrives on several concurrent
     * requests at once, more than one of them decides the same column or index
     * is missing and issues the same ALTER; all but one are then told the work
     * is already done. That is the state the helper wanted, so the answer is to
     * stop -- not to retry a statement whose only obstacle is its own goal
     * having been met.
     *
     * Lives here rather than in each helper so the several fallback ladders ask
     * the classifier one way. The classification itself belongs to
     * {@see ABJ_404_Solution_DatabaseSchemaErrorTaxonomy::isRedundantSchemaChangeError()},
     * which is also what stops the shared DAO reporter mailing these to the
     * developer.
     *
     * @param string $lastError What the engine said, or '' when it said nothing.
     * @return bool
     */
    protected function schemaChangeWasAlreadyApplied(string $lastError): bool {
        if ($lastError === '') {
            return false;
        }
        return $this->dbCore->errorClassifier()->taxonomy()->schema()
            ->isRedundantSchemaChangeError($lastError);
    }

    /**
     * Read a resumable id cursor from a WordPress option, clamped to a
     * non-negative int. Shared by the chunked backfill drains so the cursor I/O
     * has one definition.
     *
     * @param string $option
     * @return int
     */
    protected function readCursorOption(string $option): int {
        if ($option === '' || !function_exists('get_option')) {
            return 0;
        }
        $raw = get_option($option, 0);
        return max(0, is_scalar($raw) ? (int)$raw : 0);
    }

    /**
     * Persist a resumable id cursor to a WordPress option (autoload=false,
     * non-negative). Shared by the chunked backfill drains.
     *
     * @param string $option
     * @param int $cursor
     * @return void
     */
    protected function writeCursorOption(string $option, int $cursor): void {
        if ($option === '' || !function_exists('update_option')) {
            return;
        }
        update_option($option, (string)max(0, $cursor), false);
    }

    /** @return string|null */
    protected function getUpgradeRuntimeId() {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getRuntimeId();
    }

    protected function getCanonicalUrlBackfillChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_CHUNK_SIZE;
    }

    protected function getCanonicalUrlBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getLogsv2CanonicalUrlBackfillCompleteOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
    }

    protected function getRedirectsCanonicalUrlBackfillCompleteOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
    }

    protected function getRedirectsDenormBackfillChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE;
    }

    protected function getRedirectsDenormBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getRedirectsDenormReconcileChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_CHUNK_SIZE;
    }

    protected function getRedirectsDenormReconcileTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_TIME_BUDGET_SEC;
    }

    protected function getRedirectsDenormReconcileCursorOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_CURSOR_OPTION;
    }

    /** @return array<int, string> */
    protected function getPluginTableSuffixes(): array {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getPluginTableSuffixes();
    }

    /**
     * Lowercased table prefixes of every active site on a multisite network,
     * including the current site. On single-site this is just the current
     * prefix.
     *
     * This is the authoritative tenant-isolation boundary for the cross-prefix
     * maintenance paths (orphan-table adoption and the mixed-case lowercase
     * rename). A live blog's tables are never an "orphan" to adopt or a table
     * the current site should rename: a sibling subsite lowercases and owns its
     * own tables. A genuinely orphaned old prefix (left by a prefix migration)
     * has NO corresponding live blog, so it is absent from this set and remains
     * eligible.
     *
     * When the WordPress multisite APIs are unavailable the multisite branch is
     * skipped and only the current prefix is returned; callers must treat that
     * as "could not enumerate siblings" and fall back to their content
     * heuristic, never as "no siblings exist".
     *
     * @return array<int, string> Distinct lowercase prefixes, e.g. ['wp_', 'wp_2_'].
     */
    protected function getActiveBlogPrefixesLowercase(): array {
        global $wpdb;
        $prefixes = array();

        if (is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            $prefixes[] = strtolower($wpdb->prefix);
        }

        if (function_exists('is_multisite') && is_multisite()
                && function_exists('get_sites')
                && is_object($wpdb) && is_callable(array($wpdb, 'get_blog_prefix'))) {
            $siteIds = get_sites(array('number' => 0, 'fields' => 'ids'));
            if (is_array($siteIds)) {
                foreach ($siteIds as $siteId) {
                    $prefix = $wpdb->get_blog_prefix((int)$siteId);
                    if (is_string($prefix) && $prefix !== '') {
                        $prefixes[] = strtolower($prefix);
                    }
                }
            }
        }

        return array_values(array_unique($prefixes));
    }

}
