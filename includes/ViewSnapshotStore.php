<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists admin view snapshots in the dedicated cache table and option locks.
 */
class ABJ_404_Solution_ViewSnapshotStore {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var bool */
    private static $viewSnapshotTableEnsured = false;

    /** @param ABJ_404_Solution_DatabaseCore $dbCore */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
    }

    /**
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        // Cache key intentionally omits the mutation watermark. Including it
        // produced a race on busy sites. getRedirectsForView read watermark
        // value A, wrote the snapshot under key-with-A, then an incoming 404
        // capture bumped the watermark to B before viewRowsSnapshotAvailable
        // ran. The availability check read with key-with-B and missed,
        // throwing "Warmup rows stage completed but the row snapshot was not
        // available afterward." The cache TTL of 120 seconds bounds staleness
        // to two minutes after any mutation, which is acceptable. The next
        // admin page load picks up fresh data automatically.
        $scoreRange = $tableOptions['score_range'] ?? 'all';
        $cacheShape = array(
            'sub' => (string)$sub,
            'filter' => $this->scalarInt($tableOptions['filter'] ?? 0, 0),
            'orderby' => $this->scalarString($tableOptions['orderby'] ?? 'url', 'url'),
            'order' => $this->scalarString($tableOptions['order'] ?? 'ASC', 'ASC'),
            'paged' => $this->scalarInt($tableOptions['paged'] ?? 1, 1),
            'perpage' => $this->scalarInt($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE, ABJ404_OPTION_DEFAULT_PERPAGE),
            'filterText' => $this->scalarString($tableOptions['filterText'] ?? '', ''),
            'score_range' => is_string($scoreRange) ? $scoreRange : 'all',
            'blog' => function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1,
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($cacheShape) : json_encode($cacheShape);
        return $prefix . '_' . md5((string)$encoded);
    }

    /**
     * @param mixed $value
     * @param int $default
     * @return int
     */
    private function scalarInt($value, int $default): int {
        return is_scalar($value) ? (int)$value : $default;
    }

    /**
     * @param mixed $value
     * @param string $default
     * @return string
     */
    private function scalarString($value, string $default): string {
        return is_scalar($value) ? (string)$value : $default;
    }

    /** @return void */
    private function ensureViewSnapshotTableExists(): void {
        if (self::$viewSnapshotTableEnsured) {
            return;
        }
        self::$viewSnapshotTableEnsured = true;
        $sqlFile = __DIR__ . '/sql/createViewCacheTable.sql';
        $create = ABJ_404_Solution_FileSystemService::readFileContents($sqlFile);
        if (is_string($create) && trim($create) !== '') {
            $this->dbCore->queryAndGetResults($create, array('log_errors' => false));
        }
    }

    /** @param string $cacheKey @return string */
    private function getViewSnapshotLockOptionName(string $cacheKey): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }

    /** @return string */
    private function getViewSnapshotWarmupGlobalLockKey(): string {
        return 'abj404_view_table_warmup_global';
    }

    /** @return bool */
    public function acquireViewSnapshotWarmupGlobalLock(): bool {
        return $this->acquireViewSnapshotRefreshLock($this->getViewSnapshotWarmupGlobalLockKey());
    }

    /** @return void */
    public function releaseViewSnapshotWarmupGlobalLock(): void {
        $this->releaseViewSnapshotRefreshLock($this->getViewSnapshotWarmupGlobalLockKey());
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
        if ($lockTs > 0 && (time() - $lockTs) > ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS) {
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
     * @return array<int|string, mixed>|null
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
     * @return array<int|string, mixed>|null
     */
    public function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        $this->ensureViewSnapshotTableExists();
        $query = "SELECT payload, refreshed_at, expires_at
            FROM {wp_abj404_view_cache}
            WHERE cache_key = %s LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => array($cacheKey), 'log_errors' => true));
        $resultRows = $result['rows'] ?? array();
        if (!is_array($resultRows) || empty($resultRows) || !is_array($resultRows[0])) {
            return null;
        }
        $row = $resultRows[0];
        $expiresAtRaw = $row['expires_at'] ?? 0;
        $refreshedAtRaw = $row['refreshed_at'] ?? 0;
        $expiresAt = is_scalar($expiresAtRaw) ? intval($expiresAtRaw) : 0;
        $refreshedAt = is_scalar($refreshedAtRaw) ? intval($refreshedAtRaw) : 0;
        $now = time();
        $isFresh = ($expiresAt > $now);
        $recentEnough = ($refreshedAt > 0 && ($now - $refreshedAt) <= ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS);
        if (!$allowExpired && !$isFresh) {
            return null;
        }
        if ($respectCooldown && !$isFresh && !$recentEnough) {
            return null;
        }
        $payload = $row['payload'] ?? '';
        return $this->decodeSnapshotPayload(is_scalar($payload) ? (string)$payload : '');
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    public function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        if (!is_array($rows)) {
            return;
        }
        $this->ensureViewSnapshotTableExists();
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($rows) : json_encode($rows);
        if (!is_string($encoded)) {
            return;
        }
        $bytes = strlen($encoded);
        if ($bytes > ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES) {
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
        $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array($cacheKey, (string)$sub, $encoded, $bytes, $now, $expiresAt, $now),
            'log_errors' => false,
        ));
        $this->cleanupExpiredViewSnapshotRowsIfNeeded();
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<int|string, mixed>|null
     */
    public function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
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
        $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array(time() - ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS),
            'log_errors' => false,
        ));
    }
}
