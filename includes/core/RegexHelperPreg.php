<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * preg-backed implementation of ABJ_404_Solution_RegexHelper.
 *
 * Use when the PHP mbstring extension is unavailable. Also used directly
 * by callers that need PCRE regex semantics (\d, \w, lookarounds,
 * delimiters) regardless of host mbstring availability - the regex
 * methods auto-pick a delimiter and forward to preg_*.
 */
class ABJ_404_Solution_RegexHelperPreg extends ABJ_404_Solution_RegexHelper {

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
}
