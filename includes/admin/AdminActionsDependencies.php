<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable dependency bundle passed to
 * {@see ABJ_404_Solution_PluginLogicAdminActions::__construct()}.
 *
 * Replaces a 10-positional-parameter constructor (audit source
 * design-audit-2026-05-29.md, criterion 220 Interface Size), matching the
 * RedirectSpec precedent from the same audit (queue task c738).
 *
 * Parameters are deliberately untyped: PluginLogic's constructor resolves
 * the collaborators by two different paths depending on whether $dao is the
 * production ABJ_404_Solution_DataAccess or a subclass test double (see
 * PluginLogic::__construct() if/else around the dao type check). Adding
 * interface type hints here would break the subclass-double path because
 * ABJ_404_Solution_DataAccess does not implement the repository interfaces
 * (post-i266/i775/i758: typed surfaces are obtained via $dao->getLogsRepo(),
 * $dao->getContentRepo(), etc.; the DataAccess facade itself no longer
 * carries those pass-throughs). Its subclass doubles wear $dao as each
 * repo without implementing those interfaces. The @var docblocks document
 * the resolved-type intent for static analysis.
 */
final class ABJ_404_Solution_AdminActionsDependencies {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;
    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewRead;
    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;
    /** @var ABJ_404_Solution_DatabaseCoreInterface&ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;
    /** @var ABJ_404_Solution_DataAccess */
    private $dao;
    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;
    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;
    /** @var ABJ_404_Solution_PluginUpdateMetadataRepository|null */
    private $pluginUpdateRepo;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewRead
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_DatabaseCoreInterface&ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_DataAccess $dao
     * @param ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_PluginUpdateMetadataRepository|null $pluginUpdateRepo
     */
    public function __construct(
        $f,
        $logger,
        $redirectsRepo,
        $viewRead,
        $contentRepo,
        $dbCore,
        $dao,
        $urlNormalization,
        $pluginLogic,
        $pluginUpdateRepo = null
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->redirectsRepo = $redirectsRepo;
        $this->viewRead = $viewRead;
        $this->contentRepo = $contentRepo;
        $this->dbCore = $dbCore;
        $this->dao = $dao;
        $this->urlNormalization = $urlNormalization;
        $this->pluginLogic = $pluginLogic;
        $this->pluginUpdateRepo = $pluginUpdateRepo;
    }

    /** @return ABJ_404_Solution_Functions */
    public function getFunctions() { return $this->f; }

    /** @return ABJ_404_Solution_Logging */
    public function getLogger() { return $this->logger; }

    /** @return ABJ_404_Solution_RedirectsRepositoryInterface */
    public function getRedirectsRepo() { return $this->redirectsRepo; }

    /** @return ABJ_404_Solution_ViewReadServiceInterface */
    public function getViewRead() { return $this->viewRead; }

    /** @return ABJ_404_Solution_ContentRepositoryInterface */
    public function getContentRepo() { return $this->contentRepo; }

    /** @return ABJ_404_Solution_DatabaseCoreInterface */
    public function getDbCore() { return $this->dbCore; }

    /** @return ABJ_404_Solution_DatabaseQueryInterface */
    public function getDbQuery() { return $this->dbCore; }

    /** @return ABJ_404_Solution_DataAccess */
    public function getDao() { return $this->dao; }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    public function getUrlNormalization() { return $this->urlNormalization; }

    /** @return ABJ_404_Solution_PluginLogic */
    public function getPluginLogic() { return $this->pluginLogic; }

    /**
     * Lazily resolves the repository when the bundle was constructed without
     * one (legacy DI sites that don't yet pass it). Production paths register
     * the service in bootstrap.php so the lookup hits the container.
     *
     * @return ABJ_404_Solution_PluginUpdateMetadataRepository
     */
    public function getPluginUpdateRepo() {
        if ($this->pluginUpdateRepo === null) {
            $resolved = abj_service('plugin_update_metadata_repository');
            /** @var ABJ_404_Solution_PluginUpdateMetadataRepository $resolved */
            $this->pluginUpdateRepo = $resolved;
        }
        return $this->pluginUpdateRepo;
    }
}
