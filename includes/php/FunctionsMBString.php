<?php

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_FunctionsMBString extends ABJ_404_Solution_Functions {
    
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
    
}

