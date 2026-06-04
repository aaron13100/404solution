<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes request values that arrive from WordPress superglobals.
 */
class ABJ_404_Solution_RequestInputNormalizer {

    /**
     * Safely unslash request data when wp_unslash exists and is callable.
     * Some test environments report wp_unslash as existing but throw when called.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function safeWpUnslash($value) {
        if (!function_exists('wp_unslash')) {
            return $value;
        }

        try {
            return wp_unslash($value);
        } catch (Throwable $e) { // allow-silent-catch: wp_unslash() failure; pass-through preserves the original value which is always usable
            return $value;
        }
    }

    /**
     * Normalize request input to a scalar string to avoid warnings when arrays/objects are passed.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeScalar($value): string {
        $value = self::safeWpUnslash($value);
        if (!is_scalar($value)) {
            return '';
        }
        return (string)$value;
    }

    /**
     * Normalize and sanitize feedback issue selections from request data.
     *
     * @param mixed $issuesRaw
     * @return array<int, string>
     */
    public static function sanitizeFeedbackIssues($issuesRaw): array {
        $issuesRaw = self::safeWpUnslash($issuesRaw);
        if (!is_array($issuesRaw)) {
            $issuesRaw = array($issuesRaw);
        }

        $issues = array();
        foreach ($issuesRaw as $issue) {
            if (!is_scalar($issue)) {
                continue;
            }
            $clean = sanitize_text_field((string)$issue);
            if ($clean !== '') {
                $issues[] = $clean;
            }
        }
        return $issues;
    }
}
