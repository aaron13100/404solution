<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {
    use ABJ_404_Solution_AjaxSecurityTrait;

    /** Handle trash/restore actions via AJAX.
     * @return void
     */
    static function trashAction(): void {
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');
        $abj404view = $GLOBALS['abj404view'] ?? null;
        if ($abj404view === null && class_exists('ABJ_404_Solution_View')) {
            $abj404view = abj_service('view');
        }

        /** @var ABJ_404_Solution_DataAccess $abj404dao */
        /** @var ABJ_404_Solution_PluginLogic $abj404logic */

        self::requireAdminWithNonce('abj404_ajaxTrash', '_wpnonce');
        
        $idToTrash = $abj404dao->getPostOrGetSanitize('id');
        $trashAction = $abj404dao->getPostOrGetSanitize('trash');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        
        $data = array();
        $data['resultset'] = $abj404dao->moveRedirectsToTrash((int)$idToTrash, (int)$trashAction);

        // Return fresh tab counts so the JS can update the tab badges.
        // Bypass cache since the trash action just changed the counts.
        if ($subpage === 'abj404_captured') {
            $counts = $abj404dao->getCapturedStatusCounts(true);
        } else {
            $counts = $abj404dao->getRedirectStatusCounts(true);
        }
        $data['tabCounts'] = array_values($counts);

        if (empty($data['resultset'])) {
            $data['result'] = "success";

        } else {
            $data['result'] = "fail";
        }

        if ($data['result'] === 'success') {
            wp_send_json_success($data, 200);
        } else {
            wp_send_json_error(array(
                'message' => __('Error: Unable to move redirect to trash.', '404-solution'),
                'resultset' => $data['resultset'],
                'result' => 'fail',
            ), 500);
        }
    }
    
}
