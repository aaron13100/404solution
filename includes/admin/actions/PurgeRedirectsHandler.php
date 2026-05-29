<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'purgeRedirects': delegates to the redirects repository to
 * delete user-specified redirect rows, then invalidates the view cache.
 */
class ABJ_404_Solution_PurgeRedirectsHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_purgeRedirects';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $message = $this->parent->getRedirectsRepo()->deleteSpecifiedRedirects();
        $this->parent->getViewBuild()->invalidateViewDoneAndScheduleRebuild();
        return is_string($message) ? $message : '';
    }
}
