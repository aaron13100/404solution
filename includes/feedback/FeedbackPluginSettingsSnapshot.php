<?php

// allow-no-test-found: covered by tests/FeedbackPayloadSchemaContractTest.php through the public feedback payload builder

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Builds the deny-by-default plugin-settings diagnostic snapshot.
 *
 * The allowlist is intentionally a map of setting name to wire type. Adding a
 * new option to abj404_settings does not disclose it; a maintainer must add it
 * here deliberately after checking that the value is neither a credential nor
 * user-authored free text. The keys below come from PluginLogicDefaults and the
 * settings policies that persist per-page, sorting, and scheduling behavior.
 */
class ABJ_404_Solution_FeedbackPluginSettingsSnapshot {

    private const TYPE_BOOL = 'bool';
    private const TYPE_INT = 'int';
    private const TYPE_STRING = 'string';

    /**
     * Behavioral configuration safe to transmit in support diagnostics.
     *
     * Deliberately absent: GSC/OAuth/API credentials, email addresses,
     * user-authored templates/patterns/ignore lists, and filesystem paths.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = array(
        'default_redirect' => self::TYPE_INT,
        'capture_404' => self::TYPE_BOOL,
        'capture_deletion' => self::TYPE_INT,
        'manual_deletion' => self::TYPE_INT,
        'log_deletion' => self::TYPE_INT,
        'send_error_logs' => self::TYPE_BOOL,
        'debug_mode' => self::TYPE_BOOL,
        'log_raw_ips' => self::TYPE_BOOL,
        'maximum_log_disk_usage' => self::TYPE_INT,
        'remove_matches' => self::TYPE_BOOL,
        'suggest_max' => self::TYPE_INT,
        'suggest_cats' => self::TYPE_BOOL,
        'suggest_tags' => self::TYPE_BOOL,
        'suggest_minscore' => self::TYPE_INT,
        'suggest_minscore_enabled' => self::TYPE_BOOL,
        'update_suggest_url' => self::TYPE_BOOL,
        'auto_redirects' => self::TYPE_BOOL,
        'old_permalink_structure_redirects' => self::TYPE_BOOL,
        'auto_slugs' => self::TYPE_BOOL,
        'auto_trash_redirect' => self::TYPE_BOOL,
        'auto_score' => self::TYPE_INT,
        'auto_score_title' => self::TYPE_INT,
        'auto_score_category_tag' => self::TYPE_INT,
        'auto_score_content' => self::TYPE_INT,
        'auto_deletion' => self::TYPE_INT,
        'auto_302_expiration_days' => self::TYPE_INT,
        'auto_cats' => self::TYPE_BOOL,
        'auto_tags' => self::TYPE_BOOL,
        'template_redirect_priority' => self::TYPE_INT,
        'dest404_behavior' => self::TYPE_STRING,
        'redirect_all_requests' => self::TYPE_BOOL,
        'auto_trash_junk_urls' => self::TYPE_BOOL,
        'perpage' => self::TYPE_INT,
        'page_redirects_order_by' => self::TYPE_STRING,
        'page_redirects_order' => self::TYPE_STRING,
        'captured_order_by' => self::TYPE_STRING,
        'captured_order' => self::TYPE_STRING,
        'admin_notification' => self::TYPE_INT,
        'admin_notification_frequency' => self::TYPE_STRING,
        'admin_notification_digest_limit' => self::TYPE_INT,
        'days_wait_before_major_update' => self::TYPE_INT,
    );

    /**
     * Read normalized settings and return only approved scalar values.
     *
     * @return array<string, bool|int|string>
     */
    public function collect(): array {
        $repository = function_exists('abj_service_optional')
            ? abj_service_optional('options_repository')
            : null;
        if (!is_object($repository) || !method_exists($repository, 'getOptions')) {
            return array();
        }

        try {
            $options = $repository->getOptions(true);
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                'FeedbackPluginSettingsSnapshot options_repository probe failed: ' . $e->getMessage()
            );
            return array();
        }
        if (!is_array($options)) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                'FeedbackPluginSettingsSnapshot expected options array, got ' . gettype($options)
            );
            return array();
        }

        $snapshot = array();
        foreach (self::ALLOWLIST as $key => $type) {
            if (!array_key_exists($key, $options) || !is_scalar($options[$key])) {
                continue;
            }
            $normalized = $this->normalizeValue($options[$key], $type);
            if ($normalized !== null) {
                $snapshot[$key] = $normalized;
            }
        }
        return $snapshot;
    }

    /**
     * @param mixed $value
     * @return bool|int|string|null
     */
    private function normalizeValue($value, string $type) {
        if ($type === self::TYPE_INT) {
            return is_numeric($value) ? (int)$value : null;
        }
        if ($type === self::TYPE_BOOL) {
            if (in_array($value, array(true, 1, '1', 'on', 'yes'), true)) {
                return true;
            }
            if (in_array($value, array(false, 0, '0', 'off', 'no', ''), true)) {
                return false;
            }
            return null;
        }
        if ($type === self::TYPE_STRING) {
            if (is_string($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                return (string)$value;
            }
            return null;
        }
        return null;
    }
}
