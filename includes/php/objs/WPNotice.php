<?php


if (!defined('ABSPATH')) {
    exit;
}

/** Stores a message and its importance. */
class ABJ_404_Solution_WPNotice {
    
    const ERROR = 'notice-error';
    const WARNING = 'notice-warning';
    const SUCCESS = 'notice-success';
    const INFO = 'notice-info';
    
    /** @var string */
    private $type = null;
    
    /** @var string */
    private $message = '';
    
    public function __construct($type, $message) {
    	$this->type = self::INFO;
    	
        $f = ABJ_404_Solution_Functions::getInstance();
        $VALID_TYPES = array(self::ERROR, self::WARNING, self::SUCCESS, self::INFO);
        if (!in_array($type, $VALID_TYPES)) {
            if ($f->strtolower($type) == 'info') {
                $type = self::INFO;
            } else if ($f->strtolower($type) == 'warning') {
                $type = self::WARNING;
            } else if ($f->strtolower($type) == 'success') {
                $type = self::SUCCESS;
            } else if ($f->strtolower($type) == 'error') {
                $type = self::ERROR;
            } else {
                throw new Exception("Invalid type passed to constructor (" . esc_html($type) . "). Expected: " . 
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
