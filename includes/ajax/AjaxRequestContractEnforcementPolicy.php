<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether live admin-ajax contract violations may proceed.
 *
 * A non-foreign violation (a substantive value breach OR a structural problem
 * such as a missing/corrupt schema file) is tolerated on production sites:
 * logged and allowed through, because the downstream nonce, capability, and
 * input-sanitization checks still guard the request, and the alternative is
 * bricking a core feature for real users over a deployment fault. Off
 * production the same violation fails fast so the gap is caught in dev/CI.
 * Foreign-plugin keys in shared superglobals are tolerated everywhere.
 *
 * History: structural schema failures used to hard-fail on every environment.
 * Incident 2026-06-19 (the WordPress.org release shipped without contracts/,
 * so every schema lookup returned "schema not found" and contract-validated
 * AJAX died with "Invalid AJAX request.") showed that a missing schema file
 * must degrade gracefully in production rather than block the user.
 */
class ABJ_404_Solution_AjaxRequestContractEnforcementPolicy {

    const UNEXPECTED_FIELD_PREFIX = 'unexpected field: ';
    const SCHEMA_NOT_FOUND_PREFIX = 'schema not found or invalid for contract: ';

    /**
     * @param array<int, string> $violations
     */
    public function shouldProceedDespiteViolations(string $contractId, array $violations): bool {
        $substantive = $this->substantiveViolations($violations);
        if (empty($substantive)) {
            return true;
        }
        if ($this->isProductionEnvironment()) {
            $this->logToleratedViolations($contractId, $substantive);
            return true;
        }
        return false;
    }

    /**
     * @param array<int, string> $violations
     * @return array<int, string>
     */
    private function substantiveViolations(array $violations): array {
        $prefixLength = strlen(self::UNEXPECTED_FIELD_PREFIX);
        $substantive = array();
        foreach ($violations as $violation) {
            if (strncmp($violation, self::UNEXPECTED_FIELD_PREFIX, $prefixLength) !== 0) {
                $substantive[] = $violation;
            }
        }
        return $substantive;
    }

    private function isProductionEnvironment(): bool {
        if (!function_exists('wp_get_environment_type')) {
            return false;
        }
        return wp_get_environment_type() === 'production';
    }

    /**
     * @param array<int, string> $violations
     */
    private function logToleratedViolations(string $contractId, array $violations): void {
        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (!is_object($logger) || !method_exists($logger, 'debugMessage')) {
            return;
        }
        $logger->debugMessage('AJAX request contract "' . $contractId
            . '" had violations but was allowed to proceed (production tolerance): '
            . implode('; ', array_values($violations)));
    }
}
