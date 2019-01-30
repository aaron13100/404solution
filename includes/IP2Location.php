<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Everything GeoIP/MaxMind/IP to Location related.  */
class ABJ_404_Solution_IP2Location {

    /** This turns an IP into a country. Copied from https://github.com/maxmind/MaxMind-DB-Reader-php. */
    private $reader = null;
    
    /** Stores the previous update date so that we don't have to read the file every time. */
    private $previousUpdateDateCACHE = null;
    
    /** Return true if we can attempt to geocode an ip.
     * @return type
     */
    function isSupported() {
        // return class_exists("PharData");
        
        // reactivate after the database can be downloaded consistently.
        return false;
    }
    
    /** Return true if we can turn an IP into a location.
     * @return type
     */
    function readerIsInitialized() {
        return $this->reader != null;
    }
    
    /** Get the country associated with an IP.
     * @param type $ip
     * @return string
     */
    function getCountry($ip) {
        if (empty($ip)) {
            return '';
        }
        
        if ($this->reader == null || $this->shouldUpdateDatabaseFile()) {
            $this->initializeReader();
            if ($this->reader == null) {
                throw new Exception("Issue initializing ip2location reader.");
            }
        }
        
        $answer = $this->reader->get($ip);
        if ($answer == null) {
            return '';
        }
        
        $country = '';
        if (array_key_exists('country', $answer)) {
            $country = $answer['country'];
        } else {
            return '';
        }
        
        $names = null;
        if (array_key_exists('names', $country)) {
            $names = $country['names'];
        } else {
            return '';
        }

        // only get the English name.
        if (array_key_exists('en', $names)) {
            return $names['en'];
        }
        return '';
    }
    
    /** Download the database and load it. */
    function initializeReader() {
        global $abj404logging;
        
        $databaseName = 'GeoLite2-Country.mmdb';
        $destinationDirectory = ABJ404_PATH . 'temp/';
        $databaseFile = $destinationDirectory . $databaseName;
        
        if (!file_exists($databaseFile) || $this->shouldUpdateDatabaseFile()) {
            // the database is updated every tuesday so this should update every wednesday.
            // https://support.maxmind.com/geoip-faq/geoip2-and-geoip-legacy-databases/how-often-are-the-geoip2-and-geoip-legacy-databases-updated/
            
            // find and delete temporarily downloaded files.
            $this->deleteTemporaryDownloads();

            // download the file
            $databaseFileDL = ABJ404_PATH . 'temp/GeoLite2-Country.tar.gz';
            ABJ_404_Solution_Functions::readURLtoFile(
                    "http://geolite.maxmind.com/download/geoip/database/GeoLite2-Country.tar.gz", $databaseFileDL);
            if (!file_exists($databaseFileDL) || filesize($databaseFileDL) == 0) {
                $abj404logging->debugMessage("Couldn't download database file.");
                return false;
            }
            
            // if the file size is less than 500k then there's probably an issue.
            if (filesize($databaseFileDL) < 500000) {
                $fileContents = ABJ_404_Solution_Functions::readFileContents($databaseFileDL);
                $abj404logging->debugMessage("db file too small. contents: " . $fileContents);
                return false;
            }
            
            try {
                $zip = new PharData($databaseFileDL);
                
                $zip->extractTo(ABJ404_PATH . 'temp/');
                
                // find the GeoLite2-Country_20180102 directory
                $geoDirectoryPath = $this->findTheGeoDirectoryPath();
                if ($geoDirectoryPath == null) {
                    $abj404logging->debugMessage("Couldn't find GeoLite2 directory.");
                    return false;
                }
                
                // move the the .mmdb file
                $oldDBFile = $geoDirectoryPath . '/GeoLite2-Country.mmdb';
                rename($oldDBFile, $databaseFile);
                $this->writeDatabaseTimeFileNow();
                $abj404logging->infoMessage("Updated GeoLite2 database.");
                
            } catch (Exception $e) {            
                ABJ_404_Solution_Functions::safeUnlink($databaseFileDL);
                $this->deleteTemporaryDownloads();
                throw $e;
            }
            ABJ_404_Solution_Functions::safeUnlink($databaseFileDL);
            // find and delete temporarily downloaded files.
            $this->deleteTemporaryDownloads();
        }
        
        if (file_exists($databaseFile)) {
            $this->reader = new ABJ_404_Solution_MaxMind_Reader($databaseFile);
            return true;
        }
        
        return false;
    }
    
    /** Find the path where the files are extracted.  
     * @return string
     */
    function findTheGeoDirectoryPath() {
        $destinationDirectory = ABJ404_PATH . 'temp/';
        
        // find the GeoLite2-Country_20180102 directory
        $geoDirectoryPath = null;
        $tempDirFiles = scandir($destinationDirectory);
        foreach ($tempDirFiles as $file) {

            $isDir = is_dir($destinationDirectory . $file);
            $matchesName = (substr(mb_strtolower($file), 0, strlen("GeoLite2-Country")) == 
                    mb_strtolower("GeoLite2-Country"));

            if ($isDir && $matchesName) {
                $geoDirectoryPath = $destinationDirectory . $file;
                break;
            }
        }
        return $geoDirectoryPath;
    }
    
    /** Delete the download location before and after the download. 
     * @return type
     */
    function deleteTemporaryDownloads() {
        global $abj404logging;
        
        // find the GeoLite2-Country_20180102 directory
        $geoDirectoryPath = $this->findTheGeoDirectoryPath();
        if ($geoDirectoryPath == null) {
            return;
        }

        // delete everything in the directory
        $geoDirFiles = scandir($geoDirectoryPath);
        foreach ($geoDirFiles as $file) {
            if (in_array($file, array(".", ".."))) {
                continue;
            }
            if (!unlink($geoDirectoryPath . '/' . $file)) {
                $abj404logging->debugMessage("Couldn't delete file: " . $geoDirectoryPath . '/' . $file);
            }
        }
        if (!rmdir($geoDirectoryPath)) {
            $abj404logging->debugMessage("Couldn't remove directory: " . $geoDirectoryPath);
        }
    }
    
    /** Note that we have updated the database. */
    function writeDatabaseTimeFileNow() {
        $todaysDate = date('Y-m-d');
        file_put_contents($this->getDatabaseUpdateTimeFileName(), $todaysDate);
        $this->previousUpdateDateCACHE = null;
    }
    
    /** Return true if the database file should be updated. */
    function shouldUpdateDatabaseFile() {
        global $abj404logging;
        
        if ($this->previousUpdateDateCACHE == null) {
            // read the date from the time file
            $updatedDate = date('Y-m-d', strtotime('2018-01-01'));
            if (file_exists($this->getDatabaseUpdateTimeFileName())) {
                $fileContents = ABJ_404_Solution_Functions::readFileContents($this->getDatabaseUpdateTimeFileName());
                $updatedDate = date('Y-m-d', strtotime($fileContents));
            }
            $previousUpdateTime = strtotime($updatedDate);
            $this->previousUpdateDateCACHE = $previousUpdateTime;
            
        } else {
            $previousUpdateTime = $this->previousUpdateDateCACHE;
        }
        
        // if the date is >= 7 days ago, OR if it's Wednesday, then return true.
        // the database file is updated every Tuesday. so we try to make sure that updates happen on
        // Wednesday.
        $oneDayInSeconds = 60 * 60 * 24;
        $sevenDays = $oneDayInSeconds * 7;
        // sevenDaysAgo = now - (seven days) - (30 minutes)
        $sevenDaysAgo = time() - $sevenDays - (60 * 30);
        $oneDayAgo = time() - $oneDayInSeconds - (60 * 30);
        if ($previousUpdateTime < $sevenDaysAgo) {
            $abj404logging->debugMessage("The geo2ip database file is more than 7 days old.");
            return true;
        }
        
        // check if it's Wednesday and the database was updated less more than a day ago.
        if (date('w') == '3' && ($previousUpdateTime < $oneDayAgo)) {
            $abj404logging->debugMessage("It's Wednesday and the geo2ip database file is more than 1 day old.");
            return true;
        }
        
        return false;
    }
    
    function getDatabaseUpdateTimeFileName() {
        return ABJ404_PATH . 'temp/GeoLite2-Country_time.txt';
    }
    
    /** Query the DB for all rows that need to be updated. 
     * Use Geo2IP to get their location.
     * Update the database to store the country.
     * 
     * @global type $abj404dao
     * @return type
     */
    function updateCountriesInDatabase() {
        global $abj404dao;
        global $abj404logging;
        
        $rowsUpdated = 0;
        $iterations = 0;
        
        $debugMessage = "Upated IPs with countries. Updates run: ";
        $countryCountMap = array();
        
        // query for up to X IPs that don't have a country associated with them.
        $rows = $abj404dao->getIPsThatNeedACountry(10000);
        while (sizeof($rows) > 0) {
            $iterations++;
            $abj404logging->debugMessage("Found " . sizeof($rows) . " rows to update with a country.");

            // collect IPs in a Map<CountryName, List<IP>>
            $countryMap = array();
            foreach ($rows as $row) {
                $ip = $row['user_ip'];
                $country = $this->getCountry($ip);

                // initialze the list
                if (!array_key_exists($country, $countryMap)) {
                    $countryMap[$country] = array();
                }
                if (!array_key_exists($country, $countryCountMap)) {
                    $countryCountMap[$country] = (int)0;
                } else {
                    $countryCountMap[$country] = (int)$countryCountMap[$country] + (int)1;
                }

                array_push($countryMap[$country], $ip);
            }

            $abj404logging->debugMessage("Organized " . sizeof($rows) . " rows to update with a country.");
            
            // for each countryname, update all of the IPs.
            foreach ($countryMap as $countryName => $listOfIPs) {
                $uniqueListOfIPs = array_unique($listOfIPs);

                while (sizeof($uniqueListOfIPs) > 0) {
                    $ipsToUpdate = array_slice($uniqueListOfIPs, 0, 100);
                    $debugMessage .= $countryName . ": " . sizeof($ipsToUpdate) . ", ";
                    $rowsUpdated += $abj404dao->updateCountry($ipsToUpdate, $countryName);
                    $uniqueListOfIPs = array_slice($uniqueListOfIPs, 100);
                }
            }
            $abj404logging->debugMessage($rowsUpdated . " rows updated with a country so far.");
            
            // get more records to be updated.
            $rows = $abj404dao->getIPsThatNeedACountry(10000);
            
            if ($iterations > 400) {
                $abj404logging->debugMessage("Too many iterations donoe while updating countries.");
                break;
            }
        }
        
        $abj404logging->debugMessage($debugMessage . " | Countries: " . json_encode($countryCountMap));
        $abj404logging->debugMessage($rowsUpdated . " rows updated with a country total.");
        return $rowsUpdated;
    }
}

