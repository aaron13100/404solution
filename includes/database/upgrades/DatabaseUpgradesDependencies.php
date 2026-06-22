<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dependency bundle for {@see ABJ_404_Solution_DatabaseUpgradesEtc}.
 *
 * Core upgrade services resolve through the service container when omitted,
 * matching the legacy facade constructor defaults. Late n-gram collaborators
 * stay nullable because the n-gram delegate can lazily build concrete
 * collaborators or accept behavior-based test seams.
 */
final class ABJ_404_Solution_DatabaseUpgradesDependencies {

    /** @var ABJ_404_Solution_DataAccess */
    private $dataAccess;
    /** @var bool */
    private $dataAccessInjected;
    /** @var ABJ_404_Solution_Logging */
    private $logging;
    /** @var ABJ_404_Solution_Functions */
    private $functions;
    /** @var ABJ_404_Solution_PermalinkCache */
    private $permalinkCache;
    /** @var ABJ_404_Solution_SynchronizationUtils */
    private $syncUtils;
    /** @var ABJ_404_Solution_PluginLogicInterface */
    private $pluginLogic;
    /** @var ABJ_404_Solution_NGramFilter */
    private $ngramFilter;
    /** @var mixed */
    private $ngramExtractor;
    /** @var mixed */
    private $ngramCacheRepository;
    /** @var mixed */
    private $ngramCoveragePolicy;
    /** @var mixed */
    private $ngramRebuilder;
    /** @var ABJ_404_Solution_CronScheduler|null */
    private $cronScheduler;

    /**
     * @param array<string, mixed> $overrides Optional collaborators keyed by
     *        dataAccess, logging, functions, permalinkCache, syncUtils,
     *        pluginLogic, ngramFilter, ngramExtractor, ngramCacheRepository,
     *        ngramCoveragePolicy, ngramRebuilder, and cronScheduler.
     */
    public function __construct(array $overrides = array()) {
        $this->dataAccessInjected = array_key_exists('dataAccess', $overrides) && $overrides['dataAccess'] !== null;
        $this->dataAccess = $this->resolveService($overrides, 'dataAccess', 'data_access', ABJ_404_Solution_DataAccess::class);
        $this->logging = $this->resolveService($overrides, 'logging', 'logging', ABJ_404_Solution_Logging::class);
        $this->functions = $this->resolveService($overrides, 'functions', 'functions', ABJ_404_Solution_Functions::class);
        $this->permalinkCache = $this->resolveService($overrides, 'permalinkCache', 'permalink_cache', ABJ_404_Solution_PermalinkCache::class);
        $this->syncUtils = $this->resolveService($overrides, 'syncUtils', 'sync_utils', ABJ_404_Solution_SynchronizationUtils::class);
        $this->pluginLogic = $this->resolveService($overrides, 'pluginLogic', 'plugin_logic', ABJ_404_Solution_PluginLogicInterface::class);
        $this->ngramFilter = $this->resolveService($overrides, 'ngramFilter', 'ngram_filter', ABJ_404_Solution_NGramFilter::class);
        $this->ngramExtractor = $overrides['ngramExtractor'] ?? null;
        $this->ngramCacheRepository = $overrides['ngramCacheRepository'] ?? null;
        $this->ngramCoveragePolicy = $overrides['ngramCoveragePolicy'] ?? null;
        $this->ngramRebuilder = $overrides['ngramRebuilder'] ?? null;
        $cronScheduler = $overrides['cronScheduler'] ?? null;
        $this->cronScheduler = $cronScheduler instanceof ABJ_404_Solution_CronScheduler ? $cronScheduler : null;
    }

    /**
     * @param array<string, mixed> $overrides
     * @template T of object
     * @param class-string<T> $expectedClass
     * @return T
     */
    private function resolveService(array $overrides, string $key, string $serviceName, string $expectedClass) {
        $service = array_key_exists($key, $overrides) && $overrides[$key] !== null
            ? $overrides[$key]
            : abj_service($serviceName);

        if ($service instanceof $expectedClass) {
            return $service;
        }

        $actual = is_object($service) ? get_class($service) : gettype($service);
        throw new InvalidArgumentException("DatabaseUpgradesDependencies {$key} must resolve to {$expectedClass}; got {$actual}.");
    }

    /** @return ABJ_404_Solution_DataAccess */
    public function getDataAccess() { return $this->dataAccess; }

    /** @return bool */
    public function hasDataAccessOverride(): bool { return $this->dataAccessInjected; }

    /** @return ABJ_404_Solution_Logging */
    public function getLogging() { return $this->logging; }

    /** @return ABJ_404_Solution_Functions */
    public function getFunctions() { return $this->functions; }

    /** @return ABJ_404_Solution_PermalinkCache */
    public function getPermalinkCache() { return $this->permalinkCache; }

    /** @return ABJ_404_Solution_SynchronizationUtils */
    public function getSyncUtils() { return $this->syncUtils; }

    /** @return ABJ_404_Solution_PluginLogicInterface */
    public function getPluginLogic() { return $this->pluginLogic; }

    /** @return ABJ_404_Solution_NGramFilter */
    public function getNGramFilter() { return $this->ngramFilter; }

    /** @return mixed */
    public function getNGramExtractor() { return $this->ngramExtractor; }

    /** @return mixed */
    public function getNGramCacheRepository() { return $this->ngramCacheRepository; }

    /** @return mixed */
    public function getNGramCoveragePolicy() { return $this->ngramCoveragePolicy; }

    /** @return mixed */
    public function getNGramRebuilder() { return $this->ngramRebuilder; }

    /**
     * Optional cron scheduler override. Null in production so the n-gram
     * rebuild scheduler falls back to abj_cron_scheduler(); tests inject a
     * recording double to assert (or suppress) WP-Cron scheduling without
     * touching the WordPress cron API.
     *
     * @return ABJ_404_Solution_CronScheduler|null
     */
    public function getCronScheduler() { return $this->cronScheduler; }
}
