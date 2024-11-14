<?php

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

}

