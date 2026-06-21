<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * mbstring-backed implementation of ABJ_404_Solution_RegexHelper.
 *
 * Use when the PHP mbstring extension is loaded. The regex methods wrap
 * mb_ereg / mb_eregi / mb_ereg_replace, which use POSIX regex syntax (no
 * delimiters, no PCRE shorthand like \d). Callers wanting PCRE syntax must
 * use RegexHelperPreg directly.
 */
class ABJ_404_Solution_RegexHelperMb extends ABJ_404_Solution_RegexHelper {

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
}
