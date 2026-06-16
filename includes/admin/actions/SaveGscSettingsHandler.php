<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'saveGscSettings': persists Google Search Console OAuth
 * credentials submitted from the GSC settings form. Returns the validation
 * error string from GoogleSearchConsole::saveSettings() (empty on success).
 *
 * Uses a custom nonce arg '_wpnonce_gsc' to scope the GSC form's nonce
 * separately from other admin actions on the same page.
 */
class ABJ_404_Solution_SaveGscSettingsHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    public function nonceAction(): string {
        return 'abj404_gsc_save';
    }

    public function nonceArg(): string {
        return '_wpnonce_gsc';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $logger = abj_service('logging');
        $gsc = new ABJ_404_Solution_GoogleSearchConsole($logger);
        $error = $gsc->oauthStore()->saveSettings($_POST);
        return ($error === '')
            ? __('Google Search Console credentials saved.', '404-solution')
            : $error;
    }
}
