<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles captured-row status link actions such as ignore and organize later.
 */
class ABJ_404_Solution_StatusLinkActionHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    /** @return string */
    public function handleIgnoreAction(): string {
        return $this->handleStatusUpdate('ignore', 'abj404_ignore404', ABJ404_STATUS_IGNORED, 'ignore', 'ignored');
    }

    /** @return string */
    public function handleLaterAction(): string {
        return $this->handleStatusUpdate('later', 'abj404_organizeLater', ABJ404_STATUS_LATER, 'organize later', 'organize later');
    }

    /**
     * @param string $paramName The $_GET parameter name.
     * @param string $nonceAction The nonce action name.
     * @param int $activeStatus The status constant to use when action=1.
     * @param string $errorActionName Action name for error messages.
     * @param string $successActionName Action name for success messages.
     * @return string
     */
    private function handleStatusUpdate($paramName, $nonceAction, $activeStatus, $errorActionName, $successActionName): string {
        if (!isset($_GET[$paramName])) {
            return '';
        }

        if (!is_admin() || !$this->parent->verifyLinkNonce($nonceAction)) {
            return '';
        }

        $operation = isset($_GET[$paramName]) && is_scalar($_GET[$paramName]) ? trim((string)$_GET[$paramName]) : '';
        if ($operation !== '0' && $operation !== '1') {
            $this->parent->getLogger()->debugMessage("Unexpected {$errorActionName} operation: " .
                    esc_html($operation));
            return sprintf(__('Error: Bad %s operation specified.', '404-solution'), $errorActionName);
        }

        $id = isset($_GET['id']) && is_scalar($_GET['id']) ? (string)$_GET['id'] : '';
        if ($id === '' || !$this->parent->getFunctions()->regexMatch('[0-9]+', $id)) {
            return '';
        }

        $newstatus = $operation === '1' ? $activeStatus : ABJ404_STATUS_CAPTURED;
        $message = $this->parent->getRedirectsRepo()->updateRedirectTypeStatus(absint($id), (string)$newstatus);
        if ($message == '') {
            if ($newstatus == ABJ404_STATUS_CAPTURED) {
                return sprintf(__('Removed 404 URL from %s list successfully!', '404-solution'), $successActionName);
            }
            return sprintf(__('404 URL marked as %s successfully!', '404-solution'), $successActionName);
        }

        if ($newstatus == ABJ404_STATUS_CAPTURED) {
            return sprintf(__('Error: unable to remove URL from %s list', '404-solution'), $successActionName);
        }
        return sprintf(__('Error: unable to mark URL as %s', '404-solution'), $successActionName);
    }
}
