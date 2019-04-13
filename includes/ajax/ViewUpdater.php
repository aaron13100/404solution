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
        
        // TODO verify nonce
        
        $filterText = $abj404dao->getPostOrGetSanitize('filterText');
        $rowsPerPage = $abj404dao->getPostOrGetSanitize('rowsPerPage');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        
        // update the perpage option
        $options = $abj404logic->getOptions();
        $options['perpage'] = $rowsPerPage;
        update_option('abj404_settings', $options);
        
        $html = $abj404view->getPaginationLinks($subpage);
        
        echo json_encode($html, JSON_PRETTY_PRINT);
        exit;
    }
    
}

ABJ_404_Solution_ViewUpdater::init();
