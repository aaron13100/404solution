<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime instrumentation for queryAndGetResults() durations and request-level
 * timeout budgets.
 *
 * Implements technique 4 of docs/PROACTIVE_BUG_DISCOVERY.md ("Reverse-proxy
 * timeout budget audit"). Disabled by default — turned on per-process by
 * setting either:
 *   - environment variable ABJ404_QUERY_BUDGET_LOG=<directory>
 *   - PHP constant ABJ404_QUERY_BUDGET_LOG (string path to a writable directory)
 *
 * When enabled, every queryAndGetResults() call records:
 *   - resolved SQL source identifier (filename, /* abj404:src=ID *​/ marker,
 *     or backtrace-derived Class::method — see DataAccess::extractSqlFilename)
 *   - elapsed wall-clock ms
 *   - per-query timeout hint actually used (s)
 *   - timestamp + request URI
 *
 * On shutdown, if any single query exceeded its per-query budget OR the request's
 * cumulative DB time exceeded the request budget (default 25s — below the
 * Cloudflare ~100s and nginx ~60s reverse-proxy cutoffs, with margin for
 * non-DB request work), one JSONL violation entry is appended to
 * `<dir>/slow-query-budget-violations.log`.
 *
 * The log shape is intentionally append-only JSONL so multiple Playwright
 * worker processes can write concurrently without coordination, and so
 * failing E2E runs can attach the file as an artifact for triage.
 */
class ABJ_404_Solution_QueryBudgetInstrumentation {

    /** Default per-request cumulative budget in milliseconds (admin/AJAX). */
    const DEFAULT_REQUEST_BUDGET_MS = 25000;

    /** Default per-query budget in milliseconds — same as the cumulative cap. */
    const DEFAULT_QUERY_BUDGET_MS = 25000;

    /** @var bool|null Lazily resolved enabled flag. */
    private static $enabled = null;

    /** @var string|null Lazily resolved log directory. */
    private static $logDir = null;

    /** @var bool */
    private static $shutdownRegistered = false;

    /**
     * Per-request recording buffer.
     *
     * @var array{queries: list<array{sql:string, elapsed_ms:float, timeout_s:int, ts:float}>, request_budget_ms: int, started_at: float}|null
     */
    private static $state = null;

    /**
     * Returns true if instrumentation is enabled for this process.
     *
     * Reads from the ABJ404_QUERY_BUDGET_LOG environment variable or the same
     * named PHP constant.  The value, if non-empty, is the directory the
     * violations log is written to.  An explicit "0" / "false" / empty value
     * keeps it disabled.
     */
    public static function isEnabled(): bool {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        $dir = self::resolveLogDir();
        self::$enabled = ($dir !== null && $dir !== '');
        return self::$enabled;
    }

    /**
     * Returns the log directory, or null if instrumentation is disabled or
     * the directory is unwritable.  Result is cached.
     */
    public static function logDir(): ?string {
        if (self::$logDir !== null) {
            return self::$logDir === '' ? null : self::$logDir;
        }
        $dir = self::resolveLogDir();
        if ($dir === null || $dir === '') {
            self::$logDir = '';
            return null;
        }
        if (!is_dir($dir)) {
            $made = @mkdir($dir, 0755, true);
            if (!$made && !is_dir($dir)) {
                self::$logDir = '';
                return null;
            }
        }
        if (!is_writable($dir)) {
            self::$logDir = '';
            return null;
        }
        self::$logDir = $dir;
        return self::$logDir;
    }

    /** @return string|null */
    private static function resolveLogDir(): ?string {
        $envVal = getenv('ABJ404_QUERY_BUDGET_LOG');
        if (is_string($envVal) && $envVal !== '' && $envVal !== '0' && strtolower($envVal) !== 'false') {
            return $envVal;
        }
        if (defined('ABJ404_QUERY_BUDGET_LOG')) {
            $constVal = constant('ABJ404_QUERY_BUDGET_LOG');
            if (is_string($constVal) && $constVal !== '' && $constVal !== '0' && strtolower($constVal) !== 'false') {
                return $constVal;
            }
        }
        return null;
    }

    /**
     * Path to the violations log inside the configured log directory.
     */
    public static function violationLogPath(): ?string {
        $dir = self::logDir();
        if ($dir === null) {
            return null;
        }
        return rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . 'slow-query-budget-violations.log';
    }

    /**
     * Per-request cumulative budget in ms.  Override by defining
     * ABJ404_REQUEST_BUDGET_MS as a positive integer.
     */
    public static function requestBudgetMs(): int {
        if (defined('ABJ404_REQUEST_BUDGET_MS')) {
            $v = constant('ABJ404_REQUEST_BUDGET_MS');
            if (is_int($v) && $v > 0) {
                return $v;
            }
            if (is_string($v) && ctype_digit($v) && (int)$v > 0) {
                return (int)$v;
            }
        }
        return self::DEFAULT_REQUEST_BUDGET_MS;
    }

    /**
     * Records a single queryAndGetResults() invocation.
     *
     * @param string $sqlInfo  Resolved source identifier (NOT raw SQL — keeps log PII-free)
     * @param float  $elapsedMs Wall-clock duration in milliseconds
     * @param int    $timeoutSeconds The per-query timeout hint actually applied
     * @return void
     */
    public static function recordQuery(string $sqlInfo, float $elapsedMs, int $timeoutSeconds): void {
        if (!self::isEnabled()) {
            return;
        }
        if (self::$state === null) {
            self::initState();
        }
        // Ensure the shutdown flush is wired even when the early-return paths
        // in queryAndGetResults() short-circuit before WordPress finishes
        // booting.  register_shutdown_function is idempotent for our purposes
        // because flushOnShutdown() guards against double-emission.
        self::ensureShutdownRegistered();

        // Empty input would erase the source attribution that drives triage,
        // so substitute a stable sentinel.  DataAccess::extractSqlFilename
        // never produces empty strings — this guards against external callers.
        $sqlInfo = $sqlInfo === '' ? 'unknown-source' : $sqlInfo;
        if (strlen($sqlInfo) > 200) {
            $sqlInfo = substr($sqlInfo, 0, 200);
        }
        /** @var array{queries: list<array{sql:string, elapsed_ms:float, timeout_s:int, ts:float}>, request_budget_ms: int, started_at: float} $state */
        $state = self::$state;
        $state['queries'][] = array(
            'sql' => $sqlInfo,
            'elapsed_ms' => max(0.0, $elapsedMs),
            'timeout_s' => max(0, $timeoutSeconds),
            'ts' => microtime(true),
        );
        self::$state = $state;
    }

    /** @return void */
    private static function initState(): void {
        self::$state = array(
            'queries' => array(),
            'request_budget_ms' => self::requestBudgetMs(),
            'started_at' => microtime(true),
        );
    }

    /** @return void */
    private static function ensureShutdownRegistered(): void {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(array(__CLASS__, 'flushOnShutdown'));
    }

    /**
     * Flush on shutdown: if the request violated the budget, append one JSONL
     * entry to the violations log.
     *
     * @return void
     */
    public static function flushOnShutdown(): void {
        if (!self::isEnabled() || self::$state === null) {
            return;
        }
        $entry = self::buildViolationEntry();
        // Reset state so re-flush (e.g. test calling flushOnShutdown() twice)
        // does not double-emit.
        $state = self::$state;
        self::$state = null;
        if ($entry === null) {
            return;
        }
        $path = self::violationLogPath();
        if ($path === null) {
            return;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        // file_put_contents with FILE_APPEND | LOCK_EX is concurrency-safe
        // across Playwright worker processes.
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Build a violation entry for the current request, or null if no budget
     * was exceeded.
     *
     * @return array<string,mixed>|null
     */
    private static function buildViolationEntry(): ?array {
        if (self::$state === null) {
            return null;
        }
        $budgetMs = self::$state['request_budget_ms'];
        $totalMs = 0.0;
        $perQueryViolations = array();
        foreach (self::$state['queries'] as $q) {
            $totalMs += $q['elapsed_ms'];
            // Per-query budget: timeout_s converted to ms, capped at the
            // request budget (since a single query above the request budget
            // is by definition a violation).
            $perQueryBudgetMs = min(self::DEFAULT_QUERY_BUDGET_MS,
                $q['timeout_s'] > 0 ? $q['timeout_s'] * 1000 : self::DEFAULT_QUERY_BUDGET_MS);
            if ($q['elapsed_ms'] > $perQueryBudgetMs) {
                $perQueryViolations[] = array(
                    'sql' => $q['sql'],
                    'elapsed_ms' => round($q['elapsed_ms'], 2),
                    'budget_ms' => $perQueryBudgetMs,
                    'reason' => 'per-query',
                );
            }
        }
        $cumulativeViolation = $totalMs > $budgetMs;
        if (empty($perQueryViolations) && !$cumulativeViolation) {
            return null;
        }
        return array(
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'uri' => self::currentRequestUri(),
            'request_total_ms' => round($totalMs, 2),
            'request_budget_ms' => $budgetMs,
            'query_count' => count(self::$state['queries']),
            'cumulative_violation' => $cumulativeViolation,
            'violations' => $perQueryViolations,
        );
    }

    /** @return string */
    private static function currentRequestUri(): string {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($uri === '' && isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
            $uri = $_SERVER['SCRIPT_NAME'];
        }
        if ($uri === '' && PHP_SAPI === 'cli') {
            $uri = 'cli://' . (isset($_SERVER['argv'][0]) ? basename((string)$_SERVER['argv'][0]) : 'php');
        }
        return $uri === '' ? '(unknown)' : substr($uri, 0, 500);
    }

    /**
     * Reset all internal state.  Test-only.  Production code never calls this.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$enabled = null;
        self::$logDir = null;
        self::$state = null;
        self::$shutdownRegistered = false;
    }
}

/**
 * Top-level recording entry point — called from queryAndGetResults().  The
 * indirection through a free function (rather than a direct class method
 * call) mirrors the existing abj404_benchmark_record_db_query() hook so that
 * environments without the instrumentation file loaded incur zero cost
 * (the function_exists() guard at the call site short-circuits).
 *
 * @param string $sqlInfo
 * @param float  $elapsedMs
 * @param int    $timeoutSeconds
 * @return void
 */
if (!function_exists('abj404_query_budget_record')) {
    function abj404_query_budget_record(string $sqlInfo, float $elapsedMs, int $timeoutSeconds): void {
        ABJ_404_Solution_QueryBudgetInstrumentation::recordQuery(
            $sqlInfo,
            $elapsedMs,
            $timeoutSeconds
        );
    }
}
