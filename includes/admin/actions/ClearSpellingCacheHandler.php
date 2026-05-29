<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'clearSpellingCache': truncates the spelling cache table via
 * the content repository so the next suggestion request rebuilds it from
 * scratch (used after large content changes or troubleshooting bad matches).
 */
class ABJ_404_Solution_ClearSpellingCacheHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_clearSpellingCache';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $this->parent->getContentRepo()->deleteSpellingCache();
        return __('Spelling cache cleared successfully.', '404-solution');
    }
}
