<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded intra-stage progress for a table's row-formatting loop
 * (Bruno timeout cause matrix, cause class F; gap-hunt iteration 1, gap G5).
 *
 * ABJ_404_Solution_AjaxQueryTimeline accounts for the database half of a work
 * stage. This accounts for the other half. Once the rows are in memory the
 * stage still runs URL parsing and normalization, destination resolution,
 * locale-dependent formatting, and every foreign callback other plugins have
 * attached to the filters the row templates go through -- all of it inside a
 * single `foreach`, and all of it invisible between `stage_start` and a
 * `stage_end` that never arrives.
 *
 * A hang caused by ONE pathological row is the specific case this localizes.
 * With per-query records showing every query completed and the row loop
 * stopping at row 17 of 25, the stall is attributable to PHP-side work on a
 * specific row rather than to the database, which is a different fix.
 *
 * Cost is bounded by construction, not by hope. The tick interval is derived
 * from the row count so a 25-row page and a 500-row page both emit at most
 * MAX_PROGRESS_RECORDS progress records, and the record envelope is the
 * checkpoint logger's high-frequency one.
 *
 * The loop is ticked BEFORE each row is formatted, for the same reason the
 * query probe is written before the query runs: work that never finishes has
 * to have been announced before it started, or it leaves no trace at all.
 *
 * PII: only a SHA-256 prefix of the row's primary key is emitted. Row URLs,
 * destinations, and freeform content never reach the journal -- the whole row
 * is accepted only so the key lookup lives here instead of being repeated at
 * every call site.
 */
final class ABJ_404_Solution_AjaxRowLoopProgress {

    /**
     * Progress records emitted per loop, excluding the start and end pair.
     *
     * Eight is enough to place a stall inside a default 25-row page to within
     * about four rows while costing a fraction of the boundary checkpoints
     * already written for the same request. The interval scales with the row
     * count, so a 500-row page costs the same as a 25-row one.
     */
    const MAX_PROGRESS_RECORDS = 8;

    /** Row-key columns, in the order they are consulted. */
    const ROW_KEY_COLUMNS = array('id', 'log_id');

    /** @var string Ledger request ID, or '' when this loop is not instrumented. */
    private $requestId;

    /** @var string */
    private $label;

    /** @var int */
    private $total;

    /** @var int Rows between progress records; always at least 1. */
    private $interval;

    /** @var int Rows seen so far. */
    private $index = 0;

    /** @var int Progress records emitted so far. */
    private $emitted = 0;

    /** @var float */
    private $startedAt;

    /** @var float Start of the current activity window. */
    private $activityStartedAt;

    /** @var int Rows completed when the previous activity snapshot was taken. */
    private $sampledRows = 0;

    /** @var array<string, int> */
    private $hookCounts = array();

    /** @var array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null} */
    private $cacheSnapshot;

    /** @var bool Stop calling a metrics surface after it throws once. */
    private $cacheMetricsFailed = false;

    /**
     * Open a progress-tracked row loop. Returns a live tracker on an
     * instrumented admin-AJAX request and an inert one everywhere else, so
     * call sites need no conditional and the front-end 404 path pays nothing
     * beyond one static call per table render.
     *
     * @param string $label Loop identity, e.g. 'redirects_rows'.
     * @param int $totalRows Number of rows the loop is about to format.
     */
    public static function begin(string $label, int $totalRows): self {
        $progress = new self($label, $totalRows);
        if ($progress->requestId === '') {
            return $progress;
        }
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($progress->requestId, 'row_loop_start', array(
                'loop' => $progress->label,
                'rows' => $progress->total,
                'every' => $progress->interval,
            ));
        } catch (Throwable $e) {
            self::reportFailure('row loop start failed: ' . $e->getMessage());
        }
        return $progress;
    }

    private function __construct(string $label, int $totalRows) {
        $this->label = substr($label, 0, 64);
        $this->total = max(0, $totalRows);
        $this->interval = max(1, (int)ceil($this->total / self::MAX_PROGRESS_RECORDS));
        $this->startedAt = abj_clock()->nowFloat();
        $this->activityStartedAt = $this->startedAt;
        $this->requestId = self::resolveRequestId();
        if ($this->requestId !== '') {
            $this->hookCounts = self::currentHookCounts();
            $this->cacheSnapshot = $this->currentCacheSnapshot();
        }
    }

    /**
     * Announce the row that is about to be formatted. Never throws.
     *
     * @param array<string, mixed> $row The row being formatted. Only its
     *   primary key is read, and only as a hash.
     */
    public function tick(array $row): void {
        $this->index++;
        if ($this->requestId === '' || $this->emitted >= self::MAX_PROGRESS_RECORDS) {
            return;
        }
        // Rows 1, 1+interval, 1+2*interval ... so the FIRST row is always
        // announced: a loop that hangs immediately is otherwise reported as a
        // loop that never started, which points at the wrong half of the stage.
        if (($this->index - 1) % $this->interval !== 0) {
            return;
        }
        $this->emitted++;
        try {
            $record = array(
                'loop' => $this->label,
                'row' => $this->index,
                'rows' => $this->total,
                'rid' => self::hashedRowKey($row),
                'ms' => self::elapsedMs($this->startedAt),
            );
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $this->requestId,
                'row_loop_progress',
                array_merge($record, $this->activityFields(max(0, $this->index - 1)))
            );
        } catch (Throwable $e) {
            self::reportFailure('row loop progress failed: ' . $e->getMessage());
        }
    }

    /**
     * Close the loop, recording how many rows it actually formatted. Never
     * throws. The absence of this record next to a present `row_loop_start` is
     * itself the finding: the loop was entered and did not come back.
     */
    public function finish(): void {
        if ($this->requestId === '') {
            return;
        }
        try {
            $record = array(
                'loop' => $this->label,
                'rows' => $this->total,
                'rows_done' => $this->index,
                'ms' => self::elapsedMs($this->startedAt),
            );
            if ($this->emitted < self::MAX_PROGRESS_RECORDS) {
                $record = array_merge($record, $this->activityFields($this->index));
            }
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $this->requestId,
                'row_loop_end',
                $record
            );
        } catch (Throwable $e) {
            self::reportFailure('row loop end failed: ' . $e->getMessage());
        }
    }

    /** Rows formatted so far. Test-visible accounting; production reads the journal. */
    public function rowsSeen(): int {
        return $this->index;
    }

    private static function resolveRequestId(): string {
        try {
            return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
        } catch (Throwable $e) {
            self::reportFailure('row loop arming failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * A short SHA-256 prefix of the row's primary key, or '' when the row has
     * no usable key.
     *
     * Hashed rather than emitted plainly because the journal's standing
     * guarantee is that it carries no row-level site data, and a stable hash
     * is enough for what this field is for: telling whether two attempts
     * stopped on the SAME row.
     *
     * @param array<string, mixed> $row
     */
    private static function hashedRowKey(array $row): string {
        foreach (self::ROW_KEY_COLUMNS as $column) {
            if (isset($row[$column]) && is_scalar($row[$column]) && (string)$row[$column] !== '') {
                return substr(hash('sha256', (string)$row[$column]), 0, 12);
            }
        }
        return '';
    }

    private static function elapsedMs(float $startedAt): int {
        return max(0, (int)round((abj_clock()->nowFloat() - $startedAt) * 1000));
    }

    /**
     * Snapshot request-local hook counters and object-cache metrics as deltas
     * for the rows completed since the previous bounded checkpoint.
     *
     * Hook counts are temporal evidence, not callback timers. `chunk_ms` is
     * the only honest wall-time boundary: WordPress exposes trigger counters
     * and the active hook stack, but not foreign callback durations.
     *
     * @return array<string, mixed>
     */
    private function activityFields(int $completedRows): array {
        $sampledAt = abj_clock()->nowFloat();
        $currentHooks = self::currentHookCounts();
        $hookDeltas = array();
        foreach ($currentHooks as $hook => $count) {
            $delta = $count - ($this->hookCounts[$hook] ?? 0);
            if ($delta > 0) {
                $hookDeltas[$hook] = $delta;
            }
        }
        uksort($hookDeltas, static function (string $left, string $right) use ($hookDeltas): int {
            $byCount = $hookDeltas[$right] <=> $hookDeltas[$left];
            return ($byCount !== 0) ? $byCount : strcmp($left, $right);
        });
        $hookTop = array();
        foreach (array_slice($hookDeltas, 0, 1, true) as $hook => $calls) {
            $redactedHook = self::redactedHookName($hook);
            $hookTop[$redactedHook] = ($hookTop[$redactedHook] ?? 0) + $calls;
        }

        $currentCache = $this->currentCacheSnapshot();
        $sameCacheSource = $currentCache['src'] === $this->cacheSnapshot['src'];
        $cacheCalls = $sameCacheSource
            ? self::numericDelta($currentCache['calls'], $this->cacheSnapshot['calls']) : null;
        $cacheMilliseconds = $sameCacheSource
            ? self::numericDelta($currentCache['ms'], $this->cacheSnapshot['ms']) : null;

        $fields = array(
            'chunk_rows' => max(0, $completedRows - $this->sampledRows),
            'chunk_ms' => max(0, (int)round(($sampledAt - $this->activityStartedAt) * 1000)),
            'hook_active' => self::activeHookName(),
            'hook_calls' => array_sum($hookDeltas),
            'hook_top' => $hookTop,
            'cache_src' => $currentCache['src'],
            'cache_calls' => ($cacheCalls === null) ? null : (int)$cacheCalls,
            'cache_ms' => ($cacheMilliseconds === null) ? null : round($cacheMilliseconds, 3),
        );

        $this->activityStartedAt = $sampledAt;
        $this->sampledRows = $completedRows;
        $this->hookCounts = $currentHooks;
        $this->cacheSnapshot = $currentCache;
        return $fields;
    }

    /** @return array<string, int> */
    private static function currentHookCounts(): array {
        $counts = array();
        foreach (array('wp_filters', 'wp_actions') as $globalName) {
            $source = $GLOBALS[$globalName] ?? null;
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $hook => $count) {
                if (is_numeric($count) && (int)$count >= 0) {
                    $name = (string)$hook;
                    $counts[$name] = ($counts[$name] ?? 0) + (int)$count;
                }
            }
        }
        return $counts;
    }

    private static function activeHookName(): string {
        $stack = $GLOBALS['wp_current_filter'] ?? null;
        if (!is_array($stack) || empty($stack)) {
            return '';
        }
        $active = end($stack);
        return is_string($active) ? self::redactedHookName($active) : '';
    }

    /**
     * Keep conventional static hook names actionable. Hash dynamic names that
     * could contain a URL, email, user value, or another site-specific token.
     */
    private static function redactedHookName(string $hook): string {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/', $hook) === 1) {
            return preg_replace('/[0-9]+/', '#', $hook) ?? '';
        }
        return 'hook#' . substr(hash('sha256', $hook), 0, 12);
    }

    /**
     * Read Object Cache Pro's cumulative local metrics when available. Its
     * `store-reads`, `store-writes`, and `ms-cache` values distinguish Redis
     * backend work from runtime-cache hits without wrapping the cache object.
     *
     * @return array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null}
     */
    private function currentCacheSnapshot(): array {
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (!is_object($cache)) {
            return self::emptyCacheSnapshot('none');
        }
        if ($this->cacheMetricsFailed) {
            return self::emptyCacheSnapshot('error');
        }

        $metricsReader = array($cache, 'metrics');
        if (is_callable($metricsReader)) {
            $metricsSnapshot = $this->cacheSnapshotFromMetrics($metricsReader);
            if ($metricsSnapshot !== null) {
                return $metricsSnapshot;
            }
        }
        return self::cacheSnapshotFromCounters($cache);
    }

    /**
     * @param callable(): mixed $metricsReader
     * @return array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null}|null
     */
    private function cacheSnapshotFromMetrics(callable $metricsReader): ?array {
        try {
            $metrics = self::numericMetrics(call_user_func($metricsReader));
            $reads = self::metric($metrics, array('store-reads', 'reads'));
            $writes = self::metric($metrics, array('store-writes', 'writes'));
            $hits = self::metric($metrics, array('hits', 'cache-hits'));
            $misses = self::metric($metrics, array('misses', 'cache-misses'));
            $milliseconds = self::metric($metrics, array('ms-cache', 'cache-ms', 'cache-time'));
            if ($reads === null && $writes === null && $hits === null && $misses === null
                    && $milliseconds === null) {
                return null;
            }
            return array(
                'src' => 'ocp_metrics',
                'calls' => self::cacheCallTotal($reads, $writes, $hits, $misses),
                'reads' => ($reads === null) ? null : (int)$reads,
                'writes' => ($writes === null) ? null : (int)$writes,
                'hits' => ($hits === null) ? null : (int)$hits,
                'misses' => ($misses === null) ? null : (int)$misses,
                'ms' => $milliseconds,
            );
        } catch (Throwable $e) {
            $this->cacheMetricsFailed = true;
            self::reportFailure('object cache metrics failed: ' . $e->getMessage());
            return self::emptyCacheSnapshot('error');
        }
    }

    /**
     * @return array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null}
     */
    private static function cacheSnapshotFromCounters(object $cache): array {
        try {
            $hits = isset($cache->cache_hits) && is_numeric($cache->cache_hits)
                ? (int)$cache->cache_hits : null;
            $misses = isset($cache->cache_misses) && is_numeric($cache->cache_misses)
                ? (int)$cache->cache_misses : null;
            if ($hits === null && $misses === null) {
                return self::emptyCacheSnapshot('none');
            }
            return array(
                'src' => 'wp_counters',
                'calls' => (int)(($hits ?? 0) + ($misses ?? 0)),
                'reads' => null,
                'writes' => null,
                'hits' => $hits,
                'misses' => $misses,
                'ms' => null,
            );
        } catch (Throwable $e) {
            self::reportFailure('object cache counters failed: ' . $e->getMessage());
            return self::emptyCacheSnapshot('error');
        }
    }

    /** @return array{src: string, calls: null, reads: null, writes: null, hits: null, misses: null, ms: null} */
    private static function emptyCacheSnapshot(string $source): array {
        return array(
            'src' => $source,
            'calls' => null,
            'reads' => null,
            'writes' => null,
            'hits' => null,
            'misses' => null,
            'ms' => null,
        );
    }

    private static function cacheCallTotal(?float $reads, ?float $writes, ?float $hits, ?float $misses): ?int {
        if ($reads !== null || $writes !== null) {
            return (int)(($reads ?? 0) + ($writes ?? 0));
        }
        return ($hits !== null || $misses !== null) ? (int)(($hits ?? 0) + ($misses ?? 0)) : null;
    }

    /**
     * @param float|int|null $current
     * @param float|int|null $previous
     */
    private static function numericDelta($current, $previous): ?float {
        if ($current === null || $previous === null) {
            return null;
        }
        $delta = (float)$current - (float)$previous;
        return ($delta >= 0) ? $delta : null;
    }

    /**
     * Flatten numeric metrics from array or value-object return shapes.
     *
     * @param mixed $value
     * @return array<string, float>
     */
    private static function numericMetrics($value, int $depth = 0): array {
        if ($depth > 2 || (!is_array($value) && !is_object($value))) {
            return array();
        }
        $out = array();
        foreach ((array)$value as $key => $metricValue) {
            $name = strtolower((string)preg_replace('/^.*\x00/', '', (string)$key));
            $name = str_replace('_', '-', $name);
            if (is_numeric($metricValue)) {
                $out[$name] = (float)$metricValue;
            } elseif (is_array($metricValue) || is_object($metricValue)) {
                $out = array_replace($out, self::numericMetrics($metricValue, $depth + 1));
            }
        }
        return $out;
    }

    /**
     * @param array<string, float> $metrics
     * @param array<int, string> $names
     */
    private static function metric(array $metrics, array $names): ?float {
        foreach ($names as $name) {
            if (array_key_exists($name, $metrics)) {
                return $metrics[$name];
            }
        }
        return null;
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-row-loop', $message);
    }
}
