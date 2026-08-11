<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The platform services an n-gram rebuild tick runs against.
 *
 * A rebuild tick needs three things from outside its own subsystem: somewhere
 * to run queries, somewhere to log, and something that can put the next tick on
 * the schedule. They travel together because they are all "the platform", not
 * because grouping them made a parameter list shorter: every class in the
 * rebuild chain that needs one needs all three, and each was separately
 * re-deriving the same cron-scheduler default.
 *
 * That default lives here now. Callers used to write
 * `$x instanceof ABJ_404_Solution_CronScheduler ? $x : null` on the way in and
 * the receiving constructor wrote the mirror-image check on the way out, so the
 * same decision was spelled twice per call site and could drift. Passing a null
 * scheduler here means "use the shipped one"; tests pass their own.
 *
 * @see ABJ_404_Solution_NGramCacheRebuildBatchRunner
 * @see ABJ_404_Solution_NGramRescheduleFailureReport
 */
class ABJ_404_Solution_NGramRebuildRuntime {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_CronScheduler|null $cronScheduler Null resolves to
     *        the shipped scheduler; tests pass one to pin scheduling.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        ?ABJ_404_Solution_CronScheduler $cronScheduler = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->cronScheduler = $cronScheduler instanceof ABJ_404_Solution_CronScheduler
            ? $cronScheduler
            : abj_cron_scheduler();
    }

    /**
     * Accessors rather than public fields, and non-nullable rather than
     * nullable: the constructor resolves every one of these, so a consumer
     * never has to ask whether it got a real collaborator. The older bundles in
     * this codebase expose nullable public fields and make each consumer repeat
     * a `?? abj_service(...)` fallback, which is the duplication this exists to
     * remove.
     *
     * @return ABJ_404_Solution_DatabaseCore
     */
    public function dbCore(): ABJ_404_Solution_DatabaseCore {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_Logging */
    public function logger(): ABJ_404_Solution_Logging {
        return $this->logger;
    }

    /** @return ABJ_404_Solution_CronScheduler */
    public function cronScheduler(): ABJ_404_Solution_CronScheduler {
        return $this->cronScheduler;
    }
}
