<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves admin action names to handler instances.
 *
 * Exact action names are checked before prefix handlers so broad operations
 * like "bulk*" cannot shadow a specific registered action.
 */
class ABJ_404_Solution_AdminActionRegistry {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    /**
     * @param string $action
     * @return ABJ_404_Solution_AdminActionHandlerInterface|null
     */
    public function resolve(string $action) {
        $exact = $this->actionHandlerMap();
        if (isset($exact[$action])) {
            return $this->instantiateHandler($exact[$action]);
        }
        foreach ($this->actionHandlerPrefixMap() as $prefix => $cls) {
            if ($action !== '' && strpos($action, $prefix) === 0) {
                return $this->instantiateHandler($cls);
            }
        }
        return null;
    }

    /**
     * @return array<string, class-string<ABJ_404_Solution_AdminActionHandlerInterface>>
     */
    private function actionHandlerMap(): array {
        return array(
            'updateOptions'        => 'ABJ_404_Solution_UpdateOptionsHandler',
            'addRedirect'          => 'ABJ_404_Solution_AddRedirectHandler',
            'emptyRedirectTrash'   => 'ABJ_404_Solution_EmptyRedirectTrashHandler',
            'emptyCapturedTrash'   => 'ABJ_404_Solution_EmptyCapturedTrashHandler',
            'purgeRedirects'       => 'ABJ_404_Solution_PurgeRedirectsHandler',
            'runMaintenance'       => 'ABJ_404_Solution_RunMaintenanceHandler',
            'rebuildNgramCache'    => 'ABJ_404_Solution_RebuildNgramCacheHandler',
            'clearSpellingCache'   => 'ABJ_404_Solution_ClearSpellingCacheHandler',
            'saveGscSettings'      => 'ABJ_404_Solution_SaveGscSettingsHandler',
            'importFromPlugin'     => 'ABJ_404_Solution_ImportFromPluginHandler',
            'undoRegexAutoPromote' => 'ABJ_404_Solution_UndoRegexAutoPromoteHandler',
        );
    }

    /**
     * @return array<string, class-string<ABJ_404_Solution_AdminActionHandlerInterface>>
     */
    private function actionHandlerPrefixMap(): array {
        return array(
            'bulk' => 'ABJ_404_Solution_BulkActionHandler',
        );
    }

    /**
     * @param class-string<ABJ_404_Solution_AdminActionHandlerInterface> $cls
     * @return ABJ_404_Solution_AdminActionHandlerInterface
     */
    private function instantiateHandler(string $cls): ABJ_404_Solution_AdminActionHandlerInterface {
        $reflect = new ReflectionClass($cls);
        $ctor = $reflect->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            $instance = $reflect->newInstance();
        } else {
            $instance = $reflect->newInstance($this->parent);
        }
        if (!$instance instanceof ABJ_404_Solution_AdminActionHandlerInterface) {
            throw new RuntimeException("Handler class {$cls} does not implement AdminActionHandlerInterface.");
        }
        return $instance;
    }
}
