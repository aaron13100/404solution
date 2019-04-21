<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_FunctionsPreg extends ABJ_404_Solution_Functions {
    
    /** Use this to find a delimiter. 
     * @var array */
    private $delimiterChars = array('`', '^', '|', '~', '!', ';', ':', ',', '@', "'", '/');
    
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
    
    function regexSplit($pattern, $subject) {
        // find a character to use for quotes
        $delimiterA = "{";
        $delimiterB = "}";
        if (strpos($pattern, "}") !== false) {
            $delimiterA = $delimiterB = $this->findADelimiter($pattern);
        }
        return preg_split($delimiterA . $pattern . $delimiterB, $subject);
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
                    . $pattern);
        }
        
        return $charToUse;
    }

}

