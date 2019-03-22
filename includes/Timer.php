<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_Timer {

    /** @var float */
    private $start = 0;
    
    /** @var float */
    private $stop = 0;
    
    /** @var float */
    private $elapsed = 0;
    
    /** @var bool */
    private $isRunning = false;
    
    public function __construct() {
        $this->start();
    }

    /** Also restart. */
    function start() {
        $this->start = microtime(true);
        $this->elapsed = 0;
        $this->isRunning = true;
    }

    function stop() {
        $this->stop = microtime(true);
        $this->elapsed += $this->getElapsedTime();
        $this->isRunning = false;
        
        return $this->getElapsedTime();
    }
    
    function restartKeepElapsed() {
        $this->start = microtime(true);
        $this->isRunning = true;
    }
    
    /** 
     * @return float in seconds
     */
    function getElapsedTime() {
        if ($this->isRunning) {
            return microtime(true) - $this->start + $this->elapsed;
        }
        return $this->elapsed;
    }

}
