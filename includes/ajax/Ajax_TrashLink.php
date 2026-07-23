<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */
class ABJ_404_Solution_Ajax_TrashLink {

    /** Handle trash/restore actions via AJAX.
     * @return void
     */
    static function trashAction(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-trash-link')) {
            return;
        }

        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        /** @var ABJ_404_Solution_RequestInputNormalizer $requestReader */
        $requestReader = $container->has('request_input_normalizer')
            ? $container->get('request_input_normalizer')
            : abj_service('request_input_normalizer');
        /** @var ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository */
        $redirectsRepository = abj_service('redirects_repository');
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');
        $abj404view = $GLOBALS['abj404view'] ?? null;
        if ($abj404view === null && class_exists('ABJ_404_Solution_View')) {
            $abj404view = abj_service('view');
        }

        /** @var ABJ_404_Solution_PluginLogic $abj404logic */

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_ajaxTrash', '_wpnonce');
        
        $idToTrash = $requestReader->getPostOrGetSanitize('id');
        $trashAction = $requestReader->getPostOrGetSanitize('trash');
        $subpage = $requestReader->getPostOrGetSanitize('subpage');
        
        $data = array();
        $data['resultset'] = $redirectsRepository->moveRedirectsToTrash((int)$idToTrash, (int)$trashAction);

        // Return cached tab counts when available. A cache miss schedules the
        // aggregate refresh out of band; it must never block this mutation.
        if ($subpage === 'abj404_captured') {
            $counts = $viewReadService->getCapturedStatusCounts(false);
        } else {
            $counts = $viewReadService->getRedirectStatusCounts(false);
        }
        if (!empty($counts['_incomplete'])) {
            $data['countsIncomplete'] = true;
        } else {
            $data['tabCounts'] = array_values($counts);
        }

        if (empty($data['resultset'])) {
            $data['result'] = "success";

        } else {
            $data['result'] = "fail";
        }

        if ($data['result'] === 'success') {
            wp_send_json_success($data, 200);
        } else {
            $logger = abj_service('logging');
            if ($logger !== null) {
                $resultsetForLog = is_scalar($data['resultset'])
                    ? (string)$data['resultset']
                    : wp_json_encode($data['resultset']);
                $logger->warn('trashAction failed: id=' . (string)$idToTrash .
                    ', trash=' . (string)$trashAction .
                    ', subpage=' . (string)$subpage .
                    ', dao_resultset=' . (string)$resultsetForLog .
                    '. Returning HTTP 500 to AJAX caller.');
            }
            wp_send_json_error(array(
                'message' => __('Error: Unable to move redirect to trash.', '404-solution'),
                'resultset' => $data['resultset'],
                'result' => 'fail',
            ), 500);
        }
    }
    
}
