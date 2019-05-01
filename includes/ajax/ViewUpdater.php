<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

    static function init() {
        $me = new ABJ_404_Solution_ViewUpdater();
        add_action('wp_ajax_ajaxUpdatePaginationLinks', 
                array($me, 'ABJ_404_Solution_ViewUpdater::getPaginationLinks'));
        // wp_ajax_nopriv_ is for normal users
    }
    
    function getPaginationLinks() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        global $abj404view;
        
        $rowsPerPage = absint($abj404dao->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        
        wp_verify_nonce($nonce);
        
        // update the perpage option
        $abj404logic->updatePerPageOption($rowsPerPage);
        
        $data = array();
        $data['paginationLinksTop'] = $abj404view->getPaginationLinks($subpage);
        $data['paginationLinksBottom'] = $abj404view->getPaginationLinks($subpage, false);
        if ($subpage == 'abj404_redirects') {
            $data['table'] = $abj404view->getAdminRedirectsPageTable($subpage);
            
        } else if ($subpage == 'abj404_captured') {
            $data['table'] = $abj404view->getCapturedURLSPageTable($subpage);
            
        } else if ($subpage == 'abj404_logs') {
            $data['table'] = $abj404view->getAdminLogsPageTable($subpage);
            
        } else {
            $data['table'] = 'Error: Unexpected subpage requested.';
        }
        
        header('Content-type: application/json; charset=UTF-8');
        header('Content-Encoding: gzip');
        echo gzencode(json_encode($data));
        exit;
    }
    
}

ABJ_404_Solution_ViewUpdater::init();
