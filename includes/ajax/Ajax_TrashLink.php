<?php

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {

    /** Find logs to display. */
    static function trashAction() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        global $abj404view;

        if (!check_admin_referer('abj404_ajaxTrash') || !is_admin()) {
        	return json_encode("fail: old referrer? try reloading the page.");
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
