<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_FunctionsPreg extends ABJ_404_Solution_Functions {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_FunctionsPreg();
		}
		
		return self::$instance;
	}
	
	/** Use this to find a delimiter. 
     * @var array */
    private $delimiterChars = array('`', '^', '|', '~', '!', ';', ':', ',', '@', "'", '/');

    function ord($char) {
        return ord($char);
    }
    
    function strtolower($string) {
        return strtolower($string);
    }
    
    function strlen($string) {
        return strlen($string);
    }
    
    function strpos($haystack, $needle, $offset = 0) {
        if ($offset == 0) {
            return strpos($haystack, $needle);
        }
        return strpos($haystack, $needle, $offset);
    }
    
    function substr($str, $start, $length = null) {
        if ($length == null) {
            return substr($str, $start);
        }
        return substr($str, $start, $length);
    }

    function regexMatch($pattern, $string, &$regs = null) {
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_match($delimiterA . $pattern . $delimiterB, $string, $regs);
    }
    
    function regexMatchi($pattern, $string, &$regs = null) {
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_match($delimiterA . $pattern . $delimiterB . 'i', $string, $regs);
    }
    
    function regexReplace($pattern, $replacement, $string) {
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        $replacementDelimiter = $this->findADelimiter($replacement);
        $replacement = preg_replace($replacementDelimiter . '\\\\' . $replacementDelimiter, '\$', $replacement);
        return preg_replace($delimiterA . $pattern . $delimiterB, $replacement, $string);
    }
    
    function findADelimiter($pattern) {
        if ($pattern == '') {
            return $this->delimiterChars[0];
        }
        
        $charToUse = null;
        foreach ($this->delimiterChars as $char) {
            $anArray = explode($char, $pattern);
            if (sizeof($anArray) == 1) {
                $charToUse = $char;
                break;
            }
        }
        
        if ($charToUse == null) {
            throw new Exception("I can't find a valid delimiter character to use for the regular expression: "
                    . esc_html($pattern));
        }
        
        return $charToUse;
    }

    /**
     * Sanitize invalid UTF-8 byte sequences from a string.
     *
     * This is the fallback implementation for systems without mbstring extension.
     * It uses preg_replace with the 'u' modifier to remove invalid UTF-8 sequences.
     *
     * The approach:
     * 1. Use iconv if available (faster and more reliable)
     * 2. Fall back to preg_replace to remove non-UTF-8 bytes
     * 3. Remove control characters that cause database issues
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

        // Try iconv first (if available, it's very efficient)
        if (function_exists('iconv')) {
            // iconv with //IGNORE will skip invalid UTF-8 sequences
            // iconv can emit notices on malformed input; we treat those as expected and fall back.
            $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $string);

            // iconv returns false on error, fall through to preg approach
            if ($sanitized !== false) {
                // Remove null bytes and problematic control characters
                $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $sanitized);
                return $sanitized;
            }
        }

        // Fallback: use preg_replace with 'u' modifier to validate UTF-8
        // The //u modifier forces UTF-8 mode - invalid sequences cause match failure
        // By replacing '' with '', we essentially validate and keep only valid UTF-8
        $sanitized = @preg_replace('//u', '', $string);

        // If preg_replace failed (invalid UTF-8), use byte-by-byte filtering
        if ($sanitized === null) {
            // Filter out invalid UTF-8 lead bytes:
            // - C0, C1 (overlong 2-byte sequences)
            // - F5-FF (invalid lead bytes beyond UTF-8 range)
            // Keep valid ranges: C2-DF (2-byte), E0-EF (3-byte), F0-F4 (4-byte)
            $sanitized = preg_replace('/[\xC0\xC1\xF5-\xFF][\x80-\xBF]*/', '', $string);

            // Remove incomplete sequences (continuation bytes without lead byte)
            $sanitized = preg_replace('/[\x80-\xBF]+/', '', $sanitized);

            // Verify the result is now valid UTF-8 by attempting a UTF-8 match
            if (@preg_match('//u', $sanitized) === false) {
                // Still invalid - fall back to ASCII-only (safe but lossy)
                $sanitized = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $string);
            }
        }

        // Remove null bytes and other problematic control characters
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $sanitized);

        return $sanitized;
    }

}
