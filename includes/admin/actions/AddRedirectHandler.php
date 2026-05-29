<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'addRedirect': delegates to PluginLogicAdminActions::addAdminRedirect()
 * (which validates POST, normalizes URL, calls redirectsRepo->setupRedirect) and
 * appends success / failure framing.
 */
class ABJ_404_Solution_AddRedirectHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404addRedirect';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $message = $this->parent->addAdminRedirect();
        if ($message == '') {
            return __('New Redirect Added Successfully!', '404-solution');
        }
        return $message . __('Error: unable to add new redirect.', '404-solution');
    }
}
