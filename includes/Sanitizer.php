<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * String, URL, and character-set sanitization service.
 *
 * Responsibilities:
 *  - sanitize_text_field_recursive: array/scalar recursive WP sanitize_text_field
 *  - escapeForXSS: strip control characters and HTML-significant punctuation
 *  - normalizeUrlString: trim + optional rawurldecode + strip invalid UTF-8/control bytes
 *  - sanitizeUrlComponent: strip invalid UTF-8/control bytes from a URL component
 *  - containsUtf8mb4Characters: detect 4-byte UTF-8 sequences (utf8mb4-only codepoints)
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split, parent task i802). This is a
 * sanitization concern distinct from the polymorphic mbstring/preg
 * adapter and from URL percent-encoding (UrlEncoder).
 *
 * Depends on ABJ_404_Solution_MbStringAdapter for sanitizeInvalidUTF8().
 * Injected via constructor - Sanitizer does not need the rest of the
 * Functions utility surface, so it depends on the smaller adapter
 * interface directly (sibling task i825 extracted the adapter).
 */
class ABJ_404_Solution_Sanitizer {

    /** @var ABJ_404_Solution_MbStringAdapter */
    private $mbAdapter;

    /**
     * Accepts either an MbStringAdapter (the focused dependency, preferred
     * for new callers) or the legacy ABJ_404_Solution_Functions kitchen
     * sink (which carries an MbStringAdapter internally). The Functions
     * variant is kept for backward compatibility with test fixtures and
     * older wiring that has not yet migrated.
     *
     * @param ABJ_404_Solution_MbStringAdapter|ABJ_404_Solution_Functions $adapter
     */
    public function __construct($adapter) {
        if ($adapter instanceof ABJ_404_Solution_MbStringAdapter) {
            $this->mbAdapter = $adapter;
        } else if ($adapter instanceof ABJ_404_Solution_Functions) {
            $this->mbAdapter = $adapter->getMbStringAdapter();
        } else {
            throw new InvalidArgumentException(
                'ABJ_404_Solution_Sanitizer requires an MbStringAdapter or Functions instance; got '
                . (is_object($adapter) ? get_class($adapter) : gettype($adapter))
            );
        }
    }

    /**
     * Recursively applies `sanitize_text_field` to strings in an array or other data structure.
     * @param mixed $data The data to sanitize. If an array, will recursively
     * apply this function to all elements.
     * @return mixed The sanitized data.
     */
    public function sanitize_text_field_recursive($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_text_field_recursive'], $data);
        }

        return sanitize_text_field(is_string($data) ? $data : (is_scalar($data) ? (string)$data : ''));
    }

    /** Escape a string to avoid Cross Site Scripting (XSS) attacks by encoding unsafe HTML characters.
     * @param string|null $value The string to be escaped.
     * @return string The escaped string.
     */
    public function escapeForXSS(?string $value): string {
        if ($value === null) {
            return '';
        }
        // Remove control characters and other unsafe characters
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        // Remove any other characters you consider unsafe
        $value = preg_replace('/[<>"\'`{}()]/u', '', $value) ?? '';

        return $value;
    }

    /**
     * Normalize a URL string for storage or matching.
     * - Optionally decode percent-encoded octets
     * - Strip invalid UTF-8/control bytes
     *
     * Accepts `mixed` (matching the original Functions::normalizeUrlString
     * signature it was extracted from) because callers reach for it with
     * raw `$_SERVER`/`$_COOKIE`/option-row values whose static type is
     * `mixed`. Non-string scalars are coerced; null/empty short-circuit
     * to ''.
     *
     * @param mixed $url
     * @param array<string, bool> $options Supported keys: decode (bool)
     * @return string
     */
    public function normalizeUrlString($url, array $options = array()) {
        $options = array_merge(array('decode' => true), $options);

        if ($url === null || $url === '') {
            return '';
        }

        if (!is_string($url)) {
            $url = is_scalar($url) ? strval($url) : '';
        }

        $url = trim($url);
        if ($options['decode']) {
            $url = rawurldecode($url);
        }

        $url = $this->mbAdapter->sanitizeInvalidUTF8($url);
        // Remove remaining control characters (keep whitespace)
        $url = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $url) ?? $url;

        return $url;
    }

    /**
     * Sanitize URL components without stripping reserved characters.
     * Keeps characters like ()[]{} for matching but removes invalid UTF-8/control bytes.
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitizeUrlComponent($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeUrlComponent'], $value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            $value = is_scalar($value) ? strval($value) : '';
        }

        $value = $this->mbAdapter->sanitizeInvalidUTF8($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return $value;
    }

    /**
     * Check whether a string contains any UTF-8 4-byte characters (codepoints > U+FFFF).
     * These characters require utf8mb4 storage; they cannot exist in a utf8mb3 or latin1 column.
     *
     * @param string $string
     * @return bool true if the string contains at least one 4-byte UTF-8 character
     */
    public function containsUtf8mb4Characters(string $string): bool {
        if ($string === '') {
            return false;
        }
        // 4-byte UTF-8 sequences start with a byte in the range F0-F4
        // followed by three continuation bytes (80-BF).
        return (bool) preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $string);
    }
}
