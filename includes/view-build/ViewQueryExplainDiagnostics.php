<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Best-effort EXPLAIN collector for Bruno-style admin redirects/captured view
 * failures.
 *
 * This collaborator is intentionally narrow and no-throw: it only probes failed
 * SELECTs against the redirects table from the Page Redirects / Captured 404s
 * tabs, never mutates state, and returns a compact error/skipped string when it
 * cannot safely collect a plan.
 */
class ABJ_404_Solution_ViewQueryExplainDiagnostics {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseCore $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $queryResult
     * @return array<int, array<string,mixed>>|string
     */
    public function collect(string $sub, string $failedQuery, array $queryResult) {
        try {
            $reason = $this->skipReason($sub, $failedQuery, $queryResult);
            if ($reason !== '') {
                return 'skipped: ' . $reason;
            }

            $result = $this->dbCore->queryAndGetResults('EXPLAIN ' . $this->stripWrappersForExplain($failedQuery), array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '' || !empty($result['timed_out'])) {
                return 'error: ' . ($err !== '' ? $err : 'timed out');
            }
            return $this->compactExplainRows(is_array($result['rows'] ?? null) ? $result['rows'] : array());
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $queryResult
     * @return string Empty when the EXPLAIN probe is allowed.
     */
    private function skipReason(string $sub, string $failedQuery, array $queryResult): string {
        $normalizedSub = strtolower($sub);
        if ($normalizedSub !== 'abj404_redirects' && $normalizedSub !== 'abj404_captured') {
            return 'not a redirects/captured admin view';
        }
        if ($failedQuery === '') {
            return 'no query supplied';
        }
        $lastError = is_string($queryResult['last_error'] ?? null) ? trim($queryResult['last_error']) : '';
        if ($lastError === '' && empty($queryResult['timed_out'])) {
            return 'query did not fail or time out';
        }

        $stripped = $this->stripWrappersForExplain($failedQuery);
        if (!preg_match('/^\\s*select\\b/i', $stripped)) {
            return 'not a SELECT query';
        }
        if (stripos($stripped, 'abj404_redirects') === false) {
            return 'not a redirects-table query';
        }
        return '';
    }

    /**
     * @param string $query
     * @return string
     */
    private function stripWrappersForExplain(string $query): string {
        $q = trim($query);
        $q = preg_replace('/^\\s*\\/\\*\\+[^*]*\\*\\/\\s*/', '', $q) ?? $q;
        $q = preg_replace('/^\\s*SET\\s+STATEMENT\\s+max_statement_time\\s*=\\s*\\d+\\s+FOR\\s+/i', '', $q) ?? $q;
        return $q;
    }

    /**
     * Keep only the planner fields useful for support and cap row/string sizes
     * so diagnostics cannot balloon a support payload.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array<string,mixed>>
     */
    private function compactExplainRows(array $rows): array {
        $allowed = array(
            'id' => true,
            'select_type' => true,
            'table' => true,
            'type' => true,
            'possible_keys' => true,
            'key' => true,
            'key_len' => true,
            'ref' => true,
            'rows' => true,
            'filtered' => true,
            'extra' => true,
        );
        $clean = array();
        foreach ($rows as $row) {
            if (count($clean) >= 10) {
                break;
            }
            if (is_object($row)) {
                $row = (array)$row;
            }
            if (!is_array($row)) {
                continue;
            }
            $out = array();
            foreach ($row as $key => $value) {
                $lower = strtolower((string)$key);
                if (!isset($allowed[$lower])) {
                    continue;
                }
                $out[$key] = $this->boundedScalar($value);
            }
            if (!empty($out)) {
                $clean[] = $out;
            }
        }
        return $clean;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function boundedScalar($value) {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return '';
        }
        $text = (string)$value;
        return strlen($text) > 500 ? substr($text, 0, 500) : $text;
    }
}
