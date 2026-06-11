<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data access for conditional redirect rows.
 *
 * Keeps conditions-table existence checks, row validation, and persistence
 * isolated from redirect row lookup and write orchestration.
 */
class ABJ_404_Solution_RedirectConditionsRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logging = null) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        if (!$this->dbCore->tableNameResolver()->tableExists($table)) {
            return [];
        }

        $result = $this->dbCore->queryAndGetResults(
            "SELECT id, redirect_id, logic, condition_type, operator, value, sort_order
             FROM `{$table}`
             WHERE redirect_id = %d
             ORDER BY sort_order ASC, id ASC",
            array('query_params' => array($redirectId), 'log_errors' => false)
        );

        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("getRedirectConditions: DB error for redirect_id={$redirectId}: " . $lastError);
            return [];
        }

        $rawRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        $rows = [];
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return void
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        if (!$this->dbCore->tableNameResolver()->tableExists($table)) {
            $this->logger->warn("saveRedirectConditions: conditions table missing, skipping save for redirect_id={$redirectId}.");
            return;
        }

        $deleteResult = $this->dbCore->queryAndGetResults(
            "DELETE FROM `{$table}` WHERE redirect_id = %d",
            array('query_params' => array($redirectId), 'log_errors' => false)
        );
        $deleteError = isset($deleteResult['last_error']) && is_string($deleteResult['last_error']) ? $deleteResult['last_error'] : '';
        if ($deleteError !== '') {
            $this->logger->warn("saveRedirectConditions: error deleting old conditions for redirect_id={$redirectId}: " . $deleteError);
        }

        if (empty($conditions)) {
            return;
        }

        foreach ($conditions as $index => $cond) {
            $normalized = $this->normalizeConditionForInsert($cond, $index);
            if ($normalized === null) {
                continue;
            }

            $insertResult = $this->dbCore->queryAndGetResults(
                "INSERT INTO `{$table}` (`redirect_id`, `logic`, `condition_type`, `operator`, `value`, `sort_order`)
                 VALUES (%d, %s, %s, %s, %s, %d)",
                array(
                    'query_params' => array(
                        $redirectId,
                        $normalized['logic'],
                        $normalized['type'],
                        $normalized['operator'],
                        $normalized['value'],
                        $normalized['sort_order'],
                    ),
                    'log_errors' => false,
                )
            );
            $insertError = isset($insertResult['last_error']) && is_string($insertResult['last_error']) ? $insertResult['last_error'] : '';
            if ($insertError !== '') {
                $this->logger->warn("saveRedirectConditions: error inserting condition #{$index} for redirect_id={$redirectId}: " . $insertError);
            }
        }
    }

    /**
     * @param mixed $cond
     * @return array{logic: string, type: string, operator: string, value: string, sort_order: int}|null
     */
    private function normalizeConditionForInsert($cond, int $index) {
        if (!is_array($cond)) {
            return null;
        }

        $logic = isset($cond['logic']) && is_string($cond['logic'])
            ? strtoupper(trim($cond['logic'])) : 'AND';
        if (!in_array($logic, ['AND', 'OR'], true)) {
            $logic = 'AND';
        }

        $type = isset($cond['condition_type']) && is_string($cond['condition_type'])
            ? trim($cond['condition_type']) : '';
        if (!in_array($type, $this->allowedConditionTypes(), true)) {
            $this->logger->warn("saveRedirectConditions: unknown condition_type '{$type}', skipping.");
            return null;
        }

        $operator = isset($cond['operator']) && is_string($cond['operator'])
            ? trim($cond['operator']) : 'equals';
        if (!in_array($operator, $this->allowedOperators(), true)) {
            $operator = 'equals';
        }

        $value = isset($cond['value']) && is_string($cond['value'])
            ? trim($cond['value']) : '';
        if (strlen($value) > 1024) {
            $value = substr($value, 0, 1024);
        }

        $sortOrder = isset($cond['sort_order'])
            ? absint(is_scalar($cond['sort_order']) ? $cond['sort_order'] : 0)
            : $index;

        return array(
            'logic' => $logic,
            'type' => $type,
            'operator' => $operator,
            'value' => $value,
            'sort_order' => $sortOrder,
        );
    }

    /** @return array<int, string> */
    private function allowedConditionTypes(): array {
        return [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
    }

    /** @return array<int, string> */
    private function allowedOperators(): array {
        return [
            'equals', 'contains', 'regex',
            'not_equals', 'not_contains', 'cidr',
        ];
    }
}
