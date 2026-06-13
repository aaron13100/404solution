<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether live admin-ajax contract violations may proceed.
 */
class ABJ_404_Solution_AjaxRequestContractEnforcementPolicy {

    const UNEXPECTED_FIELD_PREFIX = 'unexpected field: ';
    const SCHEMA_NOT_FOUND_PREFIX = 'schema not found or invalid for contract: ';
    const TOP_LEVEL_SCHEMA_SUFFIX = ': top-level schema type must be object';
    const UNRECOGNIZED_TYPE_FRAGMENT = ' schema has unrecognized type: ';

    /**
     * @param array<int, string> $violations
     */
    public function shouldProceedDespiteViolations(string $contractId, array $violations): bool {
        if ($this->hasStructuralViolations($violations)) {
            return false;
        }

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
     */
    private function hasStructuralViolations(array $violations): bool {
        foreach ($violations as $violation) {
            if (strncmp($violation, self::SCHEMA_NOT_FOUND_PREFIX, strlen(self::SCHEMA_NOT_FOUND_PREFIX)) === 0) {
                return true;
            }
            if (substr($violation, -strlen(self::TOP_LEVEL_SCHEMA_SUFFIX)) === self::TOP_LEVEL_SCHEMA_SUFFIX) {
                return true;
            }
            if (strpos($violation, self::UNRECOGNIZED_TYPE_FRAGMENT) !== false) {
                return true;
            }
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
