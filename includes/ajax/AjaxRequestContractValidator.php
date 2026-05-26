<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by the AJAX request-contract validator when the merged current
 * request fails its schema check. In production wp_send_json_error()
 * terminates through wp_die() before the throw can propagate; in tests the
 * exception bubbles so the handler never silently continues past a failed
 * validation when the wp_send_json_error stub does not exit.
 */
class ABJ_404_Solution_AjaxContractViolationException extends RuntimeException {
}

/**
 * Runtime validator for the vendored admin-ajax request contracts.
 *
 * The schemas are JSON Schema draft-07 files, but the runtime surface here is
 * WordPress form/query data, so integer fields may arrive as decimal strings.
 * This validator intentionally implements the subset used by the ajax-*.schema
 * files: required fields, closed objects, primitive types, enum, string length,
 * regex pattern, and numeric bounds.
 */
class ABJ_404_Solution_AjaxRequestContractValidator {

    const MESSAGE = 'Invalid AJAX request.';

    /**
     * Validate the merged current request and terminate the request on failure.
     *
     * On failure wp_send_json_error() is invoked (which wp_die()s in
     * production) and an ABJ_404_Solution_AjaxContractViolationException is
     * thrown so the entry-point handler bails out cleanly even when the
     * wp_send_json_error stub does not exit (parallel test harness).
     *
     * @param string $contractId
     * @return void
     */
    public static function enforceCurrentRequest(string $contractId): void {
        self::enforcePayload($contractId, self::currentRequestPayload());
    }

    /**
     * Validate a normalized payload and terminate the request on failure.
     *
     * @param string $contractId
     * @param array<mixed, mixed> $payload
     * @return void
     */
    public static function enforcePayload(string $contractId, array $payload): void {
        if (!self::requireValidPayload($contractId, $payload)) {
            throw new ABJ_404_Solution_AjaxContractViolationException(self::MESSAGE);
        }
    }

    /**
     * Validate the merged current request and send a 400 JSON error on failure.
     *
     * @param string $contractId
     * @return bool
     */
    public static function requireValidCurrentRequest(string $contractId): bool {
        return self::requireValidPayload($contractId, self::currentRequestPayload());
    }

    /**
     * Validate an already-normalized payload and send a 400 JSON error on failure.
     *
     * @param string $contractId
     * @param array<mixed, mixed> $payload
     * @return bool
     */
    public static function requireValidPayload(string $contractId, array $payload): bool {
        $result = self::validate($contractId, $payload);
        if ($result['valid']) {
            return true;
        }

        self::sendValidationError($contractId, $result['violations']);
        return false;
    }

    /**
     * @param string $contractId
     * @param array<mixed, mixed> $payload
     * @return array{valid: bool, violations: array<int, string>}
     */
    public static function validate(string $contractId, array $payload): array {
        $payload = self::normalizePayloadKeys($payload);
        unset($payload['action']);

        $schema = self::loadSchema($contractId);
        if (!is_array($schema)) {
            return self::invalid(array('schema not found or invalid for contract: ' . $contractId));
        }

        $schemaType = $schema['type'] ?? null;
        if ($schemaType !== 'object') {
            return self::invalid(array($contractId . ': top-level schema type must be object'));
        }

        $properties = self::asStringKeyedArray($schema['properties'] ?? null) ?? array();
        $violations = array();

        $required = self::asListArray($schema['required'] ?? null) ?? array();
        foreach ($required as $field) {
            if (!is_string($field)) {
                continue;
            }
            if (!array_key_exists($field, $payload)) {
                $violations[] = 'missing required field: ' . $field;
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach ($payload as $field => $_value) {
                if (!array_key_exists($field, $properties)) {
                    $violations[] = 'unexpected field: ' . (string)$field;
                }
            }
        }

        foreach ($payload as $field => $value) {
            $propertySchema = self::asStringKeyedArray($properties[$field] ?? null);
            if ($propertySchema === null) {
                continue;
            }
            $violations = array_merge(
                $violations,
                self::validateValue($field, $value, $propertySchema)
            );
        }

        if (!empty($violations)) {
            return self::invalid($violations);
        }

        return array('valid' => true, 'violations' => array());
    }

    /**
     * Merge request data the way admin-ajax handlers use it. POST wins over
     * GET for duplicate keys, matching AjaxSecurityGate's nonce lookup order.
     *
     * @return array<string, mixed>
     */
    public static function currentRequestPayload(): array {
        $payload = array();
        foreach (array($_GET, $_REQUEST, $_POST) as $source) {
            foreach ($source as $key => $value) {
                $payload[(string)$key] = $value;
            }
        }
        unset($payload['action']);
        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadSchema(string $contractId): ?array {
        /** @var array<string, array<string, mixed>|null> $cache */
        static $cache = array();

        if (array_key_exists($contractId, $cache)) {
            return $cache[$contractId];
        }

        if (!preg_match('/\Aajax-[a-z0-9-]+\z/', $contractId)) {
            $cache[$contractId] = null;
            return null;
        }

        $path = dirname(__DIR__, 2) . '/contracts/schemas/' . $contractId . '.schema.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($raw) || $raw === '') {
            $cache[$contractId] = null;
            return null;
        }

        $decoded = json_decode($raw, true);
        $cache[$contractId] = json_last_error() === JSON_ERROR_NONE
            ? self::asStringKeyedArray($decoded) : null;
        return $cache[$contractId];
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayloadKeys(array $payload): array {
        $normalized = array();
        foreach ($payload as $key => $value) {
            $normalized[(string)$key] = $value;
        }
        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function asStringKeyedArray($value): ?array {
        if (!is_array($value)) {
            return null;
        }

        $result = array();
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>|null
     */
    private static function asListArray($value): ?array {
        if (!is_array($value)) {
            return null;
        }

        $result = array();
        foreach ($value as $item) {
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private static function validateValue(string $field, $value, array $schema): array {
        $violations = array();
        $types = self::schemaTypes($schema);
        if (!empty($types) && !self::matchesAnyType($value, $types)) {
            $violations[] = $field . ' has invalid type; expected ' . implode('|', $types);
            return $violations;
        }

        $enum = array_key_exists('enum', $schema) ? self::asListArray($schema['enum']) : null;
        if ($enum !== null && !self::matchesEnum($value, $enum)) {
            $violations[] = $field . ' is not an allowed value';
        }

        return array_merge(
            $violations,
            self::validateStringConstraints($field, $value, $schema),
            self::validateNumericBounds($field, $value, $schema)
        );
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private static function validateStringConstraints(string $field, $value, array $schema): array {
        if (!is_string($value)) {
            return array();
        }

        $violations = array();
        $minLength = self::schemaInt($schema, 'minLength');
        if ($minLength !== null && strlen($value) < $minLength) {
            $violations[] = $field . ' is shorter than minLength ' . $minLength;
        }

        $maxLength = self::schemaInt($schema, 'maxLength');
        if ($maxLength !== null && strlen($value) > $maxLength) {
            $violations[] = $field . ' is longer than maxLength ' . $maxLength;
        }

        $pattern = self::schemaString($schema, 'pattern');
        if ($pattern !== null && !self::matchesPattern($value, $pattern)) {
            $violations[] = $field . ' does not match required pattern';
        }

        return $violations;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private static function validateNumericBounds(string $field, $value, array $schema): array {
        $numericValue = self::numericValue($value);
        if ($numericValue === null) {
            return array();
        }

        $violations = array();
        $minimum = self::schemaNumber($schema, 'minimum');
        if ($minimum !== null && $numericValue < $minimum) {
            $violations[] = $field . ' is less than minimum ' . self::formatNumber($minimum);
        }

        $maximum = self::schemaNumber($schema, 'maximum');
        if ($maximum !== null && $numericValue > $maximum) {
            $violations[] = $field . ' is greater than maximum ' . self::formatNumber($maximum);
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private static function schemaTypes(array $schema): array {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return array($type);
        }
        if (is_array($type)) {
            $types = array();
            foreach ($type as $candidate) {
                if (is_string($candidate)) {
                    $types[] = $candidate;
                }
            }
            return $types;
        }
        return array();
    }

    /**
     * @param mixed $value
     * @param array<int, string> $types
     * @return bool
     */
    private static function matchesAnyType($value, array $types): bool {
        foreach ($types as $type) {
            if (self::matchesType($value, $type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     */
    private static function matchesType($value, string $type): bool {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value) || (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1);
            case 'number':
                return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
            case 'boolean':
                return is_bool($value);
            case 'object':
                return is_array($value) && self::isAssoc($value);
            case 'array':
                return is_array($value) && !self::isAssoc($value);
            case 'null':
                return $value === null;
        }
        return true;
    }

    /**
     * @param mixed $value
     * @param array<int, mixed> $enum
     * @return bool
     */
    private static function matchesEnum($value, array $enum): bool {
        foreach ($enum as $allowed) {
            if ($value === $allowed) {
                return true;
            }
            if ((is_int($allowed) || is_float($allowed)) && is_string($value)
                    && is_numeric($value) && (float)$value === (float)$allowed) {
                return true;
            }
        }
        return false;
    }

    private static function matchesPattern(string $value, string $pattern): bool {
        $delimiter = '~';
        $regex = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter . 'u';
        $result = @preg_match($regex, $value);
        return $result === 1;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isAssoc(array $value): bool {
        if ($value === array()) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaInt(array $schema, string $key): ?int {
        $value = $schema[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int)$value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaString(array $schema, string $key): ?string {
        $value = $schema[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaNumber(array $schema, string $key): ?float {
        return self::numericValue($schema[$key] ?? null);
    }

    /**
     * @param mixed $value
     */
    private static function numericValue($value): ?float {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }
        return null;
    }

    private static function formatNumber(float $value): string {
        return (string)$value;
    }

    /**
     * @param string $contractId
     * @param array<int, string> $violations
     * @return void
     */
    private static function sendValidationError(string $contractId, array $violations): void {
        $message = function_exists('__') ? __(self::MESSAGE, '404-solution') : self::MESSAGE;
        wp_send_json_error(array(
            'message' => $message,
            'contract' => $contractId,
            'violations' => array_values($violations),
        ), 400);
    }

    /**
     * @param array<int, string> $violations
     * @return array{valid: bool, violations: array<int, string>}
     */
    private static function invalid(array $violations): array {
        return array('valid' => false, 'violations' => array_values($violations));
    }
}
