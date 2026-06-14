<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/../redirects/RedirectsRetentionService.php';
require_once __DIR__ . '/RedirectConditionsRepository.php';
require_once __DIR__ . '/RedirectExportReader.php';
require_once __DIR__ . '/RedirectLookupRepository.php';
require_once __DIR__ . '/RedirectRegexCacheStore.php';
require_once __DIR__ . '/../redirects/RedirectCanonicalUrl.php';
require_once __DIR__ . '/../redirects/RedirectLookupService.php';
require_once __DIR__ . '/RedirectWriteService.php';

/**
 * Redirect lookup repository and compatibility facade.
 *
 * Extracted from the DataAccess monolith (Phase 2 of the DataAccess refactor).
 * Methods originate from two sources:
 *   - DataAccessTrait_Redirects (entirely absorbed)
 *   - DataAccessTrait_Stats (redirect update/query methods relocated)
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_RedirectsRepository implements ABJ_404_Solution_RedirectsRepositoryInterface {

    /** Maximum number of regex redirects to cache per-request (memory guard) */
    const REGEX_CACHE_MAX_COUNT = ABJ_404_Solution_RedirectRegexCacheStore::MAX_COUNT;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectConditionsRepository */
    private $conditionsRepository;

    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCacheStore;

    /** @var ABJ_404_Solution_RedirectWriteService */
    private $writeService;

    /** @var ABJ_404_Solution_RedirectExportReader */
    private $exportReader;

    /** @var ABJ_404_Solution_RedirectLookupService */
    private $lookupService;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null,
        ?ABJ_404_Solution_RedirectConditionsRepository $conditionsRepository = null,
        ?ABJ_404_Solution_RedirectRegexCacheStore $regexCacheStore = null,
        ?ABJ_404_Solution_RedirectWriteService $writeService = null,
        ?ABJ_404_Solution_RedirectExportReader $exportReader = null,
        ?ABJ_404_Solution_RedirectLookupService $lookupService = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->conditionsRepository = $conditionsRepository !== null
            ? $conditionsRepository
            : new ABJ_404_Solution_RedirectConditionsRepository($dbCore, $this->logger);
        $this->regexCacheStore = $regexCacheStore !== null
            ? $regexCacheStore
            : new ABJ_404_Solution_RedirectRegexCacheStore();
        $this->writeService = $writeService !== null
            ? $writeService
            : new ABJ_404_Solution_RedirectWriteService(
                $dbCore,
                $this->f,
                $this->logger,
                $this->regexCacheStore,
                $urlNormalization
            );
        $this->exportReader = $exportReader !== null
            ? $exportReader
            : new ABJ_404_Solution_RedirectExportReader($dbCore);
        $this->lookupService = $lookupService !== null
            ? $lookupService
            : new ABJ_404_Solution_RedirectLookupService(
                $this->f,
                new ABJ_404_Solution_RedirectLookupRepository($dbCore, $this->f),
                $urlNormalization
            );
    }

    /**
     * Expose the underlying database-query service. Collaborators that accept a
     * repository and resolve their own query service (e.g.
     * ABJ_404_Solution_ForeignRedirectSourceReader::resolveDatabaseQuery())
     * probe for this accessor; without it they fall back to a null query
     * service and silently read zero rows. DatabaseCore implements
     * ABJ_404_Solution_DatabaseQueryInterface, so callers can use the result
     * directly. Mirrors ABJ_404_Solution_DataAccess::getDbCore().
     *
     * @return ABJ_404_Solution_DatabaseCore
     */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_RedirectsRetentionService */
    private function retentionService() {
        return new ABJ_404_Solution_RedirectsRetentionService($this->dbCore, $this, $this->f, $this->logger);
    }

    /** Legacy facade retained for integrations that still call RedirectsRepository directly. */
    function deleteOldRedirectsCron() {
        return $this->retentionService()->deleteOldRedirectsCron();
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @return int
     */
    private function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        return $this->retentionService()->deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType);
    }

    private function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        return $this->retentionService()->deleteOldLogsByAge($daysToKeep, $now);
    }

    // =========================================================================
    // Regex cache accessors (static state moved from DataAccess)
    // =========================================================================

    /** @inheritDoc */
    public function clearRegexRedirectsCache(): void {
        $this->regexCacheStore->clear();
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        return ABJ_404_Solution_RedirectRegexCacheStore::getRegexRedirectsCache();
    }

    /** @param array<int, array<string, mixed>>|null $cache @return void */
    public static function setRegexRedirectsCache($cache): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexRedirectsCache($cache);
    }

    /** @return bool */
    public static function isRegexCacheDisabled(): bool {
        return ABJ_404_Solution_RedirectRegexCacheStore::isRegexCacheDisabled();
    }

    /** @param bool $disabled @return void */
    public static function setRegexCacheDisabled(bool $disabled): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexCacheDisabled($disabled);
    }

    // =========================================================================
    // Static utilities (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public static function computeRedirectsCanonicalUrl($url): string {
        return ABJ_404_Solution_RedirectCanonicalUrl::compute($url);
    }

    /** @inheritDoc */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string {
        return ABJ_404_Solution_RedirectCanonicalUrl::hitsSqlExpression($columnExpr);
    }

    // =========================================================================
    // Redirect CRUD (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    function deleteRedirect($id) {
        $this->writeService->deleteRedirect($id);
    }

    /** @inheritDoc */
    public function getExportableRedirects(): array {
        return $this->exportReader->getExportableRedirects();
    }

    /** @inheritDoc */
    function setupRedirect(ABJ_404_Solution_RedirectSpec $spec) {
        return $this->writeService->setupRedirect($spec);
    }

    /** @inheritDoc */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        return $this->lookupService->getActiveRedirectForURL($url, $degradedMode);
    }

    /** @inheritDoc */
    function getExistingRedirectForURL($url) {
        return $this->lookupService->getExistingRedirectForURL($url);
    }

    /** @inheritDoc */
    function deleteSpecifiedRedirects(array $types, string $purgeType): array {
        return $this->writeService->deleteSpecifiedRedirects($types, $purgeType);
    }

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public function getRedirectConditions(int $redirectId): array {
        return $this->conditionsRepository->getRedirectConditions($redirectId);
    }

    /** @inheritDoc */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $this->conditionsRepository->saveRedirectConditions($redirectId, $conditions);
    }

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /** @inheritDoc */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        return $this->writeService->updateRedirect($update);
    }

    /** @inheritDoc */
    function getRedirectsByIDs($ids) {
        return $this->writeService->getRedirectsByIDs($ids);
    }

    /** @inheritDoc */
    function updateRedirectTypeStatus($id, $newstatus) {
        return $this->writeService->updateRedirectTypeStatus($id, $newstatus);
    }

    /** @inheritDoc */
    function moveRedirectsToTrash($id, $trash) {
        return $this->writeService->moveRedirectsToTrash($id, $trash);
    }

}
