<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'emptyRedirectTrash': permanently deletes all trashed rows
 * from the redirects table (status in $abj404_redirect_types) via
 * PluginLogicAdminActions::doEmptyTrash(), then invalidates the view cache.
 */
class ABJ_404_Solution_EmptyRedirectTrashHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_bulkProcess';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $this->parent->doEmptyTrash('abj404_redirects');
        return __('All trashed URLs have been deleted!', '404-solution');
    }
}
