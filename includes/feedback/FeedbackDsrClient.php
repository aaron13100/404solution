<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Server-to-server client for self-service diagnostic data requests.
 *
 * The browser talks only to local admin AJAX handlers. This client sends the
 * site's stored bearer token to the reports server, classifies failures into
 * curated safe summaries, and strips any server response fields that are not
 * part of the documented browser contract.
 */
class ABJ_404_Solution_FeedbackDsrClient {

    const HTTP_TIMEOUT = 10;

    /**
     * Request the diagnostic export rows for the authenticated site token.
     *
     * @param string $token Locally stored 64-hex site token.
     * @return array{ok: true, status: int, data: array<string, mixed>}|array{ok: false, status: int, message: string}
     */
    public static function exportRows(string $token): array {
        $result = self::postJson('/api/v1/dsr/export', new stdClass(), $token);
        if (empty($result['ok'])) {
            return $result;
        }

        $rows = self::extractExportRows($result['body'] ?? '');
        if ($rows === null) {
            return self::failure('invalid_response_shape', 502);
        }

        return array('ok' => true, 'status' => (int)$result['status'], 'data' => array('rows' => $rows));
    }

    /**
     * Delete diagnostic rows for the authenticated site token.
     *
     * @param string $token Locally stored 64-hex site token.
     * @param bool $confirm Server-side destructive confirmation.
     * @return array{ok: true, status: int, data: array<string, mixed>}|array{ok: false, status: int, message: string}
     */
    public static function deleteRows(string $token, bool $confirm): array {
        $result = self::postJson('/api/v1/dsr/delete', array('confirm' => $confirm), $token);
        if (empty($result['ok'])) {
            return $result;
        }

        $payload = self::extractDeletePayload($result['body'] ?? '');
        if ($payload === null) {
            return self::failure('invalid_response_shape', 502);
        }

        return array('ok' => true, 'status' => (int)$result['status'], 'data' => $payload);
    }

    /**
     * @param array<string, mixed>|stdClass $payload
     * @return array{ok: true, status: int, body: string}|array{ok: false, status: int, message: string}
     */
    private static function postJson(string $path, $payload, string $token): array {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (!is_string($json)) {
            return self::failure('json_encode_failed', 500);
        }

        $response = wp_remote_post(self::resolveEndpoint($path), array(
            'timeout' => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'blocking' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body' => $json,
        ));

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return self::failure(self::wpErrorReason($response), 502);
        }

        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int)wp_remote_retrieve_response_code($response)
            : 0;
        $body = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '';
        $bodyString = is_string($body) ? $body : '';

        if ($status >= 200 && $status < 300) {
            return array('ok' => true, 'status' => $status, 'body' => $bodyString);
        }

        return self::failure('http_' . (string)$status, $status > 0 ? $status : 502);
    }

    /**
     * @return array<int, mixed>|null
     */
    private static function extractExportRows(string $body) {
        $decoded = self::decodeJson($body);
        if ($decoded === null) {
            return null;
        }
        if (is_array($decoded) && self::isListArray($decoded)) {
            return array_values($decoded);
        }
        if (is_array($decoded) && isset($decoded['rows'])) {
            $rows = $decoded['rows'];
            if (is_array($rows)) {
                return array_values($rows);
            }
        }
        return null;
    }

    /**
     * @return array{deleted: int, matched: int, ranDelete: bool}|null
     */
    private static function extractDeletePayload(string $body) {
        $decoded = self::decodeJson($body);
        if (!is_array($decoded)) {
            return null;
        }
        if (!array_key_exists('deleted', $decoded)
            || !array_key_exists('matched', $decoded)
            || !array_key_exists('ranDelete', $decoded)) {
            return null;
        }

        return array(
            'deleted' => (int)$decoded['deleted'],
            'matched' => (int)$decoded['matched'],
            'ranDelete' => (bool)$decoded['ranDelete'],
        );
    }

    /**
     * @return mixed|null
     */
    private static function decodeJson(string $body) {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param mixed $value
     */
    private static function isListArray($value): bool {
        if (!is_array($value)) {
            return false;
        }
        if (count($value) === 0) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array{ok: false, status: int, message: string}
     */
    private static function failure(string $reason, int $status): array {
        return array('ok' => false, 'status' => $status, 'message' => self::safeFailureMessage($reason, $status));
    }

    private static function safeFailureMessage(string $reason, int $status): string {
        if ($status === 401) {
            return __('Unauthorized (401)', '404-solution');
        }
        if ($status === 429) {
            return __('Rate limited (429). Try again later.', '404-solution');
        }
        if ($reason === 'timed_out') {
            return sprintf(__('Timed out after %ds', '404-solution'), self::HTTP_TIMEOUT);
        }
        if (strpos($reason, 'wp_error:') === 0) {
            return sprintf(
                __('Network request failed (%s)', '404-solution'),
                substr($reason, strlen('wp_error:'))
            );
        }
        if ($reason === 'json_encode_failed') {
            return __('Could not prepare the diagnostic data request.', '404-solution');
        }
        if ($reason === 'invalid_response_shape') {
            return __('The reports server returned an unexpected response.', '404-solution');
        }
        if ($status >= 500) {
            return sprintf(__('Reports server error (%d)', '404-solution'), $status);
        }
        return sprintf(__('Reports server returned HTTP %d', '404-solution'), $status);
    }

    /**
     * @param mixed $error
     */
    private static function wpErrorReason($error): string {
        if (!is_object($error)) {
            return 'wp_error:unknown';
        }
        $code = method_exists($error, 'get_error_code') ? (string)$error->get_error_code() : 'unknown';
        $message = method_exists($error, 'get_error_message') ? (string)$error->get_error_message() : '';
        if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
            return 'timed_out';
        }
        $safeCode = strtolower((string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $code));
        return 'wp_error:' . ($safeCode !== '' ? $safeCode : 'unknown');
    }

    private static function resolveEndpoint(string $path): string {
        $reportEndpoint = self::resolveReportEndpoint();
        if (substr($reportEndpoint, -15) === '/api/v1/reports') {
            return substr($reportEndpoint, 0, -15) . $path;
        }
        return 'https://404solution.ajexperience.com' . $path;
    }

    private static function resolveReportEndpoint(): string {
        $default = defined('ABJ404_REPORT_ENDPOINT')
            ? ABJ404_REPORT_ENDPOINT
            : 'https://404solution.ajexperience.com/api/v1/reports';
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_report_endpoint', $default);
            if (is_string($filtered) && $filtered !== '' && self::isHttpUrl($filtered)) {
                return $filtered;
            }
            if ($filtered !== $default) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
                    'abj404_dsr: abj404_report_endpoint filter returned an invalid value; using default. got=%s',
                    is_scalar($filtered) ? (string)$filtered : gettype($filtered)
                ));
            }
        }
        return $default;
    }

    private static function isHttpUrl(string $url): bool {
        $scheme = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_SCHEME)
            : parse_url($url, PHP_URL_SCHEME);
        $host = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_HOST)
            : parse_url($url, PHP_URL_HOST);
        return ($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '';
    }
}
