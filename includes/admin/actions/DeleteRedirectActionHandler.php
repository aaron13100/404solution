<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the legacy remove-link action for redirect rows.
 */
class ABJ_404_Solution_DeleteRedirectActionHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    /** @return string */
    public function handle(): string {
        if (!array_key_exists('remove', $_GET) || $_GET['remove'] != 1) {
            return '';
        }

        if (!is_admin() || !$this->parent->verifyLinkNonce('abj404_removeRedirect')) {
            return '';
        }

        $id = $_GET['id'] ?? '';
        if ($this->parent->getFunctions()->regexMatch('[0-9]+', $id)) {
            $this->parent->getRedirectsRepo()->deleteRedirect(absint($id));
            return __('Redirect Removed Successfully!', '404-solution');
        }

        return '';
    }
}
