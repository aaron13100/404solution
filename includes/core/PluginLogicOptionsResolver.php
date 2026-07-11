<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PluginLogicDefaults.php';
require_once __DIR__ . '/../settings/StorageOptionContracts.php';

/**
 * Owns the plugin's persistent settings option (`abj404_settings`):
 * read, normalize-for-read, merge defaults, version-upgrade if the
 * DB_VERSION stamp lags ABJ404_VERSION, normalize suggestion template
 * tokens, cache by db-check mode, and write-back through
 * StorageOptionContracts. Hosts the getOptions/updateOptions pair
 * extracted from PluginLogic. Composed by PluginLogic and exposed via
 * `$pluginLogic->optionsResolver()`; the service container key
 * `options_repository` remains as the internal plumbing handle used by
 * auth-time callers (PluginAdminAccessPolicy) that cannot route through
 * PluginLogic without creating a resolution cycle.
 */
class ABJ_404_Solution_PluginLogicOptionsResolver {

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
     * Raw, side-effect-free read of a single stored setting, bypassing the
     * entire getOptions() pipeline: no normalize-for-read, no schema-fallback
     * logging, no version upgrade, no default merge, no service resolution, no
     * cache mutation.
     *
     * This is the ONLY read path logging infrastructure may use to fetch the
     * few scalars it needs (the debug-file key, the debug-mode flag). Routing
     * those reads through getOptions() created an unbounded logging<->options
     * recursion: a stored value that fails StorageOptionContracts validation
     * logs a warning, and getDebugFilename()/isDebug() then re-read options to
     * find the log file, re-entering normalizeForRead() and re-logging without
     * bound -- the 4.3.0 "broken sites after the latest update" OOM at this
     * file's line ~250. Reading raw keeps logging strictly downstream of the
     * settings repository and can never re-enter it.
     *
     * @param string $key Setting key inside abj404_settings.
     * @param mixed $default Returned when storage is unusable or the key is absent.
     * @return mixed The raw stored value, or $default.
     */
    public function getRawSettingValue(string $key, $default = null) {
        $raw = get_option('abj404_settings');
        if (is_array($raw) && array_key_exists($key, $raw)) {
            return $raw[$key];
        }
        return $default;
    }

    /**
     * Raw, side-effect-free write of a single stored setting, bypassing the
     * storage write contract (no prepareForWrite, no schema validation, no
     * default merge) and, critically, the runtime logger. Used by logging
     * infrastructure to persist its own metadata keys (debug-file key,
     * last-sent-line counter) without re-entering the settings pipeline,
     * and by any caller that needs to update a single bookkeeping key
     * (e.g. EmailDigest's admin_notification_last_sent cadence timestamp).
     * Other keys already in the row are preserved (read-modify-write of the
     * single key), so it narrows the write to just this one key instead of
     * writing back a whole options snapshot a caller may have read much
     * earlier (e.g. at the top of a slow request). This is NOT atomic: the
     * read and the write here are still two separate WordPress option
     * calls, so a write from another request landing in between is still
     * possible in principle, just a far smaller window than a caller doing
     * its own getOptions()-then-updateOptions() round trip. Acceptable for
     * low-stakes bookkeeping keys; do not use this for values a lost update
     * would meaningfully harm.
     *
     * @param string $key Setting key inside abj404_settings.
     * @param mixed $value Value to store.
     * @return void
     */
    public function setRawSettingValue(string $key, $value): void {
        $raw = get_option('abj404_settings');
        if (!is_array($raw)) {
            $raw = array();
        }
        $raw[$key] = $value;
        update_option('abj404_settings', $raw);
        // A later full getOptions() must re-read the row rather than serve a
        // snapshot taken before this raw write.
        $this->clearCache();
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
                    $this->warn('version_upgrade service unavailable while reading options; skipped upgrade check.');
                }
            }
        }

        $pluginLogic = abj_service('plugin_logic');
        $pluginLogicClass = 'ABJ_404_Solution_PluginLogic';
        $settingsUpdate = is_object($pluginLogic) && method_exists($pluginLogic, 'settingsUpdate')
            && (!(class_exists($pluginLogicClass) && is_a($pluginLogic, $pluginLogicClass))
                || get_class($pluginLogic) === $pluginLogicClass)
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
    }

    /**
     * Legacy test seam: returns options seeded by a test before the real
     * WordPress option pipeline (and its DB_VERSION upgrade) is consulted.
     * Production callers never trigger either branch.
     *
     * Two seam shapes are honored, both anchored on PluginLogic::$instance:
     *   1. Reflection seam (older tests). ABJ_404_Solution_PluginLogic::$options
     *      is set to an array via ReflectionProperty + PluginLogicOptionsResolver::reset().
     *      Used by SpellChecker*Test, CodeReviewIssuesTest, LoggingTest, etc.
     *   2. Subclass-getOptions seam (post-b2ab795d tests). A test subclass that
     *      extends ABJ_404_Solution_PluginLogic overrides getOptions() and is
     *      installed as the singleton. Real PluginLogic has no getOptions()
     *      method (deleted with the options migration), so method_exists() is a
     *      reliable test-subclass discriminator.
     *
     * @return array<string, mixed>|null
     */
    private function legacyPluginLogicOptionsOverride() {
        if (!class_exists('ABJ_404_Solution_PluginLogic')) {
            return null;
        }
        $pluginLogic = $this->readPluginLogicInstance();
        if (!is_object($pluginLogic)) {
            return null;
        }

        // Only the real PluginLogic class declares the private $options property; anonymous
        // stubs (e.g. ShouldUpdatePluginTest::makeUpgrades) install a sibling class and would
        // raise on the reflection. Guard so the subclass-getOptions branch below is reached.
        if (is_a($pluginLogic, 'ABJ_404_Solution_PluginLogic')) {
            $reflectedOptions = $this->readPluginLogicOptionsProperty($pluginLogic);
            if (is_array($reflectedOptions)) {
                return $reflectedOptions;
            }
        }

        if (method_exists($pluginLogic, 'getOptions')) {
            $maybeOptions = $this->callPluginLogicGetOptions($pluginLogic);
            if (is_array($maybeOptions)) {
                return $maybeOptions;
            }
        }

        return null;
    }

    /** @return object|null PluginLogic singleton instance, or null when reflection fails. */
    private function readPluginLogicInstance() {
        try {
            $instanceProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'instance');
            $value = $instanceProperty->getValue();
            return is_object($value) ? $value : null;
        } catch (Throwable $e) {
            $this->warn('PluginLogicOptionsResolver could not read PluginLogic::$instance via reflection (' . $e->getMessage() . '); falling back to WordPress options.');
            return null;
        }
    }

    /**
     * @param ABJ_404_Solution_PluginLogic $pluginLogic Real PluginLogic instance (caller verified).
     * @return array<string, mixed>|null Seeded options array, or null when the property is absent / non-array.
     */
    private function readPluginLogicOptionsProperty($pluginLogic) {
        try {
            $optionsProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'options');
            $options = $optionsProperty->getValue($pluginLogic);
            if (!is_array($options)) {
                return null;
            }
            /** @var array<string, mixed> $typed */
            $typed = $options;
            return $typed;
        } catch (Throwable $e) {
            $this->warn('PluginLogicOptionsResolver could not read PluginLogic::$options via reflection (' . $e->getMessage() . '); falling through to subclass-getOptions seam.');
            return null;
        }
    }

    /**
     * Invoke a test-installed PluginLogic singleton's getOptions(true) override.
     * Real PluginLogic has no getOptions() method (removed in b2ab795d) so this
     * is only reached when a test subclass installs one as the singleton.
     *
     * @param object $pluginLogic Test stub with a getOptions(bool) method.
     * @return array<string, mixed>|null Subclass-provided options, or null when the override raised.
     */
    private function callPluginLogicGetOptions($pluginLogic) {
        try {
            /** @var callable $callable */
            $callable = array($pluginLogic, 'getOptions');
            $maybeOptions = call_user_func($callable, true);
            if (!is_array($maybeOptions)) {
                return null;
            }
            /** @var array<string, mixed> $typed */
            $typed = $maybeOptions;
            return $typed;
        } catch (Throwable $e) {
            $this->warn('PluginLogicOptionsResolver subclass-getOptions seam raised (' . $e->getMessage() . '); falling back to WordPress options.');
            return null;
        }
    }

    /**
     * Record a resolver-internal warning. Logs ONLY to the inert PHP error-log
     * sink, never the runtime logger: this class IS the settings-read path, and
     * the runtime logger reads settings to locate its own log file, so warning
     * through it during a read re-enters the read and recurses without bound
     * (the 4.3.0 "broken sites after the latest update" OOM). Hard rule:
     * normalized settings reads must never call runtime logging.
     */
    private function warn(string $message): void {
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
