<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates outbound report payloads against the shared server JSON Schema.
 *
 * The payload is converted through JSON before validation so PHP associative
 * arrays are checked as the object/list shapes the server actually receives.
 */
class ABJ_404_Solution_ReportPayloadJsonSchemaValidator {

    const REASON_VALIDATION_FAILED = 'contract_validation_failed';
    const SCHEMA_RELATIVE_PATH = 'contracts/schemas/report.schema.json';
    const OBJECT_FIELDS = array('resource_limits', 'extensions', 'environment_extras');

    /**
     * @param array<string, mixed> $payload
     * @return array{valid: bool, reason: string, detail: string}
     */
    public static function validate(array $payload): array {
        if (!self::ensureOpisLoaded()) {
            // opis/json-schema is a dev/test-only dependency (declared in
            // tests/composer.json, never bundled into the shipped plugin --
            // vendoring a third-party Composer package into a WordPress
            // plugin risks a global-namespace class collision with another
            // active plugin bundling a different version of the same
            // library). This is the expected state on every production
            // site, not an error condition: this check is an optional
            // fail-fast pre-flight, and the real source of truth is the
            // live server's own schema validation, which the transport
            // layer already falls back to email for on rejection. Treating
            // "can't run the optional check" as "payload is invalid" used
            // to throw out of FeedbackPayloadSchemaGuard::assert() and
            // silently drop every error/heartbeat/uninstall/support_request
            // report on any site without a coincidental Opis class
            // collision -- see project memory
            // project_opis_missing_breaks_telemetry.md for the incident.
            return self::skipped();
        }

        $schema = self::loadSchema();
        if (!$schema instanceof \stdClass) {
            // Same reasoning as above: the schema file itself failing to
            // load is an environment problem with the optional local
            // pre-flight, not evidence the payload is malformed.
            return self::skipped();
        }

        $data = self::payloadToJsonData(self::toWirePayload($payload));
        if (!$data instanceof \stdClass) {
            return self::skipped();
        }

        try {
            $result = (new \Opis\JsonSchema\Validator())->validate($data, $schema);
        } catch (\Throwable $e) {
            // Unlike the "optional pre-flight unavailable" skips above (Opis
            // missing, schema unreadable), this is the validator itself
            // throwing during a call that should have succeeded -- the
            // library was loaded and the schema/data both decoded fine.
            // That is unexpected enough (e.g. a schema-authoring bug in
            // report.schema.json) that it should be visible to a maintainer,
            // not just silently treated as "can't check locally".
            ABJ_404_Solution_FeedbackTransportLog::log('warn',
                'ReportPayloadJsonSchemaValidator: Opis validator threw during validate(): ' . $e->getMessage());
            return self::skipped();
        }

        if ($result->isValid()) {
            return array('valid' => true, 'reason' => '', 'detail' => '');
        }

        // The validator ran successfully and found a real contract
        // violation -- this is the one case that should still fail closed,
        // since it is the producer-drift bug this check exists to catch
        // (in CI/dev, where Opis is available via tests/composer.json).
        return self::invalid(self::formatValidationError($result->error()));
    }

    /**
     * Convert ambiguous PHP empty arrays to JSON objects for schema fields
     * that the server declares as objects. Non-empty associative arrays
     * already encode as JSON objects; non-empty lists are left untouched so
     * validation still catches object-vs-list drift.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function toWirePayload(array $payload): array {
        foreach (self::OBJECT_FIELDS as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === array()) {
                $payload[$field] = (object)array();
            }
        }
        return $payload;
    }

    private static function ensureOpisLoaded(): bool {
        $available = class_exists('\Opis\JsonSchema\Validator') && class_exists('\Opis\JsonSchema\Errors\ErrorFormatter');

        if (!$available) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }
            $available = class_exists('\Opis\JsonSchema\Validator') && class_exists('\Opis\JsonSchema\Errors\ErrorFormatter');
        }

        // Real extension point (a site could force this optional pre-flight
        // off) that doubles as the test seam for simulating "Opis
        // unavailable": once a PHP process has autoloaded Opis the classes
        // stay defined for the rest of its lifetime, so a normal PHPUnit run
        // (which loads tests/composer.json's Opis dependency at bootstrap)
        // can never otherwise exercise this branch.
        if (function_exists('apply_filters')) {
            $available = (bool) apply_filters('abj404_report_schema_validator_available', $available);
        }

        return $available;
    }

    private static function loadSchema(): ?\stdClass {
        static $schema = null;
        static $loaded = false;

        if ($loaded) {
            return $schema instanceof \stdClass ? $schema : null;
        }
        $loaded = true;

        $path = dirname(__DIR__, 2) . '/' . self::SCHEMA_RELATIVE_PATH;
        $raw = file_exists($path) ? file_get_contents($path) : false;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw);
        if (!$decoded instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $schema = $decoded;
        return $schema;
    }

    /**
     * @param array<string, mixed> $payload
     * @return \stdClass|null
     */
    private static function payloadToJsonData(array $payload): ?\stdClass {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json);
        if (!$decoded instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    private static function formatValidationError(?\Opis\JsonSchema\Errors\ValidationError $error): string {
        if ($error === null) {
            return self::SCHEMA_RELATIVE_PATH . ': unknown validation failure';
        }

        try {
            $formatter = new \Opis\JsonSchema\Errors\ErrorFormatter();
            $messages = self::flattenFormattedErrors($formatter->format($error));
            if (empty($messages)) {
                $messages = $formatter->formatFlat($error);
            }
        } catch (\Throwable $e) {
            return self::SCHEMA_RELATIVE_PATH . ': could not format validation failure (' . $e->getMessage() . ')';
        }

        $rendered = array();
        foreach ($messages as $message) {
            if (is_scalar($message)) {
                $rendered[] = (string)$message;
            }
        }

        if (empty($rendered)) {
            $rendered[] = 'unknown validation failure';
        }

        return self::SCHEMA_RELATIVE_PATH . ': ' . implode('; ', array_slice($rendered, 0, 8));
    }

    /**
     * @param mixed $formatted
     * @return array<int, string>
     */
    private static function flattenFormattedErrors($formatted): array {
        $out = array();
        if (!is_array($formatted)) {
            return $out;
        }

        foreach ($formatted as $path => $messages) {
            $label = (string)$path;
            if (!is_array($messages)) {
                if (is_scalar($messages)) {
                    $out[] = $label . ': ' . (string)$messages;
                }
                continue;
            }

            foreach ($messages as $message) {
                if (is_scalar($message)) {
                    $out[] = $label . ': ' . (string)$message;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{valid: bool, reason: string, detail: string}
     */
    private static function invalid(string $detail): array {
        return array(
            'valid' => false,
            'reason' => self::REASON_VALIDATION_FAILED,
            'detail' => $detail,
        );
    }

    /**
     * The optional local pre-flight could not run (missing Opis, unreadable
     * schema, encoding failure). Treated as valid -- not "the payload is
     * bad", just "this site cannot double-check it locally" -- so the
     * payload still reaches the real check on the server.
     *
     * @return array{valid: bool, reason: string, detail: string}
     */
    private static function skipped(): array {
        return array('valid' => true, 'reason' => '', 'detail' => '');
    }
}
