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
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logger
     * @param int $minId Fixed return value for getMinLogId()
     * @param int $maxId Fixed return value for getMaxLogId()
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
        $rollup = new ABJ_404_Solution_TrackingLogsHitsRollupService(
            $dbCore, $logger, $rebuildHealth, $minId, $maxId
        );
        parent::__construct($dbCore, $functions, $logger, $rebuildHealth, $rollup);
        $this->minId = $minId;
        $this->maxId = $maxId;
    }

    function getMaxLogId() { return $this->maxId; }
    function getMinLogId() { return $this->minId; }

    /**
     * Replace the rollup collaborator wired into this repository. Test seam
     * for cases where the test needs to swap in a fully-prepared
     * TrackingLogsHitsRollupService with extra overrides.
     *
     * @param ABJ_404_Solution_LogsHitsRollupServiceInterface $rollup
     * @return void
     */
    public function setRollupForTests(ABJ_404_Solution_LogsHitsRollupServiceInterface $rollup): void {
        $ref = new ReflectionProperty(ABJ_404_Solution_LogsRepository::class, 'rollup');
        $ref->setValue($this, $rollup);
    }
}

/**
 * LogsHitsRollupService subclass with fixed min/max log id values, so the
 * tracking rebuild pipeline (which now lives on the rollup service) sees the
 * same id range the test set up via TrackingLogsRepository.
 */
class ABJ_404_Solution_TrackingLogsHitsRollupService extends ABJ_404_Solution_LogsHitsRollupService {
    /** @var int */
    private int $minId;
    /** @var int */
    private int $maxId;
    /** @var int|null */
    private $storedMaxIdOverride = null;
    /** @var bool|null */
    private $logsHitsTableExistsOverride = null;

    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logging = null,
        $rebuildHealth = null,
        int $minId = 0,
        int $maxId = 0
    ) {
        parent::__construct($dbCore, $logging, $rebuildHealth);
        $this->minId = $minId;
        $this->maxId = $maxId;
    }

    /** @var callable|null */
    private $maxLogIdResolver = null;

    /**
     * Set a callable that produces getMaxLogId() on demand. Lets tests model
     * "MAX(id) grew during the insert window" without subclassing.
     *
     * @param callable():int $resolver
     */
    public function setMaxLogIdResolverForTests(callable $resolver): void {
        $this->maxLogIdResolver = $resolver;
    }

    public function getMaxLogId() {
        if ($this->maxLogIdResolver !== null) {
            return (int)call_user_func($this->maxLogIdResolver);
        }
        return $this->maxId;
    }
    public function getMinLogId() { return $this->minId; }

    /** Test seam: override the stored watermark value. */
    public function setStoredMaxLogIdForTests(int $value): void { $this->storedMaxIdOverride = $value; }
    public function getStoredMaxLogId() {
        if ($this->storedMaxIdOverride !== null) { return $this->storedMaxIdOverride; }
        return parent::getStoredMaxLogId();
    }

    /** Test seam: override logsHitsTableExists() boolean. */
    public function setLogsHitsTableExistsForTests(bool $value): void { $this->logsHitsTableExistsOverride = $value; }
    public function logsHitsTableExists() {
        if ($this->logsHitsTableExistsOverride !== null) { return $this->logsHitsTableExistsOverride; }
        return parent::logsHitsTableExists();
    }
}
