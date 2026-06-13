<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectDeadDestinationStore.php';

/**
 * Flags redirects whose destination URL is present in the recent failed-hit rollup.
 */
class ABJ_404_Solution_RedirectDeadDestinationScanner {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectDeadDestinationStore */
    private $store;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectDeadDestinationStore|null $store
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        ?ABJ_404_Solution_RedirectDeadDestinationStore $store = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->store = $store !== null ? $store : new ABJ_404_Solution_RedirectDeadDestinationStore();
    }

    public function flagDeadDestinationRedirects(): void {
        $flaggedIds = array();

        $hitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $hitsTableExists = $this->dbCore->tableNameResolver()->tableExists($hitsTable);

        if (!$hitsTableExists || !$this->logsHitsHasFailedHitsColumn()) {
            /** @var ABJ_404_Solution_LogsRepository|null $logsRepo */
            $logsRepo = abj_service('logs_repository');
            if ($logsRepo !== null) {
                $logsRepo->scheduleHitsTableRebuild();
            }
            $this->store->storeIds($flaggedIds);
            return;
        }

        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
        $sql = "SELECT DISTINCT r.id
             FROM {wp_abj404_redirects} r
             INNER JOIN {wp_abj404_logs_hits} h
                 ON h.requested_url = CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
                AND BINARY h.requested_url = BINARY CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
             WHERE h.last_used > %d
               AND h.failed_hits > 0
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";
        $sql = $this->dbCore->doTableNameReplacements($sql);

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array(abj_clock()->now() - 7 * 86400),
            'timeout' => 30,
        ));

        if (empty($result['timed_out']) && (!isset($result['last_error']) || $result['last_error'] == '')) {
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                $value = $this->extractIdValue($row);
                if ($value !== null) {
                    $flaggedIds[] = $value;
                }
            }
        }

        $this->store->storeIds($flaggedIds);

        if (!empty($flaggedIds)) {
            $this->logger->infoMessage(
                __CLASS__ . '/' . __FUNCTION__ . ': Flagged ' . count($flaggedIds) .
                ' redirect(s) with dead destinations: ' . implode(', ', $flaggedIds)
            );
        }
    }

    /**
     * @param mixed $row
     * @return string|null
     */
    private function extractIdValue($row): ?string {
        if (is_array($row)) {
            $value = $row['id'] ?? reset($row);
        } elseif (is_object($row)) {
            $value = $row->id ?? null;
        } else {
            $value = $row;
        }

        if (is_scalar($value) && (string)$value !== '') {
            return (string)$value;
        }

        return null;
    }

    private function logsHitsHasFailedHitsColumn(): bool {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $sql = "SELECT 1 FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = %s "
            . "AND column_name = 'failed_hits' LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array($tableName),
            'log_errors' => false,
        ));
        if (!empty($result['last_error'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }
}
