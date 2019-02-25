<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/** Stores a message and its importance. */
class ABJ_404_Solution_LevDistInfo {
    
    private $id;
    private $minDist;
    private $maxDist;
    
    public function __construct($id, $minDist, $maxDist) {
        $this->id = $id;
        $this->minDist = $minDist;
        $this->maxDist = $maxDist;
    }
    
    function getId() {
        return $this->id;
    }

    function getMinDist() {
        return $this->minDist;
    }

    function getMaxDist() {
        return $this->maxDist;
    }

}
