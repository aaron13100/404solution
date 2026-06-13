<?php

// allow-no-test-found: covered by tests/SetupWizardTest.php through setup wizard form submission entry points.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists setup wizard completion and setup-derived option changes.
 */
class ABJ_404_Solution_SetupWizardOptionStore {

    /**
     * Return true when the setup wizard has already been completed.
     *
     * @return bool
     */
    public static function isComplete(): bool {
        $completed = get_option(ABJ_404_Solution_SetupWizard::OPTION_NAME, '');
        return !empty($completed);
    }

    /**
     * Persist today's setup completion marker.
     *
     * @return void
     */
    public static function markCompleteToday(): void {
        update_option(ABJ_404_Solution_SetupWizard::OPTION_NAME, gmdate('Y-m-d', abj_clock()->now()));
    }

    /**
     * Persist plugin options derived from validated setup answers.
     *
     * @param array{q1:string,q2:string,q3:string} $answers Validated setup answers.
     * @return void
     */
    public static function saveAnswers(array $answers): void {
        $optionsRepository = abj_service('options_repository');
        $options = $optionsRepository->getOptions();
        if (!is_array($options)) {
            $options = array();
        }

        $adminEmail = get_option('admin_email');
        $options = ABJ_404_Solution_SetupWizardAnswerPolicy::applyToOptions(
            $options,
            $answers,
            is_string($adminEmail) ? $adminEmail : ''
        );

        $optionsRepository->updateOptions($options);
    }
}
