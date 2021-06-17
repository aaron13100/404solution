<?php

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ViewUpdater();
		}
		
		return self::$instance;
	}
		
    static function init() {
        $me = ABJ_404_Solution_ViewUpdater::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks', 
                array($me, 'ABJ_404_Solution_ViewUpdater::getPaginationLinks'));
        // wp_ajax_nopriv_ is for normal users
    }
    
    function getPaginationLinks() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
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
