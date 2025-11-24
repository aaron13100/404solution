<?php

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_FunctionsMBString extends ABJ_404_Solution_Functions {

    function ord($char) {
        return mb_ord($char);
    }
    
    function strtolower($string) {
    	if ($string == null) {
    		return '';
    	}
        return mb_strtolower($string);
    }
    
    function strlen($string) {
        return mb_strlen($string);
    }
    
    function strpos($haystack, $needle, $offset = 0) {
        return mb_strpos($haystack, $needle, $offset);
    }
    
    function substr($str, $start, $length = null) {
        // PHP 8.2+ doesn't accept null for string parameter
        if ($str === null) {
            return '';
        }
        return mb_substr($str, $start, $length);
    }

    function regexMatch($pattern, $string, &$regs = null) {
        return mb_ereg($pattern, $string, $regs);
    }
    
    function regexMatchi($pattern, $string, &$regs = null) {
        return mb_eregi($pattern, $string, $regs);
    }
    
    /**  Replace regular expression with multibyte support.
     * Scans string for matches to pattern, then replaces the matched text with replacement.
     * @param string $pattern The regular expression pattern.
     * @param string $replacement The replacement text.
     * @param string $string The string being checked.
     * @return string The resultant string on success, or FALSE on error.
     */
    function regexReplace($pattern, $replacement, $string) {
        return mb_ereg_replace($pattern, $replacement, $string);
    }

    /**
     * Sanitize invalid UTF-8 byte sequences from a string.
     *
     * This method removes or replaces invalid UTF-8 byte sequences that would cause
     * database errors like "Could not perform query because it contains invalid data".
     *
     * Uses mb_convert_encoding() to strip invalid UTF-8 bytes by converting from UTF-8 to UTF-8,
     * which automatically removes any invalid sequences.
     *
     * @param string|null $string The string to sanitize
     * @return string The sanitized string with only valid UTF-8 characters
     */
    function sanitizeInvalidUTF8($string) {
        // Handle null and empty cases
        if ($string === null || $string === '') {
            return '';
        }

        // Convert to string if not already
        if (!is_string($string)) {
            $string = strval($string);
        }

        // Use mb_convert_encoding to strip invalid UTF-8 bytes
        // Converting from UTF-8 to UTF-8 removes invalid sequences
        $sanitized = mb_convert_encoding($string, 'UTF-8', 'UTF-8');

        // Additional safety: remove null bytes and control characters that might cause issues
        // Keep only valid UTF-8 characters, removing C0 control characters except whitespace
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $sanitized);

        return $sanitized;
    }

}

