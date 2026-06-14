<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether debug logging is currently enabled.
 *
 * Resolution order: an in-memory PluginLogic `options['debug_mode']` override
 * (read reflectively so an early-boot caller that has populated PluginLogic but
 * not yet wired the options repository still gets the right answer) takes
 * precedence; otherwise the canonical answer comes from the options repository.
 *
 * Pure policy: reads configuration, makes one boolean decision, writes nothing.
 */
class ABJ_404_Solution_LogDebugModeResolver {

    /** @return bool true if debug mode is on. false otherwise. */
    public function isDebug(): bool {
        $legacyDebugMode = $this->legacyPluginLogicDebugMode();
        if ($legacyDebugMode !== null) {
            return $legacyDebugMode;
        }

        $options = abj_service('options_repository')->getOptions(true);

        return (array_key_exists('debug_mode', $options) && $options['debug_mode'] == true);
    }

    /** @return bool|null */
    private function legacyPluginLogicDebugMode(): ?bool {
        if (!class_exists('ABJ_404_Solution_PluginLogic', false)) {
            return null;
        }
        $pluginLogic = ABJ_404_Solution_PluginLogic::peekInstance();
        if (!is_object($pluginLogic)) {
            return null;
        }
        try {
            $optionsProperty = new ReflectionProperty('ABJ_404_Solution_PluginLogic', 'options');
            $options = $optionsProperty->getValue($pluginLogic);
            if (is_array($options) && array_key_exists('debug_mode', $options)) {
                return $options['debug_mode'] == true;
            }
        } catch (\Throwable $e) {
            abj404_logPhpFallback(
                'logger-internal',
                'could not inspect PluginLogic debug_mode override: ' . $e->getMessage()
            );
        }
        return null;
    }
}
