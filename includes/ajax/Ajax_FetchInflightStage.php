<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxFetchInflightStage. Looks up the last in-flight
 * stage stamped by `setStage()` for a given client-supplied requestId. Used
 * by the JS error handler on `textStatus === 'timeout'` so the admin notice
 * can name which phase the server was in when the client gave up. Reads
 * but does not delete the transient, letting it expire naturally to avoid
 * a race if the original AJAX is still running.
 *
 * Returns 200 with `{stage: '...'}` on success, `{stage: ''}` if the
 * transient has expired or the requestId is unknown.
 */
class ABJ_404_Solution_Ajax_FetchInflightStage {

    /** @return void */
    public function handle() {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-fetch-inflight-stage');

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        $abj404logic = abj_service('plugin_logic');

        $nonce = $functions->getPostOrGetSanitize('nonce');
        $requestId = ABJ_404_Solution_Ajax_AdminEndpointSupport::readClientRequestId();

        try {
            if (!wp_verify_nonce($nonce, 'abj404_fetchInflightStage')) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                    ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Invalid security token', null, false),
                    403
                );
                return;
            }
            if (!abj_service('admin_access_policy')->isPluginAdmin()) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                    ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Unauthorized', null, false),
                    403
                );
                return;
            }
            // Tight rate limit: this endpoint only fires from the JS timeout handler.
            // A real admin sees ~1 hit per stuck request.
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('fetch_inflight_stage', 120, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                    ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false),
                    429
                );
                return;
            }
            if ($requestId === '') {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array('stage' => ''), 200);
                return;
            }

            $stage = '';
            $queryLabel = '';
            $whatsHappening = '';
            $events = array();
            if (function_exists('get_transient')) {
                $value = get_transient('abj404_inflight_' . $requestId);
                if (is_array($value)) {
                    $stage = isset($value['stage']) && is_string($value['stage']) ? $value['stage'] : '';
                    $queryLabel = isset($value['query_label']) && is_string($value['query_label']) ? $value['query_label'] : '';
                    $whatsHappening = isset($value['what_happening']) && is_string($value['what_happening']) ? $value['what_happening'] : '';
                    $rawEvents = is_array($value['events'] ?? null) ? $value['events'] : array();
                    foreach ($rawEvents as $rawEvent) {
                        if (!is_array($rawEvent)) {
                            continue;
                        }
                        $eventStage = isset($rawEvent['stage']) && is_string($rawEvent['stage']) ? $rawEvent['stage'] : '';
                        if ($eventStage === '') {
                            continue;
                        }
                        $events[] = array(
                            'stage' => $eventStage,
                            'queryLabel' => isset($rawEvent['query_label']) && is_string($rawEvent['query_label']) ? $rawEvent['query_label'] : '',
                            'whatsHappening' => isset($rawEvent['what_happening']) && is_string($rawEvent['what_happening']) ? $rawEvent['what_happening'] : '',
                            'timeMs' => isset($rawEvent['time_ms']) && is_scalar($rawEvent['time_ms']) ? intval($rawEvent['time_ms']) : 0,
                        );
                    }
                } else if (is_string($value)) {
                    $stage = $value;
                    $diagnostics = ABJ_404_Solution_AjaxStageDiagnostics::getStageDiagnostics($stage);
                    $queryLabel = $diagnostics['query_label'];
                    $whatsHappening = $diagnostics['what_happening'];
                }
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array(
                'stage' => $stage,
                'queryLabel' => $queryLabel,
                'whatsHappening' => $whatsHappening,
                'events' => $events,
            ), 200);
            return;

        } catch (Throwable $e) { // allow-silent-catch: diagnostics endpoint is best-effort; surfacing a lookup failure is worse than returning empty stage
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array('stage' => ''), 200);
            return;
        }
    }
}
