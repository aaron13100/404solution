<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shapes REST API responses for the abj404/v1 namespace.
 */
final class ABJ_404_Solution_RestApiResponsePresenter {

    /**
     * @param array<int|string, mixed> $rows
     * @return \WP_REST_Response
     */
    public function paginated(array $rows, int $total, int $page, int $perPage): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'items'       => array_values($rows),
            'total'       => intval($total),
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int)ceil(intval($total) / $perPage)),
        ), 200);
    }

    /**
     * @param int|string $status
     * @return \WP_REST_Response
     */
    public function redirectCreated(int $id, string $from, string $to, int $code, $status): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'id'     => $id,
            'from'   => $from,
            'to'     => $to,
            'code'   => $code,
            'status' => (string)$status,
        ), 201);
    }

    /**
     * @param int|string $status
     * @return \WP_REST_Response
     */
    public function redirectUpdated(int $id, string $from, string $to, int $code, $status): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'id'     => $id,
            'from'   => $from,
            'to'     => $to,
            'code'   => $code,
            'status' => (string)$status,
        ), 200);
    }

    public function redirectTrashed(int $id): \WP_REST_Response {
        return new \WP_REST_Response(array('trashed' => true, 'id' => $id), 200);
    }

    public function capturedPromoted(int $id, string $from, string $to, int $code): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'id'   => $id,
            'from' => $from,
            'to'   => $to,
            'code' => $code,
        ), 200);
    }

    /**
     * @param array<string, mixed> $data
     * @return \WP_REST_Response
     */
    public function stats(array $data): \WP_REST_Response {
        $redirects = isset($data['redirects']) && is_array($data['redirects']) ? $data['redirects'] : array();
        $captured  = isset($data['captured']) && is_array($data['captured']) ? $data['captured'] : array();

        return new \WP_REST_Response(array(
            'redirects' => array(
                'auto_301'   => $this->scalarInt($redirects, 'auto301'),
                'auto_302'   => $this->scalarInt($redirects, 'auto302'),
                'manual_301' => $this->scalarInt($redirects, 'manual301'),
                'manual_302' => $this->scalarInt($redirects, 'manual302'),
                'trashed'    => $this->scalarInt($redirects, 'trashed'),
            ),
            'captured' => array(
                'captured' => $this->scalarInt($captured, 'captured'),
                'ignored'  => $this->scalarInt($captured, 'ignored'),
                'trashed'  => $this->scalarInt($captured, 'trashed'),
            ),
        ), 200);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalarInt(array $source, string $key): int {
        $value = $source[$key] ?? 0;
        return intval(is_scalar($value) ? $value : 0);
    }

    /**
     * @param array<string, mixed> $redirect
     */
    public function testStoredMatch(string $normalizedUrl, array $redirect): \WP_REST_Response {
        $finalDest = isset($redirect['final_dest']) && is_string($redirect['final_dest']) ? $redirect['final_dest'] : '';

        return new \WP_REST_Response(array(
            'matched'     => true,
            'type'        => 'stored',
            'redirect_id' => intval(is_scalar($redirect['id'] ?? 0) ? ($redirect['id'] ?? 0) : 0),
            'from'        => $normalizedUrl,
            'to'          => $finalDest,
            'code'        => intval(is_scalar($redirect['code'] ?? 301) ? ($redirect['code'] ?? 301) : 301),
            'status'      => intval(is_scalar($redirect['status'] ?? 0) ? ($redirect['status'] ?? 0) : 0),
        ), 200);
    }

    /**
     * @param array<string, mixed> $redirect
     */
    public function testRegexMatch(string $normalizedUrl, array $redirect): \WP_REST_Response {
        $id   = is_scalar($redirect['id'] ?? null) ? intval($redirect['id']) : 0;
        $dest = is_scalar($redirect['final_dest'] ?? null) ? (string)$redirect['final_dest'] : '';
        $code = is_scalar($redirect['code'] ?? null) ? intval($redirect['code']) : 301;

        return new \WP_REST_Response(array(
            'matched'     => true,
            'type'        => 'regex',
            'redirect_id' => $id,
            'from'        => $normalizedUrl,
            'to'          => $dest,
            'code'        => $code,
        ), 200);
    }

    public function testNoMatch(string $normalizedUrl): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'matched' => false,
            'url'     => $normalizedUrl,
        ), 200);
    }

    public function createFailed(): \WP_Error {
        return new \WP_Error('create_failed', __('Failed to create redirect.', '404-solution'), array('status' => 500));
    }

    public function updateFailed(string $errorCode): \WP_Error {
        return new \WP_Error('update_failed', $this->messageForRedirectUpdateError($errorCode), array('status' => 500));
    }

    public function trashFailed(string $error): \WP_Error {
        return new \WP_Error('trash_failed', $error, array('status' => 500));
    }

    public function capturedNotFound(): \WP_Error {
        return new \WP_Error('not_found', __('Captured 404 not found.', '404-solution'), array('status' => 404));
    }

    public function capturedBadRecord(): \WP_Error {
        return new \WP_Error('bad_record', __('The captured 404 record has no URL.', '404-solution'), array('status' => 500));
    }

    public function statsError(\Throwable $e): \WP_Error {
        return new \WP_Error('stats_error', $e->getMessage(), array('status' => 500));
    }

    private function messageForRedirectUpdateError(string $errorCode): string {
        if ($errorCode === 'bad_update_request') {
            return __('Error: Bad data passed for update redirect request.', '404-solution');
        }

        return sprintf(
            __('Error: Unable to update redirect data. Repository result: %s', '404-solution'),
            esc_html($errorCode)
        );
    }
}
