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
            return self::invalid(
                'Opis JSON Schema validator is unavailable; install opis/json-schema and load vendor/autoload.php'
            );
        }

        $schema = self::loadSchema();
        if (!$schema instanceof \stdClass) {
            return self::invalid('Could not load ' . self::SCHEMA_RELATIVE_PATH);
        }

        $data = self::payloadToJsonData(self::toWirePayload($payload));
        if (!$data instanceof \stdClass) {
            return self::invalid('Could not convert payload to JSON object for ' . self::SCHEMA_RELATIVE_PATH);
        }

        try {
            $result = (new \Opis\JsonSchema\Validator())->validate($data, $schema);
        } catch (\Throwable $e) {
            return self::invalid('Opis validator threw while checking ' . self::SCHEMA_RELATIVE_PATH . ': ' . $e->getMessage());
        }

        if ($result->isValid()) {
            return array('valid' => true, 'reason' => '', 'detail' => '');
        }

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
        if (class_exists('\Opis\JsonSchema\Validator') && class_exists('\Opis\JsonSchema\Errors\ErrorFormatter')) {
            return true;
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        return class_exists('\Opis\JsonSchema\Validator') && class_exists('\Opis\JsonSchema\Errors\ErrorFormatter');
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
}
