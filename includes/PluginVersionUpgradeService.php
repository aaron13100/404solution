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
class ABJ_404_Solution_PluginVersionUpgradeService {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @var string Log-context id for correlating upgrade messages. */
    private $uniqID;

    /** @var self|null */
    private static $instance = null;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseCoreInterface $dbCore
     */
    public function __construct(
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger,
        $dbCore
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
                abj_service('functions'),
                abj_service('logging'),
                is_object($dao) && method_exists($dao, 'getDbCore') ? $dao->getDbCore() : $dao
            );
            return self::$instance;
        }
        throw new \RuntimeException(
            'PluginVersionUpgradeService::getInstance() requires the service container helper '
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

        $syncUtils = abj_service('sync_utils');

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

        $permalinkCache = abj_service('permalink_cache');
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
        global $wpdb;

        $options = array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $options);

        $currentDBVersion = '(unknown)';
        if (array_key_exists('DB_VERSION', $options) && is_string($options['DB_VERSION'])) {
            $currentDBVersion = $options['DB_VERSION'];
        }
        $this->logger->infoMessage($this->uniqID . ': Updating database version from ' .
            $currentDBVersion . ' to ' . ABJ404_VERSION . ' (begin).');

        $fileUtils = abj_service('functions');
        $fileUtils->deleteDirectoryRecursively(ABJ404_PATH . 'temp/');

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->runSelfHealPrologue();
        $upgradesEtc->createDatabaseTables(true);

        wp_clear_scheduled_hook('abj404_duplicateCronAction');

        ABJ_404_Solution_PluginLogicLifecycle::doUnregisterCrons();
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();

        $pluginLogic = abj_service('plugin_logic');

        if (version_compare($currentDBVersion, '1.9.0') < 0) {
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
            $pluginLogic->updateOptions($options);
        }

        if (version_compare($currentDBVersion, '1.8.0') < 0) {
            $query = "SHOW TABLES LIKE '{wp_abj404_logs}'";
            $result = $this->dbCore->queryAndGetResults($query);
            $rows = $result['rows'];

            $filteredRows = is_array($rows) ? array_filter($rows) : array();
            if (!empty($filteredRows)) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/sql/migrateToNewLogsTable.sql');
                $query = $this->dbCore->doTableNameReplacements($query);
                $result = $this->dbCore->queryAndGetResults($query);

                $rowsAffected = isset($result['rows_affected']) && is_numeric($result['rows_affected'])
                    ? (int)$result['rows_affected']
                    : 0;
                if ($rowsAffected > 0) {
                    $this->logger->infoMessage($rowsAffected .
                        ' log rows were migrated to the new table structre.');
                    $this->dbCore->queryAndGetResults('drop table ' . $this->dbCore->getLowercasePrefix() . 'abj404_logs');
                }
            }
        }

        if (version_compare($currentDBVersion, '2.18.0') < 0) {
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
            $pluginLogic->updateOptions($options);
        }

        $dest404page = is_string($options['dest404page']) ? $options['dest404page'] : '';
        if ($this->f->strpos($dest404page, '|') === false) {
            if ($dest404page == '0') {
                $dest404page .= '|' . ABJ404_TYPE_404_DISPLAYED;
            } else {
                $dest404page .= '|' . ABJ404_TYPE_POST;
            }
            $options['dest404page'] = $dest404page;
            $pluginLogic->updateOptions($options);
        }

        // @cache-write-audit: opt-out - stores a setup-completion date marker, not a query result
        if ($currentDBVersion !== '0.0.0' && version_compare($currentDBVersion, '3.0.7') < 0) {
            update_option('abj404_setup_completed', gmdate('Y-m-d'));
            $this->logger->infoMessage('Marked setup wizard as completed for existing user.');
        }

        if (!isset($options['suggest_minscore_enabled'])) {
            if (isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) && intval($options['suggest_minscore']) >= 25) {
                $options['suggest_minscore_enabled'] = '1';
                $this->logger->infoMessage('Enabled minimum score filtering based on existing suggest_minscore setting.');
            } else {
                $options['suggest_minscore_enabled'] = '0';
            }
            $pluginLogic->updateOptions($options);
        }

        if (!isset($options['dest404_behavior']) || $options['dest404_behavior'] === 'theme_default') {
            $dest = is_string($options['dest404page']) ? $options['dest404page'] : '';
            if ($dest === '0|' . ABJ404_TYPE_404_DISPLAYED || $dest === (string)ABJ404_TYPE_404_DISPLAYED || $dest === '') {
                $options['dest404_behavior'] = 'theme_default';
            } else if ($dest === '0|' . ABJ404_TYPE_HOME) {
                $options['dest404_behavior'] = 'homepage';
            } else {
                $parts = explode('|', $dest);
                $pageId = isset($parts[0]) ? (int)$parts[0] : 0;
                if ($pageId > 0 && ABJ_404_Solution_SystemPage::isSystemPage($pageId)) {
                    $options['dest404_behavior'] = 'suggest';
                } else {
                    $options['dest404_behavior'] = 'custom';
                }
            }
            $pluginLogic->updateOptions($options);
        }

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
        $pluginLogic = abj_service('plugin_logic');
        if ($options == null) {
            $options = $pluginLogic->getOptions(true);
        }

        $options['DB_VERSION'] = ABJ404_VERSION;

        $pluginLogic->updateOptions($options);

        return $options;
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
            ABJ404_PATH . 'includes/Functions.php',
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
