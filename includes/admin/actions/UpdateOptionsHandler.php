<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'updateOptions': either deletes the debug file (if
 * deleteDebugFile flag is set) or rewrites $sub to 'abj404_options' so the
 * settings page submission proceeds through the normal options pipeline.
 *
 * This is the one handler that uses wp_verify_nonce() against $_POST['nonce']
 * (not check_admin_referer), preserving the pre-refactor behavior exactly.
 */
class ABJ_404_Solution_UpdateOptionsHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404UpdateOptions';
    }

    public function nonceArg(): string {
        return 'nonce';
    }

    public function useCheckAdminReferer(): bool {
        return false;
    }

    public function handle(string $action, string &$sub): string {
        $logger = $this->parent->getLogger();

        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile']) {
            $filepath = $logger->getDebugFilePath();
            if (!file_exists($filepath)) {
                return sprintf(__("Debug file not found. (%s)", '404-solution'), $filepath);
            } else if ($logger->deleteDebugFile()) {
                return sprintf(__("Debug file(s) deleted. (%s)", '404-solution'), $filepath);
            } else {
                return sprintf(__("Issue deleting debug file. (%s)", '404-solution'), $filepath);
            }
        }

        $sub = 'abj404_options';
        return '';
    }
}
