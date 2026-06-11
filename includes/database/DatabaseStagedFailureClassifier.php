<?php
/**
 * Staged view-build failure policy classifier.
 *
 * Converts a stage number and database/PHP error text into the action the
 * staged-build orchestrator should take.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseErrorClassifier facade by tests/StageFailurePolicyClassifierTest.php and tests/StagedBuildBufferMissingPreCheckTest.php
class ABJ_404_Solution_DatabaseStagedFailureClassifier {

    /** @var ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy */
    private $taxonomy;

    /**
     * @param ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy $taxonomy
     */
    public function __construct(ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy $taxonomy) {
        $this->taxonomy = $taxonomy;
    }

    /**
     * True when a staged-build query was killed in a way the next tick can
     * safely resume.
     *
     * @param string $errorText
     * @return bool
     */
    public function isResumableStagedKill(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        if ($this->taxonomy->connectivity()->isQueryTimeoutError($errorText)) {
            return true;
        }
        if ($this->taxonomy->connectivity()->isTransientConnectionError($errorText)) {
            return true;
        }
        if ($this->taxonomy->connectivity()->isDeadlockOrLockTimeoutError($errorText)) {
            return true;
        }
        if (stripos($errorText, 'query execution was interrupted') !== false) {
            return true;
        }
        if ($this->taxonomy->connectivity()->isPacketTooLarge($errorText)) {
            return true;
        }
        return false;
    }

    /**
     * True when a staged-build failure is a permanent host constraint rather
     * than a programmer-class SQL error.
     *
     * @param string $errorText
     * @return bool
     */
    public function isPermanentHostSideStagedFailure(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        if ($this->isResumableStagedKill($errorText)) {
            return false;
        }
        return ($this->taxonomy->hostState()->isAccessDeniedError($errorText)
            || $this->taxonomy->hostState()->isReadOnlyError($errorText)
            || $this->taxonomy->hostState()->isDiskFullError($errorText)
            || $this->taxonomy->hostState()->isQuotaLimitError($errorText)
            || $this->taxonomy->hostState()->isOutOfMemoryError($errorText));
    }

    /**
     * Classify an error raised by the staged view-build pipeline.
     *
     * @param int $stageNumber
     * @param string $errorText
     * @return string
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        if ($errorText === '') {
            return 'rethrow';
        }
        if (stripos($errorText, 'Staged view-build buffer missing') !== false) {
            return 'resumable';
        }
        if ($this->isResumableStagedKill($errorText)) {
            return 'resumable';
        }
        if (!$this->isPermanentHostSideStagedFailure($errorText)) {
            return 'rethrow';
        }
        $policy = ABJ_404_Solution_ViewBuildConfig::stageFailurePolicy($stageNumber);
        return $policy === 'optional' ? 'skip' : 'halt';
    }
}
