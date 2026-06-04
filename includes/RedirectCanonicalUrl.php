<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by RedirectsCanonicalUrlTest through RedirectsRepository compatibility statics

/**
 * Canonical redirect URL invariant shared by read and write paths.
 */
final class ABJ_404_Solution_RedirectCanonicalUrl {

    /** @param mixed $url @return string */
    public static function compute($url): string {
        if (!is_string($url)) {
            return '/';
        }
        $trimmed = trim($url, '/');
        if ($trimmed === '') {
            return '/';
        }
        return '/' . $trimmed;
    }

    /** @param string $columnExpr @return string */
    public static function hitsSqlExpression(string $columnExpr): string {
        return "CONCAT('/', TRIM(BOTH '/' FROM " . $columnExpr . "))";
    }
}
