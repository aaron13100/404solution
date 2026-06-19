<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/AjaxRequestContractSchemaRepository.php';
require_once __DIR__ . '/AjaxRequestContractSchemaValidator.php';
require_once __DIR__ . '/AjaxRequestContractEnforcementPolicy.php';

/**
 * Thrown by the AJAX request-contract validator when the current request
 * fails its schema check. In production wp_send_json_error() terminates
 * through wp_die() before the throw can propagate; in tests the exception
 * bubbles so handlers never continue past a failed validation when the
 * wp_send_json_error stub does not exit.
 */
class ABJ_404_Solution_AjaxContractViolationException extends RuntimeException {
}

/**
 * Public runtime adapter for the vendored admin-ajax request contracts.
 *
 * The pure requireValidPayload() path validates caller-supplied payloads
 * strictly and is used by tests and internal callers that want a hard failure
 * on any breach. The live paths -- requireValidCurrentRequest() (reads the
 * superglobals) and requireValidLivePayload() (validates a decoded packed
 * payload, e.g. the settings-save handler) -- both apply the production
 * tolerance encoded in ABJ_404_Solution_AjaxRequestContractEnforcementPolicy:
 * foreign-plugin keys in shared superglobals are tolerated everywhere, and any
 * other breach (a substantive value breach OR a missing/corrupt schema file)
 * is logged-and-allowed on production while still failing fast off production.
 * That keeps a deployment fault (a lost schema file) from bricking a core
 * feature for real users while preserving the dev/CI signal.
 */
class ABJ_404_Solution_AjaxRequestContractValidator {

    const MESSAGE = 'Invalid AJAX request.';
    const UNEXPECTED_FIELD_PREFIX = ABJ_404_Solution_AjaxRequestContractSchemaValidator::UNEXPECTED_FIELD_PREFIX;

    /** @var ABJ_404_Solution_AjaxRequestContractSchemaRepository|null */
    private static $schemaRepository = null;

    /** @var ABJ_404_Solution_AjaxRequestContractSchemaValidator|null */
    private static $schemaValidator = null;

    /** @var ABJ_404_Solution_AjaxRequestContractEnforcementPolicy|null */
    private static $enforcementPolicy = null;

    public static function enforceCurrentRequest(string $contractId): void {
        if (!self::requireValidCurrentRequest($contractId)) {
            throw new ABJ_404_Solution_AjaxContractViolationException(self::MESSAGE);
        }
    }

    /**
     * @param array<mixed, mixed> $payload
     */
    public static function enforcePayload(string $contractId, array $payload): void {
        if (!self::requireValidPayload($contractId, $payload)) {
            throw new ABJ_404_Solution_AjaxContractViolationException(self::MESSAGE);
        }
    }

    public static function requireValidCurrentRequest(string $contractId): bool {
        $result = self::validate($contractId, self::currentRequestPayload());
        if ($result['valid']) {
            return true;
        }
        if (self::enforcementPolicy()->shouldProceedDespiteViolations($contractId, $result['violations'])) {
            return true;
        }
        self::sendValidationError($contractId, $result['violations']);
        return false;
    }

    /**
     * @param array<mixed, mixed> $payload
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
     * Live-request variant of requireValidPayload() for handlers that validate a
     * decoded packed payload rather than the superglobals directly (the
     * settings-save handler decodes $_POST['encodedData'] first). Applies the
     * same production tolerance as requireValidCurrentRequest() so a missing or
     * corrupt schema degrades gracefully in production instead of bricking the
     * feature; off production it still fails fast.
     *
     * @param array<mixed, mixed> $payload
     */
    public static function requireValidLivePayload(string $contractId, array $payload): bool {
        $result = self::validate($contractId, $payload);
        if ($result['valid']) {
            return true;
        }
        if (self::enforcementPolicy()->shouldProceedDespiteViolations($contractId, $result['violations'])) {
            return true;
        }
        self::sendValidationError($contractId, $result['violations']);
        return false;
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array{valid: bool, violations: array<int, string>}
     */
    public static function validate(string $contractId, array $payload): array {
        $schema = self::schemaRepository()->loadSchema($contractId);
        if (!is_array($schema)) {
            return self::invalid(array(
                ABJ_404_Solution_AjaxRequestContractEnforcementPolicy::SCHEMA_NOT_FOUND_PREFIX . $contractId,
            ));
        }

        return self::schemaValidator()->validate($contractId, $payload, $schema);
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

    private static function schemaRepository(): ABJ_404_Solution_AjaxRequestContractSchemaRepository {
        if (self::$schemaRepository === null) {
            self::$schemaRepository = new ABJ_404_Solution_AjaxRequestContractSchemaRepository();
        }
        return self::$schemaRepository;
    }

    private static function schemaValidator(): ABJ_404_Solution_AjaxRequestContractSchemaValidator {
        if (self::$schemaValidator === null) {
            self::$schemaValidator = new ABJ_404_Solution_AjaxRequestContractSchemaValidator();
        }
        return self::$schemaValidator;
    }

    private static function enforcementPolicy(): ABJ_404_Solution_AjaxRequestContractEnforcementPolicy {
        if (self::$enforcementPolicy === null) {
            self::$enforcementPolicy = new ABJ_404_Solution_AjaxRequestContractEnforcementPolicy();
        }
        return self::$enforcementPolicy;
    }

    /**
     * @param array<int, string> $violations
     */
    private static function sendValidationError(string $contractId, array $violations): void {
        $message = function_exists('__') ? __('Invalid AJAX request.', '404-solution') : self::MESSAGE;
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
