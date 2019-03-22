<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], array($GLOBALS['abj404_whitelist']))) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/** Stores a message and its importance. */
class ABJ_404_Solution_WPNotice {
    
    const ERROR = 'notice-error';
    const WARNING = 'notice-warning';
    const SUCCESS = 'notice-success';
    const INFO = 'notice-info';
    
    /** @var string */
    private $type = self::INFO;
    
    /** @var string */
    private $message = '';
    
    public function __construct($type, $message) {
        $VALID_TYPES = array(self::ERROR, self::WARNING, self::SUCCESS, self::INFO);
        if (!in_array($type, $VALID_TYPES)) {
            if (mb_strtolower($type) == 'info') {
                $type = self::INFO;
            } else if (mb_strtolower($type) == 'warning') {
                $type = self::WARNING;
            } else if (mb_strtolower($type) == 'success') {
                $type = self::SUCCESS;
            } else if (mb_strtolower($type) == 'error') {
                $type = self::ERROR;
            } else {
                throw new Exception("Invalid type passed to constructor (" . $type . "). Expected: " . 
                    json_encode($VALID_TYPES));
            }
        }
        
        $this->type = $type;
        $this->message = $message;
    }
    
    function getType() {
        return $this->type;
    }

    function getMessage() {
        return $this->message;
    }
    
}
