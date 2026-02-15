<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {

    /** Handle trash/restore actions via AJAX. */
    static function trashAction() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404view = $GLOBALS['abj404view'] ?? null;

        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has')) {
                    if ($c->has('data_access')) {
                        $abj404dao = $c->get('data_access');
                    }
                    if ($c->has('plugin_logic')) {
                        $abj404logic = $c->get('plugin_logic');
                    }
                    if ($c->has('view')) {
                        $abj404view = $c->get('view');
                    }
                }
            } catch (Throwable $e) {
                // fall back to singletons above
            }
        }
        if ($abj404view === null && class_exists('ABJ_404_Solution_View')) {
            $abj404view = ABJ_404_Solution_View::getInstance();
        }

        $nonceOk = function_exists('check_ajax_referer')
            ? check_ajax_referer('abj404_ajaxTrash', '_wpnonce', false)
            : (function_exists('check_admin_referer') ? check_admin_referer('abj404_ajaxTrash', '_wpnonce', false) : false);

        if (!$nonceOk || !is_admin()) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')), 403);
            if (!(defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT)) {
                exit;
            }
            return;
        }

        // Verify user has appropriate capabilities (respects plugin admin users)
        if (!$abj404logic->userIsPluginAdmin()) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')), 403);
            if (!(defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT)) {
                exit;
            }
            return;
        }
        
        $idToTrash = $abj404dao->getPostOrGetSanitize('id');
        $trashAction = $abj404dao->getPostOrGetSanitize('trash');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        
        $data = array();
        $data['resultset'] = $abj404dao->moveRedirectsToTrash($idToTrash, $trashAction);
        $data['subsubsub'] = is_object($abj404view) && method_exists($abj404view, 'getSubSubSub')
            ? $abj404view->getSubSubSub($subpage)
            : '';
        
        
        if (empty($data['resultset'])) {
            $data['result'] = "success";
            
        } else {
            $data['result'] = "fail";
        }

        if ($data['result'] === 'success') {
            wp_send_json_success($data, 200);
	        } else {
	            // Keep the same fields for UI, but indicate failure via WP-shaped error response.
	            wp_send_json_error(array(
	                'message' => __('Error: Unable to move redirect to trash.', '404-solution'),
	                'resultset' => $data['resultset'],
	                'subsubsub' => $data['subsubsub'],
	                'result' => 'fail',
	            ), 500);
	        }
        if (!(defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT)) {
            exit;
        }
        return;
    }
    
}
