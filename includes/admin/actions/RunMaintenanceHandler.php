<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'runMaintenance': triggers the redirects retention cron
 * (delete redirects older than the configured age) on demand from the admin UI.
 */
class ABJ_404_Solution_RunMaintenanceHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    public function nonceAction(): string {
        return 'abj404_runMaintenance';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $message = abj_service('redirects_retention_service')->deleteOldRedirectsCron();
        return is_string($message) ? $message : '';
    }
}
