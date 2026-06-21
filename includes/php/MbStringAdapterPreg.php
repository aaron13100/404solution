<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native string-backed implementation of ABJ_404_Solution_MbStringAdapter.
 *
 * Use when the PHP mbstring extension is unavailable. Regex primitives
 * live on ABJ_404_Solution_RegexHelperPreg (sibling extraction, task
 * i826).
 */
class ABJ_404_Solution_MbStringAdapterPreg extends ABJ_404_Solution_MbStringAdapter {

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
        return ord($char);
    }

    public function strtolower(?string $string): string {
        if ($string === null) {
            return '';
        }
        return strtolower($string);
    }

    public function strlen(string $string): int {
        return strlen($string);
    }

    /** @return int|false */
    public function strpos(string $haystack, string $needle, int $offset = 0) {
        if ($offset === 0) {
            return strpos($haystack, $needle);
        }
        return strpos($haystack, $needle, $offset);
    }

    public function substr(?string $str, int $start, ?int $length = null): string {
        if ($str === null) {
            return '';
        }
        if ($length === null) {
            return substr($str, $start);
        }
        return substr($str, $start, $length);
    }

    public function sanitizeInvalidUTF8(?string $string): string {
        if ($string === null || $string === '') {
            return '';
        }

        if (function_exists('iconv')) {
            // iconv may emit warnings on malformed input; suppress and fall
            // back if it returns false.
            // allow-silent-catch: iconv //IGNORE intentionally swallows
            // malformed-byte warnings; we fall through to preg below.
            $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $string);
            if ($sanitized !== false) {
                $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $sanitized) ?? $sanitized;
                return $sanitized;
            }
        }

        // Validate UTF-8 with the //u modifier; preg_replace returns null on
        // invalid input, which signals fall-through.
        $sanitized = @preg_replace('//u', '', $string);

        if ($sanitized === null) {
            // Strip invalid UTF-8 lead bytes and stranded continuation bytes.
            $sanitized = preg_replace('/[\xC0\xC1\xF5-\xFF][\x80-\xBF]*/', '', $string) ?? $string;
            $sanitized = preg_replace('/[\x80-\xBF]+/', '', $sanitized) ?? $sanitized;

            if (@preg_match('//u', $sanitized) === false) {
                // Last-resort ASCII-only filter.
                $sanitized = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $string) ?? '';
            }
        }

        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $sanitized) ?? $sanitized;
        return $sanitized;
    }
}
