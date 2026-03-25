<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Evaluates optional conditions attached to a manual redirect.
 *
 * Conditions are stored in abj404_redirect_conditions and evaluated at
 * request time, after URL matching. Each condition has a `logic` field
 * (AND or OR) that controls how it combines with the previous result.
 *
 * Evaluation proceeds left-to-right (ordered by sort_order):
 *   - The first condition initialises the running result.
 *   - Each subsequent condition's `logic` field determines whether its
 *     outcome is ANDed or ORed into the running result.
 *
 * If no conditions are stored the redirect always fires (return true).
 */
class ABJ_404_Solution_RedirectConditionEvaluator {

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var array<int, array{type: string, result: bool}> */
    private $lastTrace = [];

    /**
     * @param ABJ_404_Solution_DataAccess $dao
     */
    public function __construct($dao) {
        $this->dao = $dao;
    }

    /**
     * Return details of the most recent shouldApplyRedirect() call.
     *
     * @return array<int, array{type: string, result: bool}>
     */
    public function getLastEvaluationTrace(): array {
        return $this->lastTrace;
    }

    /**
     * Evaluate whether conditions allow this redirect to fire.
     *
     * @param int $redirectId
     * @return bool true if redirect should proceed, false if conditions block it
     */
    public function shouldApplyRedirect(int $redirectId): bool {
        $conditions = $this->getConditionsForRedirect($redirectId);
        $this->lastTrace = [];

        // No conditions = always redirect.
        if (empty($conditions)) {
            return true;
        }

        $result = null;

        foreach ($conditions as $condition) {
            $outcome = $this->evaluateCondition($condition);

            $condType = isset($condition['condition_type']) && is_string($condition['condition_type'])
                ? $condition['condition_type'] : 'unknown';
            $this->lastTrace[] = ['type' => $condType, 'result' => $outcome];

            if ($result === null) {
                // First condition initialises the running result.
                $result = $outcome;
            } else {
                $logic = isset($condition['logic']) && is_string($condition['logic'])
                    ? strtoupper(trim($condition['logic']))
                    : 'AND';

                if ($logic === 'OR') {
                    $result = $result || $outcome;
                } else {
                    // Default to AND.
                    $result = $result && $outcome;
                }
            }
        }

        return (bool)$result;
    }

    /**
     * Get conditions for a redirect from the database.
     *
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getConditionsForRedirect(int $redirectId): array {
        return $this->dao->getRedirectConditions($redirectId);
    }

    /**
     * Evaluate a single condition against the current request.
     *
     * @param array<string, mixed> $condition
     * @return bool
     */
    private function evaluateCondition(array $condition): bool {
        $type     = isset($condition['condition_type']) && is_string($condition['condition_type'])
            ? $condition['condition_type'] : '';
        $operator = isset($condition['operator']) && is_string($condition['operator'])
            ? $condition['operator'] : 'equals';
        $value    = isset($condition['value']) && is_string($condition['value'])
            ? $condition['value'] : '';

        switch ($type) {
            case 'login_status':
                return $this->evaluateLoginStatus($operator, $value);
            case 'user_role':
                return $this->evaluateUserRole($operator, $value);
            case 'referrer':
                return $this->evaluateReferrer($operator, $value);
            case 'user_agent':
                return $this->evaluateUserAgent($operator, $value);
            case 'ip_range':
                return $this->evaluateIpRange($value);
            case 'http_header':
                return $this->evaluateHttpHeader($operator, $value);
            default:
                // Unknown condition type — treat as blocking to be safe.
                return false;
        }
    }

    /**
     * Evaluate a login_status condition.
     *
     * Expected value: 'logged_in' or 'logged_out'.
     *
     * @param string $operator (unused — login status is a boolean)
     * @param string $value
     * @return bool
     */
    private function evaluateLoginStatus(string $operator, string $value): bool {
        $isLoggedIn = function_exists('is_user_logged_in') ? is_user_logged_in() : false;

        if ($value === 'logged_in') {
            return $isLoggedIn;
        }
        if ($value === 'logged_out') {
            return !$isLoggedIn;
        }

        // Unknown value — fail safe (block redirect).
        return false;
    }

    /**
     * Evaluate a user_role condition.
     *
     * Expected operator: 'equals' (user has this role) or 'not_equals'.
     * Expected value: a WordPress role slug, e.g. 'administrator', 'editor'.
     *
     * @param string $operator
     * @param string $value
     * @return bool
     */
    private function evaluateUserRole(string $operator, string $value): bool {
        if (!function_exists('wp_get_current_user')) {
            return false;
        }

        $currentUser = wp_get_current_user();
        if (!($currentUser instanceof WP_User) || !$currentUser->exists()) {
            $hasRole = false;
        } else {
            $hasRole = in_array($value, $currentUser->roles, true);
        }

        if ($operator === 'not_equals') {
            return !$hasRole;
        }

        // 'equals' (default)
        return $hasRole;
    }

    /**
     * Evaluate a referrer condition.
     *
     * @param string $operator  equals|contains|regex|not_equals|not_contains
     * @param string $value
     * @return bool
     */
    private function evaluateReferrer(string $operator, string $value): bool {
        $referrer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])
            ? $_SERVER['HTTP_REFERER'] : '';

        return $this->matchStringValue($referrer, $operator, $value);
    }

    /**
     * Evaluate a user_agent condition.
     *
     * @param string $operator  equals|contains|regex|not_equals|not_contains
     * @param string $value
     * @return bool
     */
    private function evaluateUserAgent(string $operator, string $value): bool {
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? $_SERVER['HTTP_USER_AGENT'] : '';

        return $this->matchStringValue($userAgent, $operator, $value);
    }

    /**
     * Evaluate an ip_range condition using CIDR notation.
     *
     * Falls back to exact-match when no prefix length is specified.
     * IPv6 addresses are not in CIDR scope; they use exact match.
     *
     * @param string $value  e.g. "192.168.1.0/24" or "10.0.0.1"
     * @return bool
     */
    private function evaluateIpRange(string $value): bool {
        $clientIp = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR'] : '';

        if ($clientIp === '') {
            return false;
        }

        // No slash → exact IP match.
        if (strpos($value, '/') === false) {
            return $clientIp === $value;
        }

        $parts = explode('/', $value, 2);
        $subnet = $parts[0];
        $bits   = (int)$parts[1];

        $subnetLong = ip2long($subnet);
        $clientLong = ip2long($clientIp);

        // ip2long returns false for non-IPv4 addresses.
        if ($subnetLong === false || $clientLong === false) {
            return $clientIp === $subnet;
        }

        // Guard against invalid prefix lengths.
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            // /0 matches everything.
            return true;
        }

        $mask = ~((1 << (32 - $bits)) - 1);
        return ($clientLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Evaluate an http_header condition.
     *
     * The value field must be formatted as "Header-Name: expected-value",
     * e.g. "X-Custom-Header: myvalue".
     *
     * @param string $operator  equals|contains|regex|not_equals|not_contains
     * @param string $value     "Header-Name: expected-value"
     * @return bool
     */
    private function evaluateHttpHeader(string $operator, string $value): bool {
        // Expect "Header-Name: expected-value"
        $colonPos = strpos($value, ':');
        if ($colonPos === false) {
            return false;
        }

        $headerName    = trim(substr($value, 0, $colonPos));
        $expectedValue = trim(substr($value, $colonPos + 1));

        // Convert header name to SERVER key format: e.g. "X-Custom" → "HTTP_X_CUSTOM"
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        $actualValue = isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])
            ? $_SERVER[$serverKey] : '';

        return $this->matchStringValue($actualValue, $operator, $expectedValue);
    }

    /**
     * Match a string value against an expected value using the given operator.
     *
     * @param string $actual
     * @param string $operator  equals|contains|regex|not_equals|not_contains
     * @param string $expected
     * @return bool
     */
    private function matchStringValue(string $actual, string $operator, string $expected): bool {
        switch ($operator) {
            case 'equals':
                return $actual === $expected;

            case 'not_equals':
                return $actual !== $expected;

            case 'contains':
                return $expected !== '' && strpos($actual, $expected) !== false;

            case 'not_contains':
                return $expected === '' || strpos($actual, $expected) === false;

            case 'regex':
                if ($expected === '') {
                    return false;
                }
                // Suppress errors for invalid patterns — treat as non-match.
                $matched = @preg_match($expected, $actual);
                return $matched === 1;

            default:
                // Unknown operator — fail safe.
                return false;
        }
    }
}
