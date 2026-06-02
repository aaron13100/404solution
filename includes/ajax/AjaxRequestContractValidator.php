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
 *
 * Enforcement policy (two distinct cases, see enforceCurrentRequest /
 * requireValidCurrentRequest):
 *
 *   1. Unexpected extra fields (additionalProperties=false violations) are
 *      tolerated in EVERY environment, dev included. admin-ajax shares the
 *      $_GET/$_REQUEST/$_POST superglobals with every other active plugin,
 *      and plugins such as WooCommerce inject their own nonce fields on every
 *      request. That is outside our control and happens on developer machines
 *      too, so a foreign key must never turn into a 400 that breaks our admin
 *      AJAX surface. Unknown keys are ignored; only our own declared fields
 *      are checked. (Our own contract drift is still caught by the contract
 *      conformance tests, which run against clean superglobals.)
 *
 *   2. Substantive violations of OUR OWN declared fields (wrong type, bad
 *      enum, missing required, length/pattern/bound) are real breaches of our
 *      client's contract. These fail fast with a 400 off production so the
 *      drift is caught during development, but in production the validator
 *      "tries to work": it logs the breach (debug level, no admin email) and
 *      lets the handler proceed, since the downstream code sanitizes every
 *      value it reads and breaking a live admin screen is the worse outcome.
 *
 * The pure validate()/enforcePayload()/requireValidPayload() helpers stay
 * strict (closed-object included): they validate caller-supplied payloads, not
 * the shared live superglobals, and are what the contract tests assert against.
 */
class ABJ_404_Solution_AjaxRequestContractValidator {

    const MESSAGE = 'Invalid AJAX request.';

    /** Violation-message prefix for additionalProperties=false breaches. */
    const UNEXPECTED_FIELD_PREFIX = 'unexpected field: ';

    /**
     * Validate the merged current request and terminate the request on failure.
     *
     * Applies the live-request enforcement policy (see the class docblock):
     * unexpected extra fields are always tolerated; a substantive breach of
     * our own declared fields fails fast off production but is logged and
     * tolerated in production. On a fail-fast outcome wp_send_json_error() is
     * invoked (which wp_die()s in production for the real entry points that
     * exit first) and an ABJ_404_Solution_AjaxContractViolationException is
     * thrown so the handler bails out cleanly even when the
     * wp_send_json_error stub does not exit (parallel test harness).
     *
     * @param string $contractId
     * @return void
     */
    public static function enforceCurrentRequest(string $contractId): void {
        $result = self::validate($contractId, self::currentRequestPayload());
        if ($result['valid']) {
            return;
        }
        if (self::shouldProceedDespiteViolations($contractId, $result['violations'])) {
            return;
        }
        self::sendValidationError($contractId, $result['violations']);
        throw new ABJ_404_Solution_AjaxContractViolationException(self::MESSAGE);
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
     * Applies the live-request enforcement policy (see the class docblock):
     * returns true (request proceeds) when the only violations are unexpected
     * extra fields, or when a substantive breach occurs in production (logged
     * and tolerated). Off production, a substantive breach sends a 400 JSON
     * error and returns false.
     *
     * @param string $contractId
     * @return bool
     */
    public static function requireValidCurrentRequest(string $contractId): bool {
        $result = self::validate($contractId, self::currentRequestPayload());
        if ($result['valid']) {
            return true;
        }
        if (self::shouldProceedDespiteViolations($contractId, $result['violations'])) {
            return true;
        }
        self::sendValidationError($contractId, $result['violations']);
        return false;
    }

    /**
     * Decide whether a live request with contract violations may still proceed.
     *
     * Unexpected extra fields (foreign-plugin superglobal pollution) are
     * tolerated in every environment. A substantive breach of our own declared
     * fields is tolerated only in production, where it is logged at debug level
     * so the admin is never emailed and the live screen keeps working.
     *
     * @param string $contractId
     * @param array<int, string> $violations
     * @return bool
     */
    private static function shouldProceedDespiteViolations(string $contractId, array $violations): bool {
        $substantive = self::substantiveViolations($violations);
        if (empty($substantive)) {
            return true;
        }
        if (self::isProductionEnvironment()) {
            self::logToleratedViolations($contractId, $substantive);
            return true;
        }
        return false;
    }

    /**
     * Filter out the additionalProperties=false ("unexpected field") breaches,
     * leaving only violations of our own declared fields.
     *
     * @param array<int, string> $violations
     * @return array<int, string>
     */
    private static function substantiveViolations(array $violations): array {
        $prefixLength = strlen(self::UNEXPECTED_FIELD_PREFIX);
        $substantive = array();
        foreach ($violations as $violation) {
            if (strncmp($violation, self::UNEXPECTED_FIELD_PREFIX, $prefixLength) !== 0) {
                $substantive[] = $violation;
            }
        }
        return $substantive;
    }

    /**
     * True only on a WordPress site reporting the 'production' environment.
     * When wp_get_environment_type() is unavailable (CLI / unit tests with no
     * WP loaded) the answer is false, so the strict fail-fast path runs.
     *
     * @return bool
     */
    private static function isProductionEnvironment(): bool {
        if (!function_exists('wp_get_environment_type')) {
            return false;
        }
        return wp_get_environment_type() === 'production';
    }

    /**
     * Record a tolerated substantive contract breach at debug level so it
     * never triggers an admin email (per the plugin's error-visibility rules)
     * but is still discoverable when debug logging is on.
     *
     * @param string $contractId
     * @param array<int, string> $violations
     * @return void
     */
    private static function logToleratedViolations(string $contractId, array $violations): void {
        $logger = function_exists('abj_service') ? abj_service('logging') : null;
        if (!is_object($logger) || !method_exists($logger, 'debugMessage')) {
            return;
        }
        $logger->debugMessage('AJAX request contract "' . $contractId
            . '" had violations but was allowed to proceed (production tolerance): '
            . implode('; ', array_values($violations)));
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
                    $violations[] = self::UNEXPECTED_FIELD_PREFIX . (string)$field;
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
     * Return true only for the explicit JSON Schema primitive type names this
     * validator recognizes. An unrecognized $type string is a schema author
     * typo (e.g. 'int' instead of 'integer', 'bool' instead of 'boolean') and
     * must NOT silently pass any value: returning false here surfaces the typo
     * as a contract violation in dev so the schema gets fixed. This is a
     * schema-author error, not a runtime value-level breach, so the
     * production-lenient policy in the class docblock does not apply.
     *
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
        return false;
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
