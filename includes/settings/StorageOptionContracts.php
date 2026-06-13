<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versioned contracts for durable structured wp_options values.
 *
 * Reads are tolerant: legacy arrays are migrated to the current shape and
 * corrupt values fall back to safe defaults after logging context. Writes are
 * strict: invalid current payloads throw before update_option() can persist
 * them.
 */
class ABJ_404_Solution_StorageOptionContracts {

    const CURRENT_VERSION = 2;
    const OPTION_SETTINGS = 'abj404_settings';
    const OPTION_UNINSTALL_PREFERENCES = 'abj404_uninstall_preferences';

    /**
     * @param string $optionName
     * @param mixed $rawValue
     * @return array<string, mixed>
     */
    public static function normalizeForRead(string $optionName, $rawValue): array {
        if (!self::isContractedOption($optionName)) {
            $value = self::toStringKeyedArray($rawValue);
            return $value === null ? array() : $value;
        }

        if (!is_array($rawValue)) {
            self::logReadIssue($optionName, 'expected array, got ' . gettype($rawValue));
            return self::defaultValue($optionName);
        }

        $value = self::toStringKeyedArray($rawValue);
        if ($value === null) {
            self::logReadIssue($optionName, 'expected top-level string keys');
            return self::defaultValue($optionName);
        }

        $version = self::readVersion($value);
        if ($version === null) {
            self::logReadIssue($optionName, 'invalid _schemaVersion value');
            return self::defaultValue($optionName);
        }

        if ($version > self::CURRENT_VERSION) {
            self::logReadIssue($optionName, 'future _schemaVersion ' . $version . ' is not supported');
            return self::defaultValue($optionName);
        }

        try {
            while ($version < self::CURRENT_VERSION) {
                $migration = self::loadMigration($optionName, $version);
                $migrated = $migration($value);
                if (!is_array($migrated)) {
                    throw new RuntimeException('migration returned ' . gettype($migrated) . ', expected array');
                }
                $nextVersion = self::readVersion($migrated);
                if ($nextVersion !== $version + 1) {
                    throw new RuntimeException('migration advanced from v' . $version . ' to invalid version ' . var_export($nextVersion, true));
                }
                $value = $migrated;
                $version = $nextVersion;
            }
        } catch (Throwable $e) {
            self::logReadIssue($optionName, 'migration failed: ' . $e->getMessage());
            return self::defaultValue($optionName);
        }

        if ($optionName === self::OPTION_SETTINGS) {
            $value = self::mergeSettingsDefaults($value);
        }

        $violations = self::validateCurrentValue($optionName, $value);
        if (!empty($violations)) {
            self::logReadIssue($optionName, 'current schema validation failed: ' . implode('; ', $violations));
            return self::defaultValue($optionName);
        }

        return $value;
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function prepareForWrite(string $optionName, array $value): array {
        if (!self::isContractedOption($optionName)) {
            return $value;
        }

        if ($optionName === self::OPTION_UNINSTALL_PREFERENCES) {
            $value = array_merge(self::defaultUninstallPreferences(), $value);
        }

        $value['_schemaVersion'] = self::CURRENT_VERSION;

        $violations = self::validateCurrentValue($optionName, $value);
        if (!empty($violations)) {
            throw new InvalidArgumentException(
                'Invalid wp_options storage payload for ' . $optionName . ': ' . implode('; ', $violations)
            );
        }

        return $value;
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $value
     * @return array<int, string>
     */
    public static function validateCurrentValue(string $optionName, array $value): array {
        if (!self::isContractedOption($optionName)) {
            return array();
        }

        $violations = self::validateSchemaVersion($value);
        if ($optionName === self::OPTION_SETTINGS) {
            return array_merge($violations, self::validateSettings($value));
        }
        if ($optionName === self::OPTION_UNINSTALL_PREFERENCES) {
            return array_merge($violations, self::validateUninstallPreferences($value));
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultUninstallPreferences(): array {
        return array(
            '_schemaVersion' => self::CURRENT_VERSION,
            'delete_redirects' => false,
            'delete_logs' => false,
            'delete_cache' => true,
            'send_feedback' => false,
            'uninstall_reason' => '',
            'selected_issues' => '',
            'followup_details' => '',
            'feedback_details' => '',
            'better_plugin_name' => '',
            'other_reason_text' => '',
            'feedback_email' => '',
            'include_diagnostics' => false,
        );
    }

    private static function isContractedOption(string $optionName): bool {
        return $optionName === self::OPTION_SETTINGS || $optionName === self::OPTION_UNINSTALL_PREFERENCES;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function toStringKeyedArray($value): ?array {
        if (!is_array($value)) {
            return null;
        }

        $normalized = array();
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * Missing versions are the original unversioned storage shape, treated as v1.
     *
     * @param array<string, mixed> $value
     */
    private static function readVersion(array $value): ?int {
        if (!array_key_exists('_schemaVersion', $value)) {
            return 1;
        }

        $version = $value['_schemaVersion'];
        if (is_int($version)) {
            return $version;
        }
        if (is_string($version) && preg_match('/^[0-9]+$/', $version)) {
            return (int)$version;
        }

        return null;
    }

    /**
     * @param string $optionName
     * @param int $fromVersion
     * @return callable(array<string, mixed>): array<string, mixed>
     */
    private static function loadMigration(string $optionName, int $fromVersion): callable {
        $file = null;
        if ($optionName === self::OPTION_SETTINGS && $fromVersion === 1) {
            $file = __DIR__ . '/../storage-migrations/abj404-settings-v1-to-v2.php';
        } else if ($optionName === self::OPTION_UNINSTALL_PREFERENCES && $fromVersion === 1) {
            $file = __DIR__ . '/../storage-migrations/abj404-uninstall-preferences-v1-to-v2.php';
        }

        if (!is_string($file) || !file_exists($file)) {
            throw new RuntimeException('missing migration for ' . $optionName . ' v' . $fromVersion . '-to-v' . ($fromVersion + 1));
        }

        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException('migration file is not callable: ' . $file);
        }

        return $migration;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultValue(string $optionName): array {
        if ($optionName === self::OPTION_UNINSTALL_PREFERENCES) {
            return self::defaultUninstallPreferences();
        }

        return self::mergeSettingsDefaults(array('_schemaVersion' => self::CURRENT_VERSION));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function mergeSettingsDefaults(array $value): array {
        $value = array_merge(self::defaultSettings(), $value);
        $value['_schemaVersion'] = self::CURRENT_VERSION;
        return $value;
    }

    /**
     * Storage-contract defaults must stay standalone because this file is
     * loaded by uninstall.php without the plugin autoloader.
     *
     * @return array<string, mixed>
     */
    private static function defaultSettings(): array {
        return array(
            '_schemaVersion' => self::CURRENT_VERSION,
            'default_redirect' => '301',
            'capture_404' => '1',
            'DB_VERSION' => '0.0.0',
            'admin_notification_frequency' => 'instant',
        );
    }

    /**
     * @param array<string, mixed> $value
     * @return array<int, string>
     */
    private static function validateSchemaVersion(array $value): array {
        if (!array_key_exists('_schemaVersion', $value)) {
            return array('missing required field: _schemaVersion');
        }
        if (!is_int($value['_schemaVersion'])) {
            return array('_schemaVersion must be integer');
        }
        if ($value['_schemaVersion'] !== self::CURRENT_VERSION) {
            return array('_schemaVersion must be ' . self::CURRENT_VERSION);
        }
        return array();
    }

    /**
     * @param array<string, mixed> $value
     * @return array<int, string>
     */
    private static function validateSettings(array $value): array {
        $violations = array();

        foreach (array('default_redirect', 'capture_404', 'DB_VERSION', 'admin_notification_frequency') as $field) {
            if (!array_key_exists($field, $value)) {
                $violations[] = 'missing required field: ' . $field;
            }
        }

        if (array_key_exists('default_redirect', $value)) {
            if (!(is_string($value['default_redirect']) || is_int($value['default_redirect']))) {
                $violations[] = 'default_redirect must be string or integer';
            }
        }

        if (array_key_exists('capture_404', $value)) {
            if (!(is_string($value['capture_404']) || is_int($value['capture_404']))) {
                $violations[] = 'capture_404 must be string or integer';
            }
        }

        if (array_key_exists('DB_VERSION', $value) && !is_string($value['DB_VERSION'])) {
            $violations[] = 'DB_VERSION must be string';
        }

        if (array_key_exists('admin_notification_email', $value) && !is_string($value['admin_notification_email'])) {
            $violations[] = 'admin_notification_email must be string';
        }

        if (array_key_exists('admin_notification_frequency', $value) && !is_string($value['admin_notification_frequency'])) {
            $violations[] = 'admin_notification_frequency must be string';
        }

        if (array_key_exists('admin_notification_digest_limit', $value)) {
            $limit = $value['admin_notification_digest_limit'];
            if (!(is_int($limit) || is_string($limit))) {
                $violations[] = 'admin_notification_digest_limit must be string or integer';
            }
        }

        if (array_key_exists('dest404_behavior', $value) && !is_string($value['dest404_behavior'])) {
            $violations[] = 'dest404_behavior must be string';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<int, string>
     */
    private static function validateUninstallPreferences(array $value): array {
        $violations = array();

        $boolFields = array('delete_redirects', 'delete_logs', 'delete_cache', 'send_feedback', 'include_diagnostics');
        foreach ($boolFields as $field) {
            if (!array_key_exists($field, $value)) {
                $violations[] = 'missing required field: ' . $field;
            } else if (!is_bool($value[$field])) {
                $violations[] = $field . ' must be boolean';
            }
        }

        $stringFields = array(
            'uninstall_reason',
            'selected_issues',
            'followup_details',
            'feedback_details',
            'better_plugin_name',
            'other_reason_text',
            'feedback_email',
        );
        foreach ($stringFields as $field) {
            if (!array_key_exists($field, $value)) {
                $violations[] = 'missing required field: ' . $field;
            } else if (!is_string($value[$field])) {
                $violations[] = $field . ' must be string';
            }
        }

        return $violations;
    }

    private static function logReadIssue(string $optionName, string $detail): void {
        $message = 'Storage contract read fallback for ' . $optionName . ': ' . $detail;
        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
