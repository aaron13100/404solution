<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durably brackets third-party object-cache metrics inspection.
 *
 * A metrics capability check, metrics() call, or magic counter property can
 * block before AjaxRowLoopProgress writes the row checkpoint it was building.
 * Each potentially foreign boundary therefore gets its own start/end pair.
 * A killed worker leaves an unmatched start that identifies diagnostics work,
 * its source, and its row-loop phase without exposing cache values, property
 * names, or exception text.
 *
 * The row-operation cache decorator is unwrapped before inspection. This keeps
 * diagnostics-owned reads out of the rendered-row Redis attribution channel
 * and supports nested decorators without invoking their magic pass-through.
 *
 * allow-no-test-found: exercised through the real AJAX table render entry point in tests/AjaxRowProgressAttributionTest.php
 */
final class ABJ_404_Solution_CacheMetricsProbeTracer {

    /** @var string */
    private $requestId;

    /** @var int */
    private $operationSequence = 0;

    /** @var bool */
    private $failed = false;

    public function __construct(string $requestId) {
        $this->requestId = $requestId;
    }

    /**
     * Read one cumulative cache snapshot without letting optional diagnostics
     * break the table response.
     *
     * @param mixed $cache WordPress's current object-cache global.
     * @return array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null}
     */
    public function snapshot($cache, string $phase): array {
        if (!is_object($cache)) {
            return self::emptySnapshot('none');
        }
        if ($this->failed) {
            return self::emptySnapshot('error');
        }

        $cache = self::unwrapCache($cache);
        $phase = self::safePhase($phase);
        $capability = $this->probe(
            'metrics_capability',
            $phase,
            static fn() => self::metricsReader($cache)
        );
        if (!$capability['ok']) {
            $this->failed = true;
            return self::emptySnapshot('error');
        }

        $metricsReader = $capability['value'];
        if ($metricsReader !== null) {
            $metrics = $this->probe('metrics', $phase, static function () use ($metricsReader): array {
                return self::snapshotFromMetrics(call_user_func($metricsReader));
            });
            if (!$metrics['ok']) {
                $this->failed = true;
                return self::emptySnapshot('error');
            }
            $metricsSnapshot = $metrics['value'];
            if ($metricsSnapshot !== null && $metricsSnapshot['src'] !== 'none') {
                return $metricsSnapshot;
            }
        }

        $counters = $this->probe('counters', $phase, static function () use ($cache): array {
            return self::snapshotFromCounters($cache);
        });
        if (!$counters['ok']) {
            $this->failed = true;
            return self::emptySnapshot('error');
        }
        return $counters['value'] ?? self::emptySnapshot('none');
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return array{ok: bool, value: T|null}
     */
    private function probe(string $source, string $phase, callable $work): array {
        $fields = array(
            'operation_id' => $this->operationId($source, $phase),
            'source' => $source,
            'phase' => $phase,
        );
        $checkpointId = $this->writeStart($fields);
        $startedAt = self::nowFloat();
        try {
            $value = $work();
        } catch (Throwable $e) {
            $this->writeEnd($checkpointId, array_merge($fields, array(
                'status' => 'error',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'error' => self::errorSummary($e),
            )));
            self::reportFailure($source . ' probe failed: ' . $e->getMessage());
            return array('ok' => false, 'value' => null);
        }
        $this->writeEnd($checkpointId, array_merge($fields, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            'result' => self::resultSummary($source, $value),
        )));
        return array('ok' => true, 'value' => $value);
    }

    /** @return (callable(): mixed)|null */
    private static function metricsReader(object $cache): ?callable {
        $reader = array($cache, 'metrics');
        return is_callable($reader) ? $reader : null;
    }

    /** @param array<string, mixed> $fields */
    private function writeStart(array $fields): string {
        try {
            return ABJ_404_Solution_DurableOperationRecorder::recordStart(
                $this->requestId,
                'cache_metrics_probe_start',
                $fields
            );
        } catch (Throwable $e) {
            self::reportFailure('cache metrics probe start failed: ' . $e->getMessage());
            return '';
        }
    }

    /** @param array<string, mixed> $fields */
    private function writeEnd(string $checkpointId, array $fields): void {
        try {
            ABJ_404_Solution_DurableOperationRecorder::recordEnd(
                $this->requestId,
                'cache_metrics_probe_end',
                $checkpointId,
                $fields
            );
        } catch (Throwable $e) {
            self::reportFailure('cache metrics probe end failed: ' . $e->getMessage());
        }
    }

    private function operationId(string $source, string $phase): string {
        $this->operationSequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $source . '|' . $phase . '|' . $this->operationSequence
        ), 0, 12);
    }

    private static function unwrapCache(object $cache): object {
        $seen = array();
        while ($cache instanceof ABJ_404_Solution_InstrumentedObjectCache) {
            $id = spl_object_id($cache);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $next = $cache->originalCache();
            if ($next === $cache) {
                break;
            }
            $cache = $next;
        }
        return $cache;
    }

    /**
     * @param mixed $value
     * @return array{src: string, calls: int|null, reads: int|null, writes: int|null, hits: int|null, misses: int|null, ms: float|null}
     */
    private static function snapshotFromMetrics($value): array {
        $metrics = self::numericMetrics($value);
        $reads = self::metric($metrics, array('store-reads', 'reads'));
        $writes = self::metric($metrics, array('store-writes', 'writes'));
        $hits = self::metric($metrics, array('hits', 'cache-hits'));
        $misses = self::metric($metrics, array('misses', 'cache-misses'));
        $milliseconds = self::metric($metrics, array('ms-cache', 'cache-ms', 'cache-time'));
        if ($reads === null && $writes === null && $hits === null && $misses === null
                && $milliseconds === null) {
            return self::emptySnapshot('none');
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
    }

    /**
     * @return array{src: string, calls: int|null, reads: null, writes: null, hits: int|null, misses: int|null, ms: null}
     */
    private static function snapshotFromCounters(object $cache): array {
        $hits = isset($cache->cache_hits) && is_numeric($cache->cache_hits)
            ? (int)$cache->cache_hits : null;
        $misses = isset($cache->cache_misses) && is_numeric($cache->cache_misses)
            ? (int)$cache->cache_misses : null;
        if ($hits === null && $misses === null) {
            return self::emptySnapshot('none');
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
    }

    /** @return array{src: string, calls: null, reads: null, writes: null, hits: null, misses: null, ms: null} */
    private static function emptySnapshot(string $source): array {
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

    private static function cacheCallTotal(
        ?float $reads,
        ?float $writes,
        ?float $hits,
        ?float $misses
    ): ?int {
        if ($reads !== null || $writes !== null) {
            return (int)(($reads ?? 0) + ($writes ?? 0));
        }
        return ($hits !== null || $misses !== null)
            ? (int)(($hits ?? 0) + ($misses ?? 0))
            : null;
    }

    /**
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

    /** @param mixed $value */
    private static function resultSummary(string $source, $value): string {
        if ($source === 'metrics_capability') {
            return $value !== null ? 'available' : 'unavailable';
        }
        return is_array($value) && ($value['src'] ?? 'none') !== 'none'
            ? 'snapshot_available'
            : 'snapshot_unavailable';
    }

    /** @return array{class: string, code: int, message: string, message_length: int} */
    private static function errorSummary(Throwable $error): array {
        $message = $error->getMessage();
        return array(
            'class' => self::safeClassName(get_class($error)),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $message), 0, 12),
            'message_length' => strlen($message),
        );
    }

    private static function safeClassName(string $class): string {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
            ? $class
            : 'class#' . substr(hash('sha256', $class), 0, 12);
    }

    private static function safePhase(string $phase): string {
        return in_array($phase, array('initial', 'progress', 'finish'), true)
            ? $phase
            : 'unknown';
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        if ($startedAt === null) {
            return null;
        }
        $finishedAt = self::nowFloat();
        return $finishedAt === null
            ? null
            : max(0, (int)round(($finishedAt - $startedAt) * 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('cache-metrics-probe', $message);
    }
}
