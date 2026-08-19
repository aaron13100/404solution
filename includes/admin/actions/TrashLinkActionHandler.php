<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the $_GET['trash'] link action: move a single redirect to trash
 * (trash=1) or restore it from trash (trash=0). Triggered by the trash/restore
 * link in the View_Redirects row controls and verified with the
 * abj404_trashRedirect link nonce.
 *
 * Not registered in PluginLogicAdminActions::actionHandlerMap() because it is
 * a link-style GET action, not a POST form action through the central
 * dispatcher. View.php calls handle() directly during admin-page render.
 *
 * Extracted from PluginLogicAdminActions::hanldeTrashAction() (M201,
 * design-audit-2026-06-02). The original misspelled name is preserved as a
 * thin delegator on the parent class so existing callers and test mocks keep
 * working.
 */
class ABJ_404_Solution_TrashLinkActionHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    /**
     * Move a redirect to trash or restore it from trash, depending on
     * $_GET['trash']. Returns the message to display back to the admin
     * (or '' when no trash request is present in this hit).
     *
     * @return string
     */
    public function handle(): string {
        $message = "";
        if (!isset($_GET['trash'])) {
            return $message;
        }
        if (!is_admin() || !$this->parent->verifyLinkNonce('abj404_trashRedirect')) {
            return $message;
        }

        $trash = "";
        if ($_GET['trash'] == 0) {
            $trash = 0;
        } else if ($_GET['trash'] == 1) {
            $trash = 1;
        } else {
            $this->parent->getLogger()->errorMessage("Unexpected trash operation: " .
                    esc_html(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_GET['trash'])));
            return __('Error: Bad trash operation specified.', '404-solution');
        }

        $id = absint($_GET['id']);
        $message = $this->parent->getRedirectsRepo()->moveRedirectsToTrash($id, $trash);
        if ($message == "") {
            $subpage = isset($_GET['subpage']) ? sanitize_text_field(wp_unslash($_GET['subpage'])) : '';
            $filter = isset($_GET['filter']) ? intval($_GET['filter']) : 0;
            if ($trash == 0 && $subpage === 'abj404_captured' && $filter === ABJ404_TRASH_FILTER) {
                $this->parent->getRedirectsRepo()->updateRedirectTypeStatus($id, (string)ABJ404_STATUS_CAPTURED);
            }
            if ($trash == 1) {
                return __('Redirect moved to trash successfully!', '404-solution');
            }
            return __('Redirect restored from trash successfully!', '404-solution');
        }

        if ($trash == 1) {
            return __('Error: Unable to move redirect to trash.', '404-solution');
        }
        return __('Error: Unable to move redirect from trash.', '404-solution');
    }
}
