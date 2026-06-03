<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polymorphic mbstring/preg string-operation adapter.
 *
 * Defines the small set of byte-vs-multibyte string primitives the plugin
 * needs across hosts with and without the PHP mbstring extension. Two
 * concrete implementations live alongside this base class:
 *
 *   - ABJ_404_Solution_MbStringAdapterMb   - uses mb_* / mb_ereg_* (mbstring extension)
 *   - ABJ_404_Solution_MbStringAdapterPreg - uses native string / preg_* fallbacks
 *
 * Choose Mb when the mbstring extension is loaded; otherwise Preg. The Preg
 * variant is also used directly by callers that intentionally want PCRE
 * regex semantics regardless of host mbstring availability (e.g. SQL
 * placeholder rewrites that use \d / lookarounds, which mb_ereg's POSIX
 * syntax cannot express).
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 / M230 (parent task i802, this task i825). Functions previously
 * encoded these primitives as abstract methods on the utility base class,
 * with FunctionsMBString and FunctionsPreg implementing them via
 * inheritance. That made every collaborator that wanted these primitives
 * depend on the kitchen-sink Functions class and forced service-locator
 * re-entry from within Functions itself to fetch the polymorphic subclass.
 * Pulling the polymorphic surface into a dedicated adapter lets new callers
 * depend on only what they actually need (the 9 primitives) without
 * inheriting the rest of Functions and without re-entering the container.
 */
abstract class ABJ_404_Solution_MbStringAdapter {

    /**
     * Get the codepoint value of a single character.
     *
     * @param string $char
     * @return int
     */
    abstract public function ord(string $char): int;

    /**
     * Lowercase a string, honoring multibyte characters when available.
     *
     * @param string|null $string
     * @return string
     */
    abstract public function strtolower(?string $string): string;

    /**
     * String length, in characters when mbstring is available, in bytes otherwise.
     *
     * @param string $string
     * @return int
     */
    abstract public function strlen(string $string): int;

    /**
     * Position of $needle in $haystack starting at $offset, or false if not found.
     *
     * @param string $haystack
     * @param string $needle
     * @param int $offset
     * @return int|false
     */
    abstract public function strpos(string $haystack, string $needle, int $offset = 0);

    /**
     * Slice a string.
     *
     * @param string|null $str
     * @param int $start
     * @param int|null $length
     * @return string
     */
    abstract public function substr(?string $str, int $start, ?int $length = null): string;

    /**
     * Regex match. Mb implementations use POSIX (mb_ereg) syntax; Preg
     * implementations use PCRE syntax (without delimiters - the adapter
     * supplies them). Callers that intentionally rely on PCRE shorthand
     * classes (\d, \w, lookarounds, etc.) MUST use the Preg adapter
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
     * Strip invalid UTF-8 byte sequences and problematic control bytes from
     * a string so it is safe to pass to wpdb and to other utf8-strict
     * sinks.
     *
     * @param string|null $string
     * @return string
     */
    abstract public function sanitizeInvalidUTF8(?string $string): string;
}
