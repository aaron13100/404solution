<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'undoRegexAutoPromote': reverts the last regex auto-promotion
 * (manual redirect that was silently converted to a regex match) back to a
 * manual redirect with the original URL. Surfaced as a one-click undo on the
 * admin notice that announces the auto-promotion.
 */
class ABJ_404_Solution_UndoRegexAutoPromoteHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404undoRegexAutoPromote';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        return $this->parent->handleActionUndoRegexAutoPromote();
    }
}
