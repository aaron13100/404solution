<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-list view snapshot caching: a `wp_abj404_view_cache` table holds JSON
 * payloads for the admin list/count views so a fast first paint is possible
 * without waiting on the heavy aggregate queries. This trait owns the
 * lifecycle: table DDL, refresh-lock dance (option-based, cooldown-bounded),
 * snapshot read/write/wait, and stale-row cleanup. Constants
 * (`VIEW_SNAPSHOT_*`) and the request-local "DDL ensured" flag remain on the
 * using class because tests reset them via the public static accessor.
 */
trait ABJ_404_Solution_DataAccess_ViewSnapshotCacheTrait {

    /**
     * Build a stable cache key for admin list data/count snapshots.
     *
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        $cacheShape = array(
            'sub' => (string)$sub,
            'filter' => is_scalar($tableOptions['filter'] ?? 0) ? (int)($tableOptions['filter'] ?? 0) : 0,
            'orderby' => is_scalar($tableOptions['orderby'] ?? 'url') ? (string)($tableOptions['orderby'] ?? 'url') : 'url',
            'order' => is_scalar($tableOptions['order'] ?? 'ASC') ? (string)($tableOptions['order'] ?? 'ASC') : 'ASC',
            'paged' => is_scalar($tableOptions['paged'] ?? 1) ? (int)($tableOptions['paged'] ?? 1) : 1,
            'perpage' => is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? (int)($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE,
            'filterText' => is_scalar($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '',
            'score_range' => (function ($v) { return is_string($v) ? $v : 'all'; })($tableOptions['score_range'] ?? 'all'),
            'blog' => function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1,
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($cacheShape) : json_encode($cacheShape);
        return $prefix . '_' . md5((string)$encoded);
    }

    /** @return void */
    private function ensureViewSnapshotTableExists(): void {
        if (self::$viewSnapshotTableEnsured) {
            return;
        }
        self::$viewSnapshotTableEnsured = true;
        $sqlFile = __DIR__ . '/sql/createViewCacheTable.sql';
        $create = ABJ_404_Solution_Functions::readFileContents($sqlFile);
        if (is_string($create) && trim($create) !== '') {
            $this->queryAndGetResults($create, array('log_errors' => false));
        }
    }

    /** @param string $cacheKey @return string */
    private function getViewSnapshotLockOptionName(string $cacheKey): string {
        return $this->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }

    /** @param string $cacheKey @return string */
    private function getViewWarmupStateOptionName(string $cacheKey): string {
        return $this->getLowercasePrefix() . 'abj404_view_warmup_' . md5((string)$cacheKey);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    private function canUseViewTableSnapshotCache(array $tableOptions): bool {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $isLogsMaintenanceSort = ($orderBy === 'logshits' || $orderBy === 'last_used');
        $rawPerpage = $tableOptions['perpage'] ?? 0;
        return absint(is_scalar($rawPerpage) ? $rawPerpage : 0) <= 200 && !$isLogsMaintenanceSort;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewTableWarmupShapeKey(string $sub, array $tableOptions): string {
        return $this->getViewSnapshotCacheKey('abj404_view_table', $sub, $tableOptions);
    }

    /**
     * @param mixed $state
     * @return array<string, mixed>
     */
    private function normalizeViewWarmupState($state): array {
        $default = array(
            'status' => 'idle',
            'stage' => 'rows',
            'stage_started_at' => 0,
            'stage_completed_at' => 0,
            'attempts_by_stage' => array('rows' => 0, 'count' => 0),
            'timings_by_stage' => array(
                'rows' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
                'count' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
            ),
            'query_label' => 'getRedirectsForView',
            'last_error' => '',
            'logged_stale_by_stage' => array(),
        );
        if (!is_array($state)) {
            return $default;
        }
        $out = array_merge($default, $state);
        if (!in_array($out['status'], array('idle', 'running', 'ready', 'blocked', 'error'), true)) {
            $out['status'] = 'idle';
        }
        if (!in_array($out['stage'], array('rows', 'count'), true)) {
            $out['stage'] = 'rows';
        }
        $out['stage_started_at'] = intval($out['stage_started_at']);
        $out['stage_completed_at'] = intval($out['stage_completed_at']);

        $attempts = is_array($out['attempts_by_stage']) ? $out['attempts_by_stage'] : array();
        $out['attempts_by_stage'] = array(
            'rows' => intval($attempts['rows'] ?? 0),
            'count' => intval($attempts['count'] ?? 0),
        );

        $timings = is_array($out['timings_by_stage']) ? $out['timings_by_stage'] : array();
        $out['timings_by_stage'] = array(
            'rows' => $this->normalizeStageTiming($timings['rows'] ?? null),
            'count' => $this->normalizeStageTiming($timings['count'] ?? null),
        );

        $out['query_label'] = is_string($out['query_label']) ? $out['query_label'] : $this->getViewWarmupStageQueryLabel((string)$out['stage']);
        $out['last_error'] = is_string($out['last_error']) ? $out['last_error'] : '';
        $out['logged_stale_by_stage'] = is_array($out['logged_stale_by_stage']) ? $out['logged_stale_by_stage'] : array();
        return $out;
    }

    /**
     * @param mixed $timing
     * @return array<string, mixed>
     */
    private function normalizeStageTiming($timing): array {
        $default = array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => '');
        if (!is_array($timing)) {
            return $default;
        }
        return array(
            'last_ms' => intval($timing['last_ms'] ?? 0),
            'max_ms' => intval($timing['max_ms'] ?? 0),
            'last_completed_at' => intval($timing['last_completed_at'] ?? 0),
            'last_error' => is_string($timing['last_error'] ?? '') ? (string)$timing['last_error'] : '',
        );
    }

    /** @param string $stage @return string */
    private function getViewWarmupStageQueryLabel(string $stage): string {
        return $stage === 'count' ? 'getRedirectsForViewCount' : 'getRedirectsForView';
    }

    /** @param string $stage @return int */
    private function getViewWarmupStageNumber(string $stage): int {
        return $stage === 'count' ? 2 : 1;
    }

    /**
     * @param string $optionName
     * @return array<string, mixed>
     */
    private function getViewWarmupState(string $optionName): array {
        if (!function_exists('get_option')) {
            return $this->normalizeViewWarmupState(null);
        }
        return $this->normalizeViewWarmupState(get_option($optionName, array()));
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $state
     * @return void
     */
    private function setViewWarmupState(string $optionName, array $state): void {
        if (function_exists('update_option')) {
            update_option($optionName, $state, false);
        } else if (function_exists('add_option')) {
            add_option($optionName, $state, '', false);
        }
    }

    /**
     * Warm exactly one admin table snapshot stage, then return progress.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        if (!$this->canUseViewTableSnapshotCache($tableOptions)) {
            return array(
                'status' => 'ready',
                'ready' => true,
                'uncached' => true,
                'stage' => 'rows',
                'stageNumber' => 1,
                'queryLabel' => 'getRedirectsForView',
                'message' => 'This table shape is not snapshot-cacheable.',
            );
        }

        $shapeKey = $this->getViewTableWarmupShapeKey($sub, $tableOptions);
        $optionName = $this->getViewWarmupStateOptionName($shapeKey);
        $state = $this->getViewWarmupState($optionName);
        $now = time();

        if ($this->viewTableSnapshotAvailable($sub, $tableOptions)) {
            $state['status'] = 'ready';
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
            $state['stage_completed_at'] = $now;
            $state['last_error'] = '';
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, true);
        }

        if ($this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
        } else {
            $state['stage'] = 'rows';
            $state['query_label'] = 'getRedirectsForView';
        }

        $stage = (string)$state['stage'];
        $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
        $attemptCount = intval($attempts[$stage] ?? 0);

        if ($state['status'] === 'running') {
            $elapsed = $now - intval($state['stage_started_at']);
            if ($elapsed <= self::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS) {
                return $this->formatViewWarmupResponse($state, false);
            }
            if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
                $state['status'] = 'blocked';
                $previousError = is_string($state['last_error'] ?? '') ? trim((string)$state['last_error']) : '';
                $state['last_error'] = 'Previous warmup stage was killed or stalled too many times.'
                    . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
                $this->logViewWarmupFailure($sub, $tableOptions, $state);
                $this->setViewWarmupState($optionName, $state);
                return $this->formatViewWarmupResponse($state, false);
            }
            $loggedKey = $stage . ':' . intval($state['stage_started_at']);
            if (empty($state['logged_stale_by_stage'][$loggedKey])) {
                $this->logStaleViewWarmupStage($sub, $tableOptions, $state, $elapsed, $attemptCount);
                $state['logged_stale_by_stage'][$loggedKey] = 1;
            }
        }

        if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $previousError = is_string($state['last_error'] ?? '') ? trim((string)$state['last_error']) : '';
            if (!$this->isViewWarmupErrorDiagnostic($previousError)) {
                $attempts[$stage] = self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS - 1;
                $state['attempts_by_stage'] = $attempts;
                $attemptCount = intval($attempts[$stage] ?? 0);
            }
        }

        if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $state['status'] = 'blocked';
            $state['last_error'] = 'Warmup stage reached the retry limit.'
                . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        }

        $attempts[$stage] = $attemptCount + 1;
        $state['status'] = 'running';
        $state['stage_started_at'] = $now;
        $state['stage_completed_at'] = 0;
        $state['attempts_by_stage'] = $attempts;
        $state['query_label'] = $this->getViewWarmupStageQueryLabel($stage);
        $state['last_error'] = '';
        $this->setViewWarmupState($optionName, $state);

        $stageOptions = $tableOptions;
        $stageOptions['_abj404_query_timeout'] = self::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
        $stageOptions['_abj404_throw_on_view_query_error'] = true;

        $startMs = microtime(true);
        try {
            if ($stage === 'rows') {
                $this->getRedirectsForView($sub, $stageOptions);
                $state['status'] = 'idle';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            } else {
                $this->getRedirectsForViewCount($sub, $stageOptions);
                $state['status'] = 'ready';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            }
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $state['stage_completed_at'] = time();
            $state['last_error'] = '';

            $timings = $state['timings_by_stage'][$stage];
            $timings['last_ms'] = $elapsedMs;
            $timings['max_ms'] = max($timings['max_ms'], $elapsedMs);
            $timings['last_completed_at'] = $state['stage_completed_at'];
            $timings['last_error'] = '';
            $state['timings_by_stage'][$stage] = $timings;

            if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage(sprintf(
                    "[warmup] shape=%s stage=%s ms=%d attempts=%d error=",
                    substr($shapeKey, 0, 8),
                    $stage,
                    $elapsedMs,
                    $attemptCount + 1
                ));
            }

            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, $state['status'] === 'ready');
        } catch (Throwable $e) {
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $state['last_error'] = $e->getMessage();
            $state['stage_completed_at'] = time();
            $state['status'] = (intval($attempts[$stage] ?? 0) >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) ? 'blocked' : 'idle';

            $timings = $state['timings_by_stage'][$stage];
            $timings['last_error'] = $state['last_error'];
            $state['timings_by_stage'][$stage] = $timings;

            if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage(sprintf(
                    "[warmup] shape=%s stage=%s ms=%d attempts=%d error=%s",
                    substr($shapeKey, 0, 8),
                    $stage,
                    $elapsedMs,
                    $attemptCount + 1,
                    $state['last_error']
                ));
            }

            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param bool $ready
     * @return array<string, mixed>
     */
    private function formatViewWarmupResponse(array $state, bool $ready): array {
        $stage = (string)($state['stage'] ?? 'rows');
        return array(
            'status' => (string)($state['status'] ?? 'idle'),
            'ready' => $ready || (string)($state['status'] ?? '') === 'ready',
            'stage' => $stage,
            'stageNumber' => $this->getViewWarmupStageNumber($stage),
            'queryLabel' => $this->getViewWarmupStageQueryLabel($stage),
            'stageStartedAt' => intval($state['stage_started_at'] ?? 0),
            'stageCompletedAt' => intval($state['stage_completed_at'] ?? 0),
            'attemptsByStage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'timingsByStage' => is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array(),
            'lastError' => is_string($state['last_error'] ?? '') ? (string)$state['last_error'] : '',
        );
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @param int $elapsed
     * @param int $attemptCount
     * @return void
     */
    private function logStaleViewWarmupStage(string $sub, array $tableOptions, array $state, int $elapsed, int $attemptCount): void {
        $details = array(
            'stage' => (string)($state['stage'] ?? ''),
            'query_label' => (string)($state['query_label'] ?? ''),
            'elapsed_seconds' => $elapsed,
            'subpage' => $sub,
            'attempt_count' => $attemptCount,
            'table_shape' => array(
                'filter' => $tableOptions['filter'] ?? null,
                'orderby' => $tableOptions['orderby'] ?? null,
                'order' => $tableOptions['order'] ?? null,
                'paged' => $tableOptions['paged'] ?? null,
                'perpage' => $tableOptions['perpage'] ?? null,
                'filterText_length' => is_string($tableOptions['filterText'] ?? null) ? strlen((string)$tableOptions['filterText']) : 0,
                'score_range' => $tableOptions['score_range'] ?? null,
            ),
        );
        $message = 'Table cache warmup stage appears stalled: ' . json_encode($details);
        if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
        } else if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'errorMessage')) {
            $this->logger->errorMessage($message);
        }
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @return void
     */
    private function logViewWarmupFailure(string $sub, array $tableOptions, array $state): void {
        $details = array(
            'status' => (string)($state['status'] ?? ''),
            'stage' => (string)($state['stage'] ?? ''),
            'stage_number' => $this->getViewWarmupStageNumber((string)($state['stage'] ?? 'rows')),
            'query_label' => (string)($state['query_label'] ?? ''),
            'last_error' => (string)($state['last_error'] ?? ''),
            'subpage' => $sub,
            'attempts_by_stage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'table_shape' => array(
                'filter' => $tableOptions['filter'] ?? null,
                'orderby' => $tableOptions['orderby'] ?? null,
                'order' => $tableOptions['order'] ?? null,
                'paged' => $tableOptions['paged'] ?? null,
                'perpage' => $tableOptions['perpage'] ?? null,
                'filterText_length' => is_string($tableOptions['filterText'] ?? null) ? strlen((string)$tableOptions['filterText']) : 0,
                'score_range' => $tableOptions['score_range'] ?? null,
            ),
        );
        $message = 'Table cache warmup failed: ' . json_encode($details);
        if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'errorMessage')) {
            $this->logger->errorMessage($message);
        } else if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
        } else {
            error_log('404 Solution: ' . $message);
        }
    }

    /** @param string $lastError @return bool */
    private function isViewWarmupErrorDiagnostic(string $lastError): bool {
        if ($lastError === '') {
            return false;
        }
        return $lastError !== 'Warmup stage reached the retry limit.'
            && $lastError !== 'Previous warmup stage was killed or stalled too many times.';
    }

    /** @param string $cacheKey @return bool */
    private function isViewSnapshotRefreshLocked(string $cacheKey): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        $lockValue = get_option($lockKey, false);
        if ($lockValue === false || $lockValue === '' || $lockValue === null) {
            return false;
        }
        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (time() - $lockTs) > self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($lockKey);
            }
            return false;
        }
        return true;
    }

    /** @param string $cacheKey @return bool */
    private function acquireViewSnapshotRefreshLock(string $cacheKey): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isViewSnapshotRefreshLocked($cacheKey)) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        return (bool)add_option($lockKey, time(), '', false);
    }

    /** @param string $cacheKey @return void */
    private function releaseViewSnapshotRefreshLock(string $cacheKey): void {
        if (function_exists('delete_option')) {
            delete_option($this->getViewSnapshotLockOptionName($cacheKey));
        }
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function decodeSnapshotPayload($payload) {
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $cacheKey
     * @param bool $allowExpired
     * @param bool $respectCooldown
     * @return array<string, mixed>|null
     */
    private function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        $this->ensureViewSnapshotTableExists();
        $query = "SELECT payload, refreshed_at, expires_at
            FROM {wp_abj404_view_cache}
            WHERE cache_key = %s LIMIT 1";
        $result = $this->queryAndGetResults($query, array('query_params' => array($cacheKey), 'log_errors' => true));
        if (!is_array($result['rows']) || empty($result['rows']) || !is_array($result['rows'][0])) {
            return null;
        }
        $row = $result['rows'][0];
        $expiresAt = intval($row['expires_at'] ?? 0);
        $refreshedAt = intval($row['refreshed_at'] ?? 0);
        $now = time();
        $isFresh = ($expiresAt > $now);
        $recentEnough = ($refreshedAt > 0 && ($now - $refreshedAt) <= self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS);
        if (!$allowExpired && !$isFresh) {
            return null;
        }
        if ($respectCooldown && !$isFresh && !$recentEnough) {
            return null;
        }
        return $this->decodeSnapshotPayload((string)($row['payload'] ?? ''));
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    private function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        if (!is_array($rows)) {
            return;
        }
        $this->ensureViewSnapshotTableExists();
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($rows) : json_encode($rows);
        if (!is_string($encoded)) {
            return;
        }
        $bytes = strlen($encoded);
        if ($bytes > self::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES) {
            return;
        }
        $now = time();
        $expiresAt = $now + max(1, intval($ttlSeconds));
        $query = "INSERT INTO {wp_abj404_view_cache}
            (cache_key, subpage, payload, payload_bytes, refreshed_at, expires_at, updated_at)
            VALUES (%s, %s, %s, %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE
                subpage = VALUES(subpage),
                payload = VALUES(payload),
                payload_bytes = VALUES(payload_bytes),
                refreshed_at = VALUES(refreshed_at),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)";
        $this->queryAndGetResults($query, array(
            'query_params' => array($cacheKey, (string)$sub, $encoded, $bytes, $now, $expiresAt, $now),
            'log_errors' => false,
        ));
        $this->cleanupExpiredViewSnapshotRowsIfNeeded();
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<string, mixed>|null
     */
    private function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
        $deadline = microtime(true) + (max(100, intval($timeoutMs)) / 1000);
        while (microtime(true) < $deadline) {
            $rows = $this->getViewRowsSnapshotFromTable($cacheKey, false, false);
            if (is_array($rows)) {
                return $rows;
            }
            usleep(100000);
        }
        return null;
    }

    /** @return void */
    private function cleanupExpiredViewSnapshotRowsIfNeeded(): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        $marker = get_transient('abj404_view_cache_cleanup_marker');
        if ($marker !== false) {
            return;
        }
        set_transient('abj404_view_cache_cleanup_marker', time(), 1800);
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE expires_at < %d";
        $this->queryAndGetResults($query, array(
            'query_params' => array(time() - self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS),
            'log_errors' => false,
        ));
    }
}
