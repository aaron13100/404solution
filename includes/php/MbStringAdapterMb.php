<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * mbstring-backed implementation of ABJ_404_Solution_MbStringAdapter.
 *
 * Use when the PHP mbstring extension is loaded. The regex methods wrap
 * mb_ereg / mb_eregi / mb_ereg_replace, which use POSIX regex syntax (no
 * delimiters, no PCRE shorthand like \d). Callers wanting PCRE syntax must
 * use MbStringAdapterPreg directly.
 */
class ABJ_404_Solution_MbStringAdapterMb extends ABJ_404_Solution_MbStringAdapter {

    /** @var self|null */
    private static $instance = null;

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

    /**
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    public function regexMatch(string $pattern, string $string, ?array &$regs = null) {
        return mb_ereg($pattern, $string, $regs);
    }

    /**
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    public function regexMatchi(string $pattern, string $string, ?array &$regs = null) {
        return mb_eregi($pattern, $string, $regs);
    }

    /** @return string|null */
    public function regexReplace($pattern, $replacement, $string) {
        $result = mb_ereg_replace($pattern, $replacement, $string);
        return is_string($result) ? $result : $string;
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
