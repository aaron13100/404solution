<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin action dispatcher + thin facade.
 *
 * Two roles:
 *
 *   1. Action-name -> handler-class registry. handlePluginAction() routes
 *      POST verbs (addRedirect, updateOptions, emptyRedirectTrash, bulk*, ...)
 *      to the matching class in includes/admin/actions/, centralizing the
 *      nonce + is_admin() guard so it cannot be forgotten when a new action
 *      is added.
 *
 *   2. Backward-compatible facade for non-dispatcher entry points
 *      (link-style $_GET actions called from View.php, plus methods kept for
 *      tests that already pin the existing API). Each facade method here is
 *      a one-line delegator to the real implementation in
 *      includes/admin/actions/*Handler.php; this class does not hold the
 *      action logic itself.
 *
 * Per-action implementations live in:
 *   - TrashLinkActionHandler   (link-style $_GET['trash'])
 *   - EditRedirectHandler      (POST editRedirect, plus updateRedirectData)
 *   - AddRedirectHandler       (POST addRedirect)
 *   - BulkActionHandler        (POST bulk*)
 *   - RedirectFormResolver     (shared form parsing + regex auto-promote)
 *   - UpdateOptionsHandler, EmptyRedirectTrashHandler, EmptyCapturedTrashHandler,
 *     PurgeRedirectsHandler, RunMaintenanceHandler, RebuildNgramCacheHandler,
 *     ClearSpellingCacheHandler, SaveGscSettingsHandler, ImportFromPluginHandler,
 *     UndoRegexAutoPromoteHandler
 *
 * Standalone class extracted from PluginLogicTrait_AdminActions.
 */
class ABJ_404_Solution_PluginLogicAdminActions {

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

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbQuery;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;

    /** @var ABJ_404_Solution_AdminActionsDependencies */
    private $deps;

    /** @var ABJ_404_Solution_RedirectFormResolver|null lazy. */
    private $formResolver;

    /** @var ABJ_404_Solution_TrashLinkActionHandler|null lazy. */
    private $trashLinkHandler;

    /** @var ABJ_404_Solution_EditRedirectHandler|null lazy. */
    private $editRedirectHandler;

    /** @var ABJ_404_Solution_AddRedirectHandler|null lazy. */
    private $addRedirectHandler;

    /** @var ABJ_404_Solution_BulkActionHandler|null lazy. */
    private $bulkActionHandler;

    /** @var ABJ_404_Solution_DeleteRedirectActionHandler|null lazy. */
    private $deleteRedirectHandler;

    /** @var ABJ_404_Solution_StatusLinkActionHandler|null lazy. */
    private $statusLinkHandler;

    /** @var ABJ_404_Solution_TrashEmptier|null lazy. */
    private $trashEmptier;

    /** @var ABJ_404_Solution_LegacyImportActionHandler|null lazy. */
    private $legacyImportActionHandler;

    /** @var ABJ_404_Solution_PerPageOptionUpdater|null lazy. */
    private $perPageOptionUpdater;

    /** @var ABJ_404_Solution_AdminActionRegistry|null lazy. */
    private $actionRegistry;

    /**
     * Construct with a single typed dependency bundle. Replaces a
     * 10-positional-parameter signature (audit source design-audit-2026-05-29.md,
     * criterion 220 Interface Size). Internal field layout is unchanged; only
     * the constructor interface narrows.
     *
     * @param ABJ_404_Solution_AdminActionsDependencies $deps
     */
    function __construct(ABJ_404_Solution_AdminActionsDependencies $deps) {
        $this->f = $deps->getFunctions();
        $this->logger = $deps->getLogger();
        $this->redirectsRepo = $deps->getRedirectsRepo();
        $this->viewRead = $deps->getViewRead();
        $this->contentRepo = $deps->getContentRepo();
        $this->dbCore = $deps->getDbCore();
        $this->dbQuery = $deps->getDbQuery();
        $this->dao = $deps->getDao();
        $this->urlNormalization = $deps->getUrlNormalization();
        $this->pluginLogic = $deps->getPluginLogic();
        $this->deps = $deps;
    }

    /**
     * Accessors used by ABJ_404_Solution_AdminActionHandlerInterface implementations
     * under includes/admin/actions/. The dispatcher passes $this to each handler
     * (constructor injection) so handlers can reach shared collaborators without
     * each handler getting its own 10-argument constructor.
     *
     * @return ABJ_404_Solution_Functions
     */
    public function getFunctions() {
        return $this->f;
    }

    /** @return ABJ_404_Solution_Logging */
    public function getLogger() {
        return $this->logger;
    }

    /** @return ABJ_404_Solution_RedirectsRepositoryInterface */
    public function getRedirectsRepo() {
        return $this->redirectsRepo;
    }

    /** @return ABJ_404_Solution_ContentRepositoryInterface */
    public function getContentRepo() {
        return $this->contentRepo;
    }

    /** @return ABJ_404_Solution_ViewReadServiceInterface */
    public function getViewRead() {
        return $this->viewRead;
    }

    /** @return ABJ_404_Solution_DatabaseCoreInterface */
    public function getDbCore() {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_DatabaseQueryInterface */
    public function getDbQuery() {
        return $this->dbQuery;
    }

    /** @return ABJ_404_Solution_DataAccess */
    public function getDao() {
        return $this->dao;
    }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    public function getUrlNormalization() {
        return $this->urlNormalization;
    }

    /** @return ABJ_404_Solution_PluginLogic */
    public function getPluginLogic() {
        return $this->pluginLogic;
    }

    /** @return ABJ_404_Solution_AdminActionsDependencies */
    public function getDependencies() {
        return $this->deps;
    }

    /**
     * Shared by Add and Edit redirect handlers. Lazy so construction stays
     * cheap when admin actions never fire on this request.
     *
     * @return ABJ_404_Solution_RedirectFormResolver
     */
    public function redirectFormResolver(): ABJ_404_Solution_RedirectFormResolver {
        if ($this->formResolver === null) {
            $this->formResolver = new ABJ_404_Solution_RedirectFormResolver(
                $this->f, $this->logger, $this->urlNormalization
            );
        }
        return $this->formResolver;
    }

    /**
     * Verify a nonce for admin-link actions, without depending on the browser's Referer header.
     * Public so handlers under includes/admin/actions/ that respond to GET-link
     * actions (TrashLinkActionHandler, EditRedirectHandler) can reuse the
     * same nonce primitive used by the legacy methods on this class.
     *
     * @param string $action Nonce action string used in wp_nonce_url()
     * @param string $queryArg Nonce query arg name (default '_wpnonce')
     * @return bool
     */
    public function verifyLinkNonce($action, $queryArg = '_wpnonce') {
        if (function_exists('check_admin_referer')) {
            $ok = check_admin_referer($action, $queryArg);
            if ($ok) {
                return true;
            }
        }

        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        if (!isset($_REQUEST[$queryArg])) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_REQUEST[$queryArg]));
        if ($nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, $action) !== false;
    }

    /** Do the passed in action and return the associated message.
     *
     * Dispatches to a handler in includes/admin/actions/ via the registry above.
     * Nonce verification + is_admin() guard are centralized here so they cannot
     * be forgotten when a new action is added. Unknown actions are a no-op that
     * returns the pre-populated display-this-message (preserving pre-refactor
     * behavior of the original 12-branch if/else chain).
     *
     * @param string $action
     * @param string $sub
     * @return string
     */
    function handlePluginAction($action, &$sub) {
        $message = array_key_exists('display-this-message', $_POST) ?
            sanitize_text_field($_POST['display-this-message']) : '';

        $handler = $this->adminActionRegistry()->resolve((string)$action);
        if ($handler === null) {
            return $message;
        }

        if (!$this->verifyHandlerNonce($handler) || !is_admin()) {
            $this->logger->debugMessage("Unexpected result. How did we get here? is_admin: " .
                    is_admin() . ", Action: " . $action . ", Sub: " . $sub);
            return $message;
        }

        return $handler->handle((string)$action, $sub);
    }

    /**
     * Run the handler's declared nonce check. Most handlers use
     * check_admin_referer($action, $arg); the legacy 'updateOptions' branch
     * uses wp_verify_nonce($_POST[$arg], $action) directly. Behavior is
     * preserved verbatim per-handler so nonce semantics do not change.
     *
     * @param ABJ_404_Solution_AdminActionHandlerInterface $handler
     * @return bool
     */
    private function verifyHandlerNonce(ABJ_404_Solution_AdminActionHandlerInterface $handler): bool {
        $action = $handler->nonceAction();
        $arg = $handler->nonceArg();
        if ($handler->useCheckAdminReferer()) {
            return (bool)check_admin_referer($action, $arg);
        }
        if (!isset($_POST[$arg]) || !is_scalar($_POST[$arg])) {
            return false;
        }
        return (bool)wp_verify_nonce((string)$_POST[$arg], $action);
    }

    /**
     * Backward-compatible facade for the trash link action. The real
     * implementation lives in ABJ_404_Solution_TrashLinkActionHandler.
     *
     * The legacy misspelling (hanldeTrashAction) is preserved as an alias
     * so existing call sites (View.php) and test stubs do not break; new
     * code should call handleTrashAction().
     *
     * @return string
     */
    function handleTrashAction() {
        if ($this->trashLinkHandler === null) {
            $this->trashLinkHandler = new ABJ_404_Solution_TrashLinkActionHandler($this);
        }
        return $this->trashLinkHandler->handle();
    }

    /** @return string Legacy misspelled alias. Use handleTrashAction(). */
    function hanldeTrashAction() {
        return $this->handleTrashAction();
    }

    /** @return void */
    function handleActionChangeItemsPerRow(): void {
        $this->legacyImportActionHandler()->handleActionChangeItemsPerRow();
    }

    /** @return void */
    function handleActionExport(): void {
        $this->legacyImportActionHandler()->handleActionExport();
    }

    /** @return string|null */
    function handleActionImportFile() {
        return $this->legacyImportActionHandler()->handleActionImportFile();
    }

    /** @return void */
    function updatePerPageOption(int $rows): void {
        if ($this->perPageOptionUpdater === null) {
            $this->perPageOptionUpdater = new ABJ_404_Solution_PerPageOptionUpdater();
        }
        $this->perPageOptionUpdater->update($rows);
    }

    /**
     * @return string
     */
    function handleActionImportRedirects() {
        return $this->legacyImportActionHandler()->handleActionImportRedirects();
    }

    /** Delete redirects.
     * @return string
     */
    function handleDeleteAction() {
        if ($this->deleteRedirectHandler === null) {
            $this->deleteRedirectHandler = new ABJ_404_Solution_DeleteRedirectActionHandler($this);
        }
        return $this->deleteRedirectHandler->handle();
    }

    /** @return string */
    function handleIgnoreAction() {
        return $this->statusLinkActionHandler()->handleIgnoreAction();
    }

    /** @return string */
    function handleLaterAction() {
        return $this->statusLinkActionHandler()->handleLaterAction();
    }

    /** Edit redirect data.
     * Facade: real implementation lives in ABJ_404_Solution_EditRedirectHandler.
     *
     * @param string $sub
     * @param string $action
     * @return string
     */
    function handleActionEdit(&$sub, &$action) {
        if ($this->editRedirectHandler === null) {
            $this->editRedirectHandler = new ABJ_404_Solution_EditRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->editRedirectHandler->handle($sub, $action);
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_BulkActionHandler.
     *
     * @param string $action
     * @param array<int, int> $ids
     * @return string
     */
    function doBulkAction(string $action, array $ids): string {
        if ($this->bulkActionHandler === null) {
            $this->bulkActionHandler = new ABJ_404_Solution_BulkActionHandler($this);
        }
        return $this->bulkActionHandler->doBulkAction($action, $ids);
    }

    /**
     * @param string $sub
     * @return void
     */
    function doEmptyTrash(string $sub): void {
        if ($this->trashEmptier === null) {
            $this->trashEmptier = new ABJ_404_Solution_TrashEmptier($this->dbQuery, $this->viewRead, $this->logger);
        }
        $this->trashEmptier->emptyTrash($sub);
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_EditRedirectHandler.
     *
     * @return string
     */
    function updateRedirectData() {
        if ($this->editRedirectHandler === null) {
            $this->editRedirectHandler = new ABJ_404_Solution_EditRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->editRedirectHandler->updateRedirectData();
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_RedirectFormResolver.
     *
     * @return array<string, mixed>
     */
    function getRedirectTypeAndDest(): array {
        return $this->redirectFormResolver()->getRedirectTypeAndDest();
    }

    /**
     * Facade: real implementation lives in ABJ_404_Solution_AddRedirectHandler.
     *
     * @return string
     */
    function addAdminRedirect() {
        if ($this->addRedirectHandler === null) {
            $this->addRedirectHandler = new ABJ_404_Solution_AddRedirectHandler(
                $this, $this->redirectFormResolver()
            );
        }
        return $this->addRedirectHandler->addAdminRedirect();
    }

    /**
     * @return string Human-readable result message.
     */
    function handleActionUndoRegexAutoPromote() {
        return $this->legacyImportActionHandler()->handleActionUndoRegexAutoPromote();
    }

    /**
     * @return string Human-readable result message.
     */
    public function handleActionImportFromPlugin(): string {
        return $this->legacyImportActionHandler()->handleActionImportFromPlugin();
    }

    /** @return ABJ_404_Solution_StatusLinkActionHandler */
    private function statusLinkActionHandler(): ABJ_404_Solution_StatusLinkActionHandler {
        if ($this->statusLinkHandler === null) {
            $this->statusLinkHandler = new ABJ_404_Solution_StatusLinkActionHandler($this);
        }
        return $this->statusLinkHandler;
    }

    /** @return ABJ_404_Solution_LegacyImportActionHandler */
    private function legacyImportActionHandler(): ABJ_404_Solution_LegacyImportActionHandler {
        if ($this->legacyImportActionHandler === null) {
            $this->legacyImportActionHandler = new ABJ_404_Solution_LegacyImportActionHandler($this);
        }
        return $this->legacyImportActionHandler;
    }

    /** @return ABJ_404_Solution_AdminActionRegistry */
    private function adminActionRegistry(): ABJ_404_Solution_AdminActionRegistry {
        if ($this->actionRegistry === null) {
            $this->actionRegistry = new ABJ_404_Solution_AdminActionRegistry($this);
        }
        return $this->actionRegistry;
    }

}
