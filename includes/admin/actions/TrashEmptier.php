<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permanently deletes disabled rows from the captured or redirect trash sets.
 *
 * This is data mutation infrastructure for the empty-trash admin handlers. It
 * centralizes the status-set selection so the handlers never build SQL.
 */
class ABJ_404_Solution_TrashEmptier {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewRead;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewRead
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $viewRead, $logger) {
        $this->dbCore = $dbCore;
        $this->viewRead = $viewRead;
        $this->logger = $logger;
    }

    /**
     * @param string $sub Admin table slug: abj404_captured or abj404_redirects.
     * @return void
     */
    public function emptyTrash(string $sub): void {
        global $abj404_redirect_types;
        global $abj404_captured_types;

        if ($sub === 'abj404_captured') {
            $statusTypes = is_array($abj404_captured_types) ? $abj404_captured_types : array();
        } else if ($sub === 'abj404_redirects') {
            $statusTypes = is_array($abj404_redirect_types) ? $abj404_redirect_types : array();
        } else {
            $this->logger->errorMessage("Unrecognized type in doEmptyTrash(" . $sub . ")");
            return;
        }

        $statusList = array();
        foreach ($statusTypes as $statusType) {
            if (is_scalar($statusType)) {
                $statusList[] = (string)$statusType;
            }
        }
        if ($statusList === array()) {
            $this->logger->errorMessage("No trash status types configured in doEmptyTrash(" . $sub . ")");
            return;
        }

        $query = "delete FROM {wp_abj404_redirects} \n" .
                "where disabled = 1 \n" .
                "      and status in (" . implode(", ", $statusList) . ")";

        $result = $this->dbCore->queryAndGetResults($query);
        $rowsAffected = is_array($result) && isset($result['rows_affected']) && is_scalar($result['rows_affected'])
            ? (string)$result['rows_affected']
            : '0';
        $this->logger->debugMessage("doEmptyTrash deleted " . $rowsAffected . " rows total. (" . $sub . ")");

        $this->viewRead->invalidateStatusCountsCache();

        $this->dbCore->queryAndGetResults("optimize table {wp_abj404_redirects}");
    }
}
