<?php
/**
 * Pure database infrastructure error taxonomy.
 *
 * Owns string-based classification of host-side database failures. This class
 * has no database, transient, notice, or logging side effects so callers can
 * reuse the taxonomy without also inheriting recovery behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseErrorClassifier facade by tests/SuppressWpdbErrorsTest.php and tests/DataAccessDatabaseEdgeCasesTest.php
class ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
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

    /** @param string|null $errorText @return bool */
    public function isTransientConnectionError(?string $errorText): bool {
        $errorText = $errorText ?? '';
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        $transientMarkers = array(
            'server has gone away',
            'lost connection to mysql server during query',
            'error while sending query packet',
            'packets out of order',
            'connection was killed',
        );
        foreach ($transientMarkers as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        foreach (array('2006', '2013') as $code) {
            if ($this->f->strpos($lower, '[' . $code . ']') !== false
                || $this->f->strpos($lower, '(' . $code . ')') !== false
                || $this->f->strpos($lower, 'errno ' . $code) !== false
                || $this->f->strpos($lower, 'errno: ' . $code) !== false
                || $this->f->strpos($lower, 'error: ' . $code . ' ') !== false
                || $this->f->strpos($lower, 'error ' . $code . ':') !== false) {
                return true;
            }
        }
        return false;
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
    public function isAccessDeniedError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'access denied') !== false ||
            $this->f->strpos($lower, 'command denied') !== false);
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
    public function isQueryTimeoutError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return (strpos($errorText, '3024') !== false ||
            strpos($errorText, '1969') !== false ||
            stripos($errorText, 'max_execution_time') !== false ||
            stripos($errorText, 'max_statement_time') !== false);
    }

    /** @param string $errorText @return bool */
    public function isPacketTooLarge(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'max_allowed_packet') !== false ||
            $this->f->strpos($lower, 'got a packet bigger') !== false ||
            $this->f->strpos($lower, '1153') !== false);
    }

    /** @param string $errorText @return bool */
    public function isDeadlockOrLockTimeoutError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'deadlock found') !== false ||
            $this->f->strpos($lower, 'lock wait timeout exceeded') !== false ||
            $this->f->strpos($lower, 'error 1213') !== false ||
            $this->f->strpos($lower, 'error 1205') !== false);
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

    /** @param string $errorText @return bool */
    public function isInfrastructureSqlError(string $errorText): bool {
        return $this->isDiskFullError($errorText)
            || $this->isReadOnlyError($errorText)
            || $this->isQuotaLimitError($errorText)
            || $this->isInvalidDataError($errorText)
            || $this->isCollationError($errorText)
            || $this->isMissingPluginTableError($errorText)
            || $this->isIncorrectKeyFileError($errorText)
            || $this->isCrashedTableError($errorText)
            || $this->isDeadlockOrLockTimeoutError($errorText)
            || $this->isGaleraConflictError($errorText)
            || $this->isTransientConnectionError($errorText)
            || $this->isQueryTimeoutError($errorText)
            || $this->isAccessDeniedError($errorText);
    }
}
