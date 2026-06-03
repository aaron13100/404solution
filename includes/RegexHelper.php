<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polymorphic regex service.
 *
 * Owns the three regex primitives the plugin needs across hosts with and
 * without the PHP mbstring extension, plus the urlLooksLikeRegex URL
 * classifier. Two concrete implementations live alongside this base class:
 *
 *   - ABJ_404_Solution_RegexHelperMb   - uses mb_ereg / mb_eregi / mb_ereg_replace
 *   - ABJ_404_Solution_RegexHelperPreg - uses native preg_match / preg_replace
 *
 * Choose Mb when the mbstring extension is loaded; otherwise Preg. The Preg
 * variant is also used directly by callers that intentionally want PCRE
 * regex semantics regardless of host mbstring availability (e.g. SQL
 * placeholder rewrites that use \d / lookarounds, which mb_ereg's POSIX
 * syntax cannot express).
 *
 * Extracted from ABJ_404_Solution_MbStringAdapter per design-audit-2026-06-02
 * M201 (parent task i802, this task i826). The adapter previously carried
 * both byte/multibyte string primitives and regex primitives in the same
 * interface; splitting regex off lets callers depend on the smaller surface
 * they actually need.
 */
abstract class ABJ_404_Solution_RegexHelper {

    /**
     * Regex match. Mb implementations use POSIX (mb_ereg) syntax; Preg
     * implementations use PCRE syntax (without delimiters - the helper
     * supplies them). Callers that intentionally rely on PCRE shorthand
     * classes (\d, \w, lookarounds, etc.) MUST use the Preg helper
     * directly, not the polymorphic one.
     *
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    abstract public function regexMatch(string $pattern, string $string, ?array &$regs = null);

    /**
     * Case-insensitive regex match. Same syntax caveat as regexMatch().
     *
     * @param string $pattern
     * @param string $string
     * @param array<int, string>|null $regs
     * @return bool|int
     */
    abstract public function regexMatchi(string $pattern, string $string, ?array &$regs = null);

    /**
     * Regex replace. Same syntax caveat as regexMatch().
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $string
     * @return string|null
     */
    abstract public function regexReplace($pattern, $replacement, $string);

    /**
     * Check whether a URL appears to contain regex syntax.
     *
     * This is a PCRE-pattern URL classifier used to warn users when a
     * redirect URL looks like it contains regex syntax but is not marked
     * as a regex redirect. Implementation is PCRE-only by definition (the
     * patterns it scans for are PCRE constructs like \d, \w, lookarounds,
     * etc.), so the same concrete method serves both Mb and Preg variants
     * - it is not polymorphic.
     *
     * @param string|null $url
     * @return bool true if the URL appears to contain regex patterns
     */
    public function urlLooksLikeRegex($url) {
        if ($url === null || $url === '' || !is_string($url)) {
            return false;
        }

        $regexIndicators = array(
            '/\(\.\*\)/',           // (.*)  - common capture-all pattern
            '/\(\.\+\)/',           // (.+)  - one or more of anything
            '/\(\?\:/',             // (?:   - non-capturing group
            '/\(\?=/',              // (?=   - positive lookahead
            '/\(\?!/',              // (?!   - negative lookahead
            '/\[\^[^\]]+\]/',       // [^...]  - negated character class
            '/\[[a-z]-[a-z]\]/i',   // [a-z] or [A-Z] - character range
            '/\[[0-9]-[0-9]\]/',    // [0-9] - digit range
            '/\\\\d/',              // \d    - digit shorthand
            '/\\\\w/',              // \w    - word character shorthand
            '/\\\\s/',              // \s    - whitespace shorthand
            '/\.\*/',               // .*    - match anything (greedy)
            '/\.\+/',               // .+    - match one or more of anything
            '/\.\?/',               // .?    - match zero or one of anything
            '/\{\d+,?\d*\}/',       // {n} or {n,} or {n,m} - quantifiers
            '/\|/',                 // |     - alternation
        );

        foreach ($regexIndicators as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }
}
