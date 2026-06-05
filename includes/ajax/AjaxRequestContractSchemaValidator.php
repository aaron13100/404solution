<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates normalized payload arrays against the JSON-schema subset used by
 * the admin AJAX contracts.
 */
class ABJ_404_Solution_AjaxRequestContractSchemaValidator {

    const UNEXPECTED_FIELD_PREFIX = 'unexpected field: ';

    /** @var array<int, string> */
    private const KNOWN_TYPES = array('string', 'integer', 'number', 'boolean', 'object', 'array', 'null');

    /**
     * @param array<mixed, mixed> $payload
     * @param array<string, mixed> $schema
     * @return array{valid: bool, violations: array<int, string>}
     */
    public function validate(string $contractId, array $payload, array $schema): array {
        $payload = $this->normalizePayloadKeys($payload);
        unset($payload['action']);

        $schemaType = $schema['type'] ?? null;
        if ($schemaType !== 'object') {
            return $this->invalid(array($contractId . ': top-level schema type must be object'));
        }

        $properties = $this->asStringKeyedArray($schema['properties'] ?? null) ?? array();
        $violations = array();

        $required = $this->asListArray($schema['required'] ?? null) ?? array();
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
                    $violations[] = self::UNEXPECTED_FIELD_PREFIX . (string)$field;
                }
            }
        }

        foreach ($payload as $field => $value) {
            $propertySchema = $this->asStringKeyedArray($properties[$field] ?? null);
            if ($propertySchema === null) {
                continue;
            }
            $violations = array_merge(
                $violations,
                $this->validateValue($field, $value, $propertySchema)
            );
        }

        if (!empty($violations)) {
            return $this->invalid($violations);
        }

        return array('valid' => true, 'violations' => array());
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayloadKeys(array $payload): array {
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
    private function asStringKeyedArray($value): ?array {
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
    private function asListArray($value): ?array {
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
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateValue(string $field, $value, array $schema): array {
        $violations = array();
        $types = $this->schemaTypes($schema);
        $unknownTypes = $this->unknownSchemaTypes($types);
        if (!empty($unknownTypes)) {
            $violations[] = $field . ' schema has unrecognized type: ' . implode('|', $unknownTypes);
            return $violations;
        }
        if (!empty($types) && !$this->matchesAnyType($value, $types)) {
            $violations[] = $field . ' has invalid type; expected ' . implode('|', $types);
            return $violations;
        }

        $enum = array_key_exists('enum', $schema) ? $this->asListArray($schema['enum']) : null;
        if ($enum !== null && !$this->matchesEnum($value, $enum)) {
            $violations[] = $field . ' is not an allowed value';
        }

        return array_merge(
            $violations,
            $this->validateStringConstraints($field, $value, $schema),
            $this->validateNumericBounds($field, $value, $schema)
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function schemaTypes(array $schema): array {
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
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private function unknownSchemaTypes(array $types): array {
        $unknown = array();
        foreach ($types as $type) {
            if (!in_array($type, self::KNOWN_TYPES, true)) {
                $unknown[] = $type;
            }
        }
        return $unknown;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $types
     */
    private function matchesAnyType($value, array $types): bool {
        foreach ($types as $type) {
            if ($this->matchesType($value, $type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     */
    private function matchesType($value, string $type): bool {
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
                return is_array($value) && $this->isAssoc($value);
            case 'array':
                return is_array($value) && !$this->isAssoc($value);
            case 'null':
                return $value === null;
        }
        return false;
    }

    /**
     * @param mixed $value
     * @param array<int, mixed> $enum
     */
    private function matchesEnum($value, array $enum): bool {
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

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateStringConstraints(string $field, $value, array $schema): array {
        if (!is_string($value)) {
            return array();
        }

        $violations = array();
        $minLength = $this->schemaInt($schema, 'minLength');
        if ($minLength !== null && strlen($value) < $minLength) {
            $violations[] = $field . ' is shorter than minLength ' . $minLength;
        }

        $maxLength = $this->schemaInt($schema, 'maxLength');
        if ($maxLength !== null && strlen($value) > $maxLength) {
            $violations[] = $field . ' is longer than maxLength ' . $maxLength;
        }

        $pattern = $this->schemaString($schema, 'pattern');
        if ($pattern !== null && !$this->matchesPattern($value, $pattern)) {
            $violations[] = $field . ' does not match required pattern';
        }

        return $violations;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateNumericBounds(string $field, $value, array $schema): array {
        $numericValue = $this->numericValue($value);
        if ($numericValue === null) {
            return array();
        }

        $violations = array();
        $minimum = $this->schemaNumber($schema, 'minimum');
        if ($minimum !== null && $numericValue < $minimum) {
            $violations[] = $field . ' is less than minimum ' . $this->formatNumber($minimum);
        }

        $maximum = $this->schemaNumber($schema, 'maximum');
        if ($maximum !== null && $numericValue > $maximum) {
            $violations[] = $field . ' is greater than maximum ' . $this->formatNumber($maximum);
        }

        return $violations;
    }

    private function matchesPattern(string $value, string $pattern): bool {
        $delimiter = '~';
        $regex = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter . 'u';
        $result = @preg_match($regex, $value);
        return $result === 1;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool {
        if ($value === array()) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schemaInt(array $schema, string $key): ?int {
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
    private function schemaString(array $schema, string $key): ?string {
        $value = $schema[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schemaNumber(array $schema, string $key): ?float {
        return $this->numericValue($schema[$key] ?? null);
    }

    /**
     * @param mixed $value
     */
    private function numericValue($value): ?float {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }
        return null;
    }

    private function formatNumber(float $value): string {
        return (string)$value;
    }

    /**
     * @param array<int, string> $violations
     * @return array{valid: bool, violations: array<int, string>}
     */
    private function invalid(array $violations): array {
        return array('valid' => false, 'violations' => array_values($violations));
    }
}
