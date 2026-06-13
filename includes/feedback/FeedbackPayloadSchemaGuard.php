<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PayloadSchema.php';
require_once __DIR__ . '/ReportPayloadJsonSchemaValidator.php';
require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Schema gate for feedback report payloads. Owns every check that gives
 * the transport layer confidence the payload will not be rejected at the
 * developer endpoint, plus the PII redaction sweep that runs immediately
 * before any network/email send.
 *
 *   normalize()           - coerce schema-edge values (empty contact_email -> null)
 *   validate()            - shared report.schema.json check (logs on failure)
 *   assert()              - throws when buildPayload() output would 400 the server
 *   redact()              - run PII redaction over string payload values
 *   logContractWarnings() - log per-type contract warnings from the local schema
 */
class ABJ_404_Solution_FeedbackPayloadSchemaGuard {

    /**
     * Coerce edge cases that the report schema rejects but callers commonly
     * supply (e.g. empty-string contact_email vs null).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array {
        if (array_key_exists('contact_email', $payload) && is_string($payload['contact_email']) &&
            trim($payload['contact_email']) === '') {
            $payload['contact_email'] = null;
        }
        return $payload;
    }

    /**
     * Validate a payload against the shared report.schema.json. Logs a warn
     * on failure with the detail string so admins can correlate logs.
     *
     * @param array<string, mixed> $payload
     * @param string $type One of: error, heartbeat, uninstall, support_request
     * @return array{valid: bool, reason: string, detail: string}
     */
    public static function validate(array $payload, string $type): array {
        $validation = ABJ_404_Solution_ReportPayloadJsonSchemaValidator::validate($payload);
        if (!empty($validation['valid'])) {
            return $validation;
        }

        $detail = isset($validation['detail']) && is_scalar($validation['detail'])
            ? (string)$validation['detail']
            : '';
        ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
            'abj404_transport: type=%s contract_validation_failed detail=%s',
            $type,
            $detail
        ));
        return $validation;
    }

    /**
     * Throw if the payload violates the shared schema. Used by the builder
     * to fail-fast on producer drift before a malformed payload reaches
     * the transport layer.
     *
     * @param array<string, mixed> $payload
     * @param string $type One of: error, heartbeat, uninstall, support_request
     * @param string $context Caller name (e.g. 'buildPayload') for the
     *                        exception message.
     * @return void
     */
    public static function assert(array $payload, string $type, string $context): void {
        $validation = self::validate($payload, $type);
        if (!empty($validation['valid'])) {
            return;
        }

        $detail = isset($validation['detail']) && is_scalar($validation['detail'])
            ? (string)$validation['detail']
            : 'unknown validation failure';
        throw new \UnexpectedValueException(sprintf(
            'FeedbackTransport::%s produced payload that violates %s for type=%s: %s',
            $context,
            ABJ_404_Solution_ReportPayloadJsonSchemaValidator::SCHEMA_RELATIVE_PATH,
            $type,
            $detail
        ));
    }

    /**
     * Run PII redaction over every string value in a payload before it
     * leaves the plugin (defense-in-depth for the transport layer).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array {
        if (!function_exists('abj_service_optional')) {
            return $payload;
        }
        /** @var ABJ_404_Solution_PiiRedactor|null $redactor */
        $redactor = abj_service_optional('pii_redactor');
        if (!$redactor instanceof ABJ_404_Solution_PiiRedactor) {
            return $payload;
        }
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                if (in_array($key, array('user_message', 'reply_email', 'contact_email', 'debug_log_excerpt'), true)) {
                    continue;
                }
                $payload[$key] = $redactor->redact($value);
            }
        }
        return $payload;
    }

    /**
     * Log warn-level contract warnings for any per-type local schema
     * violations. The local schema is a tighter subset of the shared
     * report schema that catches producer drift the shared schema would
     * accept but the server treats as malformed.
     *
     * @param array<string, mixed> $payload
     * @param string $type One of: error, heartbeat, uninstall, support_request
     * @return void
     */
    public static function logContractWarnings(array $payload, string $type): void {
        static $schemas = null;
        if ($schemas === null) {
            $schemaFile = __DIR__ . '/../schema/feedback_payload_schema.php';
            if (!file_exists($schemaFile)) {
                return;
            }
            $schemas = require $schemaFile;
        }
        if (!isset($schemas[$type])) {
            return;
        }
        $violations = ABJ_404_Solution_PayloadSchema::validate($schemas[$type], $payload);
        if (empty($violations)) {
            return;
        }
        ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
            'abj404_transport: internal payload contract warning for type=%s: %s',
            $type,
            implode('; ', $violations)
        ));
    }
}
