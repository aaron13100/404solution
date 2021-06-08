<?php

// turn on debug for localhost etc
if ($GLOBALS['abj404_display_errors']) {
	error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_TimerHelper {
	
	private $timers = Array();

    public function __construct($timerName) {
    	$this->timers[$timerName] = new ABJ_404_Solution_Timer();
    }

    /** Also restart. */
    function start($timerName) {
    	$this->timers[$timerName] = new ABJ_404_Solution_Timer();
    }

    /** 
     * @return float in seconds
     */
    function getElapsedTime() {
    	$output = '';
    	$keys = array_keys($this->timers);
    	for ($i = 1; $i < count($this->timers); $i++) {
    		$timerAName = $keys[$i - 1];
    		$timerBName = $keys[$i];
    		$timerA = $this->timers[$timerAName];
    		$timerB = $this->timers[$timerBName];
    		$timeBetween = $timerB->getStartTime() - $timerA->getStartTime();
    		$output .= "From " . $timerAName . " to " . $timerBName . ": " . 
     			$timeBetween . "\n";
    	}
    	
    	return $output;
    }

}
