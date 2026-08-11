<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/diagnostics/QueryBudgetInstrumentation.php';

/**
 * Query diagnostics for safe source labels, latency simulation, and logging.
 *
 * This component owns diagnostic behavior around a query execution without
 * deciding whether the query should run or how errors recover. The executor
 * calls it for simulated latency, query-budget instrumentation, safe SQL
 * source labels, and malformed wpdb result visibility.
 */
class ABJ_404_Solution_DatabaseQueryDiagnostics {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * @param string $query
     * @param float $elapsedMs
     * @param int $timeoutSeconds
     * @return void
     */
    public function recordQueryBudgetIfEnabled(string $query, float $elapsedMs, int $timeoutSeconds): void {
        if (function_exists('abj404_benchmark_record_db_query')) {
            abj404_benchmark_record_db_query($elapsedMs);
        }
        if (function_exists('abj404_query_budget_record')
            && class_exists('ABJ_404_Solution_QueryBudgetInstrumentation', false)
            && ABJ_404_Solution_QueryBudgetInstrumentation::isEnabled()) {
            abj404_query_budget_record($this->extractSqlFilename($query), $elapsedMs, $timeoutSeconds);
        }
    }

    /**
     * Open the ledger-scoped boundary covering all work before query_probe.
     *
     * @param mixed $wpdb
     */
    public function beginQueryPreflight(
        string $query,
        $wpdb
    ): ABJ_404_Solution_DatabaseQueryPreflightTracer {
        return ABJ_404_Solution_DatabaseQueryPreflightTracer::begin(
            $this->extractSqlFilename($query),
            $wpdb
        );
    }

    /**
     * Announce a query to the per-request attribution timeline BEFORE it runs
     * (Bruno timeout cause matrix, cause class F).
     *
     * Emitted ahead of execution on purpose: a query that blocks and never
     * returns cannot be described by a record written on completion, and
     * naming the SQL shape that was in flight is what separates "the stage
     * hung in the database" from "the stage hung in PHP after the database
     * came back". See ABJ_404_Solution_AjaxQueryTimeline.
     *
     * The call-site label is resolved only when the timeline is armed, since
     * extractSqlFilename() falls back to a debug_backtrace() walk.
     *
     * @param string $query The final SQL the server will receive.
     * @param int $timeoutSeconds
     * @return array{q:int,sql_id:string}|null
     */
    public function recordQueryTimelineStart(
        string $query,
        int $timeoutSeconds,
        string $preflightId = ''
    ): ?array {
        if (!class_exists('ABJ_404_Solution_AjaxQueryTimeline')
                || !ABJ_404_Solution_AjaxQueryTimeline::isArmed()) {
            return null;
        }
        return ABJ_404_Solution_AjaxQueryTimeline::beginQuery(
            $query,
            $this->extractSqlFilename($query),
            $timeoutSeconds,
            $preflightId
        );
    }

    /**
     * Close the in-flight timeline entry with the duration the executor
     * measured. In-memory only; the value is carried out by the next probe and
     * by the request's closing summary.
     *
     * Deliberately called from BOTH the normal and the throwing path of
     * queryAndGetResults: a query that raised is still a query that ended, and
     * an unclosed entry would make every duration after it unreadable.
     *
     * @param float $elapsedMs
     * @return void
     */
    public function recordQueryTimelineEnd(float $elapsedMs): void {
        if (class_exists('ABJ_404_Solution_AjaxQueryTimeline', false)) {
            ABJ_404_Solution_AjaxQueryTimeline::endQuery($elapsedMs);
        }
    }

    /**
     * Attach the strongest DB-level timeout mode observed during the active
     * AJAX stage. MariaDB's persisted wrapper-rejection state is explicitly
     * recorded as unwrapped because its MAX_EXECUTION_TIME comment is ignored.
     */
    public function recordAjaxTimeoutMode(string $query): void {
        $mode = 'none';
        if (class_exists('ABJ_404_Solution_DatabaseRuntimeState')
                && ABJ_404_Solution_DatabaseRuntimeState::isSetStatementWrapperUnsupported()) {
            $mode = 'unwrapped';
        } else if (preg_match('/MAX_EXECUTION_TIME|max_statement_time/i', $query) === 1) {
            $mode = 'wrapped';
        }
        if (class_exists('ABJ_404_Solution_AjaxStageDiagnostics')) {
            ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array('db_timeout_mode' => $mode));
        }
    }

    /**
     * @param string $query
     * @param mixed $rows
     * @return void
     */
    public function logMalformedRowsIfNeeded(string $query, $rows): void {
        if (is_array($rows)) {
            return;
        }
        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
        $this->logger->errorMessage(
            "Query result is not an array. Query: " . $sqlInfo,
            new Exception("Query result is not an array.") // allow-raw-error: behavior preserved from pre-extraction DatabaseCore; passed to logger as diagnostic context, not thrown
        );
    }

    /**
     * Resolve a stable source identifier for safe logging.
     *
     * @param string $query
     * @return string
     */
    public function extractSqlFilename($query) {
        if (is_string($query) && $query !== '') {
            if (preg_match('/\/\*\s*abj404:src=([A-Za-z0-9_:#.\\\\\-]+)\s*\*\//i', $query, $m)) {
                return $m[1];
            }
            if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $query, $m)) {
                return basename($m[1]);
            }
        }
        return $this->resolveCallerFromBacktrace();
    }

    /** @return string */
    public function resolveCallerFromBacktrace() {
        static $internalMethods = array(
            'extractSqlFilename' => true,
            'resolveCallerFromBacktrace' => true,
            'beginQueryPreflight' => true,
            'queryAndGetResults' => true,
            'attemptInvalidDataRetry' => true,
            'attemptMissingTableRepairAndRetry' => true,
            'repairCorruptedTableAndRetry' => true,
            'scheduleCollationRecovery' => true,
            'call_user_func_array' => true,
            'call_user_func' => true,
            '__call' => true,
        );
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);
        foreach ($frames as $frame) {
            if ($this->shouldSkipBacktraceFrame($frame, $internalMethods)) {
                continue;
            }
            return $this->formatBacktraceSource($frame);
        }
        return 'unknown-source';
    }

    /**
     * @param array<string, mixed> $frame
     * @param array<string, bool> $internalMethods
     * @return bool
     */
    private function shouldSkipBacktraceFrame(array $frame, array $internalMethods): bool {
        $fn = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';
        if ($fn === '' || isset($internalMethods[$fn]) || strpos($fn, '{closure') !== false) {
            return true;
        }

        $cls = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        if ($cls !== '' && (strpos($cls, 'Patchwork') !== false || strpos($cls, 'PHPUnit\\') === 0)) {
            return true;
        }
        if (strpos($fn, 'Patchwork\\') !== false) {
            return true;
        }

        $fullFile = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
        return $fullFile !== '' && (strpos($fullFile, '/patchwork/') !== false || strpos($fullFile, '\\patchwork\\') !== false);
    }

    /**
     * @param array<string, mixed> $frame
     * @return string
     */
    private function formatBacktraceSource(array $frame): string {
        $fn = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : 'unknown-source';
        $cls = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        $fileLabel = $this->fileLabelFromBacktraceFrame($frame);

        if ($this->isInternalDatabaseClass($cls) && $fileLabel !== '' && !$this->isInternalDatabaseFileLabel($fileLabel)) {
            return $fileLabel . '::' . $fn;
        }
        if ($cls !== '') {
            return $this->shortClassLabel($cls) . '::' . $fn;
        }
        if ($fileLabel !== '') {
            return $fileLabel . '::' . $fn;
        }
        return $fn;
    }

    /**
     * @param array<string, mixed> $frame
     * @return string
     */
    private function fileLabelFromBacktraceFrame(array $frame): string {
        $fullFile = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
        $file = $fullFile !== '' ? basename($fullFile) : '';
        $fileLabel = preg_replace('/\.php$/i', '', $file);
        return is_string($fileLabel) ? $fileLabel : $file;
    }

    /** @param string $className @return bool */
    private function isInternalDatabaseClass(string $className): bool {
        return $className === 'ABJ_404_Solution_DatabaseCore'
            || $className === 'ABJ_404_Solution_DatabaseQueryExecutor'
            || $className === 'ABJ_404_Solution_DatabaseQueryDiagnostics'
            || $className === 'ABJ_404_Solution_DataAccess';
    }

    /** @param string $fileLabel @return bool */
    private function isInternalDatabaseFileLabel(string $fileLabel): bool {
        return $fileLabel === 'DatabaseCore'
            || $fileLabel === 'DatabaseQueryExecutor'
            || $fileLabel === 'DatabaseQueryDiagnostics'
            || $fileLabel === 'DataAccess';
    }

    /** @param string $className @return string */
    private function shortClassLabel(string $className): string {
        $shortClass = $className;
        $nsPos = strrpos($shortClass, '\\');
        if ($nsPos !== false) {
            $shortClass = substr($shortClass, $nsPos + 1);
        }
        if (strpos($shortClass, 'ABJ_404_Solution_') === 0) {
            return substr($shortClass, strlen('ABJ_404_Solution_'));
        }
        return $shortClass;
    }

    /** @return void */
    public function applyDiagnosticLatencyIfConfigured(): void {
        if (!function_exists('abj404_get_simulated_db_latency_ms')) {
            return;
        }
        $delayMs = absint(abj404_get_simulated_db_latency_ms());
        if ($delayMs <= 0) {
            return;
        }
        $delayMs = min(5000, $delayMs);
        usleep($delayMs * 1000);
    }
}
