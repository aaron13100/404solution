<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles any action whose verb starts with 'bulk' (bulktrash,
 * bulk_trash_delete_permanently, bulk_trash_restore, bulkignore, bulklater,
 * bulkcaptured). Validates that $_POST['idnum'] is present (echoes an error
 * and returns '' if not, matching pre-refactor behavior), then delegates the
 * iteration + per-row logic to PluginLogicAdminActions::doBulkAction().
 *
 * Registered in the dispatcher's prefix-match map (not the exact-match map).
 */
class ABJ_404_Solution_BulkActionHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

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
        if (!isset($_POST['idnum']) || !is_array($_POST['idnum'])) {
            $this->parent->getLogger()->debugMessage(
                "No ID(s) specified for bulk action: " . esc_html($action));
            echo sprintf(__("Error: No ID(s) specified for bulk action. (%s)", '404-solution'),
                esc_html($action));
            return '';
        }
        $ids = array();
        foreach ($_POST['idnum'] as $rawId) {
            if (is_scalar($rawId)) {
                $ids[] = absint($rawId);
            }
        }
        $message = $this->parent->doBulkAction($action, $ids);
        $this->parent->getViewBuild()->invalidateViewDoneAndScheduleRebuild();
        return $message;
    }
}
