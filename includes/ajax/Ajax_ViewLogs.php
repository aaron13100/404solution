<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX adapter for log URL autocomplete.
 */
class ABJ_404_Solution_Ajax_ViewLogs {

    /** @return void */
    public static function echoViewLogsFor() {
        /** @var ABJ_404_Solution_LogsRepository $logsRepository */
        $logsRepository = ABJ_404_Solution_Ajax_ServiceResolver::required('logs_repository');
        /** @var ABJ_404_Solution_Functions $f */
        $f = ABJ_404_Solution_Ajax_ServiceResolver::required('functions');
        $feedback = new ABJ_404_Solution_Ajax_SearchFeedback();

        $authResult = abj_service('ajax_security_gate')->authorizeAdminWithNonce('abj404_ajax');
        if (!$authResult['ok']) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem($authResult['message']), 200);
            return;
        }

        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('view_logs', 100, 60)) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem(__('Rate limit exceeded. Please try again later.', '404-solution')), 200);
            return;
        }

        $termRaw = array_key_exists('term', $_GET) ? $_GET['term'] : '';
        $term = $f->strtolower(sanitize_text_field($termRaw));
        $term = substr($term, 0, 100);

        $suggestion = array();
        $suggestion['label'] = __('(Show All Logs)', '404-solution');
        $suggestion['category'] = 'Special';
        $suggestion['value'] = 0;
        $specialSuggestion = array();
        $specialSuggestion[] = $suggestion;

        $rows = $logsRepository->getLogsIDandURLLike($term, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $results = self::formatLogResults($rows);
        $suggestions = $feedback->provideSearchFeedback($results, $term);
        $suggestions = array_merge($specialSuggestion, $suggestions);

        self::sendJson($suggestions, 200);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    public static function formatLogResults($rows) {
        $suggestions = array();

        foreach ($rows as $row) {
            $reqUrl = isset($row['requested_url']) ? $row['requested_url'] : '';
            $logsId = isset($row['logsid']) ? $row['logsid'] : '';
            $suggestion = array();
            $suggestion['label'] = is_scalar($reqUrl) ? (string)$reqUrl : '';
            $suggestion['category'] = 'Normal';
            $suggestion['value'] = is_scalar($logsId) ? (string)$logsId : '';

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param mixed $payload
     * @param int $status
     * @return void
     */
    private static function sendJson($payload, $status = 200) {
        ABJ_404_Solution_Ajax_Php::sendJson($payload, $status);
    }
}
