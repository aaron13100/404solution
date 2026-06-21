<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the plugin version upgrade orchestration: detect a version mismatch,
 * acquire the upgrade synchronizer lock, invalidate opcache for files whose
 * APIs may have changed across versions, run DDL upgrades, run versioned
 * data migrations, stamp DB_VERSION, and refresh the permalink cache.
 *
 * Extracted from PluginLogic so the upgrade pipeline can be exercised and
 * reasoned about as a single service rather than tangled into the
 * options/redirect orchestration class. Composed (not inherited).
 */
class ABJ_404_Solution_PluginLogicVersionUpgrader {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var object */
    private $dbCore;

    /** @var string Log-context id for correlating upgrade messages. */
    private $uniqID;

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param object $dbCore
     */
    public function __construct(
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger,
        object $dbCore
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->dbCore = $dbCore;
        $this->uniqID = uniqid('', true);
    }

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (function_exists('abj_service')) {
            $dao = abj_service('data_access');
            self::$instance = new self(
                self::functions(),
                self::logging(),
                self::dbCoreFromService($dao)
            );
            return self::$instance;
        }
        throw new \RuntimeException(
            'PluginLogicVersionUpgrader::getInstance() requires the service container helper '
            . 'abj_service() to be loaded.'
        );
    }

    /**
     * Synchronized entry point: refresh opcache, acquire lock, run the
     * upgrade action, then refresh the permalink cache.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function upgradeIfNeeded(array $options) {
        self::invalidateOpcacheForCriticalFiles();

        $syncUtils = self::syncUtils();

        $synchronizedKeyFromUser = 'update_db_version';
        $uniqueID = $syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

        if ($uniqueID == '' || $uniqueID == null) {
            $this->logger->debugMessage('Avoiding infinite loop on database update.');
            return $options;
        }

        $returnValue = $options;

        try {
            $returnValue = $this->runUpgradeAction($options);
        } catch (Throwable $e) {
            $this->logger->errorMessage('Error updating to new version. ', $e instanceof \Exception ? $e : null);
            throw $e;
        } finally {
            $syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
        }

        $permalinkCache = self::permalinkCache();
        $permalinkCache->updatePermalinkCache(1);

        return $returnValue;
    }

    /**
     * The unsynchronized upgrade body: schema creation, cron re-registration,
     * versioned migrations, DB_VERSION stamp. Public so integration tests can
     * exercise migration branches without taking the synchronizer lock.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runUpgradeAction(array $options) {
        $options = array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $options);

        $currentDBVersion = self::currentDbVersionFromOptions($options);
        $this->logger->infoMessage($this->uniqID . ': Updating database version from ' .
            $currentDBVersion . ' to ' . ABJ404_VERSION . ' (begin).');

        ABJ_404_Solution_FileSystemService::deleteDirectoryRecursively(ABJ404_PATH . 'temp/');
        $this->createDatabaseTables();
        $this->refreshUpgradeCrons();

        $pluginLogic = self::pluginLogic();
        $this->migrateIgnoredUserAgents($options, $currentDBVersion, $pluginLogic);
        $this->migrateLegacyLogsTable($currentDBVersion);
        $this->migrateIgnoredFolders($options, $currentDBVersion, $pluginLogic);
        $this->normalizeDest404Page($options, $pluginLogic);
        $this->markSetupCompletedForExistingInstall($currentDBVersion);
        $this->migrateSuggestMinScoreEnabled($options, $pluginLogic);
        $this->migrateDest404Behavior($options, $pluginLogic);

        $options = $this->stampDbVersion($options);
        $this->logger->infoMessage($this->uniqID . ': Updating database version to ' .
            ABJ404_VERSION . ' (end).');

        return $options;
    }

    /**
     * Stamp DB_VERSION on the options array and persist it. Called both as the
     * tail of runUpgradeAction() and directly from activation paths
     * (PluginLogicLifecycle::activateSingleSite, DatabaseUpgradeMultiSite) so
     * a freshly-activated site records the running version without going
     * through the full upgrade action.
     *
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function stampDbVersion($options = null): array {
        $pluginLogic = self::pluginLogic();
        if ($options == null) {
            $options = abj_service('options_repository')->getOptions(true);
        }

        $options['DB_VERSION'] = ABJ404_VERSION;

        abj_service('options_repository')->updateOptions($options);

        return $options;
    }

    /** @param array<string, mixed> $options */
    private static function currentDbVersionFromOptions(array $options): string {
        if (array_key_exists('DB_VERSION', $options) && is_string($options['DB_VERSION'])) {
            return $options['DB_VERSION'];
        }
        return '(unknown)';
    }

    /** @return void */
    private function createDatabaseTables(): void {
        $upgradesEtc = abj_service('database_upgrades');
        if (!is_object($upgradesEtc)
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'runSelfHealPrologue')
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'createDatabaseTables')) {
            $this->logger->warn('Service "database_upgrades" does not expose upgrade methods.');
        }
        if (!is_object($upgradesEtc)
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'runSelfHealPrologue')
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'createDatabaseTables')) {
            throw new \RuntimeException('Service "database_upgrades" does not expose upgrade methods.');
        }
        $upgradesEtc->components()->selfHealUpgrade()->runSelfHealPrologue();
        $upgradesEtc->components()->bootstrapUpgrade()->createDatabaseTables(true);
    }

    /** @return void */
    private function refreshUpgradeCrons(): void {
        abj_cron_scheduler()->clearHook(ABJ_404_Solution_CronScheduler::HOOK_DUPLICATE_LEGACY);

        ABJ_404_Solution_PluginLogicLifecycle::doUnregisterCrons();
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }

    /**
     * @param mixed $service
     */
    private function databaseUpgradeServiceCanInvoke($service, string $method): bool {
        return is_object($service)
            && (method_exists($service, $method) || method_exists($service, '__call'));
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateIgnoredUserAgents(
        array &$options,
        string $currentDBVersion,
        ABJ_404_Solution_PluginLogic $pluginLogic
    ): void {
        if (version_compare($currentDBVersion, '1.9.0') >= 0) {
            return;
        }

        $ignoreDoProcessStr = is_string($options['ignore_doprocess']) ? $options['ignore_doprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDoProcessStr);

        $uasForSearch = $this->f->explodeNewline($ignoreDoProcessStr);

        foreach ($userAgents as &$str) {
            if ($this->f->strtolower(trim($str)) == 'slurp') {
                $str = 'Yahoo! Slurp';
                $this->logger->infoMessage('Changed user agent "Slurp" to "Yahoo! Slurp" in the do not log list.');
            }
        }

        if (!in_array('seznambot', $uasForSearch)) {
            $userAgents[] = 'SeznamBot';
            $this->logger->infoMessage('Added user agent "SeznamBot" to do not log list."');
        }
        if (!in_array('pinterestbot', $uasForSearch)) {
            $userAgents[] = 'Pinterestbot';
            $this->logger->infoMessage('Added user agent "Pinterestbot" to do not log list."');
        }
        if (!in_array('uptimerobot', $uasForSearch)) {
            $userAgents[] = 'UptimeRobot';
            $this->logger->infoMessage('Added user agent "UptimeRobot" to do not log list."');
        }

        $options['ignore_doprocess'] = implode("\n", $userAgents);
        abj_service('options_repository')->updateOptions($options);
    }

    /** @return void */
    private function migrateLegacyLogsTable(string $currentDBVersion): void {
        if (version_compare($currentDBVersion, '1.8.0') >= 0) {
            return;
        }
        // Refuse to run from cron. Migration is operator-driven (admin upgrade path).
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }

        $query = "SHOW TABLES LIKE '{wp_abj404_logs}'";
        $dbCore = $this->dbCore;
        if (!$dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface
            || !$dbCore instanceof ABJ_404_Solution_DatabaseCoreInterface) {
            throw new \RuntimeException('PluginLogicVersionUpgrader requires database query and table-name resolver methods.');
        }

        $result = $dbCore->queryAndGetResults($query);
        $rows = isset($result['rows']) ? $result['rows'] : array();

        $filteredRows = is_array($rows) ? array_filter($rows) : array();
        if (empty($filteredRows)) {
            return;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/migrateToNewLogsTable.sql');
        $query = $dbCore->doTableNameReplacements($query);
        $result = $dbCore->queryAndGetResults($query);

        $rowsAffected = isset($result['rows_affected']) && is_numeric($result['rows_affected'])
            ? (int)$result['rows_affected']
            : 0;
        // The early-return at the top of this function ensures
        // $currentDBVersion < '1.8.0' here, so the version gate that previously
        // wrapped this block has been removed (PHPStan smaller.alwaysTrue).
        if ($rowsAffected > 0) {
            $this->logger->infoMessage($rowsAffected .
                ' log rows were migrated to the new table structre.');
            $dbCore->queryAndGetResults('drop table ' . $dbCore->tableNameResolver()->getLowercasePrefix() . 'abj404_logs');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateIgnoredFolders(
        array &$options,
        string $currentDBVersion,
        ABJ_404_Solution_PluginLogic $pluginLogic
    ): void {
        if (version_compare($currentDBVersion, '2.18.0') >= 0) {
            return;
        }

        $foldersIgnoreStr = is_string($options['folders_files_ignore']) ? $options['folders_files_ignore'] : '';
        $originalItems = $this->f->explodeNewline($foldersIgnoreStr);

        $newItems = array('wp-content/plugins/*', 'wp-content/themes/*', '.well-known/acme-challenge/*');
        foreach ($newItems as $newItem) {
            if (array_search($newItem, $originalItems) === false) {
                $originalItems[] = $newItem;
                $this->logger->infoMessage('Added ' . $newItem . ' to the list of folders to ignore."');
            }
        }

        $options['folders_files_ignore'] = implode("\n", $originalItems);
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function normalizeDest404Page(array &$options, ABJ_404_Solution_PluginLogic $pluginLogic): void {
        $dest404page = is_string($options['dest404page']) ? $options['dest404page'] : '';
        if ($this->f->strpos($dest404page, '|') !== false) {
            return;
        }

        if ($dest404page == '0') {
            $dest404page .= '|' . ABJ404_TYPE_404_DISPLAYED;
        } else {
            $dest404page .= '|' . ABJ404_TYPE_POST;
        }
        $options['dest404page'] = $dest404page;
        abj_service('options_repository')->updateOptions($options);
    }

    /** @return void */
    private function markSetupCompletedForExistingInstall(string $currentDBVersion): void {
        if ($currentDBVersion === '0.0.0' || version_compare($currentDBVersion, '3.0.7') >= 0) {
            return;
        }

        // @cache-write-audit: opt-out - stores a setup-completion date marker, not a query result
        update_option('abj404_setup_completed', gmdate('Y-m-d', abj_clock()->now()));
        $this->logger->infoMessage('Marked setup wizard as completed for existing user.');
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateSuggestMinScoreEnabled(array &$options, ABJ_404_Solution_PluginLogic $pluginLogic): void {
        if (isset($options['suggest_minscore_enabled'])) {
            return;
        }

        if (isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) && intval($options['suggest_minscore']) >= 25) {
            $options['suggest_minscore_enabled'] = '1';
            $this->logger->infoMessage('Enabled minimum score filtering based on existing suggest_minscore setting.');
        } else {
            $options['suggest_minscore_enabled'] = '0';
        }
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateDest404Behavior(array &$options, ABJ_404_Solution_PluginLogic $pluginLogic): void {
        if (isset($options['dest404_behavior']) && $options['dest404_behavior'] !== 'theme_default') {
            return;
        }

        $dest = is_string($options['dest404page']) ? $options['dest404page'] : '';
        $options['dest404_behavior'] = self::dest404BehaviorFromDestination($dest);
        abj_service('options_repository')->updateOptions($options);
    }

    private static function dest404BehaviorFromDestination(string $dest): string {
        if ($dest === '0|' . ABJ404_TYPE_404_DISPLAYED || $dest === (string)ABJ404_TYPE_404_DISPLAYED || $dest === '') {
            return 'theme_default';
        }
        if ($dest === '0|' . ABJ404_TYPE_HOME) {
            return 'homepage';
        }

        $parts = explode('|', $dest);
        $pageId = isset($parts[0]) ? (int)$parts[0] : 0;
        if ($pageId > 0 && ABJ_404_Solution_SystemPage::isSystemPage($pageId)) {
            return 'suggest';
        }
        return 'custom';
    }

    /** @return ABJ_404_Solution_Functions */
    private static function functions(): ABJ_404_Solution_Functions {
        return self::service('functions', ABJ_404_Solution_Functions::class);
    }

    /** @return ABJ_404_Solution_Logging */
    private static function logging(): ABJ_404_Solution_Logging {
        return self::service('logging', ABJ_404_Solution_Logging::class);
    }

    /** @return ABJ_404_Solution_SynchronizationUtils */
    private static function syncUtils(): ABJ_404_Solution_SynchronizationUtils {
        return self::service('sync_utils', ABJ_404_Solution_SynchronizationUtils::class);
    }

    /** @return ABJ_404_Solution_PermalinkCache */
    private static function permalinkCache(): ABJ_404_Solution_PermalinkCache {
        return self::service('permalink_cache', ABJ_404_Solution_PermalinkCache::class);
    }

    /** @return ABJ_404_Solution_PluginLogic */
    private static function pluginLogic(): ABJ_404_Solution_PluginLogic {
        return self::service('plugin_logic', ABJ_404_Solution_PluginLogic::class);
    }

    /**
     * @template T of object
     * @param string $name
     * @param class-string<T> $className
     * @return T
     */
    private static function service(string $name, string $className) {
        $service = abj_service($name);
        if (!$service instanceof $className) {
            throw new \RuntimeException('Service "' . $name . '" is not a ' . $className . ' instance.');
        }
        return $service;
    }

    /**
     * @param mixed $dao
     * @return object
     */
    private static function dbCoreFromService($dao): object {
        $dbCore = is_object($dao) && method_exists($dao, 'getDbCore') ? $dao->getDbCore() : $dao;
        if (!is_object($dbCore)) {
            throw new \RuntimeException('PluginLogicVersionUpgrader requires a database service object.');
        }
        return $dbCore;
    }

    /**
     * Invalidate opcache for files whose APIs callers depend on across an
     * upgrade. Runs BEFORE the synchronizer lock so a stale opcache copy of
     * Functions.php cannot survive the upgrade and cause a fatal in the next
     * request.
     *
     * @return string[] File paths that were successfully invalidated.
     */
    public static function invalidateOpcacheForCriticalFiles(): array {
        if (!function_exists('opcache_invalidate')) {
            return [];
        }

        $files = [
            ABJ404_PATH . 'includes/core/Functions.php',
            ABJ404_PATH . 'includes/php/MbStringAdapter.php',
            ABJ404_PATH . 'includes/php/MbStringAdapterMb.php',
            ABJ404_PATH . 'includes/php/MbStringAdapterPreg.php',
            ABJ404_PATH . 'includes/core/RegexHelper.php',
            ABJ404_PATH . 'includes/core/RegexHelperMb.php',
            ABJ404_PATH . 'includes/core/RegexHelperPreg.php',
            ABJ404_PATH . 'includes/core/QueryStringHelper.php',
            ABJ404_PATH . 'includes/php/FunctionsMBString.php',
            ABJ404_PATH . 'includes/php/FunctionsPreg.php',
        ];

        $invalidated = [];
        foreach ($files as $file) {
            if (is_file($file) && @opcache_invalidate($file, true)) {
                $invalidated[] = $file;
            }
        }

        return $invalidated;
    }
}
