<?php
/**
 * Pure database host-state error taxonomy.
 *
 * Owns string-based classification of server-side state failures: storage
 * exhaustion (disk, quota, memory), write-blocking modes (read-only,
 * Galera optimistic-concurrency conflicts), and access-control rejections
 * (access denied, missing SUPER privilege for SET STATEMENT). These are the
 * error classes the notice-state side-effects and "permanent host-side
 * staged failure" policies act on.
 *
 * No side effects: callers reuse the matchers without inheriting recovery
 * behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseInfrastructureErrorTaxonomy facade by tests/ErrorClassifierTest.php and tests/StagedBuildHostQuirksTest.php
class ABJ_404_Solution_DatabaseHostStateErrorTaxonomy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
    }

    /** @param string $errorText @return bool */
    public function isDiskFullError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'error 28') !== false ||
            $this->f->strpos($lower, 'errno: 28') !== false ||
            $this->f->strpos($lower, 'errcode: 28') !== false ||
            $this->f->strpos($lower, 'no space left on device') !== false ||
            $this->f->strpos($lower, "' is full") !== false ||
            $this->f->strpos($lower, 'table is full') !== false ||
            $this->f->strpos($lower, 'disk full') !== false);
    }

    /** @param string $errorText @return bool */
    public function isReadOnlyError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'read only') !== false ||
            $this->f->strpos($lower, 'read-only') !== false ||
            $this->f->strpos($lower, 'super_read_only') !== false);
    }

    /** @param string $errorText @return bool */
    public function isQuotaLimitError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'max_questions') !== false ||
            $this->f->strpos($lower, 'resource') !== false && $this->f->strpos($lower, 'question') !== false);
    }

    /** @param string $errorText @return bool */
    public function isAccessDeniedError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'access denied') !== false ||
            $this->f->strpos($lower, 'command denied') !== false);
    }

    /** @param string $errorText @return bool */
    public function isOutOfMemoryError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'allowed memory size') !== false ||
            $this->f->strpos($lower, 'out of memory') !== false ||
            $this->f->strpos($lower, 'memory exhausted') !== false ||
            $this->f->strpos($lower, 'memory_limit') !== false);
    }

    /** @param string $errorText @return bool */
    public function isGaleraConflictError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'record has changed since last read') !== false ||
            $this->f->strpos($lower, 'wsrep_local_state') !== false ||
            $this->f->strpos($lower, 'cluster conflict') !== false);
    }

    /**
     * True when a failed SET STATEMENT max_statement_time wrapper can be
     * stripped and retried.
     *
     * @param string $errorText
     * @return bool
     */
    public function classifySetStatementFailure(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        if ($this->f->strpos($lower, 'super privilege') !== false ||
            $this->f->strpos($lower, 'super_privilege') !== false ||
            $this->f->strpos($lower, '(at least one of) the super') !== false) {
            return true;
        }
        if (($this->f->strpos($lower, 'syntax error') !== false ||
             $this->f->strpos($lower, 'error in your sql syntax') !== false ||
             $this->f->strpos($lower, '1064') !== false) &&
            $this->f->strpos($lower, 'set statement') !== false) {
            return true;
        }
        return false;
    }
}
