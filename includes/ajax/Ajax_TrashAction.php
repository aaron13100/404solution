<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {

    /** Find logs to display. */
    static function trashAction() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();

        if (!check_admin_referer('abj404_ajaxTrash') || !is_admin()) {
            return "fail: old referrer? try reloading the page. ";
        }
        
        $idToTrash = $abj404dao->getPostOrGetSanitize('id');
        $trashAction = $abj404dao->getPostOrGetSanitize('trash');
        
        $result = $abj404dao->moveRedirectsToTrash($idToTrash, $trashAction);
        
        if (empty($result)) {
            echo "success";
            
        } else {
            echo "fail: " . $result;
        }
        
    	exit();
    }
    
}
