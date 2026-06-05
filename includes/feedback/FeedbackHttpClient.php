<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ReportPayloadJsonSchemaValidator.php';
require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * HTTP transport for feedback reports. Serializes a payload as gzipped JSON,
 * POSTs it to the developer reports endpoint, and parses the response into
 * a structured result. Has no opinions about queueing, retries, or
 * fallbacks; FeedbackTransport orchestrates those concerns.
 *
 * Result shape (see send()):
 *   ['ok' => bool, 'status' => int|null, 'reason' => string|null, 'detail' => string|null]
 */
class ABJ_404_Solution_FeedbackHttpClient {

    const HTTP_TIMEOUT = 10;

    /**
     * POST a payload to the configured endpoint.
     *
     * @param array<string, mixed> $payload Already-validated, already-redacted payload.
     * @return array<string, mixed> ok/status/reason/detail
     */
    public static function send(array $payload): array {
        $endpoint = self::resolveEndpoint();
        $wirePayload = ABJ_404_Solution_ReportPayloadJsonSchemaValidator::toWirePayload($payload);
        $json = function_exists('wp_json_encode') ? wp_json_encode($wirePayload) : json_encode($wirePayload);
        if (!is_string($json) || $json === '') {
            return array('ok' => false, 'reason' => 'json_encode_failed');
        }

        $body = function_exists('gzencode') ? gzencode($json, 6) : false;
        if ($body === false) {
            return array('ok' => false, 'reason' => 'gzencode_failed');
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'blocking' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Content-Encoding' => 'gzip',
            ),
            'body' => $body,
        ));

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $raw = $response->get_error_message();
            $msg = is_scalar($raw) ? (string)$raw : '';
            return array('ok' => false, 'reason' => 'wp_error', 'detail' => $msg);
        }

        $code = function_exists('wp_remote_retrieve_response_code') ? (int)wp_remote_retrieve_response_code($response) : 0;
        if ($code >= 200 && $code < 300) {
            return array('ok' => true, 'status' => $code);
        }
        // Surface the server's structured error message in `detail`. The dev
        // endpoint's setErrorHandler returns
        // {statusCode, error: 'validation_failed', message: '<human>', field?}
        // on schema rejections; without this extraction the admin only sees
        // "HTTP 400" and has no way to tell which field was wrong.
        $rawBody = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '';
        $detail = self::extractServerErrorDetail(is_string($rawBody) ? $rawBody : '');
        return array('ok' => false, 'reason' => 'http_' . $code, 'status' => $code, 'detail' => $detail);
    }

    /**
     * Pull the response body off a wp_remote_post() result and, when it's a
     * JSON error envelope, extract a one-line "<message> [field=<path>]"
     * detail. Falls back to a short truncated body when the response isn't
     * structured JSON, so opaque HTML error pages from a misrouted endpoint
     * still leave a fingerprint in the admin notice.
     *
     * @param string $body Raw response body from wp_remote_retrieve_body().
     * @return string Empty string if no useful detail could be extracted.
     */
    private static function extractServerErrorDetail(string $body): string {
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = '';
            if (isset($decoded['message']) && is_scalar($decoded['message'])) {
                $message = trim((string)$decoded['message']);
            }
            $field = '';
            if (isset($decoded['field']) && is_scalar($decoded['field'])) {
                $field = trim((string)$decoded['field']);
            }
            if ($message !== '' && $field !== '') {
                return $message . ' [field=' . $field . ']';
            }
            if ($message !== '') {
                return $message;
            }
        }
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }
        return strlen($trimmed) > 240 ? substr($trimmed, 0, 240) . '...' : $trimmed;
    }

    /**
     * Resolve the endpoint URL, allowing override via the
     * 'abj404_report_endpoint' filter. Falls back to a string default if
     * the constant is not defined yet (e.g. before Loader.php boots).
     *
     * @return string
     */
    private static function resolveEndpoint(): string {
        $default = defined('ABJ404_REPORT_ENDPOINT')
            ? ABJ404_REPORT_ENDPOINT
            : 'https://404solution.ajexperience.com/api/v1/reports';
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_report_endpoint', $default);
            // Reject anything that isn't a non-empty http(s) URL so a buggy
            // filter callback can never coerce wp_remote_post into a SSRF /
            // file:// / data: request, or block on a malformed host.
            if (is_string($filtered) && $filtered !== '' && self::isHttpUrl($filtered)) {
                return $filtered;
            }
            if ($filtered !== $default) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
                    'abj404_transport: abj404_report_endpoint filter returned an invalid value; using default. got=%s',
                    is_scalar($filtered) ? (string)$filtered : gettype($filtered)
                ));
            }
        }
        return $default;
    }

    /**
     * Strict-shape URL check: only accept http:// or https:// with a host
     * component. parse_url('http://') yields a parseable structure with no
     * host, so we test for that explicitly.
     *
     * @param string $url
     * @return bool
     */
    private static function isHttpUrl(string $url): bool {
        $scheme = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_SCHEME)
            : parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            return false;
        }
        $host = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_HOST)
            : parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }
}
