<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates numeric settings fields and reports already-translated error
 * messages.
 *
 * Replaces the previous PluginLogicSettingsUpdate::validateAndSetNumericField()
 * helper, which called __($errorMessage, '404-solution') on a runtime
 * variable. The WordPress POT extractor only sees __() calls with literal
 * arguments, so every error message that flowed through the old helper
 * was invisible to the translator and could never be translated (audit
 * finding 440 in design-audit-2026-06-04.md).
 *
 * This validator requires the caller to pass an already-translated string
 * (built with __('literal text', '404-solution') at the call site). The
 * literal then appears in the POT file and is translatable. The validator
 * itself never calls __() on a non-literal argument.
 */
class ABJ_404_Solution_SettingsFieldValidator {

    /**
     * Validate a numeric field from a POST payload and, on success, write
     * the absint() of the value into the options array.
     *
     * @param array<string, mixed> $options Mutated in place on success.
     * @param array<string, mixed> $postData
     * @param string $fieldName The field key in both $postData and $options.
     * @param string $alreadyTranslatedErrorMessage Built with __('literal', '404-solution') at the call site.
     * @param int $minValue Minimum permitted value (inclusive in the default mode, exclusive in absint-strict mode).
     * @param bool $useAbsintForCheck When true, requires absint($value) > $minValue. When false, requires raw value >= $minValue.
     * @return string Empty string on success, or the translated error suffixed with ".<BR/>".
     */
    public function validateAndSetNumericField(
        array &$options,
        array $postData,
        string $fieldName,
        string $alreadyTranslatedErrorMessage,
        int $minValue = 0,
        bool $useAbsintForCheck = false
    ): string {
        if (!isset($postData[$fieldName])) {
            return '';
        }

        $value = $postData[$fieldName];
        $scalarValue = is_scalar($value) ? $value : 0;

        if ($useAbsintForCheck) {
            $passes = is_numeric($value) && absint($scalarValue) > $minValue;
        } else {
            $passes = is_numeric($value) && $value >= $minValue;
        }

        if ($passes) {
            $options[$fieldName] = absint($scalarValue);
            return '';
        }

        return $alreadyTranslatedErrorMessage . '.<BR/>';
    }
}
