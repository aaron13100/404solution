<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses and validates REST API request parameters for the abj404/v1 routes.
 *
 * This keeps scalar coercion, pagination limits, status filter mapping,
 * redirect-code defaulting, and destination safety in one boundary module
 * instead of scattering request parsing across route callbacks.
 */
final class ABJ_404_Solution_RestApiRequestParser {

    /**
     * @param \WP_REST_Request $request
     * @return array{page: int, per_page: int}
     */
    public function pagination($request): array {
        $rawPage    = $request->get_param('page');
        $rawPerPage = $request->get_param('per_page');

        return array(
            'page'     => max(1, absint(is_scalar($rawPage) ? $rawPage : 1)),
            'per_page' => min(100, max(1, absint(is_scalar($rawPerPage) ? $rawPerPage : 20))),
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{page: int, per_page: int, status_filter: string, filter_text: string}|WP_Error
     */
    public function redirectsList($request) {
        $pagination = $this->pagination($request);
        $rawStatus  = $request->get_param('status');
        $rawFilter  = $request->get_param('filter');
        $status     = sanitize_text_field(is_scalar($rawStatus) ? (string)$rawStatus : '');
        $filter     = sanitize_text_field(is_scalar($rawFilter) ? (string)$rawFilter : '');

        $statusFilter = $this->statusStringToNumericFilter($status);
        if ($statusFilter === null) {
            return new \WP_Error(
                'invalid_status',
                __('Unknown status filter. Valid values are: manual, auto, regex (or omit for all active).', '404-solution'),
                array('status' => 400)
            );
        }

        return array(
            'page'          => $pagination['page'],
            'per_page'      => $pagination['per_page'],
            'status_filter' => $statusFilter,
            'filter_text'   => $filter,
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{from: string, to: string, code: int, regex: bool}|WP_Error
     */
    public function createRedirect($request) {
        $rawFrom  = $request->get_param('from');
        $rawTo    = $request->get_param('to');
        $rawCode  = $request->get_param('code');
        $rawRegex = $request->get_param('regex');

        $from = trim(is_scalar($rawFrom) ? (string)$rawFrom : '');
        $to   = trim(is_scalar($rawTo) ? (string)$rawTo : '');
        if ($from === '') {
            return new \WP_Error('missing_from', __('The "from" URL is required.', '404-solution'), array('status' => 400));
        }
        if ($to === '') {
            return new \WP_Error('missing_to', __('The "to" URL is required.', '404-solution'), array('status' => 400));
        }
        if (!$this->isSafeDestination($to)) {
            return new \WP_Error('invalid_destination', __('The "to" URL must be a relative path or an http/https URL.', '404-solution'), array('status' => 400));
        }

        return array(
            'from'  => $from,
            'to'    => $to,
            'code'  => $this->redirectCode($rawCode),
            'regex' => (bool)$rawRegex,
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{id: int, from: string, to: string, code: int, regex: bool}|WP_Error
     */
    public function updateRedirect($request) {
        $rawId    = $request->get_param('id');
        $rawFrom  = $request->get_param('from');
        $rawTo    = $request->get_param('to');
        $rawCode  = $request->get_param('code');
        $rawRegex = $request->get_param('regex');

        $id   = absint(is_scalar($rawId) ? $rawId : 0);
        $from = trim(is_scalar($rawFrom) ? (string)$rawFrom : '');
        $to   = trim(is_scalar($rawTo) ? (string)$rawTo : '');

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid redirect ID.', '404-solution'), array('status' => 400));
        }
        if ($from === '' || $to === '') {
            return new \WP_Error('missing_params', __('Both "from" and "to" parameters are required.', '404-solution'), array('status' => 400));
        }
        if (!$this->isSafeDestination($to)) {
            return new \WP_Error('invalid_destination', __('The "to" URL must be a relative path or an http/https URL.', '404-solution'), array('status' => 400));
        }

        return array(
            'id'    => $id,
            'from'  => $from,
            'to'    => $to,
            'code'  => $this->redirectCode($rawCode),
            'regex' => (bool)$rawRegex,
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{id: int, to: string, code: int}|WP_Error
     */
    public function capturedRedirect($request) {
        $rawId   = $request->get_param('id');
        $rawTo   = $request->get_param('to');
        $rawCode = $request->get_param('code');

        $id = absint(is_scalar($rawId) ? $rawId : 0);
        $to = trim(is_scalar($rawTo) ? (string)$rawTo : '');

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid captured 404 ID.', '404-solution'), array('status' => 400));
        }
        if ($to === '') {
            return new \WP_Error('missing_to', __('The "to" URL is required.', '404-solution'), array('status' => 400));
        }
        if (!$this->isSafeDestination($to)) {
            return new \WP_Error('invalid_destination', __('The "to" URL must be a relative path or an http/https URL.', '404-solution'), array('status' => 400));
        }

        return array(
            'id'   => $id,
            'to'   => $to,
            'code' => $this->redirectCode($rawCode),
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{id: int}|WP_Error
     */
    public function redirectId($request) {
        $rawId = $request->get_param('id');
        $id    = absint(is_scalar($rawId) ? $rawId : 0);

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid redirect ID.', '404-solution'), array('status' => 400));
        }

        return array('id' => $id);
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{url: string}|WP_Error
     */
    public function testRedirect($request) {
        $rawUrl = $request->get_param('url');
        if ($rawUrl === null) {
            return new \WP_Error('missing_url', __('The "url" parameter is required.', '404-solution'), array('status' => 400));
        }

        return array('url' => trim(is_scalar($rawUrl) ? (string)$rawUrl : ''));
    }

    /**
     * @param string $status
     * @return string|null
     */
    public function statusStringToNumericFilter($status) {
        if ($status === '') {
            return '0';
        }
        switch (strtolower($status)) {
            case 'manual':
                return (string)ABJ404_STATUS_MANUAL;
            case 'auto':
                return (string)ABJ404_STATUS_AUTO;
            case 'regex':
                return (string)ABJ404_STATUS_REGEX;
        }

        return null;
    }

    /**
     * @param mixed $rawCode
     * @return int
     */
    private function redirectCode($rawCode): int {
        $code = absint(is_scalar($rawCode) ? $rawCode : 301);
        return in_array($code, array(301, 302), true) ? $code : 301;
    }

    private function isSafeDestination(string $to): bool {
        if (strncasecmp($to, 'http://', 7) === 0 || strncasecmp($to, 'https://', 8) === 0) {
            return true;
        }
        if (strpos($to, '//') === 0) {
            return false;
        }

        $scheme = parse_url($to, PHP_URL_SCHEME);
        return $scheme === null || $scheme === false || $scheme === '';
    }
}
