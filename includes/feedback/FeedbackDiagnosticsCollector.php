<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects content, redirect, captured-404, log, and debug-file diagnostics
 * for feedback payloads. Each source degrades independently so one broken
 * optional service cannot abort the whole report.
 */
class ABJ_404_Solution_FeedbackDiagnosticsCollector {

    const DEBUG_LOG_MAX_BYTES = 262144;

    /**
     * @return array<string, mixed>
     */
    public function collect(string $type): array {
        $payload = array();

        $payload['published_posts_count'] = $this->tryInt(function () { return $this->countPublishedPosts(); });
        $payload['published_pages_count'] = $this->tryInt(function () { return $this->countPublishedPages(); });
        $payload['categories_count']      = $this->tryInt(function () { return $this->countCategories(); });
        $payload['tags_count']            = $this->tryInt(function () { return $this->countTags(); });

        $redirectCounts = $this->tryArray(function () { return $this->redirectCountsRaw(); });
        $payload['redirects_active_total']    = $this->pluckInt($redirectCounts, 'all');
        $payload['redirects_manual_count']    = $this->pluckInt($redirectCounts, 'manual');
        $payload['redirects_automatic_count'] = $this->pluckInt($redirectCounts, 'auto');
        $payload['redirects_regex_count']     = $this->pluckInt($redirectCounts, 'regex');
        $payload['redirects_trashed_count']   = $this->pluckInt($redirectCounts, 'trash');
        $payload['redirect_hit_count_histogram'] = $this->redirectHitCountHistogram();

        $capturedCounts = $this->tryArray(function () { return $this->capturedCountsRaw(); });
        $payload['captured_404s_active_total']  = $this->pluckInt($capturedCounts, 'all');
        $payload['captured_404s_new_count']     = $this->pluckInt($capturedCounts, 'captured');
        $payload['captured_404s_ignored_count'] = $this->pluckInt($capturedCounts, 'ignored');
        $payload['captured_404s_later_count']   = $this->pluckInt($capturedCounts, 'later');
        $payload['captured_404s_trashed_count'] = $this->pluckInt($capturedCounts, 'trash');

        $payload['log_entries_count']     = $this->tryInt(function () { return $this->logEntriesCount(); });
        $payload['log_table_size_bytes']  = $this->tryInt(function () { return $this->logTableSizeBytes(); });
        $payload['error_count_in_log']    = $this->tryInt(function () { return $this->errorCountInLog(); });
        $payload['debug_file_size_bytes'] = $this->tryInt(function () { return $this->debugFileSizeBytes(); });
        $payload += $this->debugLogPayload($type);

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
     * @return array<string, int>
     */
    private function redirectCountsRaw(): array {
        $viewReadService = $this->viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getRedirectStatusCounts')) {
            throw new \RuntimeException('ViewReadService::getRedirectStatusCounts unavailable');
        }
        $raw = $viewReadService->getRedirectStatusCounts(true);
        if (!is_array($raw)) {
            throw new \RuntimeException('getRedirectStatusCounts returned non-array');
        }
        $out = array();
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function capturedCountsRaw(): array {
        $viewReadService = $this->viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getCapturedStatusCounts')) {
            throw new \RuntimeException('ViewReadService::getCapturedStatusCounts unavailable');
        }
        $raw = $viewReadService->getCapturedStatusCounts(true);
        if (!is_array($raw)) {
            throw new \RuntimeException('getCapturedStatusCounts returned non-array');
        }
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

    private function errorCountInLog(): int {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getLatestErrorLine')) {
            throw new \RuntimeException('Logging::getLatestErrorLine unavailable');
        }
        $info = $logger->getLatestErrorLine();
        if (is_array($info) && isset($info['total_error_count']) && is_scalar($info['total_error_count'])) {
            return (int)$info['total_error_count'];
        }
        throw new \RuntimeException('getLatestErrorLine returned unexpected shape');
    }

    private function debugFileSizeBytes(): int {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getDebugFilePath')) {
            throw new \RuntimeException('Logging::getDebugFilePath unavailable');
        }
        $path = $logger->getDebugFilePath();
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return 0;
        }
        $fs = @filesize($path);
        if (is_int($fs)) {
            return $fs;
        }
        throw new \RuntimeException('filesize() failed');
    }

    /**
     * @return array{debug_log?: string}
     */
    private function debugLogPayload(string $type): array {
        // Only 'error' reports need the raw log tail for reproduction context.
        // A heartbeat has no error to diagnose; recent_error_signatures
        // (environment_extras) already surfaces any ERROR/WARN lines from the
        // same window in normalized form, so shipping up to 262144 raw bytes
        // on every weekly heartbeat is PII/bandwidth over-collection with no
        // offsetting diagnostic value.
        if ($type !== 'error') {
            return array();
        }
        return array('debug_log' => $this->tryString(function () { return $this->debugLogTail(); }));
    }

    private function debugLogTail(): string {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getDebugFilePath')) {
            throw new \RuntimeException('Logging::getDebugFilePath unavailable');
        }
        $path = $logger->getDebugFilePath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return '';
        }

        $size = @filesize($path);
        if (!is_int($size) || $size <= 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('fopen() failed');
        }

        try {
            $offset = max(0, $size - self::DEBUG_LOG_MAX_BYTES);
            if ($offset > 0 && @fseek($handle, $offset) !== 0) {
                throw new \RuntimeException('fseek() failed');
            }

            $remaining = min($size, self::DEBUG_LOG_MAX_BYTES);
            $contents = '';
            while ($remaining > 0 && !feof($handle)) {
                $chunk = @fread($handle, min(8192, $remaining));
                if ($chunk === false) {
                    throw new \RuntimeException('fread() failed');
                }
                if ($chunk === '') {
                    break;
                }
                $contents .= $chunk;
                $remaining -= strlen($chunk);
            }
            return $contents;
        } finally {
            fclose($handle);
        }
    }

    private function viewReadService(): ?object {
        if (!function_exists('abj_service_optional')) {
            return null;
        }
        $svc = abj_service_optional('view_read_service');
        return is_object($svc) ? $svc : null;
    }
}
