<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads provider admission counters exposed to the PHP worker environment.
 *
 * Both PHP server variables and the process environment are checked because
 * LSAPI deployments do not consistently populate the same boundary. A miss
 * preserves the result of each source instead of collapsing it to an empty
 * array that could be mistaken for a healthy zero.
 */
final class ABJ_404_Solution_HostServerCounterProbe {

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function capture(string $pattern, array $environment): array {
        $sources = array('$_SERVER' => $_SERVER);
        if (($environment['status'] ?? '') === 'available'
                && is_array($environment['values'] ?? null)) {
            $sources['getenv()'] = $environment['values'];
        }

        $values = array();
        $attemptedPaths = array();
        $matched = false;
        foreach ($sources as $sourceName => $source) {
            $scan = self::scanSource($pattern, $source);
            $values += $scan['values'];
            $attemptedPaths[$sourceName] = $scan['outcome'];
            $matched = $matched || $scan['matched'];
        }
        if (!array_key_exists('getenv()', $attemptedPaths)) {
            $attemptedPaths['getenv()'] = self::environmentOutcome($environment);
        }

        ksort($values);
        if ($values !== array()) {
            return array('status' => 'available', 'values' => $values);
        }
        $reason = self::unavailableReason($environment, $matched);
        return array(
            'status' => 'unavailable',
            'reason' => $reason,
            'attempted_paths' => $attemptedPaths,
            'context' => array('php_sapi_name' => php_sapi_name()),
        );
    }

    /**
     * @param array<mixed> $source
     * @return array{values: array<string, string>, outcome: string, matched: bool}
     */
    private static function scanSource(string $pattern, array $source): array {
        $values = array();
        $matched = false;
        foreach ($source as $key => $value) {
            if (!is_string($key) || preg_match($pattern, $key) !== 1) {
                continue;
            }
            $matched = true;
            $readable = self::readableScalar($value, 64);
            if ($readable !== null) {
                $values[$key] = $readable;
            }
        }
        $outcome = !$matched
            ? 'no_matching_variables'
            : ($values === array() ? 'no_readable_counters' : 'readable_counter_found');
        return array('values' => $values, 'outcome' => $outcome, 'matched' => $matched);
    }

    /** @param array<string, mixed> $environment */
    private static function environmentOutcome(array $environment): string {
        $reason = $environment['reason'] ?? 'environment_unavailable';
        return is_scalar($reason) ? (string)$reason : 'environment_unavailable';
    }

    /** @param array<string, mixed> $environment */
    private static function unavailableReason(array $environment, bool $matched): string {
        if ($matched) {
            return 'no_readable_counters';
        }
        if (($environment['status'] ?? '') === 'available') {
            return 'no_matching_variables';
        }
        $reason = $environment['reason'] ?? null;
        return is_string($reason) && $reason !== '' ? $reason : 'environment_unavailable';
    }

    /** @param mixed $value */
    private static function readableScalar($value, int $maxLength): ?string {
        if (!is_scalar($value)) {
            return null;
        }
        $string = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $string);
        if (!is_string($ascii)) {
            return null;
        }
        $truncated = substr($ascii, 0, $maxLength);
        return is_string($truncated) ? $truncated : null;
    }
}
