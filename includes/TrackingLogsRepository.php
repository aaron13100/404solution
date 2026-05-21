<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LogsRepository subclass that stubs getMaxLogId() and getMinLogId() with
 * fixed values for tests that exercise the chunked-rebuild pipeline.
 *
 * The pipeline reads MIN/MAX from logsv2 to decide chunk boundaries. A real
 * DB would need rows; this subclass lets tests control the id range directly.
 */
class ABJ_404_Solution_TrackingLogsRepository extends ABJ_404_Solution_LogsRepository {

    /** @var list<string> Query log forwarded from TrackingDatabaseCore */
    public array $queries = [];
    /** @var array<int, list<mixed>> Query params forwarded from TrackingDatabaseCore */
    public array $queryParams = [];
    /** @var int */
    private int $minId;
    /** @var int */
    private int $maxId;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $functions
     * @param mixed $logger
     * @param int $minId Fixed return value for getMinLogId()
     * @param int $maxId Fixed return value for getMaxLogId()
     */
    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logger
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logger = null,
        int $minId = 0,
        int $maxId = 0,
        $rebuildHealth = null
    ) {
        parent::__construct($dbCore, $functions, $logger, $rebuildHealth);
        $this->minId = $minId;
        $this->maxId = $maxId;
    }

    function getMaxLogId() { return $this->maxId; }
    function getMinLogId() { return $this->minId; }
}
