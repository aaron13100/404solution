<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles direct wpdb write recovery for log queue flushes.
 */
class ABJ_404_Solution_LogsWriteRecoveryPolicy {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /**
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState
     */
    public function __construct(
        $logger,
        ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState
    ) {
        $this->logger = $logger;
        $this->noticeState = $noticeState;
    }

    public function isCommandsOutOfSyncError(string $error): bool {
        return stripos($error, 'commands out of sync') !== false;
    }

    public function isTableFullError(string $error): bool {
        $lower = strtolower($error);
        return stripos($lower, 'is full') !== false || stripos($lower, 'table full') !== false;
    }

    /**
     * Free space in logsv2 by deleting the oldest 1000 entries. Rate-limited
     * to once per hour via a transient cooldown.
     */
    public function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || strpos($tableName, 'abj404_logsv2') === false) {
            $this->logger->warn("autoTrimLogsv2IfNeeded: rejected unexpected table name: " . substr($tableName, 0, 100));
            return false;
        }
        $cooldownKey = 'abj404_logsv2_trim_cooldown_until';
        $alreadyTrimmed = function_exists('get_transient') ? get_transient($cooldownKey) : false;
        if ($alreadyTrimmed) {
            return false;
        }
        global $wpdb;
        $trimSql = "DELETE FROM `{$tableName}` ORDER BY timestamp ASC LIMIT 1000";
        // DAO-bypass-approved: recovery trim must run on the active wpdb handle so last_error reflects the immediate retry context.
        $wpdb->query($trimSql);
        $ttl = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        // @cache-write-audit: opt-out - log-trim cooldown marker, not query result data.
        if (function_exists('set_transient')) {
            set_transient($cooldownKey, 1, $ttl);
        }
        if (!empty($wpdb->last_error)) {
            $this->logger->warn("Log table full: auto-trim failed: " . $wpdb->last_error);
        } else {
            $this->logger->warn("Log table full: auto-trimmed 1000 oldest entries to free space.");
        }
        return true;
    }

    public function setLogsv2FullNotice(string $errorMessage): void {
        $this->noticeState->setPluginDbNotice(
            'log_table_full',
            function_exists('__') ? __('The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.', '404-solution') : 'The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.',
            function_exists('__') ? __('The 404 Solution log table is full. The plugin automatically trimmed the oldest 1,000 log entries to free space, but logging may still be limited. Please contact your hosting provider about disk space.', '404-solution') : 'The 404 Solution log table is full. The plugin automatically trimmed the oldest 1,000 log entries to free space, but logging may still be limited. Please contact your hosting provider about disk space.',
            $errorMessage
        );
    }

    /**
     * @return wpdb|null
     */
    public function getIsolatedWpdb(): ?wpdb {
        static $isolated = null;
        if ($isolated !== null) {
            return $isolated;
        }
        if (!class_exists('wpdb')) {
            return null;
        }
        if (!defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_HOST')) {
            static $warnedNoDbConsts = false;
            if (!$warnedNoDbConsts) {
                $warnedNoDbConsts = true;
                $this->logger->warn(__METHOD__ . ': DB_USER/DB_PASSWORD/DB_NAME/DB_HOST undefined; isolated wpdb unavailable');
            }
            return null;
        }
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb
        $isolated = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $isolated->show_errors(false);
        $isolated->suppress_errors(true);
        return $isolated;
    }

    public function getWpdbRecentQueryContextForLogs(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }
        if (!defined('SAVEQUERIES') || SAVEQUERIES !== true) {
            return '';
        }
        if (empty($wpdb->queries) || !is_array($wpdb->queries)) {
            return '';
        }
        $recent = array_slice($wpdb->queries, -5);
        $parts = [];
        foreach ($recent as $q) {
            if (!is_array($q)) {
                continue;
            }
            $sql = $q[0] ?? '';
            $time = $q[1] ?? null;
            $caller = $q[2] ?? '';
            $hash = is_string($sql) ? substr(sha1($sql), 0, 10) : 'n/a';
            $who = $this->extractWpComponentFromString(is_string($caller) ? $caller : '');
            $t = is_numeric($time) ? round((float)$time, 3) : 'n/a';
            $parts[] = "{$who}:{$hash}@{$t}";
        }
        return implode(', ', $parts);
    }

    private function extractWpComponentFromString(string $text): string {
        $normalized = str_replace('\\', '/', $text);
        foreach (array('/wp-content/mu-plugins/' => 'mu-plugin', '/wp-content/plugins/' => 'plugin', '/wp-content/themes/' => 'theme') as $needle => $label) {
            $pos = strpos($normalized, $needle);
            if ($pos !== false) {
                $rest = substr($normalized, $pos + strlen($needle));
                $name = explode('/', ltrim($rest, '/'))[0] ?? '';
                return $name !== '' ? "{$label}:{$name}" : "{$label}:unknown";
            }
        }
        return 'unknown';
    }
}
