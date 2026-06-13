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

        $requestId = ABJ_404_Solution_Ajax_AdminEndpointSupport::readClientRequestId();
        $context = array(
            'action' => 'ajaxFetchInflightStage',
            'requestId' => $requestId,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );

        try {
            if (!ABJ_404_Solution_Ajax_AdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_fetchInflightStage',
                $context,
                'ajaxFetchInflightStage'
            )) {
                return;
            }

            // Tight rate limit: this endpoint only fires from the JS timeout handler.
            // A real admin sees ~1 hit per stuck request.
            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('fetch_inflight_stage', 120, 60)) {
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

            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                self::inflightStageResponseForRequestId($requestId),
                200
            );
            return;

        } catch (Throwable $e) { // allow-silent-catch: diagnostics endpoint is best-effort; surfacing a lookup failure is worse than returning empty stage
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array('stage' => ''), 200);
            return;
        }
    }

    /**
     * @return array{stage: string, queryLabel: string, whatsHappening: string, events: array<int, array{stage: string, queryLabel: string, whatsHappening: string, timeMs: int}>}
     */
    private static function inflightStageResponseForRequestId(string $requestId): array {
        if (!function_exists('get_transient')) {
            return self::emptyInflightStageResponse();
        }

        $value = get_transient('abj404_inflight_' . $requestId);
        if (is_array($value)) {
            return self::inflightStageResponseFromArray($value);
        }
        if (is_string($value)) {
            return self::inflightStageResponseFromLegacyString($value);
        }

        return self::emptyInflightStageResponse();
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array{stage: string, queryLabel: string, whatsHappening: string, events: array<int, array{stage: string, queryLabel: string, whatsHappening: string, timeMs: int}>}
     */
    private static function inflightStageResponseFromArray(array $value): array {
        return array(
            'stage' => isset($value['stage']) && is_string($value['stage']) ? $value['stage'] : '',
            'queryLabel' => isset($value['query_label']) && is_string($value['query_label'])
                ? $value['query_label'] : '',
            'whatsHappening' => isset($value['what_happening']) && is_string($value['what_happening'])
                ? $value['what_happening'] : '',
            'events' => self::inflightStageEventsFromRaw($value['events'] ?? array()),
        );
    }

    /**
     * @return array{stage: string, queryLabel: string, whatsHappening: string, events: array<int, array{stage: string, queryLabel: string, whatsHappening: string, timeMs: int}>}
     */
    private static function inflightStageResponseFromLegacyString(string $stage): array {
        $diagnostics = ABJ_404_Solution_AjaxStageDiagnostics::getStageDiagnostics($stage);
        return array(
            'stage' => $stage,
            'queryLabel' => $diagnostics['query_label'],
            'whatsHappening' => $diagnostics['what_happening'],
            'events' => array(),
        );
    }

    /**
     * @param mixed $rawEvents
     * @return array<int, array{stage: string, queryLabel: string, whatsHappening: string, timeMs: int}>
     */
    private static function inflightStageEventsFromRaw($rawEvents): array {
        if (!is_array($rawEvents)) {
            return array();
        }

        $events = array();
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
                'queryLabel' => isset($rawEvent['query_label']) && is_string($rawEvent['query_label'])
                    ? $rawEvent['query_label'] : '',
                'whatsHappening' => isset($rawEvent['what_happening']) && is_string($rawEvent['what_happening'])
                    ? $rawEvent['what_happening'] : '',
                'timeMs' => isset($rawEvent['time_ms']) && is_scalar($rawEvent['time_ms'])
                    ? intval($rawEvent['time_ms']) : 0,
            );
        }
        return $events;
    }

    /**
     * @return array{stage: string, queryLabel: string, whatsHappening: string, events: array<int, array{stage: string, queryLabel: string, whatsHappening: string, timeMs: int}>}
     */
    private static function emptyInflightStageResponse(): array {
        return array(
            'stage' => '',
            'queryLabel' => '',
            'whatsHappening' => '',
            'events' => array(),
        );
    }
}
