<?php
/**
 * Pure database connectivity error taxonomy.
 *
 * Owns string-based classification of transport-level failures: network
 * connection drops, query-execution timeouts, packet size limits, and
 * deadlock / lock-wait contention. These are the error classes the retry
 * and staged-build "resumable kill" policies act on.
 *
 * No side effects: callers reuse the matchers without inheriting recovery
 * behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseInfrastructureErrorTaxonomy facade by tests/ErrorClassifierTest.php and tests/StagedBuildHostQuirksTest.php
class ABJ_404_Solution_DatabaseConnectivityErrorTaxonomy {

    /** @var array<int, string> */
    private const COMMANDS_OUT_OF_SYNC_MARKERS = array(
        'commands out of sync',
        'cr_commands_out_of_sync',
        'errno 2014',
        'errno: 2014',
        'error 2014:',
        '[2014]',
        '(2014)',
    );

    /** @var array<int, string> */
    private const DEADLOCK_OR_LOCK_TIMEOUT_MARKERS = array(
        'deadlock found',
        'lock wait timeout exceeded',
        'lock deadlock; retry transaction',
        'error 1213',
        'error 1205',
    );

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
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
            'lost connection to mysql server',
            'error while sending query packet',
            'packets out of order',
            'connection was killed',
        );
        foreach ($transientMarkers as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        foreach (array('2006', '2013', '2055') as $code) {
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
        foreach (self::DEADLOCK_OR_LOCK_TIMEOUT_MARKERS as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param string $errorText @return bool */
    public function isCommandsOutOfSyncError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        foreach (self::COMMANDS_OUT_OF_SYNC_MARKERS as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}
