<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native string / preg-backed implementation of ABJ_404_Solution_MbStringAdapter.
 *
 * Use when the PHP mbstring extension is unavailable. Also used directly
 * by callers that need PCRE regex semantics (\d, \w, lookarounds,
 * delimiters) regardless of host mbstring availability - the regex
 * methods auto-pick a delimiter and forward to preg_*.
 */
class ABJ_404_Solution_MbStringAdapterPreg extends ABJ_404_Solution_MbStringAdapter {

    /** @var self|null */
    private static $instance = null;

    /**
     * Candidate delimiter characters tried in order when wrapping a PCRE
     * pattern. The first one that does not appear in the pattern wins.
     *
     * @var array<int, string>
     */
    private $delimiterChars = array('`', '^', '|', '~', '!', ';', ':', ',', '@', "'", '/');

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

    /**
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    public function regexMatch(string $pattern, string $string, ?array &$regs = null) {
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        $regs = $regs ?? [];
        return preg_match($delimiterA . $pattern . $delimiterB, $string, $regs);
    }

    /**
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    public function regexMatchi(string $pattern, string $string, ?array &$regs = null) {
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        $regs = $regs ?? [];
        return preg_match($delimiterA . $pattern . $delimiterB . 'i', $string, $regs);
    }

    /** @return string|null */
    public function regexReplace($pattern, $replacement, $string) {
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        $replacementDelimiter = $this->findADelimiter($replacement);
        $replacement = preg_replace($replacementDelimiter . '\\\\' . $replacementDelimiter, '\$', $replacement) ?? $replacement;
        return preg_replace($delimiterA . $pattern . $delimiterB, $replacement, $string);
    }

    /**
     * Pick a delimiter character that does not occur in $pattern so the
     * pattern can be wrapped as PCRE input without conflicting.
     *
     * @param string $pattern
     * @return string
     */
    public function findADelimiter(string $pattern): string {
        if ($pattern === '') {
            return $this->delimiterChars[0];
        }

        foreach ($this->delimiterChars as $char) {
            if ($char === '') { continue; }
            $parts = explode($char, $pattern);
            if (count($parts) === 1) {
                return $char;
            }
        }

        throw new Exception("I can't find a valid delimiter character to use for the regular expression: "
                . esc_html($pattern));
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
