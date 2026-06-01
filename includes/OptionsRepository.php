<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/PluginLogicDefaults.php';
require_once dirname(__FILE__) . '/StorageOptionContracts.php';

/**
 * Owns the plugin's persistent settings option (`abj404_settings`):
 * read, normalize-for-read, merge defaults, version-upgrade if the
 * DB_VERSION stamp lags ABJ404_VERSION, normalize suggestion template
 * tokens, cache by db-check mode, and write-back through
 * StorageOptionContracts. Replaces the getOptions/updateOptions pair
 * previously hosted on PluginLogic. Composed by callers via
 * abj_service('options_repository').
 */
class ABJ_404_Solution_OptionsRepository {

    /** @var array<string, mixed>|null */
    private $rawCache = null;

    /** @var array<string, mixed>|null */
    private $resolvedSkipDbCheck = null;

    /** @var array<string, mixed>|null */
    private $resolvedWithDbCheck = null;

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = new self();
        return self::$instance;
    }

    /** Reset cached instance and in-process caches (test seam). @return void */
    public static function reset(): void {
        if (self::$instance !== null) {
            self::$instance->clearCache();
        }
        self::$instance = null;
    }

    /** Clear in-process option caches. @return void */
    public function clearCache(): void {
        $this->rawCache = null;
        $this->resolvedSkipDbCheck = null;
        $this->resolvedWithDbCheck = null;
    }

    /**
     * Return only the delegated plugin-admin option needed by the
     * authorization policy. This intentionally avoids the full getOptions()
     * read path because capability checks can run while plugin_logic is being
     * resolved; the full path performs suggestion-template normalization via
     * PluginLogicSettingsUpdate and would create an auth-time service cycle.
     *
     * @return mixed String, array, or default value accepted by the policy normalizer.
     */
    public function getPluginAdminUsersOption() {
        $optionResult = get_option('abj404_settings');
        if (!is_array($optionResult)) {
            return ABJ_404_Solution_PluginLogicDefaults::defaults()['plugin_admin_users'];
        }

        $normalizedOptions = ABJ_404_Solution_StorageOptionContracts::normalizeForRead(
            ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
            $optionResult
        );

        if (array_key_exists('plugin_admin_users', $normalizedOptions)) {
            return $normalizedOptions['plugin_admin_users'];
        }

        return ABJ_404_Solution_PluginLogicDefaults::defaults()['plugin_admin_users'];
    }

    /**
     * Resolve the current plugin options. With $skip_db_check=true, the
     * DB_VERSION pipeline is skipped (used during the version-upgrade
     * sequence itself and from contexts that must not trigger upgrades).
     *
     * @param bool $skip_db_check
     * @return array<string, mixed>
     */
    public function getOptions(bool $skip_db_check = false): array {
        if (!$skip_db_check && is_array($this->resolvedWithDbCheck)) {
            return $this->resolvedWithDbCheck;
        }
        if ($skip_db_check) {
            if (is_array($this->resolvedSkipDbCheck)) {
                return $this->resolvedSkipDbCheck;
            }
            if (is_array($this->resolvedWithDbCheck)) {
                return $this->resolvedWithDbCheck;
            }
        }

        $legacyOptions = $this->legacyPluginLogicOptionsOverride();
        if (is_array($legacyOptions)) {
            return array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $legacyOptions);
        }

        if ($this->rawCache === null) {
            $optionResult = get_option('abj404_settings');
            if (is_array($optionResult)) {
                $normalizedOptions = ABJ_404_Solution_StorageOptionContracts::normalizeForRead(
                    ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
                    $optionResult
                );
                $this->rawCache = $normalizedOptions;
                if ($normalizedOptions !== $optionResult) {
                    $this->updateOptions($normalizedOptions);
                }
            } else {
                $this->rawCache = null;
            }
        }
        $options = $this->rawCache;

        if (!is_array($options)) {
            add_option('abj404_settings', '', '', false);
            $options = array();
        }

        $defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();
        $missing = false;
        foreach ($defaults as $key => $value) {
            if (!isset($options[$key]) || $options[$key] === '') {
                $options[$key] = $value;
                $missing = true;
            }
        }

        if ($missing) {
            $this->updateOptions($options);
        }

        if ($skip_db_check == false) {
            if (!array_key_exists('DB_VERSION', $options) || $options['DB_VERSION'] != ABJ404_VERSION) {
                $versionUpgrade = abj_service('version_upgrade');
                if (is_object($versionUpgrade) && method_exists($versionUpgrade, 'upgradeIfNeeded')) {
                    $options = $versionUpgrade->upgradeIfNeeded($options);
                } else {
                    error_log('404 Solution: version_upgrade service unavailable while reading options; skipped upgrade check.');
                }
            }
        }

        $pluginLogic = abj_service('plugin_logic');
        $settingsUpdate = is_object($pluginLogic) && method_exists($pluginLogic, 'settingsUpdate')
            && (!(class_exists('ABJ_404_Solution_PluginLogic') && $pluginLogic instanceof ABJ_404_Solution_PluginLogic)
                || get_class($pluginLogic) === ABJ_404_Solution_PluginLogic::class)
            ? $pluginLogic->settingsUpdate()
            : null;
        if (is_object($settingsUpdate)
                && method_exists($settingsUpdate, 'normalizeSuggestionTemplateOptions')
                && $settingsUpdate->normalizeSuggestionTemplateOptions($options)) {
            $this->updateOptions($options);
        }

        if ($skip_db_check) {
            $this->resolvedSkipDbCheck = $options;
        } else {
            $this->resolvedWithDbCheck = $options;
        }

        return $options;
    }

    /**
     * Persist plugin options. Merges defaults, runs the storage write
     * contract, calls update_option, then invalidates the in-process cache.
     *
     * @param array<string, mixed> $options
     * @return void
     */
    public function updateOptions(array $options): void {
        $options = array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $options);
        $options = ABJ_404_Solution_StorageOptionContracts::prepareForWrite(
            ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
            $options
        );
        update_option('abj404_settings', $options);
        $this->rawCache = $options;
        $this->resolvedSkipDbCheck = null;
        $this->resolvedWithDbCheck = null;
        $this->syncLegacyPluginLogicOptions($options);
    }

    /** @return array<string, mixed>|null */
    private function legacyPluginLogicOptionsOverride() {
        if (!class_exists('ABJ_404_Solution_PluginLogic')) {
            return null;
        }
        try {
            $instanceProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'instance');
            $pluginLogic = $instanceProperty->getValue();
            if (!is_object($pluginLogic)) {
                return null;
            }
            if (method_exists($pluginLogic, 'getOptions')) {
                $method = new ReflectionMethod($pluginLogic, 'getOptions');
                if ($method->getDeclaringClass()->getName() !== 'ABJ_404_Solution_PluginLogic') {
                    $optionsFromLogic = $pluginLogic->getOptions(true);
                    if (is_array($optionsFromLogic)) {
                        return $optionsFromLogic;
                    }
                }
            }
            $optionsProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'options');
            $options = $optionsProperty->getValue($pluginLogic);
            return is_array($options) ? $options : null;
        } catch (Throwable $e) {
            // allow-silent-catch: legacy PluginLogic reflection seam is optional; absence falls back to WordPress options.
            return null;
        }
    }

    /** @param array<string, mixed> $options @return void */
    private function syncLegacyPluginLogicOptions(array $options): void {
        if (!class_exists('ABJ_404_Solution_PluginLogic')) {
            return;
        }
        try {
            $instanceProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'instance');
            $pluginLogic = $instanceProperty->getValue();
            if (!is_object($pluginLogic)) {
                return;
            }
            $optionsProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'options');
            $optionsProperty->setValue($pluginLogic, $options);
        } catch (Throwable $e) {
            // allow-silent-catch: best-effort sync for legacy reflection tests; OptionsRepository remains authoritative.
            return;
        }
    }
}
