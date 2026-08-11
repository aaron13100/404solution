<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explains why WP-Cron refused to queue the next n-gram rebuild tick.
 *
 * A refused reschedule is the one failure the rebuild cannot recover from on
 * its own: the chain is self-arming, so the tick that fails to enqueue its
 * successor ends the rebuild silently, at whatever offset it had reached. The
 * log line is therefore the only evidence anyone gets, and it has to carry
 * enough state to tell the three causes apart -- WP-Cron switched off, an event
 * already queued under these exact args, or the cron table itself erroring.
 *
 * Split out of the batch runner because it is the piece that reaches for
 * infrastructure the orchestrator has no other reason to touch: the global
 * $wpdb, the database error classifier, and the multisite/blog context. Keeping
 * that in the batch loop mixed evidence-gathering into rebuild orchestration
 * and was most of what pushed that file past its size limit.
 */
class ABJ_404_Solution_NGramRescheduleFailureReport {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /** @var ABJ_404_Solution_NGramRebuildProgressState */
    private $progress;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Types are declared rather than only documented: this report is built
     * from four collaborators of which three were interchangeable at the call
     * site, so transposing two was legal PHP that failed later, inside the
     * failure path, which is the worst possible place to discover a wiring
     * mistake.
     *
     * @param ABJ_404_Solution_NGramRebuildRuntime $runtime Database, logging and
     *        cron: the platform a rebuild tick runs against.
     * @param ABJ_404_Solution_NGramRebuildProgressState $progress
     */
    public function __construct(
        ABJ_404_Solution_NGramRebuildRuntime $runtime,
        ABJ_404_Solution_NGramRebuildProgressState $progress
    ) {
        $this->dbCore = $runtime->dbCore();
        $this->cronScheduler = $runtime->cronScheduler();
        $this->progress = $progress;
        $this->logger = $runtime->logger();
    }

    /**
     * Record that the chain could not be re-armed.
     *
     * @param string $hookName Hook the chain reschedules itself on.
     * @param int $offset Offset the chain would have resumed from.
     * @param float $progressPercent Percent complete at the point of failure.
     * @return void
     */
    public function report(string $hookName, int $offset, float $progressPercent): void {
        // WP-Cron switched off entirely is a configuration answer, not a
        // failure to diagnose: say so plainly and skip the evidence dump.
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->logger->errorMessage(
                "Cannot schedule next N-gram rebuild batch at offset {$offset}: WP-Cron is disabled "
                . "(DISABLE_WP_CRON=true). Consider enabling WP-Cron or using server-side cron with a "
                . "fallback mechanism."
            );
            return;
        }

        global $wpdb;

        $alreadyScheduled = $this->cronScheduler->nextScheduled($hookName, [$offset]);
        $dbError = (is_object($wpdb) && !empty($wpdb->last_error)) ? (string)$wpdb->last_error : 'none';

        $errorMsg = sprintf(
            "Failed to schedule next N-gram rebuild batch at offset %d. Hook: %s, Schedule time: %d "
            . "(current: %d), Already scheduled: %s, DB error: %s, Cache initialized: %s, "
            . "Progress: %.1f%%, Multisite: %s, Blog ID: %d",
            $offset,
            $hookName,
            $this->cronScheduler->now() + 10,
            $this->cronScheduler->now(),
            // wp_date, not date: date() renders in whatever timezone the host
            // process happens to default to, so the same event read differently
            // on two hosts and the line could not be compared against anything.
            $alreadyScheduled ? (string)wp_date('Y-m-d H:i:s T', (int)$alreadyScheduled) : 'no',
            $dbError,
            $this->progress->rawInitializedValue(),
            $progressPercent,
            is_multisite() ? 'yes' : 'no',
            get_current_blog_id()
        );

        if (is_object($wpdb) && !empty($wpdb->last_error)) {
            $this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError((string)$wpdb->last_error);
        }

        $this->logger->errorMessage($errorMsg);
    }
}
