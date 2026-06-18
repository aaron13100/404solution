<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'importFromPlugin': imports redirects from a competing /
 * sibling plugin (Yoast, RankMath, AIOSEO, Safe Redirect Manager, Redirection)
 * via CrossPluginImporter. Result message includes the count imported.
 */
class ABJ_404_Solution_ImportFromPluginHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_importFromPlugin';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $message = $this->parent->handleActionImportFromPlugin();
        return $message;
    }
}
