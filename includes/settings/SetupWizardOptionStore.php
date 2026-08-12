<?php

// allow-no-test-found: covered by tests/SetupWizardTest.php through setup wizard form submission entry points.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and persists setup wizard state.
 */
class ABJ_404_Solution_SetupWizardOptionStore {

    public const OPTION_NAME = 'abj404_setup_completed';

    /**
     * Return true when the setup wizard has already been completed.
     *
     * @return bool
     */
    public static function isComplete(): bool {
        $completed = get_option(self::OPTION_NAME, '');
        return !empty($completed);
    }

    /**
     * Persist today's setup completion marker.
     *
     * @return void
     */
    public static function markCompleteToday(): void {
        update_option(self::OPTION_NAME, gmdate('Y-m-d', abj_clock()->now()));
    }

    /**
     * Return the currently persisted plugin options.
     *
     * @return array<string,mixed>
     */
    public static function loadPluginOptions(): array {
        $optionsRepository = abj_service('options_repository');
        $options = $optionsRepository->getOptions();
        return is_array($options) ? $options : array();
    }

    /**
     * Return the site administrator email used by setup decisions.
     */
    public static function adminEmail(): string {
        $adminEmail = get_option('admin_email');
        return is_string($adminEmail) ? $adminEmail : '';
    }

    /**
     * Persist plugin options already derived by the setup policy.
     *
     * @param array<string,mixed> $options
     */
    public static function savePluginOptions(array $options): void {
        abj_service('options_repository')->updateOptions($options);
    }
}
