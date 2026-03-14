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

    /** @var mixed */
    private $message = '';

    /**
     * @param mixed $type
     * @param mixed $message
     */
    public function __construct($type, $message) {
    	$this->type = self::INFO;
    	
        $f = ABJ_404_Solution_Functions::getInstance();
        $typeStr = is_string($type) ? $type : (is_scalar($type) ? (string)$type : '');
        $VALID_TYPES = array(self::ERROR, self::WARNING, self::SUCCESS, self::INFO);
        if (!in_array($typeStr, $VALID_TYPES)) {
            if ($f->strtolower($typeStr) == 'info') {
                $typeStr = self::INFO;
            } else if ($f->strtolower($typeStr) == 'warning') {
                $typeStr = self::WARNING;
            } else if ($f->strtolower($typeStr) == 'success') {
                $typeStr = self::SUCCESS;
            } else if ($f->strtolower($typeStr) == 'error') {
                $typeStr = self::ERROR;
            } else {
                throw new Exception("Invalid type passed to constructor (" . esc_html($typeStr) . "). Expected: " .
                    (string)json_encode($VALID_TYPES));
            }
        }
        
        $this->type = $typeStr;
        $this->message = $message;
    }
    
    /** @return string */
    function getType(): string {
        return $this->type;
    }

    /** @return mixed */
    function getMessage() {
        return $this->message;
    }
    
}
