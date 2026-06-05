<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema-contract validator for JS-to-PHP wire payloads.
 *
 * Single source of truth for the *shape* of any payload that crosses a
 * process boundary (PHP to server HTTP POST, JS to PHP AJAX POST). Each
 * payload has a `*.schema.php` file under `includes/schema/` that returns an
 * associative array describing every field; the producer is contract-tested
 * to match it.
 *
 * Why this exists. Commit 4080ffb5 ("fix five schema-mismatch and
 * reliability bugs") landed five independent bugs in one fix because the
 * JS payload builder, the PHP builder, and the server endpoint each
 * encoded the schema independently with no shared spec. Same class of
 * defect produced 35380dcc and earlier fixes. A single declared schema
 * plus a producer-side contract test would have caught all five at
 * unit-test time.
 *
 * Why not opis/json-schema or another lib. A hand-rolled spec is smaller
 * than the JSON-Schema-of-JSON-Schema, has zero new composer deps, and
 * only needs to encode the contracts that have historically broken
 * (field name, primitive type, optional vs required, item types inside
 * arrays/objects). The lib is callable from any context (boot, tests,
 * future JS via a generator) and degrades gracefully if a field
 * specifier is malformed.
 *
 * Spec grammar. A schema is `array<string, FieldSpec>` where each
 * FieldSpec is an associative array with these keys:
 *
 *   - `type` (string, required): one of `string`, `int`, `bool`, `array`,
 *     `object`, `string|null`, `int|null`, `bool|null`, `array|null`,
 *     `object|null`, `mixed`. `object` matches a non-empty associative
 *     array (PHP has no first-class object distinct from assoc-array on
 *     the wire). `mixed` is an escape hatch; use sparingly.
 *   - `required` (bool, default true): whether the key MUST exist on the
 *     payload. Required + null-allowed means the key is present but the
 *     value may be null.
 *   - `item_type` (string, optional): when `type` is `array`, every
 *     element must match this primitive type. Default: no item check.
 *   - `key_type` (string, optional): when `type` is `object`, every key
 *     must match this type (typically `string`). Default: `string`.
 *   - `value_type` (string, optional): when `type` is `object`, every
 *     value must match this primitive type. Default: no value check.
 *   - `enum` (array<scalar>, optional): when present, the value must be
 *     strictly equal to one of the listed scalars. Stricter than `type`
 *     alone.
 *   - `description` (string, optional): human-readable note. Ignored at
 *     runtime; used by docs / future codegen.
 *
 * Unknown fields on the payload are reported as `unexpected_field`
 * violations. The schema is the closed contract: any new field on the
 * producer must be added to the schema in the same commit.
 *
 * Single entry point. `validate(array $schema, array $payload):
 * array<int, string>` returns a list of human-readable violation strings,
 * empty when the payload conforms. Callers assert `$violations === []`
 * in tests, with the list embedded in the failure message so the test
 * report names the exact field(s) that drifted.
 */
class ABJ_404_Solution_PayloadSchema {

    /**
     * Validate a payload against a schema.
     *
     * @param array<string, array<string, mixed>> $schema FieldSpec map.
     * @param array<string, mixed> $payload Wire payload to check.
     * @param string $path Dotted path prefix used in recursive calls
     *                     and for error messages. Empty at the top level.
     * @return array<int, string> Violation messages, empty on success.
     */
    public static function validate(array $schema, array $payload, string $path = ''): array {
        $violations = [];

        foreach ($schema as $field => $spec) {
            $fieldPath = $path === '' ? (string)$field : $path . '.' . $field;
            $required = !isset($spec['required']) || $spec['required'] === true;

            if (!array_key_exists($field, $payload)) {
                if ($required) {
                    $violations[] = sprintf('missing required field: %s', $fieldPath);
                }
                continue;
            }

            $value = $payload[$field];
            $type = isset($spec['type']) && is_string($spec['type']) ? $spec['type'] : 'mixed';

            $typeViolation = self::checkType($fieldPath, $type, $value);
            if ($typeViolation !== null) {
                $violations[] = $typeViolation;
                continue;
            }

            if (isset($spec['enum']) && is_array($spec['enum'])) {
                if (!in_array($value, $spec['enum'], true)) {
                    $violations[] = sprintf(
                        '%s value %s is not in enum [%s]',
                        $fieldPath,
                        self::renderScalar($value),
                        implode(', ', array_map([self::class, 'renderScalar'], $spec['enum']))
                    );
                    continue;
                }
            }

            if (self::baseType($type) === 'array' && is_array($value) && isset($spec['item_type'])) {
                $itemType = (string)$spec['item_type'];
                foreach ($value as $i => $item) {
                    $v = self::checkType($fieldPath . '[' . $i . ']', $itemType, $item);
                    if ($v !== null) {
                        $violations[] = $v;
                    }
                }
            }

            if (self::baseType($type) === 'object' && is_array($value)) {
                $keyType = isset($spec['key_type']) ? (string)$spec['key_type'] : 'string';
                $valueType = isset($spec['value_type']) ? (string)$spec['value_type'] : null;
                foreach ($value as $k => $v) {
                    $kV = self::checkType($fieldPath . '/key', $keyType, $k);
                    if ($kV !== null) {
                        $violations[] = $kV;
                    }
                    if ($valueType !== null) {
                        $vV = self::checkType($fieldPath . '[' . self::renderScalar($k) . ']', $valueType, $v);
                        if ($vV !== null) {
                            $violations[] = $vV;
                        }
                    }
                }
            }
        }

        foreach ($payload as $key => $_value) {
            if (!array_key_exists($key, $schema)) {
                $fieldPath = $path === '' ? (string)$key : $path . '.' . $key;
                $violations[] = sprintf('unexpected_field: %s (not declared in schema)', $fieldPath);
            }
        }

        return $violations;
    }

    /**
     * Check a single value against a primitive type spec. Returns null
     * when the value matches, a violation string otherwise.
     *
     * @param string $path
     * @param string $type
     * @param mixed $value
     * @return string|null
     */
    private static function checkType(string $path, string $type, $value): ?string {
        $allowNull = self::typeAllowsNull($type);
        if ($value === null) {
            return $allowNull ? null : sprintf('%s is null but type %s disallows null', $path, $type);
        }
        $base = self::baseType($type);
        switch ($base) {
            case 'string':
                return is_string($value) ? null : self::typeMismatch($path, 'string', $value);
            case 'int':
                return is_int($value) ? null : self::typeMismatch($path, 'int', $value);
            case 'bool':
                return is_bool($value) ? null : self::typeMismatch($path, 'bool', $value);
            case 'array':
                if (!is_array($value)) {
                    return self::typeMismatch($path, 'array', $value);
                }
                if (self::isAssoc($value)) {
                    return sprintf('%s expected array (list), got object (associative)', $path);
                }
                return null;
            case 'object':
                if (!is_array($value)) {
                    return self::typeMismatch($path, 'object', $value);
                }
                if ($value !== [] && !self::isAssoc($value)) {
                    return sprintf('%s expected object (associative), got list', $path);
                }
                return null;
            case 'mixed':
                return null;
            default:
                return sprintf('%s schema type %s is unknown to the validator', $path, $type);
        }
    }

    private static function typeAllowsNull(string $type): bool {
        return substr($type, -5) === '|null' || $type === 'mixed';
    }

    private static function baseType(string $type): string {
        if (substr($type, -5) === '|null') {
            return substr($type, 0, -5);
        }
        return $type;
    }

    /**
     * @param mixed $value
     */
    private static function typeMismatch(string $path, string $expected, $value): string {
        return sprintf('%s expected %s, got %s', $path, $expected, self::describeType($value));
    }

    /**
     * @param mixed $value
     */
    private static function describeType($value): string {
        if (is_array($value)) {
            return self::isAssoc($value) ? 'object' : 'array';
        }
        return gettype($value);
    }

    /**
     * Render a scalar for error messages. Falls back to gettype() for
     * non-scalars so the message stays readable even when the value is
     * an array or object.
     *
     * @param mixed $value
     */
    private static function renderScalar($value): string {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return self::describeType($value);
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function isAssoc(array $arr): bool {
        if ($arr === []) {
            return false;
        }
        // array_is_list() is PHP 8.1+; plugin still supports 7.4. Use the
        // canonical pre-8.1 idiom: a list has int-keyed sequential keys 0..N-1.
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
