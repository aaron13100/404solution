<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DebugLogEvidenceBudget.php';
require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Collects content, redirect, captured-404, log, and debug-file diagnostics
 * for feedback payloads. Each source degrades independently so one broken
 * optional service cannot abort the whole report.
 */
class ABJ_404_Solution_FeedbackDiagnosticsCollector {

    /**
     * Status-count freshness reported alongside the tallies. The three
     * cache-derived values mirror
     * ABJ_404_Solution_StatusCountsRefreshCoordinator::STATE_*; `unavailable`
     * is this collector's own state for "the read service could not be
     * reached at all", which is otherwise indistinguishable from a cold cache
     * because both ship NULL counts.
     */
    const STATUS_COUNTS_STATE_UNAVAILABLE = 'unavailable';

    /**
     * The state a minimal / diagnostics-redacted payload carries. The counts
     * were deliberately withheld, which is a different fact from a cold cache
     * or an unreachable service, and saying so keeps the discriminator honest.
     */
    const STATUS_COUNTS_STATE_REDACTED = 'redacted';

    /**
     * @return array<string, mixed>
     */
    public function collect(string $type): array {
        $payload = array();

        $payload['published_posts_count'] = $this->tryInt(function () { return $this->countPublishedPosts(); });
        $payload['published_pages_count'] = $this->tryInt(function () { return $this->countPublishedPages(); });
        $payload['categories_count']      = $this->tryInt(function () { return $this->countCategories(); });
        $payload['tags_count']            = $this->tryInt(function () { return $this->countTags(); });

        $redirects = $this->statusCountsWithState(
            'getRedirectStatusCountsResult', 'getRedirectStatusCounts'
        );
        $redirectCounts = $redirects['counts'];
        $payload['redirects_active_total']    = $this->pluckInt($redirectCounts, 'all');
        $payload['redirects_manual_count']    = $this->pluckInt($redirectCounts, 'manual');
        $payload['redirects_automatic_count'] = $this->pluckInt($redirectCounts, 'auto');
        $payload['redirects_regex_count']     = $this->pluckInt($redirectCounts, 'regex');
        $payload['redirects_trashed_count']   = $this->pluckInt($redirectCounts, 'trash');
        $payload['redirects_status_counts_state'] = $redirects['state'];
        $payload['redirect_hit_count_histogram'] = $this->redirectHitCountHistogram();

        $captured = $this->statusCountsWithState(
            'getCapturedStatusCountsResult', 'getCapturedStatusCounts'
        );
        $capturedCounts = $captured['counts'];
        $payload['captured_404s_active_total']  = $this->pluckInt($capturedCounts, 'all');
        $payload['captured_404s_new_count']     = $this->pluckInt($capturedCounts, 'captured');
        $payload['captured_404s_ignored_count'] = $this->pluckInt($capturedCounts, 'ignored');
        $payload['captured_404s_later_count']   = $this->pluckInt($capturedCounts, 'later');
        $payload['captured_404s_trashed_count'] = $this->pluckInt($capturedCounts, 'trash');
        $payload['captured_404s_status_counts_state'] = $captured['state'];

        $payload['log_entries_count']     = $this->tryInt(function () { return $this->logEntriesCount(); });
        $payload['log_table_size_bytes']  = $this->tryInt(function () { return $this->logTableSizeBytes(); });
        $payload += $this->debugLogReportFields($type);

        return $payload;
    }

    private function tryInt(callable $fn): ?int {
        try {
            $v = $fn();
            return is_int($v) ? $v : null;
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackDiagnosticsCollector count lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private function tryString(callable $fn): string {
        try {
            $v = $fn();
            return is_string($v) ? $v : '';
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackDiagnosticsCollector string lookup failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * @return array<string, int>
     */
    private function tryArray(callable $fn): array {
        try {
            $v = $fn();
            if (!is_array($v)) {
                return array();
            }
            $coerced = array();
            foreach ($v as $k => $val) {
                if (is_string($k) && is_int($val)) {
                    $coerced[$k] = $val;
                }
            }
            return $coerced;
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackDiagnosticsCollector array lookup failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * @param array<string, mixed> $map
     */
    private function pluckInt(array $map, string $key): ?int {
        if (!array_key_exists($key, $map)) {
            return null;
        }
        $v = $map[$key];
        return is_scalar($v) ? (int)$v : null;
    }

    private function countPublishedPosts(): int {
        if (!function_exists('wp_count_posts')) {
            throw new \RuntimeException('wp_count_posts unavailable');
        }
        $posts = wp_count_posts();
        if (is_object($posts) && isset($posts->publish) && is_scalar($posts->publish)) {
            return (int)$posts->publish;
        }
        throw new \RuntimeException('wp_count_posts returned unexpected shape');
    }

    private function countPublishedPages(): int {
        if (!function_exists('wp_count_posts')) {
            throw new \RuntimeException('wp_count_posts unavailable');
        }
        $pages = wp_count_posts('page');
        if (is_object($pages) && isset($pages->publish) && is_scalar($pages->publish)) {
            return (int)$pages->publish;
        }
        throw new \RuntimeException('wp_count_posts(page) returned unexpected shape');
    }

    private function countCategories(): int {
        if (!function_exists('wp_count_terms')) {
            throw new \RuntimeException('wp_count_terms unavailable');
        }
        $v = wp_count_terms(array('taxonomy' => 'category'));
        if (function_exists('is_wp_error') && is_wp_error($v)) {
            throw new \RuntimeException('wp_count_terms(category) returned WP_Error');
        }
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('wp_count_terms(category) returned unexpected shape');
    }

    private function countTags(): int {
        if (!function_exists('wp_count_terms')) {
            throw new \RuntimeException('wp_count_terms unavailable');
        }
        $v = wp_count_terms(array('taxonomy' => 'post_tag'));
        if (function_exists('is_wp_error') && is_wp_error($v)) {
            throw new \RuntimeException('wp_count_terms(post_tag) returned WP_Error');
        }
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('wp_count_terms(post_tag) returned unexpected shape');
    }

    /**
     * Read one status-count scope together with the cache state that produced
     * it. Without the state, a support report cannot tell a site that has
     * genuinely zero redirects from one whose count has never been computed:
     * both arrive as NULL/0 tallies.
     *
     * @param string $resultMethod State-carrying accessor (preferred).
     * @param string $flatMethod Legacy flat accessor, used when a read service
     *        predates the state-carrying one; its `_incomplete` marker still
     *        distinguishes uncomputed from computed.
     * @return array{counts: array<string, int>, state: string}
     */
    private function statusCountsWithState(string $resultMethod, string $flatMethod): array {
        try {
            $viewReadService = $this->viewReadService();
            if ($viewReadService === null) {
                throw new \RuntimeException('view_read_service unavailable');
            }
            if (method_exists($viewReadService, $resultMethod)) {
                return self::normalizeStatusCountsResult(
                    $resultMethod,
                    $viewReadService->{$resultMethod}()
                );
            }
            if (!method_exists($viewReadService, $flatMethod)) {
                throw new \RuntimeException('ViewReadService::' . $flatMethod . ' unavailable');
            }
            $flat = $viewReadService->{$flatMethod}();
            if (!is_array($flat)) {
                throw new \RuntimeException($flatMethod . ' returned non-array');
            }
            $counts = self::intMap($flat);
            return array(
                'counts' => $counts,
                'state' => empty($counts['_incomplete'])
                    ? ABJ_404_Solution_StatusCountsRefreshCoordinator::STATE_FRESH
                    : ABJ_404_Solution_StatusCountsRefreshCoordinator::STATE_UNCOMPUTED,
            );
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn',
                'FeedbackDiagnosticsCollector status-count lookup failed: ' . $e->getMessage());
            return array('counts' => array(), 'state' => self::STATUS_COUNTS_STATE_UNAVAILABLE);
        }
    }

    /**
     * @param mixed $raw
     * @return array{counts: array<string, int>, state: string}
     */
    private static function normalizeStatusCountsResult(string $method, $raw): array {
        if (!is_array($raw) || !isset($raw['counts']) || !is_array($raw['counts'])
                || !isset($raw['state']) || !is_string($raw['state']) || $raw['state'] === '') {
            throw new \RuntimeException($method . ' returned an unexpected shape');
        }
        return array('counts' => self::intMap($raw['counts']), 'state' => $raw['state']);
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, int>
     */
    private static function intMap(array $raw): array {
        $out = array();
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    /**
     * @return array<string, int>|null
     */
    private function redirectHitCountHistogram(): ?array {
        $histogram = $this->tryArray(function () { return $this->redirectHitCountHistogramRaw(); });
        if (empty($histogram)) {
            return null;
        }
        $buckets = array(
            'zero_hits' => 0,
            'one_to_ten_hits' => 0,
            'eleven_to_hundred_hits' => 0,
            'over_hundred_hits' => 0,
        );
        foreach ($buckets as $key => $_default) {
            $buckets[$key] = $this->pluckInt($histogram, $key) ?? 0;
        }
        return $buckets;
    }

    /**
     * @return array<string, int>
     */
    private function redirectHitCountHistogramRaw(): array {
        $viewReadService = $this->viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getRedirectHitCountHistogram')) {
            throw new \RuntimeException('ViewReadService::getRedirectHitCountHistogram unavailable');
        }
        $raw = $viewReadService->getRedirectHitCountHistogram();
        if (!is_array($raw)) {
            throw new \RuntimeException('getRedirectHitCountHistogram returned non-array');
        }
        $out = array();
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    private function logEntriesCount(): int {
        $viewReadService = $this->viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getLogsCount')) {
            throw new \RuntimeException('ViewReadService::getLogsCount unavailable');
        }
        $v = $viewReadService->getLogsCount(0);
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('getLogsCount returned unexpected shape');
    }

    private function logTableSizeBytes(): int {
        $dao = $this->viewReadService();
        if ($dao === null || !method_exists($dao, 'getLogDiskUsage')) {
            throw new \RuntimeException('DataAccess::getLogDiskUsage unavailable');
        }
        $v = $dao->getLogDiskUsage();
        if (!is_scalar($v)) {
            throw new \RuntimeException('getLogDiskUsage returned unexpected shape');
        }
        $bytes = (int)$v;
        if ($bytes < 0) {
            // -1 is the documented "query failed / unknown" sentinel from
            // LogsMetricsReader::getLogDiskUsage(). Map to null via tryInt()
            // so the feedback payload matches its schema (minimum: 0 | null).
            throw new \RuntimeException('getLogDiskUsage unknown (query failed)');
        }
        return $bytes;
    }

    /**
     * Count, size, tail, and anchor are projected from one reader result. The
     * production Logging facade exposes the snapshot API; the compatibility
     * branch keeps older test doubles and partially upgraded installs usable.
     *
     * @return array<string, mixed>
     */
    private function debugLogReportFields(string $type): array {
        try {
            $evidence = $this->debugLogReportEvidence();
            $fields = array(
                'error_count_in_log' => isset($evidence['total_error_count'])
                    && is_scalar($evidence['total_error_count'])
                    ? (int)$evidence['total_error_count'] : null,
                'debug_file_size_bytes' => isset($evidence['debug_file_size_bytes'])
                    && is_scalar($evidence['debug_file_size_bytes'])
                    ? (int)$evidence['debug_file_size_bytes'] : null,
            );
            if ($type === 'error') {
                $fields['debug_log'] = isset($evidence['debug_log']) && is_string($evidence['debug_log'])
                    ? $evidence['debug_log'] : '';
                $fields['debug_log_evidence'] = isset($evidence['debug_log_evidence'])
                    && is_array($evidence['debug_log_evidence'])
                    ? $evidence['debug_log_evidence'] : ABJ_404_Solution_DebugLogEvidenceBudget::emptyEvidence()['debug_log_evidence'];
            }
            return $fields;
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                'FeedbackDiagnosticsCollector debug-log snapshot failed: ' . $e->getMessage()
            );
            $fields = array('error_count_in_log' => null, 'debug_file_size_bytes' => null);
            if ($type === 'error') {
                $fields['debug_log'] = '';
                $fields['debug_log_evidence'] = ABJ_404_Solution_DebugLogEvidenceBudget::emptyEvidence()['debug_log_evidence'];
            }
            return $fields;
        }
    }

    /** @return array<string, mixed> */
    private function debugLogReportEvidence(): array {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger)) {
            throw new \RuntimeException('Logging service unavailable');
        }
        if (method_exists($logger, 'getDebugLogSnapshot')) {
            $snapshot = $logger->getDebugLogSnapshot();
            if (is_array($snapshot)) {
                return $this->shapeDebugLogSnapshot($snapshot);
            }
            throw new \RuntimeException('getDebugLogSnapshot returned unexpected shape');
        }
        if (method_exists($logger, 'getDebugFilePath')) {
            $path = $logger->getDebugFilePath();
            if (is_string($path) && $path !== '' && class_exists('ABJ_404_Solution_DebugLogReader')) {
                $reader = new ABJ_404_Solution_DebugLogReader(function (string $message): void {
                    ABJ_404_Solution_FeedbackTransportLog::log('warn', $message);
                });
                return $this->shapeDebugLogSnapshot($reader->getSnapshot($path));
            }
        }
        if (!method_exists($logger, 'getLatestErrorLine')) {
            throw new \RuntimeException('Logging debug-log readers unavailable');
        }
        $latest = $logger->getLatestErrorLine();
        if (!is_array($latest)) {
            throw new \RuntimeException('getLatestErrorLine returned unexpected shape');
        }
        return array(
            'total_error_count' => isset($latest['total_error_count']) && is_scalar($latest['total_error_count'])
                ? (int)$latest['total_error_count'] : 0,
            'debug_file_size_bytes' => 0,
            'debug_log' => '',
            'debug_log_evidence' => ABJ_404_Solution_DebugLogEvidenceBudget::emptyEvidence()['debug_log_evidence'],
        );
    }

    /** @return array<string, mixed> */
    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function shapeDebugLogSnapshot(array $snapshot): array {
        $shaped = ABJ_404_Solution_DebugLogEvidenceBudget::fromSnapshot($snapshot);
        return array(
            'total_error_count' => isset($snapshot['total_error_count']) && is_scalar($snapshot['total_error_count'])
                ? (int)$snapshot['total_error_count'] : 0,
            'debug_file_size_bytes' => isset($snapshot['file_size']) && is_scalar($snapshot['file_size'])
                ? (int)$snapshot['file_size'] : 0,
            'debug_log' => $shaped['debug_log'],
            'debug_log_evidence' => $shaped['debug_log_evidence'],
        );
    }

    private function viewReadService(): ?object {
        if (!function_exists('abj_service_optional')) {
            return null;
        }
        $svc = abj_service_optional('view_read_service');
        return is_object($svc) ? $svc : null;
    }
}
