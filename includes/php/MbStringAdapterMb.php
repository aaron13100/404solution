<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * mbstring-backed implementation of ABJ_404_Solution_MbStringAdapter.
 *
 * Use when the PHP mbstring extension is loaded. Regex primitives live
 * on ABJ_404_Solution_RegexHelperMb (sibling extraction, task i826).
 */
class ABJ_404_Solution_MbStringAdapterMb extends ABJ_404_Solution_MbStringAdapter {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function ord(string $char): int {
        return mb_ord($char);
    }

    public function strtolower(?string $string): string {
        if ($string === null) {
            return '';
        }
        return mb_strtolower($string);
    }

    public function strlen(string $string): int {
        return mb_strlen($string);
    }

    /** @return int|false */
    public function strpos(string $haystack, string $needle, int $offset = 0) {
        return mb_strpos($haystack, $needle, $offset);
    }

    public function substr(?string $str, int $start, ?int $length = null): string {
        if ($str === null) {
            return '';
        }
        return mb_substr($str, $start, $length);
    }

    public function sanitizeInvalidUTF8(?string $string): string {
        if ($string === null || $string === '') {
            return '';
        }
        // Converting UTF-8 to UTF-8 drops invalid sequences. mb_convert_encoding
        // can return false on hard failure; treat that as "nothing salvageable".
        $sanitized = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        if (!is_string($sanitized)) {
            return '';
        }
        // Remove null bytes and C0 control characters (keep \t, \n, \r).
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $sanitized) ?? $sanitized;
        return $sanitized;
    }
}
