<?php
/**
 * Pure database schema / data-shape error taxonomy.
 *
 * Owns string-based classification of integrity failures: corrupted or
 * crashed tables (MyISAM "marked as crashed", "Incorrect key file"),
 * missing plugin tables, transient view-build table churn, and data-shape
 * issues (invalid UTF-8, collation mismatch). These are the error classes
 * the auto-repair, table-recreate, and collation-degrade policies act on.
 *
 * No side effects: callers reuse the matchers without inheriting recovery
 * behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseInfrastructureErrorTaxonomy facade by tests/ErrorClassifierTest.php and tests/StagedBuildHostQuirksTest.php
class ABJ_404_Solution_DatabaseSchemaErrorTaxonomy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
    }

    /** @param string $errorText @return bool */
    public function isCrashedTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return stripos($errorText, 'is marked as crashed') !== false;
    }

    /** @param string $errorText @return bool */
    public function isIncorrectKeyFileError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return stripos($errorText, 'Incorrect key file') !== false;
    }

    /** @param string $errorText @return bool */
    public function isMissingPluginTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        if ($this->f->strpos($lower, '_abj404_logs_hits') !== false) {
            return false;
        }
        return ($this->f->strpos($lower, "doesn't exist") !== false &&
            $this->f->strpos($lower, '_abj404_') !== false);
    }

    /** @param string $errorText @return bool */
    public function isTransientViewBuildTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, '_abj404_view_build') !== false ||
            $this->f->strpos($lower, '_abj404_view_done') !== false ||
            $this->f->strpos($lower, '_abj404_view_deleteme') !== false);
    }

    /**
     * Determine whether an error indicates invalid text/charset payload.
     *
     * @param mixed $errorText
     * @return bool
     */
    public function isInvalidDataError($errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return (
            $this->f->strpos($lower, 'contains invalid data') !== false ||
            $this->f->strpos($lower, 'incorrect string value') !== false ||
            $this->f->strpos($lower, 'invalid utf8') !== false
        );
    }

    /** @param string $errorText @return bool */
    public function isCollationError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'illegal mix of collations') !== false ||
            $this->f->strpos($lower, 'unknown collation') !== false ||
            $this->f->strpos($lower, 'collation') !== false && $this->f->strpos($lower, 'not valid') !== false);
    }
}
