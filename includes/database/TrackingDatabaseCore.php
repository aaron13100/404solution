<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DatabaseCore subclass that records all queries and their params for assertion
 * in tests. Optionally simulates failures at a specific chunk or phase.
 *
 * Used by the Phase 8 extraction tests that exercise LogsRepository's
 * createRedirectsForViewHitsTable() chunked-rebuild pipeline. The pipeline
 * calls $this->dbCore->queryAndGetResults(), so a tracking DatabaseCore is
 * the DI seam that lets tests observe the SQL without hitting a real DB.
 */
class ABJ_404_Solution_TrackingDatabaseCore extends ABJ_404_Solution_DatabaseCore {

    /** @var array<int, string> */
    public array $queries = [];
    /** @var array<int, mixed> */
    public array $queryParams = [];
    /** @var array<string, array<string, mixed>> Per-pattern response overrides (substring => result array) */
    public array $queryResponses = [];
    /** @var int */
    private int $failOnChunk;
    /** @var bool */
    private bool $failOnPhase2;
    /** @var int */
    private int $chunkInsertCount = 0;

    /**
     * @param mixed $functions
     * @param mixed $logger
     * @param int $failOnChunk Chunk number to simulate failure on (0 = none)
     * @param bool $failOnPhase2 Whether to simulate a Phase 2 failure
     */
    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct($functions = null, $logger = null, int $failOnChunk = 0, bool $failOnPhase2 = false) {
        parent::__construct($functions, $logger);
        $this->failOnChunk = $failOnChunk;
        $this->failOnPhase2 = $failOnPhase2;
    }

    public function queryAndGetResults($query, $options = array()): array {
        $idx = count($this->queries);
        $this->queries[$idx] = $query;
        $this->queryParams[$idx] = $options['query_params'] ?? [];

        foreach ($this->queryResponses as $pattern => $response) {
            if (stripos($query, $pattern) !== false) {
                return $response;
            }
        }

        $normalized = strtolower((string)$query);
        $isChunkInsert = stripos($normalized, 'insert into') !== false
            && stripos($normalized, 'preagg') !== false
            && stripos($normalized, 'where') !== false;

        if ($isChunkInsert) {
            $this->chunkInsertCount++;
            if ($this->failOnChunk > 0 && $this->chunkInsertCount === $this->failOnChunk) {
                return [
                    'rows' => [],
                    'last_error' => 'Simulated chunk failure',
                    'elapsed_time' => 0.1,
                    'last_result' => [],
                    'rows_affected' => 0,
                    'insert_id' => 0,
                ];
            }
        }

        $isPhase2 = stripos($normalized, 'insert into') !== false
            && stripos($normalized, 'inner join') !== false
            && stripos($normalized, 'preagg') !== false;
        if ($isPhase2 && $this->failOnPhase2) {
            return [
                'rows' => [],
                'last_error' => 'Simulated Phase 2 failure',
                'elapsed_time' => 0.1,
                'last_result' => [],
                'rows_affected' => 0,
                'insert_id' => 0,
            ];
        }

        return [
            'rows' => [],
            'last_error' => '',
            'elapsed_time' => 0.05,
            'last_result' => [],
            'rows_affected' => 0,
            'insert_id' => 0,
        ];
    }

    public function executeAsTransaction(array $statementArray): void {
        foreach ($statementArray as $stmt) {
            $this->queries[] = $stmt;
        }
    }
}
