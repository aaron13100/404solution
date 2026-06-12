<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders persisted admin notice message keys in the current WordPress locale.
 */
class ABJ_404_Solution_AdminNoticeMessageCatalog {

    /**
     * @param array<mixed,mixed> $payload
     * @return string
     */
    public static function renderPayloadMessage(array $payload): string {
        $key = isset($payload['message_key']) && is_string($payload['message_key']) ? $payload['message_key'] : '';
        if ($key !== '') {
            $params = isset($payload['message_params']) && is_array($payload['message_params'])
                ? $payload['message_params'] : array();
            return self::renderKey($key, $params);
        }
        return isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : '';
    }

    /**
     * @param array<mixed,mixed> $payload
     * @return string
     */
    public static function renderDbGuidance(array $payload): string {
        $type = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : '';
        if ($type === 'disk_full') {
            return self::translate('Contact your hosting provider. This is usually caused by a database quota, tablespace limit, or full /tmp partition - not necessarily a full disk.');
        }
        if ($type === 'read_only') {
            return self::translate('Your database is currently in read-only mode. Contact your hosting provider.');
        }
        if ($type === 'query_quota') {
            return self::translate('Your database query quota was exceeded. This usually resets automatically.');
        }
        if ($type === 'corrupted_temp_table') {
            return self::translate('A temporary MySQL table was corrupted, usually caused by disk or hardware issues. The plugin cannot repair it. Please contact your hosting provider.');
        }
        if ($type === 'log_table_full') {
            return self::translate('The 404 Solution log table is full. The plugin automatically trimmed the oldest 1,000 log entries to free space, but logging may still be limited. Please contact your hosting provider about disk space.');
        }
        if ($type === 'stale_permalink_cache') {
            return self::translate('The permalink cache appears to be empty. Try rebuilding it from the Tools tab, or check that your site has enough disk space.');
        }
        if ($type === 'lock_timeout') {
            return self::translate('A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.');
        }
        return '';
    }

    /**
     * @param array<mixed,mixed> $params
     * @return string
     */
    public static function renderKey(string $key, array $params = array()): string {
        switch ($key) {
            case 'db.disk_full':
                return self::translate('Database storage appears full (disk/engine space). Plugin write-heavy tasks are temporarily paused.');
            case 'db.innodb_tablespace_full':
                return self::translate('The InnoDB tablespace appears to be exhausted. Deleting plugin data will NOT free this space. Contact your hosting provider to expand the InnoDB tablespace (ibdata1).');
            case 'db.query_quota':
                return self::translate('Database query quota was exceeded (for example max_questions). Non-essential plugin background tasks are temporarily paused.');
            case 'db.read_only':
                return self::translate('Database appears to be in read-only mode. Plugin write operations are temporarily paused.');
            case 'db.corrupted_temp_table':
                return self::translate('A database temporary table is corrupted - this is usually caused by a full or failing disk. Please contact your host. (MySQL error 1034)');
            case 'db.table_full':
                return self::translate('The logs table is full.');
            case 'db.log_table_full':
                return self::translate('The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.');
            case 'db.stale_permalink_cache':
                return self::translate('Permalink cache appears empty after rebuild - suggestions may be degraded. Try rebuilding again or check available disk space.');
            case 'db.lock_timeout':
                return self::translate('A database lock wait timeout occurred. If this persists, contact your host - another process may be holding a long-running lock.');
            case 'db.missing_table':
                return self::renderMissingTable($params);
            case 'view.logs_hits_rollup_stale':
                return sprintf(
                    self::translate('The 404 Solution redirects-hits rollup has been behind MAX(logsv2.id) for at least %d hour(s). The cron-driven rebuild event (abj404_updateLogsHitsTableAction) does not appear to be firing, so the redirects list will show stale "hits" and "last hit" columns until cron resumes. To resolve: if DISABLE_WP_CRON is set in wp-config.php either remove it, or configure a system cron job that requests wp-cron.php periodically. To force a rebuild right now in your browser, open the 404 Solution Redirects page with ?abj404_force_view_rebuild=1 appended to the URL.'),
                    self::intParam($params, 'age_hours')
                );
            case 'view.build_cron_stuck':
                return sprintf(
                    self::translate('WordPress cron does not appear to be running. The earliest overdue cron event has been waiting at least %d hours, so cron-dependent plugin features (staged view-build, daily cleanup, log updates, digest emails) are not advancing. To resolve: if DISABLE_WP_CRON is set in wp-config.php either remove it, or configure a system cron job that requests wp-cron.php periodically. To force the redirect view to rebuild right now in your browser (workaround while cron is broken), open the 404 Solution Redirects page with ?abj404_force_view_rebuild=1 appended to the URL.'),
                    self::intParam($params, 'hours_stuck')
                );
            case 'view.build_schedule_failed':
                $message = self::translate('Scheduling the 404 Solution staged view-build cron event failed. The build will not advance in the background until this clears. This usually indicates the WordPress cron lock is held, the cron option is unwritable, or a custom cron implementation rejected the event. Check your hosting provider and any cron-replacement plugins. To force the redirect view to rebuild right now in your browser (workaround while cron scheduling is failing), open the 404 Solution Redirects page with ?abj404_force_view_rebuild=1 appended to the URL.');
                $detail = self::stringParam($params, 'detail');
                return $detail !== '' ? $message . ' (' . $detail . ')' : $message;
            case 'view.stage_degraded':
                return self::renderStageDegraded($params);
            case 'view.build_named_lock_unsupported':
                return self::translate('This database does not support session-scoped GET_LOCK named locks. The plugin is using a WordPress option-row fallback to coordinate the staged view-build. Common on PlanetScale, Vitess, and split-routing ProxySQL deployments.');
            case 'view.low_memory_limit':
                return sprintf(
                    self::translate('Your PHP memory_limit (%s) is below the recommended 128M; the redirect view rebuild may fail on large sites.'),
                    self::stringParam($params, 'memory_limit')
                );
            case 'view.filesystem_env':
                return self::translate('The 404 Solution view-build pipeline detected filesystem host constraints that may degrade the next rebuild: ')
                    . self::stringParam($params, 'warnings');
            case 'view.session_env':
                return self::translate('The 404 Solution view-build pipeline detected MySQL session-variable settings that may degrade the next rebuild: ')
                    . self::stringParam($params, 'warnings');
            default:
                return '';
        }
    }

    /**
     * @param array<mixed,mixed> $params
     * @return string
     */
    private static function renderMissingTable(array $params): string {
        $tableLabel = self::stringParam($params, 'table_label');
        if ($tableLabel === '') {
            $tableLabel = 'a plugin database table';
        }
        $message = sprintf(
            self::translate('404 Solution cannot function correctly: the database table %s is missing, and the plugin tried to recreate it but the CREATE TABLE statement could not be executed. This almost always means the WordPress database user does not have permission to run CREATE TABLE (and likely ALTER TABLE / CREATE INDEX) on this database. Until this is fixed, the plugin cannot record 404s, serve redirects, or generate suggestions. To fix it: ask your hosting provider or database administrator to grant CREATE, ALTER, and INDEX privileges to the WordPress database user for this site, then reload this page. Alternatively, restore the missing table from a recent database backup.'),
            $tableLabel
        );
        $rawError = self::stringParam($params, 'raw_error');
        if ($rawError !== '') {
            $message .= ' ' . sprintf(self::translate('Original database error: %s'), $rawError);
        }
        $prefixDiag = self::stringParam($params, 'prefix_diag');
        if ($prefixDiag !== '') {
            $message .= ' ' . $prefixDiag;
        }
        return $message;
    }

    /**
     * @param array<mixed,mixed> $params
     * @return string
     */
    private static function renderStageDegraded(array $params): string {
        $stageNumber = self::intParam($params, 'stage');
        $kind = self::stringParam($params, 'kind');
        $errorText = self::stringParam($params, 'error');
        $errorSnippet = substr(trim($errorText), 0, 200);
        $base = $kind === 'halted'
            ? sprintf(self::translate('The 404 Solution view-build pipeline halted at stage %d/11.'), $stageNumber)
            : sprintf(self::translate('The 404 Solution view-build pipeline skipped optional stage %d/11.'), $stageNumber);

        $hint = '';
        if (stripos($errorText, 'create temporary') !== false || stripos($errorText, "to database '") !== false
            || ($stageNumber === 9 && stripos($errorText, 'access denied') !== false)) {
            $hint = ' ' . self::translate('Ask your host to grant the CREATE TEMPORARY TABLES privilege to your WordPress database user so the hits aggregate column can be populated.');
        } elseif (stripos($errorText, 'alter command denied') !== false) {
            $hint = ' ' . self::translate('Ask your host to grant the ALTER privilege to your WordPress database user.');
        } elseif (stripos($errorText, 'rename') !== false || $stageNumber === 11) {
            $hint = ' ' . self::translate('Ask your host to grant ALTER + DROP + CREATE on the database used by WordPress so the view-build swap can complete.');
        } elseif (stripos($errorText, 'access denied') !== false || stripos($errorText, 'command denied') !== false) {
            $hint = ' ' . self::translate('Ask your host to review your WordPress database user privileges.');
        }

        return $base . $hint . ' ' . sprintf(self::translate('Original error: %s'), $errorSnippet);
    }

    private static function translate(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }

    /** @param array<mixed,mixed> $params */
    private static function stringParam(array $params, string $key): string {
        return isset($params[$key]) && is_scalar($params[$key]) ? (string)$params[$key] : '';
    }

    /** @param array<mixed,mixed> $params */
    private static function intParam(array $params, string $key): int {
        return isset($params[$key]) && is_scalar($params[$key]) ? (int)$params[$key] : 0;
    }
}
