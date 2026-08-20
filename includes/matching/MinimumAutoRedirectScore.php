<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the minimum match score an automatic redirect has to clear
 * (the `auto_score` option), in the one form every user-facing surface
 * should quote it.
 *
 * Two screens name this number to the admin and they must never disagree:
 * the Simple Mode "Create automatic redirects" help text (which exists
 * because Simple Mode never renders the auto_score field itself) and the
 * admin-only note on the front-end suggestions page. Both read the value
 * actually in force rather than the shipped 90, so an admin who already
 * moved the bar is not told the wrong number, and both fall back to the
 * same shipped default when the stored value is missing, blank, or not a
 * number, so neither sentence can render with a hole in it.
 *
 * Display-only. The matcher itself reads auto_score through
 * {@see ABJ_404_Solution_MatchRequest::getMinScore()}, which additionally
 * applies the per-engine overrides; those overrides are deliberately not
 * reflected here, because the number being explained to the admin is the
 * global bar they can go and change.
 *
 * // allow-no-test-found: exercised by SimpleModeMatchScoreHelpTextTest
 */
class ABJ_404_Solution_MinimumAutoRedirectScore {

    /**
     * The configured minimum auto-redirect score, as a display string.
     *
     * @param array<string, mixed> $options The plugin options.
     * @return string The configured score, or the shipped default when the
     *   stored value is missing, blank, or not a number.
     */
    public static function forDisplay(array $options): string {
        $shippedDefault = self::shippedDefault();

        if (!array_key_exists('auto_score', $options)) {
            return $shippedDefault;
        }
        $configured = $options['auto_score'];
        if (!is_scalar($configured)) {
            return $shippedDefault;
        }
        $configured = (string)$configured;
        if (!is_numeric($configured)) {
            return $shippedDefault;
        }

        return $configured;
    }

    /**
     * The same number as a float, for comparing a match score against the bar.
     *
     * @param array<string, mixed> $options The plugin options.
     * @return float
     */
    public static function asFloat(array $options): float {
        return (float)self::forDisplay($options);
    }

    /**
     * The auto_score value the plugin ships with, read from the defaults
     * rather than repeated as a literal so a change to the shipped bar does
     * not have to be made in three places.
     *
     * @return string
     */
    private static function shippedDefault(): string {
        $defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();
        if (isset($defaults['auto_score']) && is_scalar($defaults['auto_score'])) {
            return (string)$defaults['auto_score'];
        }
        return '90';
    }
}
