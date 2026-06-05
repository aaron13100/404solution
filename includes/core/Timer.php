<?php


if (!defined('ABSPATH')) {
    exit;
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

    /** @var callable */
    private $currentTime;
    
    /**
     * @param callable|null $currentTime Optional clock returning the current time in seconds.
     */
    public function __construct(?callable $currentTime = null) {
        $this->currentTime = $currentTime ?: static function (): float {
            return microtime(true);
        };
        $this->start();
    }

    /** @return float */
    private function now(): float {
        return (float) call_user_func($this->currentTime);
    }

    /** Also restart.
     * @return void
     */
    function start(): void {
        $this->start = $this->now();
        $this->elapsed = 0;
        $this->isRunning = true;
    }

    /** @return float */
    function stop(): float {
        $this->stop = $this->now();
        $elapsedThisTime = $this->stop - $this->start;
        $this->elapsed += $elapsedThisTime;
        $this->isRunning = false;
        
        return $this->getElapsedTime();
    }
    
    /** @return void */
    function restartKeepElapsed(): void {
        $this->start = $this->now();
        $this->isRunning = true;
    }
    
    /** 
     * @return float in seconds
     */
    function getElapsedTime() {
        if ($this->isRunning) {
            return $this->now() - $this->start + $this->elapsed;
        }
        return $this->elapsed;
    }
    
    /** @return float */
    function getStartTime(): float {
    	return $this->start;
    }

}
