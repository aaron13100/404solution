<?php

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {

    /** Handle trash/restore actions via AJAX. */
    static function trashAction() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        global $abj404view;

        $nonceOk = function_exists('check_ajax_referer')
            ? check_ajax_referer('abj404_ajaxTrash', '_wpnonce', false)
            : (function_exists('check_admin_referer') ? check_admin_referer('abj404_ajaxTrash', '_wpnonce', false) : false);

        if (!$nonceOk || !is_admin()) {
            echo json_encode(array('result' => 'fail', 'message' => 'Invalid nonce. Please reload the page.'));
            exit();
        }

        // Verify user has appropriate capabilities (respects plugin admin users)
        if (!$abj404logic->userIsPluginAdmin()) {
            echo json_encode(array('result' => 'fail', 'message' => 'Unauthorized'));
            exit();
        }
        
        $idToTrash = $abj404dao->getPostOrGetSanitize('id');
        $trashAction = $abj404dao->getPostOrGetSanitize('trash');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        
        $data = array();
        $data['resultset'] = $abj404dao->moveRedirectsToTrash($idToTrash, $trashAction);
        $data['subsubsub'] = $abj404view->getSubSubSub($subpage);        
        
        
        if (empty($data['resultset'])) {
            $data['result'] = "success";
            
        } else {
            $data['result'] = "fail";
        }
        
        echo json_encode($data, JSON_PRETTY_PRINT);
    	exit();
    }
    
}
